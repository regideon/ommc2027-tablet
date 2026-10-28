<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TradeCheck\FieldType;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAnswer;
use App\Models\Forms\TradeCheck\TradeCheckAnswerItems;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\Forms\TradeCheck\TradeCheckGroup;
use App\Models\Forms\TradeCheck\TradeCheckItem;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use App\Support\Forms\TradeCheckSubmissionRules;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Trade Check form's single service. Trade Check is dynamic: the Portal
 * pull carries the template definitions (groups + items) and the user's answer
 * values, which are cached locally so the form can be rendered and completed
 * offline.
 *
 * Portal caveat (accepted): answer values are stored per template item, not per
 * user, so templates are effectively single-owner. The tablet follows the same
 * contract.
 */
class TradeCheckService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{trade_checks: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Cached Trade Check templates (definitions), ordered by name.
     *
     * @return Collection<int, TradeCheck>
     */
    public function programs(): Collection
    {
        $result = $this->pull();
        $this->programReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheDefinitions($result['trade_checks']);
        }

        return TradeCheck::query()->orderBy('name')->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * The current user's answered Trade Check submissions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function submissions(): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $result = $this->pull();
        $this->globalReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheDefinitions($result['trade_checks']);
            $this->cacheAnswers($result['trade_checks'], $viewer);
            $this->reconcileDeleted($result['trade_checks']);
        }

        $local = TradeCheck::query()
            ->with([
                'group.items.answerItem',
                'group.items.attachments',
                'salescall.customer',
                'answers' => fn ($query) => $query->where('submitted_by', $viewer->id),
            ])
            ->get();

        return $local
            ->map(fn (TradeCheck $tradeCheck): array => $this->mapLocal($tradeCheck, $viewer))
            ->sortBy([
                ['has_submission', 'desc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    public function globalReadFailed(): bool
    {
        return $this->globalReadFailed;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE / EDIT
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): TradeCheck
    {
        $validated = TradeCheckSubmissionRules::validate($input);

        $tradeCheck = TradeCheck::query()->with('group.items')->find($validated['trade_check_id']);

        if (! $tradeCheck) {
            throw ValidationException::withMessages([
                'trade_check_id' => ['The selected Trade Check template is not available locally.'],
            ]);
        }

        $this->assertRequiredAnswers($tradeCheck, $validated['answers'] ?? []);
        $attachments = $this->normalizeItemAttachments($input['attachments'] ?? []);
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($user, $tradeCheck, $validated, $attachments, &$storedPaths): void {
                $tradeCheck->update([
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAnswers($tradeCheck, $user, $validated['answers'] ?? []);
                $this->removeAttachments($tradeCheck, $validated['remove_attachments'] ?? []);
                $this->persistAttachments($tradeCheck, $user, $attachments, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $tradeCheck->fresh(['group.items.answerItem', 'answers']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): TradeCheck
    {
        if (! $submission instanceof TradeCheck) {
            throw new InvalidArgumentException('Expected a Trade Check submission.');
        }

        $tradeCheck = $submission;

        if (! $tradeCheck->isEditableBy((int) auth()->id())) {
            throw ValidationException::withMessages([
                'trade_check' => ['Only Trade Checks that are still pending can be edited.'],
            ]);
        }

        $validated = TradeCheckSubmissionRules::validate($input, includeTemplate: false);

        $this->assertRequiredAnswers($tradeCheck->load('group.items'), $validated['answers'] ?? []);
        $attachments = $this->normalizeItemAttachments($input['attachments'] ?? []);
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($tradeCheck, $validated, $attachments, &$storedPaths): void {
                $tradeCheck->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAnswers($tradeCheck, auth()->user(), $validated['answers'] ?? []);
                $this->removeAttachments($tradeCheck, $validated['remove_attachments'] ?? []);
                $this->persistAttachments($tradeCheck, auth()->user(), $attachments, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $tradeCheck->fresh(['group.items.answerItem', 'answers']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): TradeCheck
    {
        $tradeCheck = isset($row['server_id'])
            ? TradeCheck::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($tradeCheck) {
            return $tradeCheck;
        }

        throw ValidationException::withMessages([
            'trade_check' => ['This Trade Check is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{trade_checks: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readTradeChecksWithStatus();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheDefinitions(array $rows): void
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return;
        }

        foreach ($rows as $row) {
            if (! isset($row['id'], $row['name'])) {
                continue;
            }

            $clientUuid = is_string($row['client_uuid'] ?? null) && $row['client_uuid'] !== '' ? $row['client_uuid'] : null;
            $tradeCheck = $clientUuid !== null
                ? TradeCheck::query()->where('local_uuid', $clientUuid)->first()
                : null;
            $tradeCheck ??= TradeCheck::query()->firstOrNew(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($tradeCheck->exists && $tradeCheck->sync_status !== 'synced') {
                continue;
            }

            if (! $tradeCheck->exists) {
                $tradeCheck->local_uuid = (string) Str::uuid();
                $tradeCheck->submitted_by = $viewer->id;
                $tradeCheck->sync_status = 'synced';
                $tradeCheck->sync_attempts = 0;
            }

            $tradeCheck->name = $row['name'];
            $tradeCheck->save();

            foreach ($row['group'] ?? [] as $groupRow) {
                if (! isset($groupRow['name'])) {
                    continue;
                }

                $group = TradeCheckGroup::updateOrCreate(
                    [
                        'trade_check_id' => $tradeCheck->id,
                        'name' => $groupRow['name'],
                    ],
                    [
                        'category' => (int) ($groupRow['category'] ?? 1),
                        'description' => $groupRow['description'] ?? null,
                        'order' => (int) ($groupRow['order'] ?? 0),
                    ],
                );

                foreach ($groupRow['items'] ?? [] as $itemRow) {
                    if (! isset($itemRow['name'])) {
                        continue;
                    }

                    TradeCheckItem::updateOrCreate(
                        [
                            'group_id' => $group->id,
                            'name' => $itemRow['name'],
                        ],
                        [
                            'description' => $itemRow['description'] ?? null,
                            'type' => (int) ($itemRow['type'] ?? 1),
                            'is_required' => (bool) ($itemRow['is_required'] ?? false),
                            'is_image_required' => (bool) ($itemRow['is_image_required'] ?? false),
                            'options' => $itemRow['options'] ?? null,
                            'order' => (int) ($itemRow['order'] ?? 0),
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheAnswers(array $rows, User $viewer): void
    {
        // The Portal identifies the submitter by its own user id; match it via
        // the server id stored at login rather than the local user id.
        $serverUserId = (int) ($viewer->server_id ?? 0);

        if ($serverUserId <= 0) {
            return;
        }

        foreach ($rows as $row) {
            $tradeCheck = TradeCheck::query()->where('server_id', $row['id'] ?? null)->first();

            // Never overwrite local work that has not been pushed yet.
            if (! $tradeCheck || $tradeCheck->sync_status !== 'synced') {
                continue;
            }

            $itemValues = [];
            $itemIds = [];

            foreach ($row['group'] ?? [] as $groupRow) {
                foreach ($groupRow['items'] ?? [] as $itemRow) {
                    if (! isset($itemRow['name'])) {
                        continue;
                    }

                    $localItemId = TradeCheckItem::query()
                        ->whereHas('group', fn ($query) => $query->where('trade_check_id', $tradeCheck->id))
                        ->where('name', $itemRow['name'])
                        ->value('id');

                    if ($localItemId) {
                        $itemValues[$itemRow['name']] = $itemRow['answer'] ?? null;
                        $itemIds[$itemRow['name']] = $localItemId;
                    }
                }
            }

            foreach ($row['answers'] ?? [] as $answerRow) {
                if ((int) ($answerRow['submitted_by'] ?? 0) !== $serverUserId) {
                    continue;
                }

                $itemName = $answerRow['item']['name'] ?? null;

                if (! $itemName || ! isset($itemIds[$itemName])) {
                    continue;
                }

                TradeCheckAnswer::updateOrCreate(
                    [
                        'trade_check_id' => $tradeCheck->id,
                        'item_id' => $itemIds[$itemName],
                        'submitted_by' => $viewer->id,
                    ],
                    [
                        'salescall_id' => null,
                        'rsm_status' => (int) ($answerRow['rsm_status'] ?? RSMStatus::PENDING->value),
                        'tdh_status' => (int) ($answerRow['tdh_status'] ?? TDHStatus::PENDING->value),
                        'synced_at' => now(),
                    ],
                );

                TradeCheckAnswerItems::updateOrCreate(
                    [
                        'trade_check_id' => $tradeCheck->id,
                        'item_id' => $itemIds[$itemName],
                    ],
                    ['answer' => $itemValues[$itemName] ?? null],
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function reconcileDeleted(array $rows): void
    {
        $serverIds = collect($rows)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        TradeCheck::query()
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (TradeCheck $tradeCheck): void {
                foreach ($tradeCheck->attachments ?? collect() as $attachment) {
                    $this->deleteAttachmentFile($attachment);
                }

                $tradeCheck->delete();
            });
    }

    /**
     * @param  array<int|string, mixed>  $answers  item id => value
     */
    private function persistAnswers(TradeCheck $tradeCheck, User $user, array $answers): void
    {
        foreach ($tradeCheck->group->flatMap->items as $item) {
            $value = array_key_exists($item->id, $answers) ? $answers[$item->id] : null;
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            TradeCheckAnswer::updateOrCreate(
                [
                    'trade_check_id' => $tradeCheck->id,
                    'item_id' => $item->id,
                    'submitted_by' => $user->id,
                ],
                [
                    'salescall_id' => $tradeCheck->salescall_id,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'tdh_status' => TDHStatus::PENDING->value,
                ],
            );

            TradeCheckAnswerItems::updateOrCreate(
                [
                    'trade_check_id' => $tradeCheck->id,
                    'item_id' => $item->id,
                ],
                ['answer' => $value],
            );
        }
    }

    /**
     * @param  array<int|string, mixed>  $answers
     */
    private function assertRequiredAnswers(TradeCheck $tradeCheck, array $answers): void
    {
        $errors = [];

        foreach ($tradeCheck->group->flatMap->items as $item) {
            $value = $answers[$item->id] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($item->is_required && ($value === null || $value === '')) {
                $errors['answers.'.$item->id] = ['This Trade Check item is required.'];

                continue;
            }

            $options = $this->optionsFor($item);

            if ($value !== null && $value !== '' && $options !== []
                && ! in_array((string) $value, array_map('strval', $options), true)) {
                $errors['answers.'.$item->id] = ['Choose a valid option for this item.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Boolean items without explicit options still accept Yes/No, and the answer
     * must always be one of the item's options.
     *
     * @return array<int, mixed>
     */
    private function optionsFor(TradeCheckItem $item): array
    {
        $options = is_array($item->options)
            ? array_values(array_filter($item->options, static fn (mixed $option): bool => $option !== null && $option !== ''))
            : [];

        if ($item->type === FieldType::BOOLEAN && $options === []) {
            return ['Yes', 'No'];
        }

        return $options;
    }

    /**
     * @param  array<int|string, mixed>  $attachments  item id => list of staged attachment ids
     * @return array<int, array{item_id: int, staged_ids: array<int, int>}>
     */
    private function normalizeItemAttachments(array $attachments): array
    {
        $normalized = [];

        foreach ($attachments as $itemId => $ids) {
            if (! is_array($ids)) {
                continue;
            }

            $stagedIds = array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $ids,
            ), static fn (int $id): bool => $id > 0));

            if ($stagedIds === []) {
                continue;
            }

            $normalized[] = [
                'item_id' => (int) $itemId,
                'staged_ids' => $stagedIds,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{item_id: int, staged_ids: array<int, int>}>  $attachments
     * @param  list<string>  $storedPaths
     */
    private function persistAttachments(TradeCheck $tradeCheck, User $user, array $attachments, array &$storedPaths): void
    {
        $validItemIds = $tradeCheck->group->flatMap->items->pluck('id')->all();
        $stagedService = app(StagedAttachmentService::class);

        foreach ($attachments as $group) {
            if (! in_array($group['item_id'], $validItemIds, true)) {
                continue;
            }

            foreach ($group['staged_ids'] as $stagedId) {
                $staged = $stagedService->findForUser($user, $stagedId);

                if (! $staged instanceof StagedAttachment) {
                    throw ValidationException::withMessages([
                        'attachments' => ['A staged attachment is no longer available. Re-add the photo and try again.'],
                    ]);
                }

                $metadata = $stagedService->consume($user, $staged, 'trade_check_attachments');
                $storedPaths[] = $metadata['local_path'];

                TradeCheckAttachment::create([
                    'local_uuid' => (string) Str::uuid(),
                    'trade_check_id' => $tradeCheck->id,
                    'item_id' => $group['item_id'],
                    'local_path' => $metadata['local_path'],
                    'original_name' => $metadata['original_name'],
                    'mime_type' => $metadata['mime_type'],
                    'extension' => $metadata['extension'],
                    'byte_size' => $metadata['byte_size'],
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $storedPaths
     */
    private function cleanupStoredPaths(array $storedPaths): void
    {
        foreach ($storedPaths as $storedPath) {
            if (is_string($storedPath) && is_file($storedPath)) {
                @unlink($storedPath);
            }
        }
    }

    /**
     * Retry a local write on transient SQLite open/lock errors (CANTOPEN, busy,
     * disk I/O) before surfacing the failure.
     */
    private function attemptTransaction(Closure $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! $this->isTransientDatabaseError($exception)) {
                    throw $exception;
                }

                usleep(150_000 * $attempt);
            }
        }
    }

    private function isTransientDatabaseError(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'unable to open database file')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'disk I/O error');
    }

    /**
     * Delete the given local attachments (rows + files) from a Trade Check.
     * Removal is local-only; the Portal keeps its own copy.
     *
     * @param  array<int, mixed>  $ids
     */
    private function removeAttachments(TradeCheck $tradeCheck, array $ids): void
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        TradeCheckAttachment::query()
            ->where('trade_check_id', $tradeCheck->id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (TradeCheckAttachment $attachment): void {
                $this->deleteAttachmentFile($attachment);
                $attachment->delete();
            });
    }

    public function attachmentUrl(TradeCheckAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "trade_check_attachments/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($attachment->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    private function deleteAttachmentFile(TradeCheckAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("trade_check_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(TradeCheck $tradeCheck, User $viewer): array
    {
        $answers = $tradeCheck->answers->where('submitted_by', $viewer->id)->keyBy('item_id');
        $firstAnswer = $answers->first();
        $rsm = $firstAnswer?->rsm_status?->value ?? RSMStatus::PENDING->value;
        $tdh = $firstAnswer?->tdh_status?->value ?? TDHStatus::PENDING->value;

        return [
            'id' => $tradeCheck->id,
            'server_id' => $tradeCheck->server_id,
            'local_uuid' => $tradeCheck->local_uuid,
            'name' => $tradeCheck->name,
            'sales_call' => $this->salesCallLabel($tradeCheck->salescall),
            'salescall_uuid' => $tradeCheck->salescall?->local_uuid,
            'rsm_status' => $rsm,
            'tdh_status' => $tdh,
            'has_submission' => $answers->isNotEmpty(),
            'is_editable' => $tradeCheck->isEditableBy($viewer->id),
            'sync_status' => $tradeCheck->sync_status,
            'groups' => $tradeCheck->group
                ->sortBy('order')
                ->values()
                ->map(function (TradeCheckGroup $group) use ($answers): array {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'category' => $group->category->value,
                        'category_label' => $group->category->meta('title'),
                        'description' => $group->description,
                        'items' => $group->items
                            ->sortBy('order')
                            ->values()
                            ->map(function (TradeCheckItem $item) use ($answers): array {
                                return [
                                    'id' => $item->id,
                                    'name' => $item->name,
                                    'description' => $item->description,
                                    'type' => $item->type->value,
                                    'type_label' => $item->type->meta('title'),
                                    'is_required' => $item->is_required,
                                    'is_image_required' => $item->is_image_required,
                                    'options' => $item->options ?? [],
                                    'answer' => $item->answerItem?->answer,
                                    'has_answer' => $answers->has($item->id),
                                    'attachments' => $item->attachments
                                        ->map(fn (TradeCheckAttachment $attachment): array => [
                                            'id' => $attachment->id,
                                            'server_id' => $attachment->server_id,
                                            'original_name' => $attachment->original_name,
                                            'mime_type' => $attachment->mime_type,
                                            'url' => $this->attachmentUrl($attachment),
                                        ])
                                        ->values()
                                        ->all(),
                                ];
                            })
                            ->all(),
                    ];
                })
                ->all(),
            'created_at' => $tradeCheck->created_at?->toISOString(),
        ];
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }
}

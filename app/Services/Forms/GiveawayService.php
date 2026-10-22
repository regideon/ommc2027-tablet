<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\RSMStatus;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Giveaway form's single service: reads the current user's submissions
 * (local rows cached from a live Portal pull) and creates/edits them
 * offline-first. Each item carries one uploaded signature image.
 *
 * Portal caveat (accepted): a push force-recreates the submission's items, so
 * a local edit re-marks signature rows pending to re-attach after the rebuild.
 */
class GiveawayService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{giveaways: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Giveaway has no master data; the contract method returns an empty set.
     *
     * @return Collection<int, Giveaway>
     */
    public function programs(): Collection
    {
        return collect();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * The current user's Giveaway submissions, newest first.
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
            $this->cacheDefinitions($result['giveaways']);
            $this->cacheAnswers($result['giveaways'], $viewer);
            $this->reconcileDeleted($result['giveaways']);
        }

        $local = Giveaway::query()
            ->with([
                'items.answerItem',
                'salescall.customer',
                'answers' => fn ($query) => $query->where('submitted_by', $viewer->id)->with('signature'),
            ])
            ->where('submitted_by', $viewer->id)
            ->get();

        return $local
            ->map(fn (Giveaway $giveaway): array => $this->mapLocal($giveaway, $viewer))
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
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
    public function create(User $user, array $input): Giveaway
    {
        $validated = Validator::make($input, $this->rules())->validate();

        $items = $this->normalizeItems($validated['items'] ?? []);
        $this->assertItems($items);
        $storedPaths = [];
        $giveaway = null;

        try {
            $this->attemptTransaction(function () use ($user, $validated, $items, &$storedPaths, &$giveaway): void {
                $giveaway = Giveaway::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'name' => $validated['name'],
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                foreach ($items as $index => $row) {
                    $item = GiveawayItem::create([
                        'giveaway_id' => $giveaway->id,
                        'category' => $row['category'],
                        'name' => $row['name'],
                    ]);

                    $this->persistAnswerForItem($giveaway, $user, $item, $row, $index, $storedPaths);
                }
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $giveaway->fresh(['items.answerItem', 'answers.signature']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Giveaway
    {
        if (! $submission instanceof Giveaway) {
            throw new InvalidArgumentException('Expected a Giveaway submission.');
        }

        $giveaway = $submission;

        if (! $giveaway->isEditableBy((int) auth()->id())) {
            throw ValidationException::withMessages([
                'giveaway' => ['Only Giveaways that are still pending can be edited.'],
            ]);
        }

        $validated = Validator::make($input, $this->rules())->validate();

        $items = $this->normalizeItems($validated['items'] ?? []);
        $this->assertItems($items);
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($giveaway, $validated, $items, &$storedPaths): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $giveaway->update([
                    'name' => $validated['name'],
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $keptItemIds = [];

                foreach ($items as $index => $row) {
                    $item = $row['id'] !== null
                        ? GiveawayItem::query()->where('giveaway_id', $giveaway->id)->find($row['id'])
                        : null;

                    if ($item instanceof GiveawayItem) {
                        $item->update([
                            'category' => $row['category'],
                            'name' => $row['name'],
                        ]);
                    } else {
                        $item = GiveawayItem::create([
                            'giveaway_id' => $giveaway->id,
                            'category' => $row['category'],
                            'name' => $row['name'],
                        ]);
                    }

                    $keptItemIds[] = $item->id;

                    $this->persistAnswerForItem($giveaway, $user, $item, $row, $index, $storedPaths);
                }

                $this->removeDroppedItems($giveaway, $keptItemIds);
                $this->resetSignatureSyncState($giveaway);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $giveaway->fresh(['items.answerItem', 'answers.signature']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Giveaway
    {
        $giveaway = isset($row['server_id'])
            ? Giveaway::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($giveaway) {
            return $giveaway;
        }

        throw ValidationException::withMessages([
            'giveaway' => ['This Giveaway is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    public function signatureUrl(GiveawaySignature $signature): ?string
    {
        if (! $signature->local_path || ! is_file($signature->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($signature->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $signature->local_uuid ?: (string) $signature->id;
        $relativePath = "giveaway_signatures/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($signature->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{giveaways: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readGiveawaysWithStatus();
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

            $giveaway = Giveaway::query()->firstOrNew(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($giveaway->exists && $giveaway->sync_status !== 'synced') {
                continue;
            }

            if (! $giveaway->exists) {
                $giveaway->local_uuid = (string) Str::uuid();
                $giveaway->submitted_by = $viewer->id;
                $giveaway->sync_status = 'synced';
                $giveaway->sync_attempts = 0;
            }

            $giveaway->name = $row['name'];
            $giveaway->save();

            foreach ($row['items'] ?? [] as $itemRow) {
                if (! is_array($itemRow) || ! isset($itemRow['name'])) {
                    continue;
                }

                GiveawayItem::updateOrCreate(
                    [
                        'giveaway_id' => $giveaway->id,
                        'name' => $itemRow['name'],
                    ],
                    [
                        'category' => (int) ($itemRow['category'] ?? GiveawayCategory::GC->value),
                    ],
                );
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
            $giveaway = Giveaway::query()->where('server_id', $row['id'] ?? null)->first();

            // Never overwrite local work that has not been pushed yet.
            if (! $giveaway || $giveaway->sync_status !== 'synced') {
                continue;
            }

            $itemNames = collect($row['items'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && isset($item['id'], $item['name']))
                ->mapWithKeys(fn (array $item): array => [(int) $item['id'] => (string) $item['name']]);

            foreach ($row['answers'] ?? [] as $answerRow) {
                if (! is_array($answerRow) || (int) ($answerRow['submitted_by'] ?? 0) !== $serverUserId) {
                    continue;
                }

                $itemName = $itemNames->get((int) ($answerRow['item_id'] ?? 0));

                if ($itemName === null) {
                    continue;
                }

                $localItemId = GiveawayItem::query()
                    ->where('giveaway_id', $giveaway->id)
                    ->where('name', $itemName)
                    ->value('id');

                if (! $localItemId) {
                    continue;
                }

                GiveawayAnswer::updateOrCreate(
                    [
                        'giveaway_id' => $giveaway->id,
                        'item_id' => $localItemId,
                        'submitted_by' => $viewer->id,
                    ],
                    [
                        'salescall_id' => null,
                        'rsm_status' => (int) ($answerRow['rsm_status'] ?? RSMStatus::PENDING->value),
                    ],
                );

                $answerItem = $answerRow['answer_item'] ?? $answerRow['answerItem'] ?? null;

                GiveawayAnswerItem::updateOrCreate(
                    [
                        'giveaway_id' => $giveaway->id,
                        'item_id' => $localItemId,
                    ],
                    ['answer' => is_array($answerItem) ? ($answerItem['answer'] ?? null) : null],
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

        Giveaway::query()
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (Giveaway $giveaway): void {
                foreach ($giveaway->signatures ?? collect() as $signature) {
                    $this->deleteSignatureFile($signature);
                }

                $giveaway->delete();
            });
    }

    /**
     * @param  array{id: ?int, category: ?int, name: string, answer: ?string, signature_id: ?int, staged_signature_id: ?int}  $row
     * @param  list<string>  $storedPaths
     */
    private function persistAnswerForItem(Giveaway $giveaway, User $user, GiveawayItem $item, array $row, int $index, array &$storedPaths): void
    {
        $answer = GiveawayAnswer::updateOrCreate(
            [
                'giveaway_id' => $giveaway->id,
                'item_id' => $item->id,
                'submitted_by' => $user->id,
            ],
            [
                'salescall_id' => $giveaway->salescall_id,
                'rsm_status' => RSMStatus::PENDING->value,
            ],
        );

        GiveawayAnswerItem::updateOrCreate(
            [
                'giveaway_id' => $giveaway->id,
                'item_id' => $item->id,
            ],
            ['answer' => $row['answer']],
        );

        if ($row['staged_signature_id'] !== null) {
            $this->replaceSignature($giveaway, $answer, $user, $row['staged_signature_id'], $index, $storedPaths);
        }
    }

    /**
     * @param  list<string>  $storedPaths
     */
    private function replaceSignature(Giveaway $giveaway, GiveawayAnswer $answer, User $user, int $stagedId, int $index, array &$storedPaths): void
    {
        $stagedService = app(StagedAttachmentService::class);
        $staged = $stagedService->findForUser($user, $stagedId);

        if (! $staged instanceof StagedAttachment) {
            throw ValidationException::withMessages([
                "items.$index.signature_id" => ['A staged signature is no longer available. Re-upload the signature and try again.'],
            ]);
        }

        GiveawaySignature::query()
            ->where('answer_id', $answer->id)
            ->get()
            ->each(function (GiveawaySignature $existing): void {
                $this->deleteSignatureFile($existing);
                $existing->delete();
            });

        $metadata = $stagedService->consume($user, $staged, 'giveaway_signatures');
        $storedPaths[] = $metadata['local_path'];

        GiveawaySignature::create([
            'local_uuid' => (string) Str::uuid(),
            'giveaway_id' => $giveaway->id,
            'answer_id' => $answer->id,
            'salescall_id' => $giveaway->salescall_id,
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

    /**
     * @param  array<int, mixed>  $keptItemIds
     */
    private function removeDroppedItems(Giveaway $giveaway, array $keptItemIds): void
    {
        $dropped = GiveawayItem::query()
            ->where('giveaway_id', $giveaway->id)
            ->whereNotIn('id', $keptItemIds)
            ->get();

        foreach ($dropped as $item) {
            $answerIds = GiveawayAnswer::query()
                ->where('giveaway_id', $giveaway->id)
                ->where('item_id', $item->id)
                ->pluck('id');

            GiveawaySignature::query()
                ->whereIn('answer_id', $answerIds)
                ->get()
                ->each(function (GiveawaySignature $signature): void {
                    $this->deleteSignatureFile($signature);
                });

            $item->delete();
        }
    }

    /**
     * The Portal rebuilds items on every push (cascading its signatures), so
     * local signatures are re-queued to attach again after the rebuild.
     */
    private function resetSignatureSyncState(Giveaway $giveaway): void
    {
        foreach ($giveaway->signatures()->get() as $signature) {
            if ($signature->local_path && is_file($signature->local_path)) {
                $signature->update([
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<int, array{id: ?int, category: ?int, name: string, answer: ?string, signature_id: ?int, staged_signature_id: ?int}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach (array_values($items) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $answer = $row['answer'] ?? null;
            $answer = is_string($answer) ? trim($answer) : $answer;

            $normalized[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'category' => isset($row['category']) ? (int) $row['category'] : null,
                'name' => trim((string) ($row['name'] ?? '')),
                'answer' => $answer === '' || $answer === null ? null : (string) $answer,
                'signature_id' => isset($row['signature_id']) ? (int) $row['signature_id'] : null,
                'staged_signature_id' => isset($row['staged_signature_id']) ? (int) $row['staged_signature_id'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{id: ?int, category: ?int, name: string, answer: ?string, signature_id: ?int, staged_signature_id: ?int}>  $items
     */
    private function assertItems(array $items): void
    {
        $errors = [];
        $names = [];

        if ($items === []) {
            $errors['items'] = ['Add at least one Giveaway item.'];
        }

        foreach ($items as $index => $row) {
            $name = $row['name'];

            if ($name === '') {
                $errors["items.$index.name"] = ['This Giveaway item requires a name.'];
            } elseif (in_array(mb_strtolower($name), $names, true)) {
                $errors["items.$index.name"] = ['Item names must be unique within a Giveaway.'];
            } else {
                $names[] = mb_strtolower($name);
            }

            if ($row['category'] === null || ! in_array($row['category'], GiveawayCategory::values(), true)) {
                $errors["items.$index.category"] = ['Choose a valid Giveaway category.'];
            }

            if ($row['signature_id'] === null && $row['staged_signature_id'] === null) {
                $errors["items.$index.signature_id"] = ['This Giveaway item requires a signature.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
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

    private function deleteSignatureFile(GiveawaySignature $signature): void
    {
        if ($signature->local_path && is_file($signature->local_path)) {
            @unlink($signature->local_path);
        }

        $extension = strtolower(pathinfo((string) $signature->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $signature->local_uuid ?: (string) $signature->id;
        $previewPath = public_path("giveaway_signatures/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Giveaway $giveaway, User $viewer): array
    {
        $answers = $giveaway->answers->keyBy('item_id');
        $firstAnswer = $answers->first();

        return [
            'id' => $giveaway->id,
            'server_id' => $giveaway->server_id,
            'local_uuid' => $giveaway->local_uuid,
            'name' => $giveaway->name,
            'sales_call' => $this->salesCallLabel($giveaway->salescall),
            'salescall_uuid' => $giveaway->salescall?->local_uuid,
            'rsm_status' => $firstAnswer?->rsm_status?->value ?? RSMStatus::PENDING->value,
            'has_submission' => $answers->isNotEmpty(),
            'is_editable' => $giveaway->isEditableBy($viewer->id),
            'sync_status' => $giveaway->sync_status,
            'items' => $giveaway->items
                ->sortBy('id')
                ->values()
                ->map(function (GiveawayItem $item) use ($answers): array {
                    $signature = $answers->get($item->id)?->signature;

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category' => $item->category->value,
                        'category_label' => $item->category->meta('title'),
                        'answer' => $item->answerItem?->answer,
                        'signature' => $signature instanceof GiveawaySignature ? [
                            'id' => $signature->id,
                            'original_name' => $signature->original_name,
                            'url' => $this->signatureUrl($signature),
                        ] : null,
                    ];
                })
                ->all(),
            'created_at' => $giveaway->created_at?->toISOString(),
        ];
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }

    /**
     * Local user-input rules. The Portal push request remains the final
     * server-side validator.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.category' => ['required', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.answer' => ['nullable', 'string'],
            'items.*.signature_id' => ['nullable', 'integer'],
            'items.*.staged_signature_id' => ['nullable', 'integer'],
        ];
    }
}

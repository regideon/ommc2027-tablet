<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\RSMStatus;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveAwayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawayPhoto;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Forms\Giveaway\GiveawaySubmissionItem;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use App\Support\Forms\GiveawayRules;
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
 * Giveaway submissions are built from Portal-managed item masterdata. A
 * submission carries one recipient signature and one optional photo.
 */
class GiveawayService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{master_items: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $masterPullCache = null;

    /** @var array{giveaways: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, GiveawayItem>
     */
    public function programs(): Collection
    {
        $result = $this->masterPull();
        $this->programReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheMasterItems($result['master_items']);
        }

        return GiveawayItem::query()
            ->orderBy('category')
            ->orderBy('order')
            ->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function submissions(): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $masterResult = $this->masterPull();

        if (! $masterResult['failed']) {
            $this->cacheMasterItems($masterResult['master_items']);
        }

        $result = $this->pull();
        $this->globalReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheDefinitions($result['giveaways']);
            $this->cacheAnswers($result['giveaways'], $viewer);
            $this->cacheAttachments($result['giveaways']);
            $this->reconcileDeleted($result['giveaways']);
        }

        $local = Giveaway::query()
            ->with([
                'items.item',
                'items.answerItem',
                'answers' => fn ($query) => $query->where('submitted_by', $viewer->id),
                'signature',
                'photo',
                'salescall.customer',
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
        $validated = GiveawayRules::validate($input);
        $items = $this->normalizeItems($validated['items'] ?? []);
        $this->assertItems($items);
        $this->assertSignature(null, $validated);
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

                foreach ($items as $row) {
                    $item = GiveawaySubmissionItem::create([
                        'giveaway_id' => $giveaway->id,
                        'giveaway_item_id' => $row['giveaway_item_id'],
                    ]);

                    $this->persistAnswerForItem($giveaway, $user, $item, $row);
                }

                $this->replaceSignature($giveaway, $user, $validated, $storedPaths);
                $this->replacePhoto($giveaway, $user, $validated, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $giveaway->fresh(['items.item', 'answers.answerItem', 'signature', 'photo']);
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

        if (! $giveaway->is_editable) {
            throw ValidationException::withMessages([
                'giveaway' => ['Only Giveaways that have not synced can be edited.'],
            ]);
        }

        $validated = GiveawayRules::validate($input);
        $items = $this->normalizeItems($validated['items'] ?? []);
        $this->assertItems($items);
        $this->assertSignature($giveaway, $validated);
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

                foreach ($items as $row) {
                    $item = GiveawaySubmissionItem::query()
                        ->where('giveaway_id', $giveaway->id)
                        ->where('giveaway_item_id', $row['giveaway_item_id'])
                        ->first();

                    if (! $item instanceof GiveawaySubmissionItem) {
                        $item = GiveawaySubmissionItem::create([
                            'giveaway_id' => $giveaway->id,
                            'giveaway_item_id' => $row['giveaway_item_id'],
                        ]);
                    }

                    $keptItemIds[] = $item->id;
                    $this->persistAnswerForItem($giveaway, $user, $item, $row);
                }

                GiveawaySubmissionItem::query()
                    ->where('giveaway_id', $giveaway->id)
                    ->whereNotIn('id', $keptItemIds)
                    ->get()
                    ->each(fn (GiveawaySubmissionItem $item) => $item->delete());

                $this->replaceSignature($giveaway, $user, $validated, $storedPaths);
                $this->replacePhoto($giveaway, $user, $validated, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $giveaway->fresh(['items.item', 'answers.answerItem', 'signature', 'photo']);
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
        return $this->localFileUrl($signature->local_path, $signature->local_uuid, (string) $signature->id, 'giveaway_signatures');
    }

    public function photoUrl(GiveawayPhoto $photo): ?string
    {
        return $this->localFileUrl($photo->local_path, $photo->local_uuid, (string) $photo->id, 'giveaway_photos');
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
     * @return array{master_items: array<int, array<string, mixed>>, failed: bool}
     */
    private function masterPull(): array
    {
        return $this->masterPullCache ??= $this->syncService->readGiveawayMasterDataWithStatus();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheMasterItems(array $rows): void
    {
        foreach ($rows as $row) {
            if (! isset($row['id'], $row['name'], $row['category'])) {
                continue;
            }

            GiveawayItem::updateOrCreate(
                ['server_id' => (int) $row['id']],
                [
                    'category' => (int) $row['category'],
                    'name' => (string) $row['name'],
                    'order' => (int) ($row['order'] ?? 0),
                ],
            );
        }
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
            $giveaway = $clientUuid !== null
                ? Giveaway::query()->where('local_uuid', $clientUuid)->first()
                : null;
            $giveaway ??= Giveaway::query()->firstOrNew(['server_id' => $row['id']]);

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
                if (! is_array($itemRow) || ! isset($itemRow['id'], $itemRow['giveaway_item_id'])) {
                    continue;
                }

                $master = GiveawayItem::query()->where('server_id', (int) $itemRow['giveaway_item_id'])->first();

                if (! $master instanceof GiveawayItem) {
                    continue;
                }

                GiveawaySubmissionItem::updateOrCreate(
                    [
                        'giveaway_id' => $giveaway->id,
                        'giveaway_item_id' => $master->id,
                    ],
                    ['server_id' => (int) $itemRow['id']],
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheAnswers(array $rows, User $viewer): void
    {
        $serverUserId = (int) ($viewer->server_id ?? 0);

        if ($serverUserId <= 0) {
            return;
        }

        foreach ($rows as $row) {
            $giveaway = Giveaway::query()->where('server_id', $row['id'] ?? null)->first();

            if (! $giveaway || $giveaway->sync_status !== 'synced') {
                continue;
            }

            foreach ($row['answers'] ?? [] as $answerRow) {
                if (! is_array($answerRow) || (int) ($answerRow['submitted_by'] ?? 0) !== $serverUserId) {
                    continue;
                }

                $item = GiveawaySubmissionItem::query()
                    ->where('giveaway_id', $giveaway->id)
                    ->where('server_id', (int) ($answerRow['item_id'] ?? 0))
                    ->first();

                if (! $item instanceof GiveawaySubmissionItem) {
                    continue;
                }

                GiveAwayAnswer::updateOrCreate(
                    [
                        'giveaway_id' => $giveaway->id,
                        'item_id' => $item->id,
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
                        'item_id' => $item->id,
                    ],
                    ['answer' => is_array($answerItem) ? ($answerItem['answer'] ?? null) : null],
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheAttachments(array $rows): void
    {
        foreach ($rows as $row) {
            $giveaway = Giveaway::query()->where('server_id', $row['id'] ?? null)->first();

            if (! $giveaway || $giveaway->sync_status !== 'synced') {
                continue;
            }

            if (is_array($row['signature'] ?? null)) {
                $signature = GiveawaySignature::firstOrNew(['giveaway_id' => $giveaway->id]);
                $signature->local_uuid ??= (string) Str::uuid();
                $signature->fill([
                    'server_id' => $row['signature']['id'] ?? null,
                    'sync_status' => 'synced',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                    'synced_at' => now(),
                ])->save();
            }

            if (is_array($row['photo'] ?? null)) {
                $photo = GiveawayPhoto::firstOrNew(['giveaway_id' => $giveaway->id]);
                $photo->local_uuid ??= (string) Str::uuid();
                $photo->fill([
                    'server_id' => $row['photo']['id'] ?? null,
                    'sync_status' => 'synced',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                    'synced_at' => now(),
                ])->save();
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
                if ($giveaway->signature instanceof GiveawaySignature) {
                    $this->deleteSignatureFile($giveaway->signature);
                }

                if ($giveaway->photo instanceof GiveawayPhoto) {
                    $this->deletePhotoFile($giveaway->photo);
                }

                $giveaway->delete();
            });
    }

    /**
     * @param  array{id: ?int, giveaway_item_id: int, answer: ?string}  $row
     */
    private function persistAnswerForItem(Giveaway $giveaway, User $user, GiveawaySubmissionItem $item, array $row): void
    {
        GiveAwayAnswer::updateOrCreate(
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
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $storedPaths
     */
    private function replaceSignature(Giveaway $giveaway, User $user, array $input, array &$storedPaths): void
    {
        $stagedId = isset($input['staged_signature_id']) ? (int) $input['staged_signature_id'] : null;

        if ($stagedId === null) {
            return;
        }

        $staged = app(StagedAttachmentService::class)->findForUser($user, $stagedId);

        if (! $staged instanceof StagedAttachment) {
            throw ValidationException::withMessages([
                'signature_id' => ['A staged signature is no longer available. Re-upload the signature and try again.'],
            ]);
        }

        if ($giveaway->signature instanceof GiveawaySignature) {
            $this->deleteSignatureFile($giveaway->signature);
            $giveaway->signature->delete();
        }

        $metadata = app(StagedAttachmentService::class)->consume($user, $staged, 'giveaway_signatures');
        $storedPaths[] = $metadata['local_path'];

        GiveawaySignature::create([
            'local_uuid' => (string) Str::uuid(),
            'giveaway_id' => $giveaway->id,
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
     * @param  array<string, mixed>  $input
     * @param  list<string>  $storedPaths
     */
    private function replacePhoto(Giveaway $giveaway, User $user, array $input, array &$storedPaths): void
    {
        $stagedId = isset($input['staged_photo_id']) ? (int) $input['staged_photo_id'] : null;
        $remove = (bool) ($input['remove_photo'] ?? false);

        if ($remove && $giveaway->photo instanceof GiveawayPhoto) {
            $this->deletePhotoFile($giveaway->photo);
            $giveaway->photo->delete();
        }

        if ($stagedId === null) {
            return;
        }

        $staged = app(StagedAttachmentService::class)->findForUser($user, $stagedId);

        if (! $staged instanceof StagedAttachment) {
            throw ValidationException::withMessages([
                'photo_id' => ['A staged photo is no longer available. Re-add the photo and try again.'],
            ]);
        }

        if ($giveaway->photo instanceof GiveawayPhoto) {
            $this->deletePhotoFile($giveaway->photo);
            $giveaway->photo->delete();
        }

        $metadata = app(StagedAttachmentService::class)->consume($user, $staged, 'giveaway_photos');
        $storedPaths[] = $metadata['local_path'];

        GiveawayPhoto::create([
            'local_uuid' => (string) Str::uuid(),
            'giveaway_id' => $giveaway->id,
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
     * @param  array<string, mixed>  $input
     */
    private function assertSignature(?Giveaway $giveaway, array $input): void
    {
        $hasExisting = $giveaway?->signature instanceof GiveawaySignature
            || ! empty($input['signature_id']);
        $hasStaged = ! empty($input['staged_signature_id']);

        if (! $hasExisting && ! $hasStaged) {
            throw ValidationException::withMessages([
                'signature_id' => ['The recipient signature is required.'],
            ]);
        }
    }

    /**
     * @param  array<int, array{id: ?int, giveaway_item_id: int, answer: ?string}>  $items
     */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one Giveaway item.'],
            ]);
        }

        $validIds = GiveawayItem::query()->pluck('id')->all();

        foreach ($items as $index => $row) {
            if (! in_array($row['giveaway_item_id'], $validIds, true)) {
                throw ValidationException::withMessages([
                    "items.$index.giveaway_item_id" => ['The selected Giveaway item is not available locally.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<int, array{id: ?int, giveaway_item_id: int, answer: ?string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $row) {
            if (! is_array($row) || ! isset($row['giveaway_item_id'])) {
                continue;
            }

            $answer = $row['answer'] ?? null;
            $answer = is_string($answer) ? trim($answer) : $answer;

            $normalized[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'giveaway_item_id' => (int) $row['giveaway_item_id'],
                'answer' => $answer === '' ? null : $answer,
            ];
        }

        return $normalized;
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

    private function localFileUrl(?string $localPath, ?string $localUuid, string $fallbackIdentity, string $directory): ?string
    {
        if (! $localPath || ! is_file($localPath)) {
            return null;
        }

        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $localUuid ?: $fallbackIdentity;
        $relativePath = "{$directory}/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($localPath, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    private function deleteSignatureFile(GiveawaySignature $signature): void
    {
        $this->deleteLocalFile($signature->local_path, $signature->local_uuid, (string) $signature->id, 'giveaway_signatures');
    }

    private function deletePhotoFile(GiveawayPhoto $photo): void
    {
        $this->deleteLocalFile($photo->local_path, $photo->local_uuid, (string) $photo->id, 'giveaway_photos');
    }

    private function deleteLocalFile(?string $localPath, ?string $localUuid, string $fallbackIdentity, string $directory): void
    {
        if ($localPath && is_file($localPath)) {
            @unlink($localPath);
        }

        $extension = strtolower(pathinfo((string) $localPath, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $localUuid ?: $fallbackIdentity;
        $previewPath = public_path("{$directory}/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Giveaway $giveaway, User $viewer): array
    {
        $answers = $giveaway->answers->where('submitted_by', $viewer->id)->keyBy('item_id');
        $firstAnswer = $answers->first();

        return [
            'id' => $giveaway->id,
            'server_id' => $giveaway->server_id,
            'local_uuid' => $giveaway->local_uuid,
            'name' => $giveaway->name,
            'sales_call' => $this->salesCallLabel($giveaway->salescall),
            'salescall_uuid' => $giveaway->salescall?->local_uuid,
            'rsm_status' => $firstAnswer?->rsm_status?->value ?? RSMStatus::PENDING->value,
            'is_editable' => $giveaway->is_editable,
            'sync_status' => $giveaway->sync_status,
            'items' => $giveaway->items
                ->sortBy('id')
                ->values()
                ->map(fn (GiveawaySubmissionItem $item): array => [
                    'id' => $item->id,
                    'giveaway_item_id' => $item->giveaway_item_id,
                    'category' => $item->item?->category?->value,
                    'category_label' => $item->item?->category?->meta('title'),
                    'name' => $item->item?->name,
                    'answer' => $item->answerItem?->answer,
                ])
                ->all(),
            'signature' => $giveaway->signature === null
                ? null
                : [
                    'id' => $giveaway->signature->id,
                    'server_id' => $giveaway->signature->server_id,
                    'original_name' => $giveaway->signature->original_name,
                    'mime_type' => $giveaway->signature->mime_type,
                    'url' => $this->signatureUrl($giveaway->signature),
                ],
            'photo' => $giveaway->photo === null
                ? null
                : [
                    'id' => $giveaway->photo->id,
                    'server_id' => $giveaway->photo->server_id,
                    'original_name' => $giveaway->photo->original_name,
                    'mime_type' => $giveaway->photo->mime_type,
                    'url' => $this->photoUrl($giveaway->photo),
                ],
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
}

<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageAttachment;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use App\Support\Forms\SignageRules;
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
 * The CRR Signage form's single service: reads the current user's submissions
 * (local rows cached from a live, scoped Portal pull) and creates/edits them
 * offline-first. Products and types are pull-only masters; attachments are
 * typed (request, completed) and at least one request is required.
 */
class SignageService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{signages: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Refresh both master picklists (products and types) from the Portal; the
     * contract returns the product cache, and the form page reads both.
     *
     * @return Collection<int, SignageProduct>
     */
    public function programs(): Collection
    {
        $products = $this->syncService->readSignageProductsWithStatus();
        $this->programReadFailed = $products['failed'];

        if (! $products['failed']) {
            $this->reconcileLookup(
                SignageProduct::class,
                $products['products'],
                fn (array $product): array => [
                    'server_id' => $product['id'],
                    'name' => $product['name'],
                ],
                'signages',
            );
        }

        $types = $this->syncService->readSignageTypesWithStatus();
        $this->programReadFailed = $this->programReadFailed || $types['failed'];

        if (! $types['failed']) {
            $this->reconcileLookup(
                SignageType::class,
                $types['types'],
                fn (array $type): array => [
                    'server_id' => $type['id'],
                    'name' => $type['name'],
                ],
                'signages',
            );
        }

        return SignageProduct::query()->orderBy('name')->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * The current user's CRR Signage submissions, newest first.
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
            $this->cacheRows($result['signages']);
            $this->reconcileDeleted($result['signages']);
        }

        $local = Signage::query()
            ->with(['attachments', 'product', 'type', 'salescall.customer'])
            ->where('submitted_by', $viewer->id)
            ->get();

        return $local
            ->map(fn (Signage $signage): array => $this->mapLocal($signage))
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
    public function create(User $user, array $input): Signage
    {
        $validated = SignageRules::validate($input);

        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);

        $this->assertRequestAttachmentForCreate($staged);

        $signage = null;
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($user, $validated, $staged, &$signage, &$storedPaths): void {
                $signage = Signage::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'product_id' => $validated['product_id'],
                    'type_id' => $validated['type_id'],
                    'category' => $validated['category'],
                    'name' => $validated['name'],
                    'rsm_status' => RSMStatus::PENDING->value,
                    'tdh_status' => TDHStatus::PENDING->value,
                    'tds_status' => TDSStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistStagedAttachments($signage, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $signage->fresh(['attachments']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Signage
    {
        if (! $submission instanceof Signage) {
            throw new InvalidArgumentException('Expected a CRR Signage submission.');
        }

        $signage = $submission;

        if (! $signage->is_editable) {
            throw ValidationException::withMessages([
                'signage' => ['Only CRR Signages that have not synced or have been returned to the DRM can be edited.'],
            ]);
        }

        $validated = SignageRules::validate($input);

        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);
        $removed = $this->normalizeIds($validated['remove_attachments'] ?? []);

        $this->assertRequestAttachmentForUpdate($signage, $staged, $removed);

        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($signage, $validated, $staged, $removed, &$storedPaths): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $signage->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'product_id' => $validated['product_id'],
                    'type_id' => $validated['type_id'],
                    'category' => $validated['category'],
                    'name' => $validated['name'],
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->removeAttachments($signage, $removed);
                $this->persistStagedAttachments($signage, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $signage->fresh(['attachments']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Signage
    {
        $signage = isset($row['server_id'])
            ? Signage::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($signage) {
            return $signage;
        }

        throw ValidationException::withMessages([
            'signage' => ['This CRR Signage is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    public function attachmentUrl(SignageAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "signage_attachments/{$identity}.{$extension}";
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

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{signages: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readSignagesWithStatus();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheRows(array $rows): void
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return;
        }

        foreach ($rows as $row) {
            if (! isset($row['id'], $row['name'])) {
                continue;
            }

            $productId = isset($row['product_id'])
                ? SignageProduct::query()->where('server_id', (int) $row['product_id'])->value('id')
                : null;

            $typeId = isset($row['type_id'])
                ? SignageType::query()->where('server_id', (int) $row['type_id'])->value('id')
                : null;

            // Wait for the master picklists to be cached before mirroring rows.
            if (! $productId || ! $typeId) {
                continue;
            }

            $clientUuid = is_string($row['client_uuid'] ?? null) && $row['client_uuid'] !== '' ? $row['client_uuid'] : null;
            $signage = $clientUuid !== null
                ? Signage::query()->where('local_uuid', $clientUuid)->first()
                : null;
            $signage ??= Signage::query()->firstOrNew(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($signage->exists && $signage->sync_status !== 'synced') {
                continue;
            }

            if (! $signage->exists) {
                $signage->local_uuid = (string) Str::uuid();
                $signage->submitted_by = $viewer->id;
                $signage->sync_status = 'synced';
                $signage->sync_attempts = 0;
            }

            $salescallId = isset($row['salescall_id']) && $row['salescall_id'] !== null
                ? Salescall::query()->where('server_id', (int) $row['salescall_id'])->value('id')
                : null;

            $signage->fill([
                'salescall_id' => $salescallId,
                'product_id' => $productId,
                'type_id' => $typeId,
                'category' => (int) ($row['category'] ?? SignageCategory::NEW->value),
                'name' => $row['name'],
                'rsm_status' => (int) ($row['rsm_status'] ?? RSMStatus::PENDING->value),
                'tdh_status' => (int) ($row['tdh_status'] ?? TDHStatus::PENDING->value),
                'tds_status' => (int) ($row['tds_status'] ?? TDSStatus::PENDING->value),
                'synced_at' => now(),
            ]);

            $signage->save();
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

        Signage::query()
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (Signage $signage): void {
                foreach ($signage->attachments ?? collect() as $attachment) {
                    $this->deleteAttachmentFile($attachment);
                }

                $signage->delete();
            });
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function assertRequestAttachmentForCreate(array $staged): void
    {
        if (! $this->hasStagedType($staged, SignageAttachmentType::REQUEST)) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  array<int, int>  $removed
     */
    private function assertRequestAttachmentForUpdate(Signage $signage, array $staged, array $removed): void
    {
        $remaining = SignageAttachment::query()
            ->where('signage_id', $signage->id)
            ->where('type', SignageAttachmentType::REQUEST->value)
            ->whereNotIn('id', $removed)
            ->count();

        if ($remaining + ($this->hasStagedType($staged, SignageAttachmentType::REQUEST) ? 1 : 0) === 0) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function hasStagedType(array $staged, SignageAttachmentType $type): bool
    {
        return collect($staged)->contains(
            fn (array $row): bool => $row['type'] === $type->value
        );
    }

    private function missingRequestAttachment(): ValidationException
    {
        return ValidationException::withMessages([
            'attachments' => ['At least one Request attachment is required.'],
        ]);
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  list<string>  $storedPaths
     */
    private function persistStagedAttachments(Signage $signage, User $user, array $staged, array &$storedPaths): void
    {
        foreach ($staged as $index => $row) {
            $stagedService = app(StagedAttachmentService::class);
            $stagedModel = $stagedService->findForUser($user, $row['staged_id']);

            if (! $stagedModel instanceof StagedAttachment) {
                throw ValidationException::withMessages([
                    "staged_attachments.$index.staged_id" => ['A staged attachment is no longer available. Re-add the file and try again.'],
                ]);
            }

            $metadata = $stagedService->consume($user, $stagedModel, 'signage_attachments');
            $storedPaths[] = $metadata['local_path'];

            SignageAttachment::create([
                'local_uuid' => (string) Str::uuid(),
                'signage_id' => $signage->id,
                'type' => $row['type'],
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

    /**
     * @param  array<int, int>  $ids
     */
    private function removeAttachments(Signage $signage, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        SignageAttachment::query()
            ->where('signage_id', $signage->id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (SignageAttachment $attachment): void {
                $this->deleteAttachmentFile($attachment);
                $attachment->delete();
            });
    }

    /**
     * @param  array<int|string, mixed>  $staged
     * @return array<int, array{type: int, staged_id: int}>
     */
    private function normalizeStagedAttachments(array $staged): array
    {
        $normalized = [];

        foreach (array_values($staged) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = isset($row['type']) ? (int) $row['type'] : 0;
            $stagedId = isset($row['staged_id']) ? (int) $row['staged_id'] : 0;

            if ($stagedId <= 0 || ! in_array($type, SignageAttachmentType::values(), true)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'staged_id' => $stagedId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));
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

    private function deleteAttachmentFile(SignageAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("signage_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Signage $signage): array
    {
        return [
            'id' => $signage->id,
            'server_id' => $signage->server_id,
            'local_uuid' => $signage->local_uuid,
            'name' => $signage->name,
            'category' => $signage->category->value,
            'category_label' => $signage->category->meta('title'),
            'product' => $signage->product?->name ?? '—',
            'type' => $signage->type?->name ?? '—',
            'sales_call' => $this->salesCallLabel($signage->salescall),
            'salescall_uuid' => $signage->salescall?->local_uuid,
            'rsm_status' => $signage->rsm_status->value,
            'tdh_status' => $signage->tdh_status->value,
            'tds_status' => $signage->tds_status->value,
            'is_editable' => $signage->is_editable,
            'sync_status' => $signage->sync_status,
            'attachments' => $signage->attachments
                ->sortBy('id')
                ->values()
                ->map(fn (SignageAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'type' => $attachment->type->value,
                    'type_label' => $attachment->type->meta('title'),
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $this->attachmentUrl($attachment),
                ])
                ->all(),
            'created_at' => $signage->created_at?->toISOString(),
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

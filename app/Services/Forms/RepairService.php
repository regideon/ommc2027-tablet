<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\Repair\RepairAttachmentType;
use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\Repair\Repair;
use App\Models\Forms\Repair\RepairAttachment;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use App\Support\Forms\RepairRules;
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
 * The Repair form's single service: reads the current user's submissions
 * (local rows cached from a live, scoped Portal pull) and creates/edits them
 * offline-first. Attachments are typed (invoice, CM preparations, RAC
 * approval, request) and at least one is required.
 */
class RepairService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{repairs: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Repair has no master data; the contract method returns an empty set.
     *
     * @return Collection<int, Repair>
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
     * The current user's Repair submissions, newest first.
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
            $this->cacheRows($result['repairs']);
            $this->reconcileDeleted($result['repairs']);
        }

        $local = Repair::query()
            ->with(['attachments', 'salescall.customer'])
            ->where('submitted_by', $viewer->id)
            ->get();

        return $local
            ->map(fn (Repair $repair): array => $this->mapLocal($repair))
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
    public function create(User $user, array $input): Repair
    {
        $validated = RepairRules::validate($input);

        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);

        if ($staged === []) {
            throw ValidationException::withMessages([
                'attachments' => ['At least one attachment is required.'],
            ]);
        }

        $repair = null;
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($user, $validated, $staged, &$repair, &$storedPaths): void {
                $repair = Repair::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'plate_number' => $validated['plate_number'],
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'details' => $validated['details'],
                    'amount' => $validated['amount'],
                    'remarks' => $validated['remarks'] ?? null,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'sps_status' => SPSStatus::PENDING->value,
                    'spm_status' => SPMStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistStagedAttachments($repair, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $repair->fresh(['attachments']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Repair
    {
        if (! $submission instanceof Repair) {
            throw new InvalidArgumentException('Expected a Repair submission.');
        }

        $repair = $submission;

        if (! $repair->isEditable()) {
            throw ValidationException::withMessages([
                'repair' => ['Only Repairs that are still pending can be edited.'],
            ]);
        }

        $validated = RepairRules::validate($input);

        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);
        $removed = $this->normalizeIds($validated['remove_attachments'] ?? []);

        $remaining = RepairAttachment::query()
            ->where('repair_id', $repair->id)
            ->whereNotIn('id', $removed)
            ->count();

        if ($remaining + count($staged) === 0) {
            throw ValidationException::withMessages([
                'attachments' => ['At least one attachment is required.'],
            ]);
        }

        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($repair, $validated, $staged, $removed, &$storedPaths): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $repair->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'plate_number' => $validated['plate_number'],
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'details' => $validated['details'],
                    'amount' => $validated['amount'],
                    'remarks' => $validated['remarks'] ?? null,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->removeAttachments($repair, $removed);
                $this->persistStagedAttachments($repair, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $repair->fresh(['attachments']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Repair
    {
        $repair = isset($row['server_id'])
            ? Repair::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($repair) {
            return $repair;
        }

        throw ValidationException::withMessages([
            'repair' => ['This Repair is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    public function attachmentUrl(RepairAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "repair_attachments/{$identity}.{$extension}";
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
     * @return array{repairs: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readRepairsWithStatus();
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
            if (! isset($row['id'], $row['plate_number'])) {
                continue;
            }

            $clientUuid = is_string($row['client_uuid'] ?? null) && $row['client_uuid'] !== '' ? $row['client_uuid'] : null;
            $repair = $clientUuid !== null
                ? Repair::query()->where('local_uuid', $clientUuid)->first()
                : null;
            $repair ??= Repair::query()->firstOrNew(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($repair->exists && $repair->sync_status !== 'synced') {
                continue;
            }

            if (! $repair->exists) {
                $repair->local_uuid = (string) Str::uuid();
                $repair->submitted_by = $viewer->id;
                $repair->sync_status = 'synced';
                $repair->sync_attempts = 0;
            }

            $salescallId = isset($row['salescall_id']) && $row['salescall_id'] !== null
                ? Salescall::query()->where('server_id', (int) $row['salescall_id'])->value('id')
                : null;

            $repair->fill([
                'salescall_id' => $salescallId,
                'plate_number' => $row['plate_number'],
                'invoice_number' => $row['invoice_number'] ?? null,
                'details' => $row['details'] ?? '',
                'amount' => $row['amount'] ?? 0,
                'remarks' => $row['remarks'] ?? null,
                'sap_doc_number' => $row['sap_doc_number'] ?? null,
                'rsm_status' => (int) ($row['rsm_status'] ?? RSMStatus::PENDING->value),
                'sps_status' => (int) ($row['sps_status'] ?? SPSStatus::PENDING->value),
                'spm_status' => (int) ($row['spm_status'] ?? SPMStatus::PENDING->value),
                'synced_at' => now(),
            ]);

            $repair->save();
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

        Repair::query()
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (Repair $repair): void {
                foreach ($repair->attachments ?? collect() as $attachment) {
                    $this->deleteAttachmentFile($attachment);
                }

                $repair->delete();
            });
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  list<string>  $storedPaths
     */
    private function persistStagedAttachments(Repair $repair, User $user, array $staged, array &$storedPaths): void
    {
        foreach ($staged as $index => $row) {
            $stagedService = app(StagedAttachmentService::class);
            $stagedModel = $stagedService->findForUser($user, $row['staged_id']);

            if (! $stagedModel instanceof StagedAttachment) {
                throw ValidationException::withMessages([
                    "staged_attachments.$index.staged_id" => ['A staged attachment is no longer available. Re-add the file and try again.'],
                ]);
            }

            $metadata = $stagedService->consume($user, $stagedModel, 'repair_attachments');
            $storedPaths[] = $metadata['local_path'];

            RepairAttachment::create([
                'local_uuid' => (string) Str::uuid(),
                'repair_id' => $repair->id,
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
    private function removeAttachments(Repair $repair, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        RepairAttachment::query()
            ->where('repair_id', $repair->id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (RepairAttachment $attachment): void {
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

            if ($stagedId <= 0 || ! in_array($type, RepairAttachmentType::values(), true)) {
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

    private function deleteAttachmentFile(RepairAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("repair_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Repair $repair): array
    {
        return [
            'id' => $repair->id,
            'server_id' => $repair->server_id,
            'local_uuid' => $repair->local_uuid,
            'plate_number' => $repair->plate_number,
            'invoice_number' => $repair->invoice_number,
            'details' => $repair->details,
            'amount' => (string) $repair->amount,
            'remarks' => $repair->remarks,
            'sap_doc_number' => $repair->sap_doc_number,
            'sales_call' => $this->salesCallLabel($repair->salescall),
            'salescall_uuid' => $repair->salescall?->local_uuid,
            'rsm_status' => $repair->rsm_status->value,
            'sps_status' => $repair->sps_status->value,
            'spm_status' => $repair->spm_status->value,
            'is_editable' => $repair->isEditable(),
            'sync_status' => $repair->sync_status,
            'attachments' => $repair->attachments
                ->sortBy('id')
                ->values()
                ->map(fn (RepairAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'type' => $attachment->type->value,
                    'type_label' => $attachment->type->meta('title'),
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $this->attachmentUrl($attachment),
                ])
                ->all(),
            'created_at' => $repair->created_at?->toISOString(),
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

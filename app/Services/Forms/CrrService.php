<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrAttachment;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Salescall;
use App\Models\User;
use App\Services\SyncService;
use App\Support\Forms\CrrRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The CRR form's single service: reads the current user's submissions (local
 * rows merged with a live Portal pull) and creates/edits responses locally,
 * offline-first. User input is validated; pulled server data is not.
 */
class CrrService extends AbstractFormService implements FormSubmissionService
{
    private const GLOBAL_LIST_LIMIT = 200;

    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array<int, int> */
    private array $currentProgramIds = [];

    public function __construct(
        private readonly SyncService $syncService,
        private readonly MarketingService $marketingService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Refresh the CRR program picklist from the Portal and return the cache.
     *
     * @return Collection<int, CrrProgram>
     */
    public function programs(): Collection
    {
        $this->currentProgramIds = [];

        $result = $this->syncService->readCrrProgramsWithStatus();
        $this->programReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->currentProgramIds = $this->reconcileLookup(
                CrrProgram::class,
                $result['programs'],
                fn (array $program): array => [
                    'server_id' => $program['id'],
                    'name' => $program['name'],
                    'is_marketing_required' => (bool) ($program['is_marketing_required'] ?? true),
                ],
                'crrs',
            );
        }

        $query = CrrProgram::query();

        if ($this->currentProgramIds !== []) {
            $query->whereIn('id', $this->currentProgramIds);
        }

        return $query->orderBy('name')->get();
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

        $this->globalReadFailed = false;

        try {
            $local = Crr::query()
                ->with(['program', 'salescall.customer', 'attachments'])
                ->where('submitted_by', $viewer->id)
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->globalReadFailed = true;
            $local = collect();
        }

        $server = $this->syncService->readCrrsWithStatus();
        $this->globalReadFailed = $this->globalReadFailed || $server['failed'];

        if (! $server['failed']) {
            $this->persistApprovalStatuses($server['crrs']);
            $local = $this->reconcileDeletedFromServer($local, $server['crrs']);
        }

        $programsByServerId = CrrProgram::query()
            ->whereNotNull('server_id')
            ->get()
            ->keyBy('server_id');

        return $this->merge($local, $server['crrs'], $programsByServerId)
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->take(self::GLOBAL_LIST_LIMIT)
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
    public function create(User $user, array $input): Crr
    {
        $validated = CrrRules::validate($input);
        $salescallId = $this->resolveSalescall($validated['salescall_uuid'] ?? null);
        $program = CrrProgram::query()->find($validated['crr_program_id']);

        if ($program instanceof CrrProgram) {
            $this->ensureMarketingRequirement($program, $salescallId, $user);
        }

        $attachments = $this->normalizeAttachments($this->attachmentPayload($input));
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($user, $validated, $salescallId, $attachments, &$storedPaths): Crr {
                $crr = Crr::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $salescallId,
                    'crr_program_id' => $validated['crr_program_id'],
                    'date_implemented' => $validated['date_implemented'] ?? null,
                    'amount' => $validated['amount'],
                    'details' => $validated['details'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'sps_status' => SPSStatus::PENDING->value,
                    'spm_status' => SPMStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAttachments($crr, $attachments, $storedPaths);

                return $crr->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Crr
    {
        if (! $submission instanceof Crr) {
            throw new InvalidArgumentException('Expected a CRR submission.');
        }

        $crr = $submission;

        if (! $crr->is_editable) {
            throw ValidationException::withMessages([
                'crr' => ['Only CRRs that have not synced or have been returned to the DRM can be edited.'],
            ]);
        }

        $validated = CrrRules::validate($input);
        $salescallId = $this->resolveSalescall($validated['salescall_uuid'] ?? null);
        $program = CrrProgram::query()->find($validated['crr_program_id']);

        if ($program instanceof CrrProgram && $crr->submittedBy instanceof User) {
            $this->ensureMarketingRequirement($program, $salescallId, $crr->submittedBy);
        }

        $attachments = $this->normalizeAttachments($this->attachmentPayload($input));
        $storedPaths = [];

        try {
            DB::transaction(function () use ($crr, $validated, $salescallId, $attachments, &$storedPaths): void {
                $crr->update([
                    'salescall_id' => $salescallId,
                    'crr_program_id' => $validated['crr_program_id'],
                    'date_implemented' => $validated['date_implemented'] ?? null,
                    'amount' => $validated['amount'],
                    'details' => $validated['details'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAttachments($crr, $attachments, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $crr->fresh('attachments');
    }

    /**
     * Materialize a pulled server CRR locally so it can be edited and pushed
     * back with its existing server id.
     *
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Crr
    {
        $program = CrrProgram::query()
            ->where('server_id', $row['crr_program_id'] ?? null)
            ->first();

        if (! $program) {
            throw ValidationException::withMessages([
                'crr' => ['The CRR program is not available locally yet. Refresh and try again.'],
            ]);
        }

        return Crr::create([
            'local_uuid' => (string) Str::uuid(),
            'server_id' => $row['server_id'] ?? null,
            'submitted_by' => $user->id,
            'salescall_id' => null,
            'crr_program_id' => $program->id,
            'date_implemented' => $row['date_implemented'] ?? null,
            'amount' => $row['amount'] ?? 0,
            'details' => $row['details'] ?? null,
            'remarks' => $row['remarks'] ?? null,
            'rsm_status' => $row['rsm_status'] ?? RSMStatus::PENDING->value,
            'sps_status' => $row['sps_status'] ?? SPSStatus::PENDING->value,
            'spm_status' => $row['spm_status'] ?? SPMStatus::PENDING->value,
            'sync_status' => 'synced',
            'sync_attempts' => 0,
            'sync_error' => null,
            'synced_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, Crr>  $localCrrs
     * @param  array<int, array<string, mixed>>  $serverCrrs
     * @param  Collection<int, CrrProgram>  $programsByServerId
     * @return Collection<string, array<string, mixed>>
     */
    private function merge(Collection $localCrrs, array $serverCrrs, Collection $programsByServerId): Collection
    {
        $rows = $localCrrs->mapWithKeys(function (Crr $crr): array {
            $key = $crr->server_id ? 'server:'.$crr->server_id : 'local:'.$crr->local_uuid;

            return [$key => $this->mapLocal($crr)];
        });

        foreach ($serverCrrs as $serverCrr) {
            if (! isset($serverCrr['id'])) {
                continue;
            }

            $rows['server:'.$serverCrr['id']] = $this->mapServer($serverCrr, $programsByServerId);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Crr $crr): array
    {
        return [
            'id' => $crr->id,
            'server_id' => $crr->server_id,
            'local_uuid' => $crr->local_uuid,
            'program' => $crr->program?->name ?? '—',
            'date_implemented' => $crr->date_implemented,
            'amount' => (string) $crr->amount,
            'details' => $crr->details,
            'remarks' => $crr->remarks,
            'sap_doc_number' => $crr->sap_doc_number,
            'sales_call' => $this->salesCallLabel($crr->salescall),
            'salescall_uuid' => $crr->salescall?->local_uuid,
            'rsm_status' => $crr->rsm_status->value,
            'sps_status' => $crr->sps_status->value,
            'spm_status' => $crr->spm_status->value,
            'is_editable' => $crr->is_editable,
            'sync_status' => $crr->sync_status,
            'attachments' => $crr->attachments
                ->map(fn (CrrAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'server_id' => $attachment->server_id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $this->localAttachmentUrl($attachment),
                ])
                ->values()
                ->all(),
            'created_at' => $crr->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $serverCrr
     * @param  Collection<int, CrrProgram>  $programsByServerId
     * @return array<string, mixed>
     */
    private function mapServer(array $serverCrr, Collection $programsByServerId): array
    {
        $rsm = (int) ($serverCrr['rsm_status'] ?? 1);
        $sps = (int) ($serverCrr['sps_status'] ?? 1);
        $spm = (int) ($serverCrr['spm_status'] ?? 1);
        $programId = (int) ($serverCrr['crr_program_id'] ?? 0);

        return [
            'id' => null,
            'server_id' => $serverCrr['id'],
            'client_uuid' => $serverCrr['client_uuid'] ?? null,
            'local_uuid' => null,
            'program' => $programsByServerId->get($programId)?->name ?? '—',
            'date_implemented' => $serverCrr['date_implemented'] ?? null,
            'amount' => (string) ($serverCrr['amount'] ?? ''),
            'details' => $serverCrr['details'] ?? null,
            'remarks' => $serverCrr['remarks'] ?? null,
            'sap_doc_number' => $serverCrr['sap_doc_number'] ?? null,
            'sales_call' => '—',
            'salescall_uuid' => null,
            'rsm_status' => $rsm,
            'sps_status' => $sps,
            'spm_status' => $spm,
            'is_editable' => $sps === SPSStatus::RETURNED->value,
            'sync_status' => 'synced',
            'attachments' => collect($serverCrr['attachments'] ?? [])
                ->map(fn (array $attachment): array => [
                    'id' => null,
                    'server_id' => $attachment['id'] ?? null,
                    'original_name' => $attachment['original_name'] ?? null,
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'url' => $attachment['url'] ?? null,
                ])
                ->values()
                ->all(),
            'created_at' => $serverCrr['created_at'] ?? null,
        ];
    }

    /**
     * Persist Portal-owned approval fields on synced local rows without
     * touching local work that has not been pushed yet.
     *
     * @param  array<int, array<string, mixed>>  $serverCrrs
     */
    private function persistApprovalStatuses(array $serverCrrs): void
    {
        foreach ($serverCrrs as $row) {
            $serverId = $row['id'] ?? null;

            if (! is_numeric($serverId)) {
                continue;
            }

            $crr = Crr::query()
                ->where('server_id', (int) $serverId)
                ->first();

            if (! $crr instanceof Crr || $crr->sync_status !== 'synced') {
                continue;
            }

            $crr->update([
                'rsm_status' => (int) ($row['rsm_status'] ?? $crr->rsm_status->value),
                'sps_status' => (int) ($row['sps_status'] ?? $crr->sps_status->value),
                'spm_status' => (int) ($row['spm_status'] ?? $crr->spm_status->value),
            ]);
        }
    }

    /**
     * Drop local rows whose server counterpart no longer appears in a successful
     * pull (deleted on the Portal), including their attachment files.
     *
     * @param  Collection<int, Crr>  $localCrrs
     * @param  array<int, array<string, mixed>>  $serverCrrs
     * @return Collection<int, Crr>
     */
    private function reconcileDeletedFromServer(Collection $localCrrs, array $serverCrrs): Collection
    {
        $serverIds = collect($serverCrrs)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $localCrrs->reject(function (Crr $crr) use ($serverIds): bool {
            if (! $crr->server_id || in_array((int) $crr->server_id, $serverIds, true)) {
                return false;
            }

            $this->deleteLocalCrr($crr);

            return true;
        })->values();
    }

    private function deleteLocalCrr(Crr $crr): void
    {
        $crr->loadMissing('attachments');

        foreach ($crr->attachments as $attachment) {
            $this->deleteAttachmentFiles($attachment);
        }

        $crr->delete();
    }

    private function deleteAttachmentFiles(CrrAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("crr_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }

    private function ensureMarketingRequirement(CrrProgram $program, ?int $salescallId, User $user): void
    {
        if (! $program->is_marketing_required || $salescallId === null) {
            return;
        }

        $salescall = Salescall::query()->find($salescallId);

        if (! $salescall instanceof Salescall) {
            return;
        }

        $state = $this->marketingService->approvalStateForSalescall($user, $salescall);

        if ($state === 'approved') {
            return;
        }

        throw ValidationException::withMessages([
            'crr_program_id' => [match ($state) {
                'pending' => 'The Marketing Recommendation for this sales call must be approved before saving a CRR with this program.',
                'unknown' => 'Marketing approval could not be confirmed. Reconnect and try again.',
                default => 'Create a Marketing Recommendation for this sales call before saving a CRR with this program.',
            }],
        ]);
    }

    private function localAttachmentUrl(CrrAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "crr_attachments/{$identity}.{$extension}";
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, mixed>
     */
    private function attachmentPayload(array $input): array
    {
        return is_array($input['attachments'] ?? null) ? $input['attachments'] : [];
    }

    /**
     * @param  array<int, array{bytes: string, original_name: string, mime_type: string, extension: string, byte_size: int}>  $attachments
     * @param  list<string>  $storedPaths
     */
    private function persistAttachments(Crr $crr, array $attachments, array &$storedPaths): void
    {
        foreach ($attachments as $attachment) {
            $uuid = (string) Str::uuid();
            $relativePath = $this->storeLocalAttachment(
                'crr_attachments',
                $uuid,
                $attachment['extension'],
                $attachment['bytes'],
            );
            $storedPaths[] = Storage::disk('local')->path($relativePath);

            $crr->attachments()->create([
                'local_uuid' => $uuid,
                'local_path' => Storage::disk('local')->path($relativePath),
                'original_name' => $attachment['original_name'],
                'mime_type' => $attachment['mime_type'],
                'extension' => $attachment['extension'],
                'byte_size' => $attachment['byte_size'],
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);
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
}

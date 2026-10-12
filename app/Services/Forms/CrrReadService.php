<?php

namespace App\Services\Forms;

use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrAttachment;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Salescall;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Reads the current user's CRR submissions: local pending rows merged with a
 * live Portal pull. Mirrors ExpenseReadService.
 */
class CrrReadService
{
    private const GLOBAL_LIST_LIMIT = 200;

    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    public function __construct(private readonly SyncService $syncService) {}

    /**
     * Refresh the CRR program picklist from the Portal and return the cache.
     *
     * @return Collection<int, CrrProgram>
     */
    public function crrPrograms(): Collection
    {
        $result = $this->syncService->readCrrProgramsWithStatus();
        $this->programReadFailed = $result['failed'];

        if (! $result['failed']) {
            foreach ($result['programs'] as $program) {
                if (! isset($program['id'], $program['name'])) {
                    continue;
                }

                CrrProgram::updateOrCreate(
                    ['server_id' => $program['id']],
                    ['name' => $program['name']],
                );
            }
        }

        return CrrProgram::query()->orderBy('name')->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function crrForCurrentUser(): Collection
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
            'is_editable' => $crr->isEditable(),
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
            'is_editable' => $rsm === 1 && $sps === 1 && $spm === 1,
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

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
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
}

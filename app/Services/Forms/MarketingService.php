<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\Marketing\RealizationStage;
use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingMechanic;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\Marketing\MarketingRealization;
use App\Models\Forms\Marketing\MarketingVolume;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use App\Services\SyncService;
use App\Support\Forms\MarketingRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * The Marketing Recommendation form's single service: reads the current user's
 * submissions (local rows merged with a live Portal pull) and creates/edits
 * responses locally, offline-first. User input is validated; pulled server data
 * is not.
 */
class MarketingService extends AbstractFormService implements FormSubmissionService
{
    private const GLOBAL_LIST_LIMIT = 200;

    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, MarketingProgram>
     */
    public function programs(): Collection
    {
        $result = $this->syncService->readMarketingProgramsWithStatus();
        $this->programReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->reconcileLookup(
                MarketingProgram::class,
                $result['programs'],
                fn (array $program): array => [
                    'server_id' => $program['id'],
                    'name' => $program['name'],
                ],
                'marketings',
            );
        }

        return MarketingProgram::query()->orderBy('name')->get();
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
            $local = Marketing::query()
                ->with(['program', 'regionType', 'salescall.customer', 'volumes', 'realizations', 'mechanics'])
                ->where('submitted_by', $viewer->id)
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->globalReadFailed = true;
            $local = collect();
        }

        $server = $this->refreshApprovalStatuses();
        $this->globalReadFailed = $this->globalReadFailed || $server['failed'];

        if (! $server['failed']) {
            $local = $this->reconcileDeletedFromServer($local, $server['marketings']);
        }

        $programsByServerId = MarketingProgram::query()
            ->whereNotNull('server_id')
            ->get()
            ->keyBy('server_id');

        $regionTypeNames = RegionType::query()->pluck('name', 'id');

        return $this->merge($local, $server['marketings'], $programsByServerId, $regionTypeNames)
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
     * Pull the latest Marketing rows and persist their approval state on the
     * matching local rows. A failed pull leaves the last-known state intact.
     *
     * @return array{marketings: array<int, array<string, mixed>>, failed: bool}
     */
    public function refreshApprovalStatuses(): array
    {
        $result = $this->syncService->readMarketingsWithStatus();

        if (! $result['failed']) {
            $this->persistApprovalStatuses($result['marketings']);
        }

        return $result;
    }

    /**
     * Sales-call IDs with a locally persisted, RAC-approved Marketing
     * Recommendation from the rep.
     *
     * @return array<int, int>
     */
    public function locallyApprovedSalescallIdsFor(User $user): array
    {
        return Marketing::query()
            ->where('submitted_by', $user->id)
            ->whereNotNull('salescall_id')
            ->where('rac_status', RACStatus::APPROVED->value)
            ->distinct()
            ->pluck('salescall_id')
            ->map(fn (mixed $salescallId): int => (int) $salescallId)
            ->values()
            ->all();
    }

    /**
     * Resolve whether the rep's Marketing Recommendation for the sales call is
     * approved, pending, missing, or cannot be confirmed while offline.
     */
    public function approvalStateForSalescall(User $user, Salescall $salescall): string
    {
        $result = $this->refreshApprovalStatuses();

        $local = Marketing::query()
            ->where('salescall_id', $salescall->id)
            ->where('submitted_by', $user->id)
            ->get();

        if ($local->contains(fn (Marketing $marketing): bool => $marketing->isApproved())) {
            return 'approved';
        }

        if (! $result['failed']) {
            if ($this->serverHasMarketingFor($result['marketings'], $user, $salescall, approvedOnly: true)) {
                return 'approved';
            }

            if ($local->isNotEmpty()
                || $this->serverHasMarketingFor($result['marketings'], $user, $salescall, approvedOnly: false)) {
                return 'pending';
            }

            return 'none';
        }

        return $local->isNotEmpty() ? 'pending' : 'unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE / EDIT
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Marketing
    {
        $validated = MarketingRules::validate($input);

        $salescallId = $this->resolveSalescall($validated['salescall_uuid'] ?? null);

        return DB::transaction(function () use ($user, $validated, $salescallId): Marketing {
            $marketing = Marketing::create([
                'local_uuid' => (string) Str::uuid(),
                'submitted_at' => $validated['submitted_at'] ?? now(),
                'submitted_by' => $user->id,
                'region_type_id' => $validated['region_type_id'],
                'role_id' => $this->resolveRoleId($user),
                'program_id' => $validated['program_id'],
                'salescall_id' => $salescallId,
                'duration_start_at' => $validated['duration_start_at'],
                'duration_end_at' => $validated['duration_end_at'],
                'objective' => $validated['objective'],
                'name' => $validated['name'],
                'mechanics_description' => $validated['mechanics_description'],
                'sps_status' => SPSStatus::PENDING->value,
                'rac_status' => RACStatus::PENDING->value,
                'rac_return_count' => 0,
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);

            $this->persistChildren($marketing, $validated);

            return $marketing->load(['volumes', 'realizations', 'mechanics']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Marketing
    {
        if (! $submission instanceof Marketing) {
            throw new InvalidArgumentException('Expected a Marketing submission.');
        }

        $marketing = $submission;

        if (! $marketing->is_editable) {
            throw ValidationException::withMessages([
                'marketing' => ['Only Marketing Recommendations that have not synced or have been returned to the DRM can be edited.'],
            ]);
        }

        $validated = MarketingRules::validate($input);

        DB::transaction(function () use ($marketing, $validated): void {
            $marketing->update([
                'submitted_at' => $validated['submitted_at'] ?? $marketing->submitted_at ?? now(),
                'region_type_id' => $validated['region_type_id'],
                'program_id' => $validated['program_id'],
                'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                'duration_start_at' => $validated['duration_start_at'],
                'duration_end_at' => $validated['duration_end_at'],
                'objective' => $validated['objective'],
                'name' => $validated['name'],
                'mechanics_description' => $validated['mechanics_description'],
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);

            $this->persistChildren($marketing, $validated);
        });

        return $marketing->fresh(['volumes', 'realizations', 'mechanics']);
    }

    /**
     * Materialize a pulled server Marketing row locally so it can be edited and
     * pushed back with its existing server id.
     *
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Marketing
    {
        $program = MarketingProgram::query()
            ->where('server_id', $row['program_id'] ?? null)
            ->first();

        if (! $program) {
            throw ValidationException::withMessages([
                'marketing' => ['The Marketing program is not available locally yet. Refresh and try again.'],
            ]);
        }

        $regionTypeId = RegionType::query()->whereKey($row['region_type_id'] ?? null)->value('id')
            ?? $user->region_type_id;

        if (! $regionTypeId) {
            throw ValidationException::withMessages([
                'marketing' => ['The Marketing region type is not available locally yet. Refresh and try again.'],
            ]);
        }

        return DB::transaction(function () use ($user, $row, $program, $regionTypeId): Marketing {
            $marketing = Marketing::create([
                'local_uuid' => (string) Str::uuid(),
                'server_id' => $row['server_id'] ?? null,
                'submitted_at' => $row['submitted_at'] ?? now(),
                'submitted_by' => $user->id,
                'region_type_id' => $regionTypeId,
                'role_id' => $this->resolveRoleId($user),
                'program_id' => $program->id,
                'salescall_id' => null,
                'duration_start_at' => $row['duration_start_at'] ?? now(),
                'duration_end_at' => $row['duration_end_at'] ?? now(),
                'objective' => $row['objective'] ?? '',
                'name' => $row['name'] ?? 'Marketing',
                'mechanics_description' => $row['mechanics_description'] ?? '',
                'sps_status' => $row['sps_status'] ?? SPSStatus::PENDING->value,
                'rac_status' => $row['rac_status'] ?? RACStatus::PENDING->value,
                'rac_return_count' => $row['rac_return_count'] ?? 0,
                'sync_status' => 'synced',
                'sync_attempts' => 0,
                'sync_error' => null,
                'synced_at' => now(),
            ]);

            $this->persistChildren($marketing, [
                'volumes' => $row['volumes'] ?? [],
                'realizations' => $row['realizations'] ?? [],
                'mechanics' => $row['mechanics'] ?? [],
            ]);

            return $marketing->load(['volumes', 'realizations', 'mechanics']);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistChildren(Marketing $marketing, array $data): void
    {
        $volumes = collect($data['volumes'] ?? [])
            ->map(function (array $row): array {
                $before = (float) ($row['before_program'] ?? 0);
                $during = (float) ($row['during_program'] ?? 0);
                $volume = isset($row['volume']) && $row['volume'] !== '' && $row['volume'] !== null
                    ? (float) $row['volume']
                    : $during - $before;

                return [
                    'before_program' => $before,
                    'during_program' => $during,
                    'volume' => $volume,
                ];
            })
            ->values()
            ->all();

        $this->replaceChildren($marketing, 'volumes', $volumes, function (Marketing $parent, array $row): void {
            MarketingVolume::create(['marketing_id' => $parent->id, ...$row]);
        });

        $realizations = collect($data['realizations'] ?? [])
            ->map(fn (array $row): array => [
                'stage' => (int) $row['stage'],
                'start_at' => $row['start_at'],
                'end_at' => $row['end_at'],
                'volume' => (float) ($row['volume'] ?? 0),
            ])
            ->values()
            ->all();

        $this->replaceChildren($marketing, 'realizations', $realizations, function (Marketing $parent, array $row): void {
            MarketingRealization::create(['marketing_id' => $parent->id, ...$row]);
        });

        $mechanics = collect($data['mechanics'] ?? [])
            ->map(fn (array $row): array => [
                'period' => $row['period'],
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'sub_total' => (float) ($row['sub_total'] ?? 0),
            ])
            ->values()
            ->all();

        $this->replaceChildren($marketing, 'mechanics', $mechanics, function (Marketing $parent, array $row): void {
            MarketingMechanic::create(['marketing_id' => $parent->id, ...$row]);
        });
    }

    /**
     * Drop local rows whose server counterpart no longer appears in a successful
     * pull (deleted on the Portal). Child rows cascade with the parent.
     *
     * @param  Collection<int, Marketing>  $localMarketings
     * @param  array<int, array<string, mixed>>  $serverMarketings
     * @return Collection<int, Marketing>
     */
    private function reconcileDeletedFromServer(Collection $localMarketings, array $serverMarketings): Collection
    {
        $serverIds = collect($serverMarketings)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $localMarketings->reject(function (Marketing $marketing) use ($serverIds): bool {
            if (! $marketing->server_id || in_array((int) $marketing->server_id, $serverIds, true)) {
                return false;
            }

            $marketing->delete();

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, Marketing>  $localMarketings
     * @param  array<int, array<string, mixed>>  $serverMarketings
     * @param  Collection<int, MarketingProgram>  $programsByServerId
     * @param  Collection<int, string>  $regionTypeNames
     * @return Collection<string, array<string, mixed>>
     */
    private function merge(Collection $localMarketings, array $serverMarketings, Collection $programsByServerId, Collection $regionTypeNames): Collection
    {
        $rows = $localMarketings->mapWithKeys(function (Marketing $marketing): array {
            $key = $marketing->server_id ? 'server:'.$marketing->server_id : 'local:'.$marketing->local_uuid;

            return [$key => $this->mapLocal($marketing)];
        });

        foreach ($serverMarketings as $serverMarketing) {
            if (! isset($serverMarketing['id'])) {
                continue;
            }

            $rows['server:'.$serverMarketing['id']] = $this->mapServer($serverMarketing, $programsByServerId, $regionTypeNames);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Marketing $marketing): array
    {
        return [
            'id' => $marketing->id,
            'server_id' => $marketing->server_id,
            'local_uuid' => $marketing->local_uuid,
            'name' => $marketing->name,
            'program' => $marketing->program?->name ?? '—',
            'region_type' => $marketing->regionType?->name ?? '—',
            'submitted_at' => $marketing->submitted_at?->toISOString(),
            'duration_start_at' => $marketing->duration_start_at?->toDateString(),
            'duration_end_at' => $marketing->duration_end_at?->toDateString(),
            'objective' => $marketing->objective,
            'mechanics_description' => $marketing->mechanics_description,
            'sales_call' => $this->salesCallLabel($marketing->salescall),
            'salescall_uuid' => $marketing->salescall?->local_uuid,
            'sps_status' => $marketing->sps_status->value,
            'rac_status' => $marketing->rac_status->value,
            'rac_return_count' => $marketing->rac_return_count,
            'is_editable' => $marketing->is_editable,
            'sync_status' => $marketing->sync_status,
            'volumes' => $marketing->volumes
                ->map(fn (MarketingVolume $volume): array => [
                    'before_program' => (string) $volume->before_program,
                    'during_program' => (string) $volume->during_program,
                    'volume' => (string) $volume->volume,
                ])->values()->all(),
            'realizations' => $marketing->realizations
                ->map(fn (MarketingRealization $realization): array => [
                    'stage' => $realization->stage->value,
                    'stage_label' => $realization->stage->meta('title'),
                    'start_at' => $realization->start_at?->toDateString(),
                    'end_at' => $realization->end_at?->toDateString(),
                    'volume' => (string) $realization->volume,
                ])->values()->all(),
            'mechanics' => $marketing->mechanics
                ->map(fn (MarketingMechanic $mechanic): array => [
                    'period' => $mechanic->period?->toDateString(),
                    'unit_price' => (string) $mechanic->unit_price,
                    'sub_total' => (string) $mechanic->sub_total,
                ])->values()->all(),
            'created_at' => $marketing->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $serverMarketing
     * @param  Collection<int, MarketingProgram>  $programsByServerId
     * @param  Collection<int, string>  $regionTypeNames
     * @return array<string, mixed>
     */
    private function mapServer(array $serverMarketing, Collection $programsByServerId, Collection $regionTypeNames): array
    {
        $sps = (int) ($serverMarketing['sps_status'] ?? 1);
        $rac = (int) ($serverMarketing['rac_status'] ?? 1);

        return [
            'id' => null,
            'server_id' => $serverMarketing['id'],
            'client_uuid' => $serverMarketing['client_uuid'] ?? null,
            'local_uuid' => null,
            'name' => $serverMarketing['name'] ?? 'Marketing',
            'program_id' => (int) ($serverMarketing['program_id'] ?? 0),
            'program' => $programsByServerId->get((int) ($serverMarketing['program_id'] ?? 0))?->name ?? '—',
            'region_type_id' => (int) ($serverMarketing['region_type_id'] ?? 0),
            'region_type' => $regionTypeNames->get((int) ($serverMarketing['region_type_id'] ?? 0)) ?? '—',
            'submitted_at' => $serverMarketing['submitted_at'] ?? null,
            'duration_start_at' => $serverMarketing['duration_start_at'] ?? null,
            'duration_end_at' => $serverMarketing['duration_end_at'] ?? null,
            'objective' => $serverMarketing['objective'] ?? null,
            'mechanics_description' => $serverMarketing['mechanics_description'] ?? null,
            'sales_call' => '—',
            'salescall_uuid' => null,
            'sps_status' => $sps,
            'rac_status' => $rac,
            'rac_return_count' => (int) ($serverMarketing['rac_return_count'] ?? 0),
            'is_editable' => $sps === SPSStatus::RETURNED->value
                || $rac === RACStatus::RETURNED_TO_DRM->value,
            'sync_status' => 'synced',
            'volumes' => collect($serverMarketing['volumes'] ?? [])
                ->map(fn (array $volume): array => [
                    'before_program' => (string) ($volume['before_program'] ?? ''),
                    'during_program' => (string) ($volume['during_program'] ?? ''),
                    'volume' => (string) ($volume['volume'] ?? ''),
                ])->values()->all(),
            'realizations' => collect($serverMarketing['realizations'] ?? [])
                ->map(function (array $realization): array {
                    $stage = isset($realization['stage']) ? RealizationStage::tryFrom((int) $realization['stage']) : null;

                    return [
                        'stage' => $stage?->value,
                        'stage_label' => $stage?->meta('title') ?? '—',
                        'start_at' => $realization['start_at'] ?? null,
                        'end_at' => $realization['end_at'] ?? null,
                        'volume' => (string) ($realization['volume'] ?? ''),
                    ];
                })->values()->all(),
            'mechanics' => collect($serverMarketing['mechanics'] ?? [])
                ->map(fn (array $mechanic): array => [
                    'period' => $mechanic['period'] ?? null,
                    'unit_price' => (string) ($mechanic['unit_price'] ?? ''),
                    'sub_total' => (string) ($mechanic['sub_total'] ?? ''),
                ])->values()->all(),
            'created_at' => $serverMarketing['created_at'] ?? null,
        ];
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }

    private function resolveRoleId(User $user): int
    {
        $roleId = $user->roles()->value('id');

        if ($roleId) {
            return (int) $roleId;
        }

        return (int) Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web'])->id;
    }

    /**
     * Persist Portal-owned approval fields on local rows without touching
     * children, sync metadata, or locally pending submissions.
     *
     * @param  array<int, array<string, mixed>>  $serverMarketings
     */
    private function persistApprovalStatuses(array $serverMarketings): void
    {
        foreach ($serverMarketings as $row) {
            $serverId = $row['id'] ?? null;

            if (! is_numeric($serverId)) {
                continue;
            }

            $marketing = Marketing::query()
                ->where('server_id', (int) $serverId)
                ->first();

            if (! $marketing instanceof Marketing) {
                continue;
            }

            $marketing->update([
                'sps_status' => (int) ($row['sps_status'] ?? $marketing->sps_status->value),
                'rac_status' => (int) ($row['rac_status'] ?? $marketing->rac_status->value),
                'rac_return_count' => (int) ($row['rac_return_count'] ?? $marketing->rac_return_count),
            ]);
        }
    }

    /**
     * Match a pulled Marketing row to the rep and sales call using the Portal
     * IDs, covering a submission made from another tablet.
     *
     * @param  array<int, array<string, mixed>>  $serverMarketings
     */
    private function serverHasMarketingFor(
        array $serverMarketings,
        User $user,
        Salescall $salescall,
        bool $approvedOnly,
    ): bool {
        if (! $salescall->server_id || ! $user->server_id) {
            return false;
        }

        return collect($serverMarketings)->contains(function (array $row) use ($salescall, $user, $approvedOnly): bool {
            if ((int) ($row['salescall_id'] ?? 0) !== (int) $salescall->server_id) {
                return false;
            }

            if ((int) ($row['submitted_by'] ?? 0) !== (int) $user->server_id) {
                return false;
            }

            return ! $approvedOnly
                || (int) ($row['rac_status'] ?? 0) === RACStatus::APPROVED->value;
        });
    }
}

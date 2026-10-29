<?php

use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingMechanic;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\Marketing\MarketingRealization;
use App\Models\Forms\Marketing\MarketingVolume;
use App\Models\RegionType;
use App\Models\User;
use App\Services\Forms\MarketingService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function marketingWorkflowUser(): User
{
    $user = User::factory()->create(['api_token' => 'marketing-test-token']);
    $user->assignRole(Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web']));

    return $user;
}

function marketingWorkflowProgram(?int $serverId = null): MarketingProgram
{
    return MarketingProgram::create(['name' => 'Price Support', 'server_id' => $serverId]);
}

function marketingWorkflowRegion(): RegionType
{
    return RegionType::firstOrCreate(['name' => 'Metro Manila']);
}

function marketingValidInput(MarketingProgram $program, RegionType $region, array $overrides = []): array
{
    return array_merge([
        'program_id' => $program->id,
        'region_type_id' => $region->id,
        'duration_start_at' => '2026-11-01',
        'duration_end_at' => '2027-01-31',
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'submitted_at' => null,
        'salescall_uuid' => null,
        'volumes' => [
            ['before_program' => '1000', 'during_program' => '1500', 'volume' => '500'],
        ],
        'realizations' => [
            ['stage' => 1, 'start_at' => '2026-11-01', 'end_at' => '2026-12-31', 'volume' => '5000'],
        ],
        'mechanics' => [
            ['period' => '2026-11-15', 'unit_price' => '1500', 'sub_total' => '7500'],
        ],
    ], $overrides);
}

test('creating a Marketing recommendation persists the header and children locally', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();

    $marketing = app(MarketingService::class)->create($user, marketingValidInput($program, $region));

    expect($marketing->exists)->toBeTrue()
        ->and($marketing->submitted_by)->toBe($user->id)
        ->and($marketing->program_id)->toBe($program->id)
        ->and($marketing->region_type_id)->toBe($region->id)
        ->and($marketing->sync_status)->toBe('pending')
        ->and($marketing->sps_status->value)->toBe(1)
        ->and($marketing->rac_status->value)->toBe(1)
        ->and($marketing->role_id)->not->toBeNull()
        ->and($marketing->volumes)->toHaveCount(1)
        ->and($marketing->realizations)->toHaveCount(1)
        ->and($marketing->mechanics)->toHaveCount(1);
});

test('creating a Marketing recommendation validates required fields', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();

    expect(fn () => app(MarketingService::class)->create($user, marketingValidInput($program, $region, ['name' => ''])))
        ->toThrow(ValidationException::class);

    expect(fn () => app(MarketingService::class)->create($user, marketingValidInput($program, $region, ['program_id' => 9999])))
        ->toThrow(ValidationException::class);
});

test('updating a Marketing recommendation replaces the child snapshot idempotently', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();
    $service = app(MarketingService::class);

    $marketing = $service->create($user, marketingValidInput($program, $region));

    $input = marketingValidInput($program, $region, [
        'volumes' => [
            ['before_program' => '1000', 'during_program' => '1200', 'volume' => '200'],
            ['before_program' => '2000', 'during_program' => '2600', 'volume' => '600'],
        ],
    ]);

    $service->update($marketing, $input);
    $service->update($marketing->fresh(), $input);

    expect(MarketingVolume::where('marketing_id', $marketing->id)->count())->toBe(2)
        ->and(MarketingRealization::where('marketing_id', $marketing->id)->count())->toBe(1)
        ->and(MarketingMechanic::where('marketing_id', $marketing->id)->count())->toBe(1)
        ->and($marketing->fresh()->sync_status)->toBe('pending');
});

test('a synced Marketing recommendation is read-only until it is returned to DRM', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();
    $service = app(MarketingService::class);

    $marketing = $service->create($user, marketingValidInput($program, $region));
    $marketing->update([
        'sps_status' => 3,
        'sync_status' => 'synced',
    ]);

    expect(fn () => $service->update($marketing->fresh(), marketingValidInput($program, $region)))
        ->toThrow(ValidationException::class);
});

test('the Marketing read service merges local and server rows with the server winning', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();

    Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $user->roles()->value('id'),
        'program_id' => $program->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Local objective',
        'name' => 'Local name',
        'mechanics_description' => 'Local mechanics',
        'sps_status' => 1,
        'rac_status' => 1,
        'sync_status' => 'synced',
    ]);

    Http::fake([
        '*/api/forms/marketing/pull' => Http::response([
            'marketings' => [[
                'id' => 900,
                'name' => 'Server name',
                'program_id' => 55,
                'region_type_id' => $region->id,
                'sps_status' => 2,
                'rac_status' => 1,
                'rac_return_count' => 0,
                'volumes' => [['before_program' => '1.00', 'during_program' => '2.00', 'volume' => '1.00']],
                'realizations' => [],
                'mechanics' => [],
            ]],
        ], 200),
    ]);

    $this->actingAs($user);
    $rows = app(MarketingService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Server name')
        ->and($rows->first()['program'])->toBe('Price Support')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['volumes'])->toHaveCount(1);
});

test('a Marketing recommendation is editable before sync and after a return to DRM', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();

    $marketing = app(MarketingService::class)->create($user, marketingValidInput($program, $region));

    expect($marketing->fresh()->is_editable)->toBeTrue();

    $marketing->update(['sync_status' => 'synced']);

    expect($marketing->fresh()->is_editable)->toBeFalse();

    $marketing->update(['rac_status' => RACStatus::RETURNED_TO_DRM->value]);

    expect($marketing->fresh()->is_editable)->toBeTrue();
});

test('the Marketing read service keeps local rows when the pull fails', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();
    $service = app(MarketingService::class);

    $service->create($user, marketingValidInput($program, $region));

    Http::fake(['*/api/forms/marketing/pull' => Http::response([], 500)]);
    $this->actingAs($user);

    $read = app(MarketingService::class);
    $rows = $read->submissions();

    expect($rows)->toHaveCount(1)
        ->and($read->globalReadFailed())->toBeTrue();
});

test('a Marketing recommendation deleted on the server is purged locally', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();

    $marketing = Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $user->roles()->value('id'),
        'program_id' => $program->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Objective',
        'name' => 'Name',
        'mechanics_description' => 'Mechanics',
        'sps_status' => 1,
        'rac_status' => 1,
        'sync_status' => 'synced',
    ]);

    MarketingVolume::create(['marketing_id' => $marketing->id, 'before_program' => 1, 'during_program' => 2, 'volume' => 1]);

    Http::fake(['*/api/forms/marketing/pull' => Http::response(['marketings' => []], 200)]);
    $this->actingAs($user);

    $rows = app(MarketingService::class)->submissions();

    expect($rows)->toHaveCount(0)
        ->and(Marketing::find($marketing->id))->toBeNull()
        ->and(MarketingVolume::where('marketing_id', $marketing->id)->count())->toBe(0);
});

test('the Marketing read service upserts programs from the server', function () {
    $user = marketingWorkflowUser();

    Http::fake([
        '*/api/forms/marketing/programs' => Http::response([
            'marketing_programs' => [
                ['id' => 10, 'name' => 'Price Support'],
                ['id' => 11, 'name' => 'MCB Volume Growth Incentive'],
            ],
        ], 200),
    ]);

    $this->actingAs($user);
    $programs = app(MarketingService::class)->programs();

    expect($programs)->toHaveCount(2)
        ->and(MarketingProgram::where('server_id', 10)->value('name'))->toBe('Price Support');
});

test('pushing a Marketing recommendation posts the mapped payload and stores the server id', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();

    $marketing = app(MarketingService::class)->create($user, marketingValidInput($program, $region));

    Http::fake(fn ($request) => str_contains($request->url(), '/api/forms/marketing/push')
        ? Http::response(['server_id' => 777], 200)
        : Http::response([], 200));

    app(SyncService::class)->push();

    Http::assertSent(function ($request) use ($marketing, $program) {
        return str_ends_with($request->url(), '/api/forms/marketing/push')
            && $request['client_uuid'] === $marketing->local_uuid
            && $request['program_id'] === $program->server_id
            && $request['name'] === 'Branch Expansion Support'
            && count($request['volumes']) === 1
            && count($request['realizations']) === 1
            && count($request['mechanics']) === 1;
    });

    expect($marketing->fresh()->sync_status)->toBe('synced')
        ->and($marketing->fresh()->server_id)->toBe(777);
});

test('a pulled server Marketing row can be adopted locally for editing', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();

    Http::fake(['*/api/forms/marketing/pull' => Http::response([
        'marketings' => [[
            'id' => 901,
            'name' => 'Server Marketing',
            'program_id' => 55,
            'region_type_id' => $region->id,
            'duration_start_at' => '2026-11-01 00:00:00',
            'duration_end_at' => '2027-01-31 23:59:59',
            'objective' => 'Server objective',
            'mechanics_description' => 'Server mechanics',
            'sps_status' => 1,
            'rac_status' => 1,
            'rac_return_count' => 0,
            'volumes' => [['before_program' => '1.00', 'during_program' => '2.00', 'volume' => '1.00']],
            'realizations' => [['stage' => 1, 'start_at' => '2026-11-01 00:00:00', 'end_at' => '2026-12-31 23:59:59', 'volume' => '5000.00']],
            'mechanics' => [['period' => '2026-11-15 00:00:00', 'unit_price' => '1500.00', 'sub_total' => '7500.00']],
        ]],
    ], 200)]);

    $this->actingAs($user);
    $service = app(MarketingService::class);
    $row = $service->submissions()->first();

    $adopted = $service->adopt($user, $row);

    expect($adopted->server_id)->toBe(901)
        ->and($adopted->program_id)->toBe($program->id)
        ->and($adopted->region_type_id)->toBe($region->id)
        ->and($adopted->sync_status)->toBe('synced')
        ->and($adopted->volumes)->toHaveCount(1)
        ->and($adopted->realizations)->toHaveCount(1)
        ->and($adopted->mechanics)->toHaveCount(1);
});

test('has pending changes includes Marketing recommendations', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram();
    $region = marketingWorkflowRegion();

    app(MarketingService::class)->create($user, marketingValidInput($program, $region));

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

function marketingWorkflowServerMarketing(
    User $user,
    MarketingProgram $program,
    RegionType $region,
    int $spsStatus = SPSStatus::PENDING->value,
    int $racStatus = RACStatus::PENDING->value,
): Marketing {
    return Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $user->roles()->value('id'),
        'program_id' => $program->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Objective',
        'name' => 'Name',
        'mechanics_description' => 'Mechanics',
        'sps_status' => $spsStatus,
        'rac_status' => $racStatus,
        'rac_return_count' => 0,
        'sync_status' => 'synced',
    ]);
}

test('refreshing Marketing approval statuses persists the Portal state locally', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();
    $marketing = marketingWorkflowServerMarketing($user, $program, $region);

    Http::fake([
        '*/api/forms/marketing/pull' => Http::response([
            'marketings' => [[
                'id' => 900,
                'salescall_id' => null,
                'submitted_by' => $user->server_id,
                'sps_status' => SPSStatus::ENDORSED->value,
                'rac_status' => RACStatus::APPROVED->value,
                'rac_return_count' => 1,
            ]],
        ], 200),
    ]);

    $this->actingAs($user);
    $result = app(MarketingService::class)->refreshApprovalStatuses();

    expect($result['failed'])->toBeFalse()
        ->and($result['marketings'])->toHaveCount(1);

    $marketing->refresh();

    expect($marketing->sps_status)->toBe(SPSStatus::ENDORSED)
        ->and($marketing->rac_status)->toBe(RACStatus::APPROVED)
        ->and($marketing->rac_return_count)->toBe(1)
        ->and($marketing->isApproved())->toBeTrue();
});

test('the Marketing list read persists approval status on the local row', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();
    $marketing = marketingWorkflowServerMarketing($user, $program, $region);

    Http::fake([
        '*/api/forms/marketing/pull' => Http::response([
            'marketings' => [[
                'id' => 900,
                'name' => 'Server name',
                'program_id' => 55,
                'region_type_id' => $region->id,
                'salescall_id' => null,
                'submitted_by' => $user->server_id,
                'sps_status' => SPSStatus::ENDORSED->value,
                'rac_status' => RACStatus::APPROVED->value,
                'rac_return_count' => 0,
                'volumes' => [],
                'realizations' => [],
                'mechanics' => [],
            ]],
        ], 200),
    ]);

    $this->actingAs($user);
    app(MarketingService::class)->submissions();

    expect($marketing->fresh()->rac_status)->toBe(RACStatus::APPROVED);
});

test('a failed Marketing approval refresh keeps the last known status', function () {
    $user = marketingWorkflowUser();
    $program = marketingWorkflowProgram(serverId: 55);
    $region = marketingWorkflowRegion();
    $marketing = marketingWorkflowServerMarketing(
        $user,
        $program,
        $region,
        spsStatus: SPSStatus::ENDORSED->value,
        racStatus: RACStatus::APPROVED->value,
    );

    Http::fake(['*/api/forms/marketing/pull' => Http::response([], 500)]);

    $this->actingAs($user);
    $result = app(MarketingService::class)->refreshApprovalStatuses();

    expect($result['failed'])->toBeTrue()
        ->and($marketing->fresh()->rac_status)->toBe(RACStatus::APPROVED);
});

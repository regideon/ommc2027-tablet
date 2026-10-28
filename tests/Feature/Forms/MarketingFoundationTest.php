<?php

use App\Enums\Marketing\RealizationStage;
use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Models\Customer;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingMechanic;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\Marketing\MarketingRealization;
use App\Models\Forms\Marketing\MarketingVolume;
use App\Models\Itinerary;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use App\Support\Forms\MarketingRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function marketingFoundationUser(): User
{
    return User::factory()->create();
}

function marketingFoundationSalescall(): Salescall
{
    $user = marketingFoundationUser();
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);
    $customer = Customer::create(['name' => 'Marketing Foundation Customer', 'is_active' => true]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function marketingFoundationPayload(int $programId, int $regionTypeId, string $salescallUuid): array
{
    return [
        'server_id' => null,
        'salescall_uuid' => $salescallUuid,
        'program_id' => $programId,
        'region_type_id' => $regionTypeId,
        'duration_start_at' => now()->toDateTimeString(),
        'duration_end_at' => now()->addDay()->toDateTimeString(),
        'objective' => 'Grow volume',
        'name' => 'Summer Program',
        'mechanics_description' => 'Buy and win',
        'submitted_at' => now()->toDateTimeString(),
        'volumes' => [
            ['before_program' => 10.5, 'during_program' => 12.5, 'volume' => 2.0],
        ],
        'realizations' => [
            [
                'stage' => RealizationStage::FIRST_STAGE->value,
                'start_at' => now()->toDateTimeString(),
                'end_at' => now()->addDay()->toDateTimeString(),
                'volume' => 1.5,
            ],
        ],
        'mechanics' => [
            ['period' => '2026-10-15', 'unit_price' => 5.0, 'sub_total' => 50.0],
        ],
    ];
}

test('the marketing tables and key columns exist', function () {
    expect(Schema::hasColumns('marketing_programs', ['id', 'server_id', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('marketings', [
            'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
            'submitted_at', 'region_type_id', 'role_id', 'program_id',
            'duration_start_at', 'duration_end_at', 'objective', 'name',
            'mechanics_description', 'sps_status', 'rac_status', 'rac_return_count',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('marketing_volumes', ['marketing_id', 'before_program', 'during_program', 'volume']))->toBeTrue()
        ->and(Schema::hasColumns('marketing_realizations', ['marketing_id', 'stage', 'start_at', 'end_at', 'volume']))->toBeTrue()
        ->and(Schema::hasColumns('marketing_mechanics', ['marketing_id', 'period', 'unit_price', 'sub_total']))->toBeTrue();
});

test('marketing records resolve enum casts and relationships', function () {
    $user = marketingFoundationUser();
    $salescall = marketingFoundationSalescall();
    $program = MarketingProgram::create(['server_id' => 9001, 'name' => 'Summer']);
    $regionType = RegionType::create(['name' => 'Region A']);
    $role = Role::create(['name' => 'drm']);

    $marketing = Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'region_type_id' => $regionType->id,
        'role_id' => $role->id,
        'program_id' => $program->id,
        'salescall_id' => $salescall->id,
        'submitted_at' => now(),
        'duration_start_at' => now(),
        'duration_end_at' => now()->addDay(),
        'objective' => 'Grow volume',
        'name' => 'Summer Program',
        'mechanics_description' => 'Buy and win',
        'sps_status' => SPSStatus::ENDORSED->value,
        'rac_status' => RACStatus::APPROVED->value,
    ]);

    $volume = $marketing->volumes()->create([
        'before_program' => 10.5,
        'during_program' => 12.5,
        'volume' => 2,
    ]);

    $realization = $marketing->realizations()->create([
        'stage' => RealizationStage::SECOND_STAGE->value,
        'start_at' => now(),
        'end_at' => now()->addDay(),
        'volume' => 1.5,
    ]);

    $mechanic = $marketing->mechanics()->create([
        'period' => now(),
        'unit_price' => 5,
        'sub_total' => 50,
    ]);

    $fresh = $marketing->fresh();

    expect($fresh->sps_status)->toBe(SPSStatus::ENDORSED)
        ->and($fresh->rac_status)->toBe(RACStatus::APPROVED)
        ->and($fresh->submittedBy->id)->toBe($user->id)
        ->and($fresh->regionType->id)->toBe($regionType->id)
        ->and($fresh->role->id)->toBe($role->id)
        ->and($fresh->program->id)->toBe($program->id)
        ->and($fresh->salescall->id)->toBe($salescall->id)
        ->and($fresh->volumes()->count())->toBe(1)
        ->and($fresh->realizations()->count())->toBe(1)
        ->and($fresh->mechanics()->count())->toBe(1);

    expect((new MarketingProgram)->getTable())->toBe('marketing_programs')
        ->and($program->fresh()->server_id)->toBe(9001);

    expect($volume)->toBeInstanceOf(MarketingVolume::class)
        ->and((float) $volume->fresh()->volume)->toBe(2.0)
        ->and($volume->marketing->id)->toBe($marketing->id);

    expect($realization)->toBeInstanceOf(MarketingRealization::class)
        ->and($realization->fresh()->stage)->toBe(RealizationStage::SECOND_STAGE)
        ->and($realization->fresh()->start_at)->toBeInstanceOf(Carbon::class)
        ->and($realization->marketing->id)->toBe($marketing->id);

    expect($mechanic)->toBeInstanceOf(MarketingMechanic::class)
        ->and($mechanic->fresh()->period)->toBeInstanceOf(Carbon::class)
        ->and((float) $mechanic->fresh()->unit_price)->toBe(5.0)
        ->and($mechanic->marketing->id)->toBe($marketing->id);
});

test('marketing rules accept a valid payload and reject invalid ones', function () {
    marketingFoundationUser();
    $salescall = marketingFoundationSalescall();
    $program = MarketingProgram::create(['server_id' => 9002, 'name' => 'Winter']);
    $regionType = RegionType::create(['name' => 'Region B']);

    $payload = marketingFoundationPayload($program->id, $regionType->id, $salescall->local_uuid);

    expect(Validator::make($payload, MarketingRules::rules())->passes())->toBeTrue();

    $invalid = $payload;
    $invalid['program_id'] = 999999;
    $invalid['name'] = null;
    unset($invalid['objective']);

    expect(Validator::make($invalid, MarketingRules::rules())->fails())->toBeTrue();
});

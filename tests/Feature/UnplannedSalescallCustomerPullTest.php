<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallStatus;
use App\Models\SalescallType;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{User, Customer, Itinerary, Salescall, MaterialGroup, Brand, string}
 */
function unplannedPullFixture(string $salescallSyncStatus = 'synced'): array
{
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $customerId = -94001;
    $customerUuid = (string) Str::uuid();

    DB::table('customers')->insert([
        'id' => $customerId,
        'local_uuid' => $customerUuid,
        'server_id' => 55001,
        'name' => 'Offline Fixture Customer',
        'is_active' => true,
        'sync_status' => 'synced',
        'sync_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $itinerary = Itinerary::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 77001,
        'date_month' => now()->startOfMonth()->toDateString(),
        'itinerary_status_id' => 1,
        'created_by' => $user->id,
        'sync_status' => 'synced',
    ]);

    SalescallType::create(['name' => SalescallType::UNPLANNED]);
    SalescallStatus::create(['name' => SalescallStatus::PENDING]);

    $salescall = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customerId,
        'salescall_type_id' => SalescallType::idFor(SalescallType::UNPLANNED),
        'salescall_status_id' => SalescallStatus::idFor(SalescallStatus::PENDING),
        'visit_date' => now(),
        'route_start_at' => now(),
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 88001,
        'sync_status' => $salescallSyncStatus,
    ]);

    $materialGroup = MaterialGroup::create(['name' => 'Pull Group']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Pull Brand',
        'enabled' => true,
    ]);

    DB::table('sync_states')->insert([
        'key' => 'location_reference_snapshot_complete',
        'value' => json_encode(['reference_contract_version' => 3]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$user, Customer::findOrFail($customerId), $itinerary, $salescall, $materialGroup, $brand, $customerUuid];
}

/**
 * @return array<string, mixed>
 */
function unplannedPullPayload(
    Itinerary $itinerary,
    Salescall $salescall,
    string $customerUuid,
    array $salescallBrands,
    bool $includeCustomer = true,
): array {
    return [
        'customers' => $includeCustomer ? [[
            'id' => 55001,
            'local_uuid' => $customerUuid,
            'name' => 'Offline Fixture Customer',
            'is_active' => true,
            'updated_at' => now()->toISOString(),
        ]] : [],
        'itineraries' => [[
            'id' => 77001,
            'local_uuid' => $itinerary->local_uuid,
            'date_month' => now()->startOfMonth()->toDateString(),
            'itinerary_status_id' => 1,
            'salescalls' => [[
                'id' => 88001,
                'local_uuid' => $salescall->local_uuid,
                'customer_id' => 55001,
                'route_start_at' => now()->toDateTimeString(),
                'salescall_type_id' => SalescallType::idFor(SalescallType::UNPLANNED),
                'salescall_status_id' => SalescallStatus::idFor(SalescallStatus::PENDING),
                'salescall_brands' => $salescallBrands,
            ]],
        ]],
    ];
}

function fakeUnplannedPull(array $payload): void
{
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/schedule/pull' => Http::response($payload),
    ]);
}

test('schedule pull keeps a local Customer reference for an unsynced Customer Sales Call', function () {
    [$user, $customer, $itinerary, $salescall, $group, $brand, $customerUuid] = unplannedPullFixture();
    $this->actingAs($user);

    SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'quantity' => 2,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'synced',
    ]);

    fakeUnplannedPull(unplannedPullPayload($itinerary, $salescall, $customerUuid, [[
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'quantity' => 2,
    ]]));

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and([
            (int) $salescall->fresh()->customer_id,
            SalescallBrand::query()->count(),
            (int) (SalescallBrand::query()->first()?->customer_id ?? 0),
        ])->toBe([$customer->id, 1, (int) $customer->id]);
});

test('schedule pull repairs a pending Sales Call that already holds a Portal Customer ID', function () {
    [$user, $customer, $itinerary, $salescall, $group, $brand, $customerUuid] = unplannedPullFixture('pending');
    $this->actingAs($user);

    $salescall->update(['customer_id' => 55001]);

    fakeUnplannedPull(unplannedPullPayload($itinerary, $salescall, $customerUuid, [[
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'quantity' => 3,
    ]]));

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and((int) $salescall->fresh()->customer_id)->toBe((int) $customer->id)
        ->and((int) SalescallBrand::query()->sole()->customer_id)->toBe((int) $customer->id);
});

test('schedule pull preserves a pending local Sales Call and its pending Brands', function () {
    [$user, $customer, $itinerary, $salescall, $group, $brand, $customerUuid] = unplannedPullFixture('pending');
    $this->actingAs($user);

    $localBrand = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'quantity' => 1,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);

    fakeUnplannedPull(unplannedPullPayload($itinerary, $salescall, $customerUuid, []));

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and((int) $salescall->fresh()->customer_id)->toBe((int) $customer->id)
        ->and((int) SalescallBrand::query()->sole()->id)->toBe((int) $localBrand->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending');
});

test('schedule pull stores Customer Notes under the local Customer ID', function () {
    [$user, $customer, $itinerary, $salescall, $group, $brand, $customerUuid] = unplannedPullFixture();
    $this->actingAs($user);

    $payload = unplannedPullPayload($itinerary, $salescall, $customerUuid, []);
    $payload['customer_notes'] = [[
        'id' => 99001,
        'local_uuid' => (string) Str::uuid(),
        'customer_id' => 55001,
        'title' => 'Pull note',
        'body' => 'Note body',
    ]];

    fakeUnplannedPull($payload);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and((int) CustomerNote::query()->sole()->customer_id)->toBe((int) $customer->id);
});

test('schedule pull resolves a Sales Call Customer that is not on the received Customer page', function () {
    [$user, $customer, $itinerary, $salescall, $group, $brand, $customerUuid] = unplannedPullFixture();
    $this->actingAs($user);

    fakeUnplannedPull(unplannedPullPayload($itinerary, $salescall, $customerUuid, [[
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'quantity' => 2,
    ]], includeCustomer: false));

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and((int) $salescall->fresh()->customer_id)->toBe((int) $customer->id)
        ->and((int) SalescallBrand::query()->sole()->customer_id)->toBe((int) $customer->id);
});

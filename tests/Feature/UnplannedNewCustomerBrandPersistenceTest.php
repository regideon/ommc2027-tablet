<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallStatus;
use App\Models\SalescallType;
use App\Models\User;
use App\Services\SyncService;
use App\Support\SalescallBrandDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{User, Customer, Itinerary, Salescall, MaterialGroup, Brand}
 */
function unplannedNewCustomerBrandFixture(?int $serverId = null, string $customerSyncStatus = 'pending'): array
{
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $localCustomerId = -94001;
    $localUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => $localCustomerId,
        'local_uuid' => $localUuid,
        'server_id' => $serverId,
        'name' => 'Offline Fixture Customer',
        'is_active' => true,
        'sync_status' => $customerSyncStatus,
        'sync_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customer = Customer::findOrFail($localCustomerId);

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

    Livewire::actingAs($user)
        ->test(SalescallPage::class)
        ->call('createUnplannedSalescall', $customer->id, now()->toDateTimeString());

    $salescall = Salescall::query()->latest('id')->firstOrFail();
    $materialGroup = MaterialGroup::create(['name' => 'Persistence Group']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Persistence Brand',
        'enabled' => true,
    ]);

    return [$user, $customer, $itinerary, $salescall, $materialGroup, $brand];
}

function unplannedNewCustomerBrandSelection(int $materialGroupId, int $brandId): array
{
    return [$materialGroupId => [[
        'brand_id' => (string) $brandId,
        'quantity' => 2,
        'brand_other' => '',
    ]]];
}

function saveUnplannedNewCustomerBrand(Salescall $salescall, MaterialGroup $group, Brand $brand): mixed
{
    return Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, unplannedNewCustomerBrandSelection($group->id, $brand->id));
}

test('a new negative local Customer saves an Unplanned Sales Call Brand before Push', function () {
    [, $customer, , $salescall, $group, $brand] = unplannedNewCustomerBrandFixture();

    expect($customer->id)->toBe(-94001)
        ->and($customer->server_id)->toBeNull()
        ->and($customer->sync_status)->toBe('pending')
        ->and((int) $salescall->customer_id)->toBe($customer->id);

    saveUnplannedNewCustomerBrand($salescall, $group, $brand)
        ->assertReturned(true)
        ->assertNotified('Brands saved.');

    expect((int) SalescallBrand::query()->sole()->customer_id)->toBe($customer->id)
        ->and((int) CustomerBrand::query()->sole()->customer_id)->toBe($customer->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending');
});

test('Customer Push adds server identity without changing local Customer or Sales Call IDs', function () {
    config(['sync.server_url' => 'http://portal.test']);
    [$user, $customer, , $salescall, $group, $brand] = unplannedNewCustomerBrandFixture();
    $this->actingAs($user);
    Http::fake([
        'portal.test/api/customers/sync' => Http::response([
            'server_id' => 55001,
            'local_uuid' => $customer->local_uuid,
            'updated_at' => now()->toISOString(),
        ]),
    ]);

    $result = app(SyncService::class)->pushCustomer($customer->id);

    expect($result->success)->toBeTrue()
        ->and((int) $customer->fresh()->id)->toBe(-94001)
        ->and((int) $customer->fresh()->server_id)->toBe(55001)
        ->and($customer->fresh()->sync_status)->toBe('synced')
        ->and((int) $salescall->fresh()->customer_id)->toBe(-94001);

    saveUnplannedNewCustomerBrand($salescall->fresh(), $group, $brand)
        ->assertReturned(true)
        ->assertNotified('Brands saved.');
});

test('schedule Pull keeps the local Customer ID for an unsynced Customer Sales Call', function () {
    config(['sync.server_url' => 'http://portal.test']);
    [$user, $customer, $itinerary, $salescall, $group, $brand] = unplannedNewCustomerBrandFixture();
    $this->actingAs($user);
    Http::fake([
        'portal.test/api/customers/sync' => Http::response([
            'server_id' => 55001,
            'local_uuid' => $customer->local_uuid,
            'updated_at' => now()->toISOString(),
        ]),
    ]);
    $pushResult = app(SyncService::class)->pushCustomer($customer->id);
    $customer->refresh();
    $salescall->update(['server_id' => 88001, 'sync_status' => 'synced']);
    $salescall->refresh();

    expect($pushResult->success)->toBeTrue()
        ->and((int) $customer->id)->toBe(-94001)
        ->and((int) $customer->server_id)->toBe(55001)
        ->and((int) $salescall->customer_id)->toBe(-94001)
        ->and($salescall->sync_status)->toBe('synced');

    DB::table('sync_states')->insert([
        'key' => 'location_reference_snapshot_complete',
        'value' => json_encode(['reference_contract_version' => 3]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake([
        'portal.test/api/salescalls/push' => Http::response(['server_id' => 88001, 'ref_number' => 188001]),
        'portal.test/api/schedule/pull' => Http::response([
            'customers' => [[
                'id' => 55001,
                'local_uuid' => $customer->local_uuid,
                'name' => 'Offline Fixture Customer',
                'is_active' => true,
                'updated_at' => now()->toISOString(),
            ]],
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
                    'salescall_brands' => [],
                ]],
            ]],
        ]),
    ]);

    $pullResult = app(SyncService::class)->pull();
    $customer->refresh();
    $salescall->refresh();

    expect($pullResult->success)->toBeTrue()
        ->and((int) $customer->id)->toBe(-94001)
        ->and((int) $customer->server_id)->toBe(55001)
        ->and((int) $salescall->customer_id)->toBe(-94001)
        ->and(Customer::query()->whereKey($salescall->customer_id)->exists())->toBeTrue()
        ->and(Customer::query()->where('server_id', $salescall->customer_id)->exists())->toBeFalse();

    expect(app(SyncService::class)->push()->success)->toBeTrue()
        ->and((int) $salescall->fresh()->customer_id)->toBe(-94001);

    $diagnostics = app(SalescallBrandDiagnostics::class)->collect(
        $salescall->id,
        unplannedNewCustomerBrandSelection($group->id, $brand->id),
    );

    expect($diagnostics['references']['customer']['exists'])->toBeTrue()
        ->and($diagnostics['references']['customer']['server_id'])->toBe(55001)
        ->and($diagnostics['references']['customer']['server_id_match']['unique_local_id'])->toBeNull();

    saveUnplannedNewCustomerBrand($salescall, $group, $brand)
        ->assertReturned(true)
        ->assertNotified('Brands saved.');

    expect((int) SalescallBrand::query()->sole()->customer_id)->toBe(-94001)
        ->and((int) CustomerBrand::query()->sole()->customer_id)->toBe(-94001)
        ->and((int) $salescall->fresh()->customer_id)->toBe(-94001);
});

test('a server-ID collision with another local Customer is rejected without replacing pending associations', function () {
    [, $customer, , $salescall, $group, $brand] = unplannedNewCustomerBrandFixture(55001, 'synced');
    DB::table('customers')->insert([
        'id' => 55001,
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 66001,
        'name' => 'Unrelated Local Customer',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('salescalls')->where('id', $salescall->id)->update(['customer_id' => 55001]);
    $diagnostics = app(SalescallBrandDiagnostics::class)->collect(
        $salescall->id,
        unplannedNewCustomerBrandSelection($group->id, $brand->id),
    );
    $existingSalescallBrand = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $existingCustomerBrand = CustomerBrand::create([
        'customer_id' => $customer->id,
        'material_group_id' => $group->id,
        'brand_id' => $brand->id,
        'last_salescall_id' => $salescall->id,
        'last_updated_by' => null,
    ]);

    saveUnplannedNewCustomerBrand($salescall->fresh(), $group, $brand)
        ->assertReturned(false)
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->sole()->id)->toBe($existingSalescallBrand->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending')
        ->and(CustomerBrand::query()->sole()->id)->toBe($existingCustomerBrand->id)
        ->and($diagnostics['references']['customer']['server_id_match']['conflicts_with_local_id'])->toBeTrue()
        ->and((int) $customer->fresh()->id)->toBe(-94001);
});

test('existing Customer Unplanned and normal Sales Calls retain local-key Brand behavior', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $customer = Customer::create([
        'name' => 'Existing Fixture Customer',
        'is_active' => true,
        'server_id' => 19,
        'sync_status' => 'synced',
    ]);
    $itinerary = Itinerary::create([
        'local_uuid' => (string) Str::uuid(),
        'date_month' => now()->startOfMonth()->toDateString(),
        'itinerary_status_id' => 1,
        'created_by' => $user->id,
        'sync_status' => 'pending',
    ]);
    SalescallType::create(['name' => SalescallType::UNPLANNED]);
    SalescallStatus::create(['name' => SalescallStatus::PENDING]);
    $group = MaterialGroup::create(['name' => 'Existing Group']);
    $brand = Brand::create(['material_group_id' => $group->id, 'name' => 'Existing Brand', 'enabled' => true]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)->call('createUnplannedSalescall', $customer->id, now()->toDateTimeString());
    $unplanned = Salescall::query()->latest('id')->firstOrFail();
    $normal = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);

    saveUnplannedNewCustomerBrand($unplanned, $group, $brand)->assertReturned(true);
    saveUnplannedNewCustomerBrand($normal, $group, $brand)->assertReturned(true);

    expect(SalescallBrand::query()->where('salescall_id', $unplanned->id)->sole()->customer_id)->toBe($customer->id)
        ->and(SalescallBrand::query()->where('salescall_id', $normal->id)->sole()->customer_id)->toBe($customer->id);
});

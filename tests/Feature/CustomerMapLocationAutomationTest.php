<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerEditPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedMapAutomationFixture(bool $mappingComplete = true): array
{
    $now = now();
    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_types')->insert([
        ['id' => 1, 'name' => 'Metro Manila', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'name' => 'North Luzon', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 3, 'name' => 'South Luzon', 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 4, 'name' => 'Visayas', 'sort' => 4, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 5, 'name' => 'Mindanao', 'sort' => 5, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('regions')->insert(['id' => 7, 'code' => 'R4A', 'psgc_code' => 'TEST-REGION-7', 'name' => 'Region IV-A (CALABARZON)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert([
        ['id' => 52, 'region_id' => 7, 'name' => 'Region IV-A (Cavite, Rizal)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 53, 'region_id' => 7, 'name' => 'Region IV-A (Laguna, Batangas, Quezon)', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('provinces')->insert(['id' => 28, 'region_id' => 7, 'region_specific_id' => 53, 'psgc_code' => 'TEST-PROVINCE-28', 'name' => 'Laguna', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 27, 'region_id' => 7, 'region_specific_id' => 52, 'psgc_code' => 'TEST-PROVINCE-27', 'name' => 'Cavite', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 505, 'psgc_code' => 'TEST-MUNICIPALITY-505', 'region_id' => 7, 'province_id' => 28, 'name' => 'City of Calamba', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 13567, 'municipality_id' => 505, 'code' => 'punta', 'name' => 'Punta', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert([
        ['id' => 16, 'region_specific_id' => 52, 'code' => 'FIXTURE-GMA-A', 'name' => 'Fixture GMA Cluster A', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 17, 'region_specific_id' => 52, 'code' => 'FIXTURE-GMA-B', 'name' => 'Fixture GMA Cluster B', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 18, 'region_specific_id' => 53, 'code' => 'FIXTURE-SL-A', 'name' => 'Fixture SL Cluster', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['reference_contract_version' => 2])]);
    DB::table('general_categories')->insert(['id' => 1, 'name' => 'Mixed Outlet', 'priority_visit' => null, 'duration_per_visit' => null, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('location_area_cluster_mappings')->insert([
        ['location_level' => 'province', 'location_id' => 28, 'decision' => 'mapped', 'area_cluster_id' => 18, 'created_at' => $now, 'updated_at' => $now],
        ['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 17, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $mappedUser = User::factory()->create(['email' => 'mapped@example.test']);
    $manualUser = User::factory()->create(['email' => 'manual@example.test']);
    DB::table('customer_access_mappings')->insert([
        ['scope' => 'region_specific', 'mapping_key' => '52', 'is_complete' => $mappingComplete, 'user_references' => json_encode([['email' => $mappedUser->email]], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now],
        ['scope' => 'region_specific', 'mapping_key' => '53', 'is_complete' => $mappingComplete, 'user_references' => json_encode([['email' => $mappedUser->email]], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now],
    ]);

    return [$mappedUser, $manualUser];
}

function fakeMapAutomationGeocoder(array $address = []): void
{
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
        'display_name' => 'Punta, Calamba, Laguna, CALABARZON',
        'address' => $address ?: [
            'suburb' => 'Punta', 'city' => 'Calamba', 'county' => 'Laguna', 'state' => 'CALABARZON',
        ],
    ], 200)]);
}

test('map-derived cluster survives Access recalculation when Specific Region has multiple clusters', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('physical_region_id', 7)
        ->assertSet('province_id', 28)
        ->assertSet('municipality_id', 505)
        ->assertSet('barangay_id', 13567)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.');
});

test('map-derived Area Cluster 17 survives recalculation into multi-cluster Specific Region 52', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder(['county' => 'Cavite', 'state' => 'CALABARZON']);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 14.4, 120.9)
        ->assertSet('physical_region_id', 7)
        ->assertSet('province_id', 27)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', 17)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.');
});

test('map-derived unresolved or incomplete mapping preserves manual Access and location selections', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture(mappingComplete: false);
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.');
});

test('explicitly unresolved location mapping preserves manual Specific Region, Area Cluster, and Access', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    DB::table('location_area_cluster_mappings')->where('location_level', 'province')->where('location_id', 28)->update([
        'decision' => 'unresolved', 'area_cluster_id' => null,
    ]);
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('physical_region_id', 7)
        ->set('region_specific_id', 52)
        ->set('area_cluster_id', 16)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 14.2, 121.2)
        ->assertSet('physical_region_id', 7)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', 16)
        ->assertSet('access_user_ids', [$manualUser->id]);
});

test('map resolution to an unchanged Specific Region preserves manual Access', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('physical_region_id', 7)
        ->set('region_specific_id', 53)
        ->set('area_cluster_id', 18)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 14.2, 121.2)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id]);
});

test('partial geocoder match keeps existing physical Region and manual Specific Region', function () {
    [$mappedUser] = seedMapAutomationFixture();
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder(['city' => 'Unmatched City', 'county' => 'Unmatched Province', 'state' => 'Unmatched Region']);

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->set('region_specific_id', 52)
        ->set('area_cluster_id', 16)
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('physical_region_id', 7)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', 16);
});

test('Customer Edit uses the inherited map resolver and preserves the mapped multi-cluster choice', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Map Edit Customer', 'company_id' => 1, 'region_specific_id' => 52, 'area_cluster_id' => 16,
        'is_active' => true, 'sync_status' => 'synced', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert([
        'customer_id' => $customerId, 'user_id' => $manualUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id, $mappedUser->id]);
});

test('OE Company Access remains authoritative when map resolution changes geographic location', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    DB::table('companies')->insert(['id' => 2, 'name' => 'OE', 'code' => 'OE', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_access_mappings')->insert([
        'scope' => 'company', 'mapping_key' => 'OE', 'is_complete' => true,
        'user_references' => json_encode([['email' => $manualUser->email]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($mappedUser);
    fakeMapAutomationGeocoder();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 2)
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id]);
});

test('Customer Add persists map-derived location and manual Access through its sync path', function () {
    [$mappedUser, $manualUser] = seedMapAutomationFixture();
    $this->actingAs($mappedUser);
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'nominatim.openstreetmap.org/reverse')) {
            return Http::response([
                'display_name' => 'Punta, Calamba, Laguna, CALABARZON',
                'address' => ['suburb' => 'Punta', 'city' => 'Calamba', 'county' => 'Laguna', 'state' => 'CALABARZON'],
            ], 200);
        }
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => 'map-test-token', 'code' => 'OMMC90001']);
        }
        if (str_ends_with($request->url(), '/api/sync/push/customer')) {
            return Http::response(['server_id' => 99001, 'local_uuid' => $request['local_uuid'], 'unique_id' => 'OMMC90001', 'updated_at' => now()->toISOString()]);
        }

        return Http::response([], 200);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Map Add Customer')
        ->set('access_user_ids', [$manualUser->id])
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->call('saveCustomer')
        ->assertRedirect();

    $customer = DB::table('customers')->where('name', 'Map Add Customer')->first();
    expect($customer)->not->toBeNull()
        ->and((int) $customer->province_id)->toBe(28)
        ->and((int) $customer->municipality_id)->toBe(505)
        ->and((int) $customer->barangay_id)->toBe(13567)
        ->and((int) $customer->region_specific_id)->toBe(53)
        ->and((int) $customer->area_cluster_id)->toBe(18)
        ->and(DB::table('customer_user')->where('customer_id', $customer->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all())
        ->toBe([$manualUser->id]);
});

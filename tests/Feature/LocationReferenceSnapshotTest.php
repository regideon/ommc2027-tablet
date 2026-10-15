<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerAccessMappingResolver;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    $this->user = User::factory()->create(['api_token' => 'snapshot-test-token']);
    $this->actingAs($this->user);
    $this->snapshot = function (array $overrides = []): array {
        $sections = array_replace(array_fill_keys(['regions', 'region_specifics', 'area_clusters', 'provinces', 'municipalities', 'barangays'], []), $overrides);

        return [
            'reference_contract_version' => 3,
            'landmass_options' => ['LUZON' => 'LUZON', 'MINDANAO' => 'MINDANAO'],
            ...$sections,
            'customer_access_mappings' => [],
            'reference_counts' => array_map('count', $sections),
        ];
    };
});

function syncLocationSnapshot(): SyncService
{
    return app(SyncService::class);
}

function completeBaseCustomerAddState(int $companyId = 1): array
{
    DB::table('general_categories')->insertOrIgnore([
        'id' => 1, 'name' => 'Snapshot Test Category', 'sort' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'name' => 'Snapshot Guard Customer',
        'company_id' => $companyId,
        'is_active' => true,
        'access_user_ids' => [auth()->id()],
        'person_in_charge_id' => auth()->id(),
        'general_category_id' => 1,
        'competitor_volume' => 2,
        'address' => '1 Snapshot Test Road',
        'latitude' => '14.6500',
        'longitude' => '121.0500',
        'contact_person' => 'Snapshot Contact',
        'business_landline_number' => '02-8000000',
        'business_mobile_number' => '09170000000',
        'date_established' => '2018-01-01',
    ];
}

test('a complete location response applies validated references without changing membership rows or historical references', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'OLD', 'name' => 'Historical Region'],
        ['id' => 2, 'code' => 'R2', 'name' => 'Current Region'],
        ['id' => 3, 'code' => 'R3', 'name' => 'Commercial Parent Region'],
    ]);
    DB::table('region_specifics')->insert(['id' => 20, 'region_id' => 2, 'name' => 'Commercial Region']);
    DB::table('area_clusters')->insert(['id' => 30, 'region_specific_id' => 20, 'code' => 'A30', 'name' => 'Cluster', 'enabled' => true]);
    DB::table('provinces')->insert(['id' => 40, 'region_id' => 2, 'name' => 'Province', 'enabled' => true]);
    DB::table('municipalities')->insert(['id' => 50, 'region_id' => 2, 'province_id' => 40, 'name' => 'Municipality']);
    DB::table('barangays')->insert(['id' => 60, 'municipality_id' => 50, 'code' => 'B60', 'name' => 'Barangay', 'enabled' => true]);
    DB::table('customers')->insert(['id' => -60, 'name' => 'Historical Customer', 'region_specific_id' => 20, 'municipality_id' => 50, 'is_active' => true, 'sync_status' => 'synced']);

    Http::fake(['portal.test/api/locations/pull' => Http::response(($this->snapshot)([
        'regions' => [['id' => 2, 'code' => 'R2', 'name' => 'Current Region'], ['id' => 3, 'code' => 'R3', 'name' => 'Commercial Parent Region']],
        'region_specifics' => [['id' => 20, 'region_id' => 3, 'name' => 'Commercial Region']],
        'area_clusters' => [['id' => 30, 'region_specific_id' => 20, 'code' => 'A30', 'name' => 'Cluster', 'enabled' => true]],
        'provinces' => [['id' => 40, 'region_id' => 2, 'name' => 'Province', 'enabled' => true]],
        'municipalities' => [['id' => 50, 'region_id' => 2, 'province_id' => 40, 'name' => 'Municipality']],
        'barangays' => [['id' => 60, 'municipality_id' => 50, 'code' => 'B60', 'name' => 'Barangay', 'enabled' => true]],
    ]))]);

    $result = syncLocationSnapshot()->pullLocations();
    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77])
        ->and(DB::table('regions')->where('id', 2)->value('name'))->toBe('Current Region')
        ->and(DB::table('regions')->where('id', 1)->value('name'))->toBe('Historical Region')
        ->and(DB::table('customers')->where('id', -60)->value('region_specific_id'))->toBe(20)
        ->and(json_decode((string) DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->value('value'), true)['reference_contract_version'])->toBe(3)
        ->and(json_decode((string) DB::table('sync_states')->where('key', 'landmass_options')->value('value'), true))->toBe(['LUZON' => 'LUZON', 'MINDANAO' => 'MINDANAO']);
});

test('location mapping references are strictly validated and cached with the snapshot', function (): void {
    $payload = ($this->snapshot)([
        'regions' => [['id' => 2, 'code' => 'R2', 'name' => 'Region 2']],
        'region_specifics' => [['id' => 20, 'region_id' => 2, 'name' => 'Commercial Region']],
        'area_clusters' => [['id' => 30, 'region_specific_id' => 20, 'code' => 'A30', 'name' => 'Cluster', 'enabled' => true]],
        'provinces' => [['id' => 40, 'region_id' => 2, 'name' => 'Province', 'enabled' => true]],
    ]);
    $payload['location_area_cluster_mappings'] = [[
        'id' => 1, 'location_level' => 'province', 'location_id' => 40,
        'decision' => 'mapped', 'area_cluster_id' => 30,
    ]];
    Http::fake(['portal.test/api/locations/pull' => Http::response($payload)]);

    $result = syncLocationSnapshot()->pullLocations();
    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('location_area_cluster_mappings')->where('location_id', 40)->value('area_cluster_id'))->toBe(30);

    $payload['location_area_cluster_mappings'][0]['area_cluster_id'] = 999;
    Http::fake(['portal.test/api/locations/pull' => Http::response($payload)]);
    $rejected = syncLocationSnapshot()->pullLocations();
    expect($rejected->success)->toBeFalse()
        ->and(DB::table('location_area_cluster_mappings')->where('location_id', 40)->value('area_cluster_id'))->toBe(30);
});

test('the restored Specific Region manifest and all 34 dependent Area Clusters pass strict snapshot validation', function (): void {
    $regions = [];
    foreach (range(1, 19) as $id) {
        $regions[] = ['id' => $id, 'code' => "R{$id}", 'name' => "Physical Region {$id}"];
    }

    $manifest = [
        ['id' => 52, 'name' => 'Region IV-A (Cavite, Rizal)', 'region_id' => 7, 'area_cluster_count' => 2],
        ['id' => 53, 'name' => 'Region IV-A (Laguna, Batangas, Quezon)', 'region_id' => 7, 'area_cluster_count' => 1],
        ['id' => 54, 'name' => 'Region III (Central Luzon)', 'region_id' => 6, 'area_cluster_count' => 3],
        ['id' => 55, 'name' => 'Region XI (Davao Region)', 'region_id' => 16, 'area_cluster_count' => 1],
        ['id' => 56, 'name' => 'Region X (Northern Mindanao)', 'region_id' => 15, 'area_cluster_count' => 1],
        ['id' => 57, 'name' => 'Region IX (Zamboanga Peninsula)', 'region_id' => 14, 'area_cluster_count' => 1],
        ['id' => 58, 'name' => 'Region XII (SOCCSKSARGEN)', 'region_id' => 17, 'area_cluster_count' => 1],
        ['id' => 59, 'name' => 'Region XIII (Caraga)', 'region_id' => 18, 'area_cluster_count' => 1],
        ['id' => 60, 'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)', 'region_id' => 19, 'area_cluster_count' => 3],
        ['id' => 61, 'name' => 'Region II (Cagayan Valley)', 'region_id' => 5, 'area_cluster_count' => 1],
        ['id' => 62, 'name' => 'Cordillera Administrative Region (CAR)', 'region_id' => 3, 'area_cluster_count' => 2],
        ['id' => 63, 'name' => 'Region I (Ilocos Region)', 'region_id' => 4, 'area_cluster_count' => 3],
        ['id' => 64, 'name' => 'Region V (Bicol Region)', 'region_id' => 9, 'area_cluster_count' => 1],
        ['id' => 65, 'name' => 'Region IV-B (MIMAROPA)', 'region_id' => 8, 'area_cluster_count' => 3],
        ['id' => 66, 'name' => 'Region VII (Central Visayas)', 'region_id' => 12, 'area_cluster_count' => 3],
        ['id' => 67, 'name' => 'Region VIII (Eastern Visayas)', 'region_id' => 13, 'area_cluster_count' => 3],
        ['id' => 68, 'name' => 'Region VI (Western Visayas)', 'region_id' => 10, 'area_cluster_count' => 4],
    ];
    $regionSpecifics = [['id' => 1, 'region_id' => 1, 'name' => 'National Capital Region (NCR)', 'sort' => 0]];
    $areaClusterCounts = [1 => 10];

    foreach ($manifest as $reference) {
        $regionSpecifics[] = [
            'id' => $reference['id'],
            'region_id' => $reference['region_id'],
            'name' => $reference['name'],
            'sort' => 0,
        ];
        $areaClusterCounts[$reference['id']] = $reference['area_cluster_count'];
    }

    $areaClusters = [];
    $areaClusterId = 1;
    foreach ($areaClusterCounts as $regionSpecificId => $count) {
        for ($index = 1; $index <= $count; $index++) {
            $areaClusters[] = [
                'id' => $areaClusterId,
                'region_specific_id' => $regionSpecificId,
                'code' => "CLUSTER-{$regionSpecificId}-{$index}",
                'name' => "Cluster {$regionSpecificId}-{$index}",
                'enabled' => true,
            ];
            $areaClusterId++;
        }
    }

    Http::fake(['portal.test/api/locations/pull' => Http::response(($this->snapshot)([
        'regions' => $regions,
        'region_specifics' => $regionSpecifics,
        'area_clusters' => $areaClusters,
    ]))]);

    $result = syncLocationSnapshot()->pullLocations();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('regions')->count())->toBe(19)
        ->and(DB::table('region_specifics')->count())->toBe(18)
        ->and(DB::table('area_clusters')->count())->toBe(44)
        ->and(DB::table('area_clusters')->whereNotIn('region_specific_id', DB::table('region_specifics')->select('id'))->count())->toBe(0)
        ->and(DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->exists())->toBeTrue();
});

test('location pull caches Access mappings and resolves their Users by exact email identity', function (): void {
    $payload = ($this->snapshot)([
        'regions' => [['id' => 2, 'code' => 'R2', 'name' => 'Region 2']],
        'region_specifics' => [['id' => 20, 'region_id' => 2, 'name' => 'Specific 20']],
    ]);
    $payload['customer_access_mappings'] = [
        [
            'scope' => 'region_specific',
            'region_specific_id' => 20,
            'group_code' => 'GMA',
            'is_complete' => true,
            'users' => [['email' => 'mapped.access@example.test', 'name' => 'Mapped Access User']],
        ],
        [
            'scope' => 'company',
            'company_code' => 'OE',
            'is_complete' => true,
            'users' => [['email' => 'mapped.oe@example.test', 'name' => 'Mapped OE User']],
        ],
        [
            'scope' => 'group',
            'group_code' => 'CREATOR-GROUP',
            'is_complete' => false,
            'users' => [['email' => 'unresolved.creator.group@example.test', 'name' => 'Offline Reference']],
        ],
    ];
    Http::fake(['portal.test/api/locations/pull' => Http::response($payload)]);

    $result = syncLocationSnapshot()->pullLocations();

    $mapping = DB::table('customer_access_mappings')->where('scope', 'region_specific')->first();
    expect($result->success)->toBeTrue($result->message)
        ->and($mapping->mapping_key)->toBe('20')
        ->and((bool) $mapping->is_complete)->toBeTrue()
        ->and(json_decode($mapping->user_references, true))->toBe([['email' => 'mapped.access@example.test', 'name' => 'Mapped Access User']])
        ->and(User::query()->where('email', 'mapped.access@example.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'mapped.oe@example.test')->exists())->toBeTrue()
        ->and(DB::table('customer_access_mappings')->where('scope', 'group')->value('mapping_key'))->toBe('CREATOR-GROUP')
        ->and(User::query()->where('email', 'unresolved.creator.group@example.test')->exists())->toBeFalse();

    $resolved = app(CustomerAccessMappingResolver::class)->forCustomer(1, 20);
    expect($resolved['complete'])->toBeTrue()
        ->and($resolved['user_ids'])->toBe([User::query()->where('email', 'mapped.access@example.test')->value('id')]);
});

test('older creator-group references without last_name preserve a saved local surname', function (): void {
    $user = User::factory()->create([
        'name' => 'Jordan',
        'last_name' => 'Rivera',
        'email' => 'jordan.legacy-reference@example.test',
    ]);
    $payload = ($this->snapshot)();
    $payload['customer_access_mappings'] = [[
        'scope' => 'group',
        'group_code' => 'LEGACY-GROUP',
        'is_complete' => true,
        'users' => [['email' => $user->email, 'name' => 'Jordan']],
    ]];
    Http::fake(['portal.test/api/locations/pull' => Http::response($payload)]);

    $result = syncLocationSnapshot()->pullLocations();

    expect($result->success)->toBeTrue($result->message)
        ->and(User::query()->findOrFail($user->id)->last_name)->toBe('Rivera')
        ->and(DB::table('customer_access_mappings')->where('scope', 'group')->value('mapping_key'))->toBe('LEGACY-GROUP');
});

test('legacy v1 location snapshots without creator mappings are rejected and preserve the prior cache', function (): void {
    DB::table('customer_access_mappings')->insert([
        'scope' => 'region_specific',
        'mapping_key' => '77',
        'is_complete' => true,
        'user_references' => json_encode([['email' => 'old@example.test', 'name' => 'Old User']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyPayload = ($this->snapshot)([
        'regions' => [['id' => 78, 'code' => 'R78', 'name' => 'New Region']],
    ]);
    $legacyPayload['reference_contract_version'] = 1;
    unset($legacyPayload['customer_access_mappings']);
    Http::fake(['portal.test/api/locations/pull' => Http::response($legacyPayload)]);

    $result = syncLocationSnapshot()->pullLocations();

    expect($result->success)->toBeFalse()
        ->and(DB::table('customer_access_mappings')->count())->toBe(1)
        ->and(DB::table('customer_access_mappings')->value('mapping_key'))->toBe('77')
        ->and(DB::table('regions')->where('id', 78)->exists())->toBeFalse();
});

test('invalid Access mapping references reject the full snapshot and preserve the prior cache', function (): void {
    DB::table('customer_access_mappings')->insert([
        'scope' => 'region_specific',
        'mapping_key' => '77',
        'is_complete' => true,
        'user_references' => json_encode([['email' => 'old@example.test', 'name' => 'Old User']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = ($this->snapshot)([
        'regions' => [['id' => 2, 'code' => 'R2', 'name' => 'Region 2']],
    ]);
    $payload['customer_access_mappings'] = [[
        'scope' => 'region_specific',
        'region_specific_id' => 99,
        'group_code' => 'GMA',
        'is_complete' => true,
        'users' => [['email' => 'mapped@example.test', 'name' => 'Mapped User']],
    ]];
    Http::fake(['portal.test/api/locations/pull' => Http::response($payload)]);

    $result = syncLocationSnapshot()->pullLocations();

    expect($result->success)->toBeFalse()
        ->and(DB::table('customer_access_mappings')->count())->toBe(1)
        ->and(DB::table('customer_access_mappings')->value('mapping_key'))->toBe('77')
        ->and(DB::table('regions')->where('id', 2)->exists())->toBeFalse();
});

test('a complete location pull succeeds when the historical membership table is empty or absent', function (): void {
    Http::fake(['portal.test/api/locations/pull' => Http::sequence()
        ->push(($this->snapshot)(['regions' => [['id' => 91, 'code' => 'R91', 'name' => 'Pulled Region']]]))
        ->push(($this->snapshot)(['regions' => [['id' => 92, 'code' => 'R92', 'name' => 'Pulled Without Membership Table']]]))]);

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeTrue()
        ->and(DB::table('location_reference_memberships')->count())->toBe(0)
        ->and(DB::table('regions')->where('id', 91)->value('name'))->toBe('Pulled Region');

    Schema::dropIfExists('location_reference_memberships');
    $withoutMembershipTable = syncLocationSnapshot()->pullLocations();
    expect($withoutMembershipTable->success)->toBeTrue($withoutMembershipTable->message)
        ->and(DB::table('regions')->where('id', 92)->value('name'))->toBe('Pulled Without Membership Table')
        ->and(DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->exists())->toBeTrue();
});

test('malformed json, missing sections, invalid rows, and count mismatches preserve prior reference and membership data', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);
    $valid = ($this->snapshot)(['regions' => [['id' => 88, 'code' => 'R88', 'name' => 'New Region']]]);
    $responses = [
        '{broken json',
        json_encode(['reference_contract_version' => 1, 'reference_counts' => []]),
        json_encode(($this->snapshot)(['regions' => [['name' => 'Missing ID']]])),
        json_encode([...$valid, 'reference_counts' => ['regions' => 0, 'region_specifics' => 0, 'area_clusters' => 0, 'provinces' => 0, 'municipalities' => 0, 'barangays' => 0]]),
        json_encode(($this->snapshot)(['region_specifics' => [['id' => 89, 'region_id' => 999, 'name' => 'Broken Parent']]])),
    ];
    $responseIndex = 0;
    Http::fake(function () use (&$responseIndex, $responses) {
        return Http::response($responses[$responseIndex++], 200, ['Content-Type' => 'application/json']);
    });

    foreach (range(1, 5) as $_) {
        expect(syncLocationSnapshot()->pullLocations()->success)->toBeFalse()
            ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77])
            ->and(DB::table('regions')->where('id', 88)->exists())->toBeFalse();
    }
});

test('a failed refresh leaves the last complete membership unchanged', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    Http::fake(['portal.test/api/locations/pull' => Http::sequence()
        ->push(($this->snapshot)(['regions' => [['id' => 77, 'code' => 'R77', 'psgc_code' => '770000000', 'name' => 'Previously Confirmed Region']]]))
        ->push(['message' => 'unavailable'], 503)]);

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeTrue();

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeFalse()
        ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77]);

    Livewire::test(CustomerCreatePage::class)
        ->assertDontSee('Previously Confirmed Region');
});

test('a new Customer is blocked when no complete location and Landmass snapshot is available', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/customers/reserve-code')) {
            return Http::response(['token' => '11111111-1111-4111-8111-111111111111', 'code' => 'FLEET1001']);
        }

        return Http::response(['message' => 'Expected test push failure'], 503);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set(completeBaseCustomerAddState())
        ->set('name', 'Offline FLEET')
        ->assertDontSee('Portal location references')
        ->assertDontSee('membership')
        ->call('saveCustomer')
        ->assertHasErrors('landmass_code');

    expect(DB::table('customers')->where('name', 'Offline FLEET')->exists())->toBeFalse();
});

test('Add Customer blocks when required reference options are unavailable', function (): void {
    Schema::dropIfExists('location_reference_memberships');
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/customers/reserve-code')) {
            return Http::response(['token' => '22222222-2222-4222-8222-222222222222', 'code' => 'FLEET1002']);
        }

        return Http::response(['message' => 'Portal unavailable during isolated test'], 503);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set(completeBaseCustomerAddState())
        ->set('name', 'No Reference Dataset Customer')
        ->assertOk()
        ->assertDontSee('Portal location references')
        ->call('saveCustomer')
        ->assertHasErrors('landmass_code');

    expect(DB::table('customers')->where('name', 'No Reference Dataset Customer')->exists())->toBeFalse();
});

test('new Customer dropdowns load every locally retained reference regardless of snapshot membership', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'OLD', 'psgc_code' => '010000000', 'name' => 'Stale Region'],
        ['id' => 2, 'code' => 'R2', 'psgc_code' => '020000000', 'name' => 'Current Region'],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);

    Livewire::test(CustomerCreatePage::class)
        ->assertDontSee('Current Region')
        ->assertDontSee('Stale Region')
        ->assertDontSee('historical; unavailable')
        ->assertDontSee('membership')
        ->assertDontSee('Portal location references');
});

test('a locally selectable stale reference is blocked until reference options are verified', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R1', 'psgc_code' => '010000000', 'name' => 'Physical Region']);
    DB::table('region_specifics')->insert([
        ['id' => 53, 'region_id' => 1, 'name' => 'Locally Retained Specific Region'],
        ['id' => 54, 'region_id' => 1, 'name' => 'Other Specific Region'],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set([...completeBaseCustomerAddState(), 'name' => 'Stale Specific Region Customer'])
        ->set('physical_region_id', 1)
        ->set('region_specific_id', 53)
        ->assertSet('region_specific_id', 53)
        ->call('saveCustomer')
        ->assertHasErrors('landmass_code');

    expect(Customer::query()->where('name', 'Stale Specific Region Customer')->exists())->toBeFalse();
});

test('all six location selectors load local names but Add blocks before a complete reference snapshot', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert(['id' => 11, 'code' => 'R11', 'psgc_code' => '110000000', 'name' => 'Physical Region', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert(['id' => 21, 'region_id' => 11, 'name' => 'Commercial Region', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 31, 'region_specific_id' => 21, 'code' => 'AC31', 'name' => 'Commercial Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('provinces')->insert(['id' => 41, 'region_id' => 11, 'name' => 'Province One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('municipalities')->insert(['id' => 51, 'region_id' => 11, 'province_id' => 41, 'name' => 'City One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('barangays')->insert(['id' => 61, 'municipality_id' => 51, 'code' => 'B61', 'name' => 'Barangay One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/customers/reserve-code')) {
            return Http::response(['token' => '33333333-3333-4333-8333-333333333333', 'code' => 'FLEET1003']);
        }

        return Http::response(['server_id' => 991, 'unique_id' => 'FLEET1003', 'updated_at' => now()->toISOString()]);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set(completeBaseCustomerAddState())
        ->set('region_specific_id', 21)
        ->set('physical_region_id', 11)
        ->set('province_id', 41)
        ->set('municipality_id', 51)
        ->set('barangay_id', 61)
        ->set('area_cluster_id', 31)
        ->set('name', 'Local Reference Customer')
        ->assertSee('Region')
        ->assertSee('Specific Region')
        ->assertSee('Area Cluster')
        ->assertSee('Province One')
        ->assertSee('City One')
        ->assertSee('Barangay One')
        ->assertDontSee('historical; unavailable')
        ->assertDontSee('Portal location references')
        ->assertDontSee('membership')
        ->call('saveCustomer')
        ->assertHasErrors('landmass_code');

    expect(Customer::query()->where('name', 'Local Reference Customer')->exists())->toBeFalse();

});

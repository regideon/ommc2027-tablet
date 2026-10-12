<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerEditPage;
use App\Models\Customer;
use App\Models\LocationAreaClusterMapping;
use App\Models\User;
use App\Services\LocationAreaClusterMappingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function tabletLocationMappingFixture(): void
{
    DB::table('regions')->insert(['id' => 7, 'code' => 'IV-A', 'name' => 'Region IV-A']);
    DB::table('regions')->insert([
        ['id' => 6, 'code' => 'III', 'name' => 'Central Luzon'],
        ['id' => 10, 'code' => 'VI', 'name' => 'Western Visayas'],
        ['id' => 16, 'code' => 'XI', 'name' => 'Davao Region'],
    ]);
    DB::table('region_specifics')->insert([
        ['id' => 52, 'region_id' => 7, 'name' => 'GMA split'],
        ['id' => 53, 'region_id' => 7, 'name' => 'SL split'],
        ['id' => 54, 'region_id' => 6, 'name' => 'NL'],
        ['id' => 68, 'region_id' => 10, 'name' => 'VIS'],
        ['id' => 55, 'region_id' => 16, 'name' => 'MIN'],
    ]);
    DB::table('area_clusters')->insert([
        ['id' => 17, 'region_specific_id' => 52, 'code' => 'GMA', 'name' => 'GMA Area', 'enabled' => true],
        ['id' => 18, 'region_specific_id' => 53, 'code' => 'SL', 'name' => 'SL Area', 'enabled' => true],
        ['id' => 19, 'region_specific_id' => 54, 'code' => 'NL', 'name' => 'NL Area', 'enabled' => true],
        ['id' => 42, 'region_specific_id' => 68, 'code' => 'VIS', 'name' => 'VIS Area', 'enabled' => true],
        ['id' => 20, 'region_specific_id' => 55, 'code' => 'MIN', 'name' => 'MIN Area', 'enabled' => true],
    ]);
    DB::table('provinces')->insert([
        ['id' => 27, 'region_id' => 7, 'name' => 'Cavite', 'enabled' => true],
        ['id' => 28, 'region_id' => 7, 'name' => 'Laguna', 'enabled' => true],
        ['id' => 20, 'region_id' => 6, 'name' => 'Bulacan', 'enabled' => true],
        ['id' => 45, 'region_id' => 10, 'name' => 'Iloilo', 'enabled' => true],
        ['id' => 67, 'region_id' => 16, 'name' => 'Davao del Norte', 'enabled' => true],
    ]);
    DB::table('municipalities')->insert(['id' => 480, 'region_id' => 7, 'province_id' => 27, 'name' => 'Bacoor', 'enabled' => true]);
    DB::table('barangays')->insert(['id' => 12712, 'municipality_id' => 480, 'code' => 'BAY', 'name' => 'Bayanan', 'enabled' => true]);
}

test('resolves most specific offline location mapping and derives its Specific Region', function (): void {
    tabletLocationMappingFixture();
    LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 18]);
    LocationAreaClusterMapping::create(['location_level' => 'municipality', 'location_id' => 480, 'decision' => 'mapped', 'area_cluster_id' => 17]);

    expect(app(LocationAreaClusterMappingResolver::class)->resolve(27, 480, 12712))->toMatchArray([
        'decision' => 'mapped', 'area_cluster_id' => 17, 'region_specific_id' => 52, 'source_level' => 'municipality',
    ]);
});

test('offline synthetic locations cover the five candidate groups and the Region IV-A split', function (): void {
    tabletLocationMappingFixture();
    foreach ([27 => 17, 20 => 19, 28 => 18, 45 => 42, 67 => 20] as $provinceId => $clusterId) {
        LocationAreaClusterMapping::create([
            'location_level' => 'province',
            'location_id' => $provinceId,
            'decision' => 'mapped',
            'area_cluster_id' => $clusterId,
        ]);
    }

    $resolver = app(LocationAreaClusterMappingResolver::class);
    expect($resolver->resolve(27, null, null)['region_specific_id'])->toBe(52)
        ->and($resolver->resolve(20, null, null)['region_specific_id'])->toBe(54)
        ->and($resolver->resolve(28, null, null)['region_specific_id'])->toBe(53)
        ->and($resolver->resolve(45, null, null)['region_specific_id'])->toBe(68)
        ->and($resolver->resolve(67, null, null)['region_specific_id'])->toBe(55);
});

test('a specific unresolved decision blocks a broader location default', function (): void {
    tabletLocationMappingFixture();
    LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 17]);
    LocationAreaClusterMapping::create(['location_level' => 'barangay', 'location_id' => 12712, 'decision' => 'unresolved']);

    expect(app(LocationAreaClusterMappingResolver::class)->resolve(27, 480, 12712))->toMatchArray([
        'decision' => 'unresolved', 'area_cluster_id' => null, 'region_specific_id' => null, 'source_level' => 'barangay',
    ]);
});

test('rejects mapping a locality to a cluster in another physical Region', function (): void {
    tabletLocationMappingFixture();
    DB::table('regions')->insert(['id' => 8, 'code' => 'V', 'name' => 'Region V']);
    DB::table('region_specifics')->insert(['id' => 64, 'region_id' => 8, 'name' => 'Bicol']);
    DB::table('area_clusters')->insert(['id' => 99, 'region_specific_id' => 64, 'code' => 'BIC', 'name' => 'Bicol', 'enabled' => true]);

    expect(fn () => LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 99]))
        ->toThrow(ValidationException::class);
});

test('Customer Add applies an offline mapped Area Cluster and parent Specific Region', function (): void {
    tabletLocationMappingFixture();
    $user = User::factory()->create();
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 1])]);
    LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 17]);

    Livewire::actingAs($user)
        ->test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->set('province_id', 27)
        ->assertSet('area_cluster_id', 17)
        ->assertSet('region_specific_id', 52);
});

test('Customer Add preserves a mapped Area Cluster when its Specific Region has multiple options', function (): void {
    tabletLocationMappingFixture();
    $user = User::factory()->create();
    DB::table('area_clusters')->insert([
        ['id' => 16, 'region_specific_id' => 52, 'code' => 'GMA-SECOND', 'name' => 'GMA second area', 'enabled' => true],
        ['id' => 21, 'region_specific_id' => 53, 'code' => 'SL-SECOND', 'name' => 'SL second area', 'enabled' => true],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 1])]);
    LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 17]);

    $page = Livewire::actingAs($user)
        ->test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->set('province_id', 27)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', 17);

    $page->set('region_specific_id', 53)
        ->assertSet('area_cluster_id', null);
});

test('Customer Edit preserves mapped Area Cluster while recalculating Access and clears stale cluster on manual Specific Region change', function (): void {
    tabletLocationMappingFixture();
    $user = User::factory()->create();
    DB::table('companies')->insert(['id' => 1, 'code' => 'OMMC', 'name' => 'OMMC', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert([
        ['id' => 16, 'region_specific_id' => 52, 'code' => 'GMA-SECOND', 'name' => 'GMA second area', 'enabled' => true],
        ['id' => 21, 'region_specific_id' => 53, 'code' => 'SL-SECOND', 'name' => 'SL second area', 'enabled' => true],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 1])]);
    LocationAreaClusterMapping::create(['location_level' => 'province', 'location_id' => 27, 'decision' => 'mapped', 'area_cluster_id' => 17]);
    $customer = Customer::query()->create([
        'name' => 'Location Mapping Edit Fixture',
        'company_id' => 1,
        'region_specific_id' => 53,
        'province_id' => 28,
        'area_cluster_id' => 18,
        'is_active' => true,
    ]);

    $page = Livewire::actingAs($user)
        ->test(CustomerEditPage::class, ['customerId' => (string) $customer->id])
        ->set('province_id', 27)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', 17);

    $page->set('region_specific_id', 53)
        ->assertSet('area_cluster_id', null);
});

test('Customer Add preserves manual Area Cluster and Specific Region when no local decision applies', function (): void {
    tabletLocationMappingFixture();
    $user = User::factory()->create();
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 1])]);

    Livewire::actingAs($user)
        ->test(CustomerCreatePage::class)
        ->set('region_specific_id', 53)
        ->set('area_cluster_id', 18)
        ->set('province_id', 27)
        ->assertSet('region_specific_id', 53)
        ->assertSet('area_cluster_id', 18);
});

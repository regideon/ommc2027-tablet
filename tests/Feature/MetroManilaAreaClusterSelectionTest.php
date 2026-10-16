<?php

use App\Services\LocationAreaClusterMappingResolver;
use App\Services\MetroManilaAreaClusterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedNcrOperationalClusters(bool $omitAreaThree = false): void
{
    $now = now();
    DB::table('regions')->insert([
        ['id' => 2, 'code' => 'NCR', 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 7, 'code' => 'R4A', 'name' => 'Region IV-A', 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('region_specifics')->insert(['id' => 52, 'region_id' => 7, 'name' => 'GMA', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    foreach (config('metro_manila_area_cluster_mapping.municipality_area_codes') as $id => $_codes) {
        DB::table('municipalities')->insert([
            'id' => $id, 'region_id' => 2, 'province_id' => null, 'name' => 'NCR locality '.$id,
            'psgc_code' => '1300000000'.$id, 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach (range(1, 10) as $area) {
        if ($omitAreaThree && $area === 3) {
            continue;
        }
        DB::table('area_clusters')->insert([
            'id' => 100 + $area, 'region_specific_id' => 52, 'code' => 'gmaarea'.$area,
            'name' => 'GMA - Area '.$area, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    DB::table('barangays')->insert([
        'id' => 500, 'municipality_id' => 14, 'psgc_code' => '1300000000001', 'code' => 'central',
        'name' => 'Central', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

test('Metro Manila crosswalk covers all 17 municipalities and Quezon City stays ambiguous', function () {
    expect(config('metro_manila_area_cluster_mapping.municipality_area_codes'))->toBe([
        2 => ['gmaarea1'], 3 => ['gmaarea8'], 4 => ['gmaarea7'], 5 => ['gmaarea1'],
        6 => ['gmaarea4'], 7 => ['gmaarea5'], 8 => ['gmaarea6'], 9 => ['gmaarea8'],
        10 => ['gmaarea1'], 11 => ['gmaarea9'], 12 => ['gmaarea7'], 13 => ['gmaarea10'],
        14 => ['gmaarea2', 'gmaarea3'], 15 => ['gmaarea4'], 16 => ['gmaarea9'],
        17 => ['gmaarea1'], 18 => ['gmaarea10'],
    ]);

    seedNcrOperationalClusters();
    $resolver = app(MetroManilaAreaClusterResolver::class);

    expect($resolver->eligibleClusterIds(2))->toBe([101])
        ->and($resolver->eligibleClusterIds(14))->toBe([102, 103])
        ->and(app(LocationAreaClusterMappingResolver::class)->resolve(null, 14, 500)['decision'])->toBe('requires_finer_location');
});

test('approved Quezon City barangay mapping selects only its eligible operational cluster', function () {
    seedNcrOperationalClusters();
    DB::table('location_area_cluster_mappings')->insert([
        'location_level' => 'barangay', 'location_id' => 500, 'decision' => 'mapped',
        'area_cluster_id' => 102, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(LocationAreaClusterMappingResolver::class)->resolve(null, 14, 500))->toMatchArray([
        'decision' => 'mapped', 'area_cluster_id' => 102, 'region_specific_id' => 52,
    ]);
});

test('missing operational definitions fail closed without legacy substitution', function () {
    seedNcrOperationalClusters(omitAreaThree: true);

    expect(app(MetroManilaAreaClusterResolver::class)->eligibleClusterIds(14))->toBe([])
        ->and(app(LocationAreaClusterMappingResolver::class)->resolve(null, 14, null)['decision'])->toBe('unresolved');
});

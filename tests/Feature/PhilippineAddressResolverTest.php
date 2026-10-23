<?php

use App\Services\PhilippineAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedResolverGeography(): void
{
    $now = now();

    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'psgc_code' => '0305400000', 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'psgc_code' => '0305401000', 'region_id' => 1, 'province_id' => 1, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'psgc_code' => '0305401001', 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
}

function seedTabletNcrResolverGeography(): void
{
    $now = now();

    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'MM', 'psgc_code' => null, 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'code' => 'PSGC-1300000000', 'psgc_code' => '1300000000', 'name' => 'National Capital Region (NCR)', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 3, 'code' => 'PSGC-1400000000', 'psgc_code' => '1400000000', 'name' => 'Cordillera Administrative Region (CAR)', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 7, 'code' => 'PSGC-0400000000', 'psgc_code' => '0400000000', 'name' => 'Region IV-A (CALABARZON)', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('region_specifics')->insert([
        ['id' => 52, 'region_id' => 7, 'name' => 'Region IV-A (Cavite, Rizal)', 'sort' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $municipalities = [
        2 => ['City of Caloocan', '1380100000'],
        3 => ['City of Las Piñas', '1380200000'],
        4 => ['City of Makati', '1380300000'],
        5 => ['City of Malabon', '1380400000'],
        6 => ['City of Mandaluyong', '1380500000'],
        7 => ['City of Manila', '1380600000'],
        8 => ['City of Marikina', '1380700000'],
        9 => ['City of Muntinlupa', '1380800000'],
        10 => ['City of Navotas', '1380900000'],
        11 => ['City of Parañaque', '1381000000'],
        12 => ['Pasay City', '1381100000'],
        13 => ['City of Pasig', '1381200000'],
        14 => ['Quezon City', '1381300000'],
        15 => ['City of San Juan', '1381400000'],
        16 => ['City of Taguig', '1381500000'],
        17 => ['City of Valenzuela', '1381600000'],
        18 => ['Pateros', '1381701000'],
    ];

    foreach ($municipalities as $id => [$name, $psgcCode]) {
        DB::table('municipalities')->insert([
            'id' => $id, 'psgc_code' => $psgcCode, 'region_id' => 2, 'province_id' => null,
            'name' => $name, 'enabled' => true, 'sort' => $id, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // A same-named municipality outside NCR reproduces the nationwide ambiguity.
    DB::table('municipalities')->insert([
        'id' => 40, 'psgc_code' => '1400122000', 'region_id' => 3, 'province_id' => null,
        'name' => 'San Juan', 'enabled' => true, 'sort' => 40, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('barangays')->insert([
        ['id' => 500, 'psgc_code' => '1381300001', 'municipality_id' => 14, 'code' => 'central', 'name' => 'Central', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 1632, 'psgc_code' => '1381400019', 'municipality_id' => 15, 'code' => 'tibagan', 'name' => 'Tibagan', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    foreach (range(1, 10) as $area) {
        DB::table('area_clusters')->insert([
            'id' => 100 + $area, 'region_specific_id' => 52, 'code' => 'gmaarea'.$area,
            'name' => 'GMA - Area '.$area, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    DB::table('location_area_cluster_mappings')->insert([
        'location_level' => 'barangay', 'location_id' => 500, 'decision' => 'mapped',
        'area_cluster_id' => 102, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

test('resolver maps reverse geocode components to reference ids', function () {
    seedResolverGeography();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Pulung Maragul, Angeles, Pampanga, Central Luzon',
            'address' => [
                'house_number' => '123',
                'road' => 'Roxas Street',
                'suburb' => 'Pulung Maragul',
                'city' => 'Angeles',
                'county' => 'Pampanga',
                'state' => 'Central Luzon',
            ],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()
        ->and($result['region_id'])->toBe(1)
        ->and($result['province_id'])->toBe(1)
        ->and($result['municipality_id'])->toBe(1)
        ->and($result['barangay_id'])->toBe(1)
        ->and($result['region_specific_id'])->toBeNull()
        ->and($result['area_cluster_id'])->toBeNull()
        ->and($result['address'])->toBe('123 Roxas St, Pulung Maragul, Angeles, Pampanga, Central Luzon')
        ->and($result['address_is_partial'])->toBeFalse();
});

test('resolver fails closed when the geocoder is unreachable', function () {
    seedResolverGeography();
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unreachable')
        ->and($result['region_id'])->toBeNull()
        ->and($result['address'])->toBeNull();
});

test('resolver returns null for an unknown component instead of guessing', function () {
    seedResolverGeography();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Somewhere',
            'address' => ['state' => 'Central Luzon', 'county' => 'Pampanga', 'city' => 'Atlantis'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()
        ->and($result['region_id'])->toBe(1)
        ->and($result['municipality_id'])->toBeNull()
        ->and($result['barangay_id'])->toBeNull()
        ->and($result['address'])->toBe('Somewhere');
});

test('resolver returns null when a component name is ambiguous', function () {
    seedResolverGeography();

    DB::table('barangays')->insert(['id' => 2, 'municipality_id' => 1, 'code' => 'pulungmaragul-duplicate', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Somewhere',
            'address' => ['suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()->and($result['barangay_id'])->toBeNull();
});

test('resolver fails closed on a connection exception', function () {
    seedResolverGeography();

    Http::fake(function () {
        throw new ConnectionException('offline');
    });

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unreachable')
        ->and($result['region_id'])->toBeNull()
        ->and($result['address'])->toBeNull();
});

test('resolver leaves area cluster null when the specific region has no enabled cluster', function () {
    seedResolverGeography();
    DB::table('area_clusters')->delete();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas Street, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['region_specific_id'])->toBeNull()
        ->and($result['area_cluster_id'])->toBeNull();
});

test('resolver leaves area cluster null when the specific region has multiple clusters', function () {
    seedResolverGeography();
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 1, 'code' => 'gmaarea2', 'name' => 'GMA - Area 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas Street, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['region_specific_id'])->toBeNull()
        ->and($result['area_cluster_id'])->toBeNull();
});

test('resolver prefers authoritative psgc rows over legacy duplicates', function () {
    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'MM', 'psgc_code' => null, 'name' => 'Metro Manila', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'code' => 'PSGC-1300000000', 'psgc_code' => '1300000000', 'name' => 'National Capital Region (NCR)', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => null, 'psgc_code' => null, 'name' => 'Quezon City', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('municipalities')->insert([
        ['id' => 1, 'region_id' => 1, 'province_id' => 1, 'psgc_code' => null, 'name' => 'Quezon City', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 14, 'region_id' => 2, 'province_id' => null, 'psgc_code' => '1381300000', 'name' => 'Quezon City', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('barangays')->insert(['id' => 1553, 'municipality_id' => 14, 'psgc_code' => '1381300001', 'code' => 'pinyahan', 'name' => 'Pinyahan', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '24, Mapang-akit Street, Pinyahan, Diliman, Quezon City, Metro Manila',
            'address' => [
                'house_number' => '24', 'road' => 'Mapang-akit Street', 'quarter' => 'Pinyahan',
                'suburb' => 'Diliman', 'city' => 'Quezon City', 'state_district' => 'Eastern Manila District', 'region' => 'Metro Manila',
            ],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(14.633293953499, 121.04837064589);

    expect($result['municipality_id'])->toBe(14)
        ->and($result['region_id'])->toBe(2)
        ->and($result['province_id'])->toBeNull()
        ->and($result['barangay_id'])->toBe(1553);
});

test('resolver derives the province from the matched municipality when the county does not match', function () {
    seedResolverGeography();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Angeles',
            'address' => ['city' => 'Angeles', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['municipality_id'])->toBe(1)
        ->and($result['province_id'])->toBe(1);
});

test('resolver falls back to the region only specific region when the name and province link do not match', function () {
    seedResolverGeography();
    DB::table('provinces')->update(['region_specific_id' => null]);
    DB::table('region_specifics')->update(['name' => 'Custom Zone']);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Angeles',
            'address' => ['city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['region_id'])->toBe(1)
        ->and($result['province_id'])->toBe(1)
        ->and($result['region_specific_id'])->toBeNull()
        ->and($result['area_cluster_id'])->toBeNull();
});

test('resolver applies the approved GMA cluster to every NCR municipality', function () {
    seedTabletNcrResolverGeography();

    $expectedClusters = [
        2 => 101, 3 => 108, 4 => 107, 5 => 101, 6 => 104, 7 => 105, 8 => 106,
        9 => 108, 10 => 101, 11 => 109, 12 => 107, 13 => 110, 14 => 102, 15 => 104,
        16 => 109, 17 => 101, 18 => 110,
    ];

    $providerCities = [
        2 => 'Caloocan', 3 => 'Las Piñas', 4 => 'Makati', 5 => 'Malabon',
        6 => 'Mandaluyong', 7 => 'Manila', 8 => 'Marikina', 9 => 'Muntinlupa',
        10 => 'Navotas', 11 => 'Parañaque', 12 => 'Pasay', 13 => 'Pasig',
        14 => 'Quezon City', 15 => 'San Juan', 16 => 'Taguig', 17 => 'Valenzuela', 18 => 'Pateros',
    ];

    $responses = [];

    foreach ($providerCities as $municipalityId => $providerCity) {
        $address = ['city' => $providerCity, 'region' => 'Metro Manila'];

        if ($municipalityId === 14) {
            $address['quarter'] = 'Central';
        }

        if ($municipalityId === 15) {
            $address['quarter'] = 'Tibagan';
        }

        $responses[] = ['display_name' => $providerCity.', Metro Manila', 'address' => $address];
    }

    $responseIndex = 0;
    Http::fake(function () use (&$responseIndex, $responses) {
        return Http::response($responses[$responseIndex++], 200);
    });

    foreach ($providerCities as $municipalityId => $providerCity) {
        $result = app(PhilippineAddressResolver::class)->resolve(14.6, 121.0);
        $message = 'NCR municipality '.$municipalityId.' ('.$providerCity.')';

        expect($result['region_id'])->toBe(2, $message)
            ->and($result['province_id'])->toBeNull($message)
            ->and($result['municipality_id'])->toBe($municipalityId, $message)
            ->and($result['region_specific_id'])->toBe(52, $message)
            ->and($result['area_cluster_id'])->toBe($expectedClusters[$municipalityId], $message);
    }
});

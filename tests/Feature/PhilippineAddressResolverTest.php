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
        ->and($result['region_specific_id'])->toBe(1)
        ->and($result['area_cluster_id'])->toBe(1)
        ->and($result['address'])->toBe('123 Roxas Street');
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

    expect($result['region_specific_id'])->toBe(1)
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

    expect($result['region_specific_id'])->toBe(1)
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
        ->and($result['region_specific_id'])->toBe(1)
        ->and($result['area_cluster_id'])->toBe(1);
});

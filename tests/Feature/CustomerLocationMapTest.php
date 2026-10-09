<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedTabletLocationFixtures(): User
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'psgc_code' => '0354000000', 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['reference_contract_version' => 1])]);

    return User::factory()->create();
}

test('tablet customer form has editable location dropdowns and the map', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Livewire::test(CustomerCreatePage::class)
        ->assertOk()
        ->assertSee('Select region')
        ->assertSee('Select specific region')
        ->assertSee('No province / independent locality')
        ->assertSee('Select municipality')
        ->assertSee('Select barangay')
        ->assertSee('data-location-address="address"', false)
        ->assertSee('Pick on Map')
        ->assertSee('data-location-coordinate="latitude"', false);
});

test('tablet resolveLocation fills coordinates, address and hierarchy', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('latitude', '15.1456000')
        ->set('longitude', '120.5887000')
        ->call('resolveLocation')
        ->assertSet('address', '123 Roxas Street')
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->assertSet('barangay_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->assertSet('region_specific_id', 1);
});

test('tablet resolveLocation reports offline and keeps existing values', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

    Livewire::test(CustomerCreatePage::class)
        ->set('latitude', '15.1456000')
        ->set('longitude', '120.5887000')
        ->set('address', 'Existing Street')
        ->call('resolveLocation')
        ->assertSet('locationError', 'Internet connection required for location.')
        ->assertSet('address', 'Existing Street');
});

test('tablet resolveLocation resolves directly from passed coordinates', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->call('resolveLocation', 15.1456, 120.5887)
        ->assertSet('latitude', '15.1456')
        ->assertSet('longitude', '120.5887')
        ->assertSet('address', '123 Roxas Street')
        ->assertSet('municipality_id', 1)
        ->assertSet('barangay_id', 1);
});

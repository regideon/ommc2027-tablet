<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedSaveLocationFixtures(): User
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    return User::factory()->create();
}

test('a location resolved from the map can be saved on the create page', function () {
    $this->actingAs(seedSaveLocationFixtures());

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('name', 'New Outlet')
        ->set('company_id', 1)
        ->set('latitude', '15.1456000')
        ->set('longitude', '120.5887000')
        ->call('resolveLocation')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = Customer::where('name', 'New Outlet')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->region_specific_id)->toBe(1)
        ->and($customer->province_id)->toBe(1)
        ->and($customer->municipality_id)->toBe(1)
        ->and($customer->barangay_id)->toBe(1)
        ->and($customer->area_cluster_id)->toBe(1)
        ->and($customer->address)->toBe('123 Roxas Street');
});

<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedCustomerLocationPickerFixtures(): User
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'MM', 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'NCR', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_specific_id' => 1, 'name' => 'Metro Manila', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Quezon City', 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('general_categories')->insert(['id' => 1, 'name' => 'Mixed Outlet', 'priority_visit' => null, 'duration_per_visit' => null, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);

    return User::factory()->create();
}

test('customer add page renders the map location picker', function () {
    $this->actingAs(seedCustomerLocationPickerFixtures());

    Livewire::test(CustomerCreatePage::class)
        ->assertOk()
        ->assertSee('Pick on Map')
        ->assertSee('customer-location-map', false)
        ->assertSee('openPicker()', false)
        ->assertSee('data-location-coordinate="latitude"', false)
        ->assertSee('data-location-coordinate="longitude"', false)
        ->assertSee('Select specific region')
        ->assertSee('data-location-address="address"', false)
        ->assertSee('nominatim.openstreetmap.org', false)
        ->assertSee('confirmLocation()', false)
        ->assertSee('window.customerLocationPicker', false)
        ->assertSee('wire:model="latitude"', false)
        ->assertSee('wire:model="longitude"', false);
});

test('customer location picker writes coordinates into the component state', function () {
    $this->actingAs(seedCustomerLocationPickerFixtures());

    Livewire::test(CustomerCreatePage::class)
        ->set('latitude', '14.6500000')
        ->set('longitude', '121.0500000')
        ->assertSet('latitude', '14.6500000')
        ->assertSet('longitude', '121.0500000');
});

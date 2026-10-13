<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedInteractionLocationReferences(): void
{
    $now = now();
    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert([
        ['id' => 7, 'code' => 'R4A', 'psgc_code' => 'TEST-REGION-7', 'name' => 'Region IV-A (CALABARZON)', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 1, 'code' => 'NL', 'psgc_code' => 'TEST-REGION-1', 'name' => 'North Luzon', 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('region_specifics')->insert([
        ['id' => 52, 'region_id' => 7, 'name' => 'GMA Region', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 53, 'region_id' => 7, 'name' => 'South Luzon Region', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 54, 'region_id' => 1, 'name' => 'North Luzon Region', 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('provinces')->insert([
        ['id' => 28, 'region_id' => 7, 'region_specific_id' => 53, 'psgc_code' => 'TEST-PROVINCE-28', 'name' => 'Laguna', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 29, 'region_id' => 7, 'region_specific_id' => 53, 'psgc_code' => 'TEST-PROVINCE-29', 'name' => 'Fixture Province', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('municipalities')->insert([
        ['id' => 505, 'psgc_code' => 'TEST-MUNICIPALITY-505', 'region_id' => 7, 'province_id' => 28, 'name' => 'City of Calamba', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 506, 'psgc_code' => 'TEST-MUNICIPALITY-506', 'region_id' => null, 'province_id' => 29, 'name' => 'Nullable Region City', 'enabled' => true, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('barangays')->insert([
        ['id' => 13567, 'municipality_id' => 505, 'code' => 'punta', 'name' => 'Punta', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 13568, 'municipality_id' => 506, 'code' => 'nullable', 'name' => 'Nullable Barangay', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('area_clusters')->insert([
        ['id' => 16, 'region_specific_id' => 52, 'code' => 'GMA-A', 'name' => 'GMA Cluster A', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 18, 'region_specific_id' => 53, 'code' => 'SL-A', 'name' => 'South Luzon Cluster', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 19, 'region_specific_id' => 54, 'code' => 'NL-A', 'name' => 'North Luzon Cluster', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('region_types')->insert([
        ['id' => 1, 'name' => 'Metro Manila', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'name' => 'North Luzon', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 3, 'name' => 'South Luzon', 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 4, 'name' => 'Visayas', 'sort' => 4, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 5, 'name' => 'Mindanao', 'sort' => 5, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['reference_contract_version' => 3])]);
}

function fakeInteractionReverseGeocode(array $responses): void
{
    $sequence = Http::sequence();
    foreach ($responses as $response) {
        $sequence->push($response, 200);
    }
    Http::fake(['nominatim.openstreetmap.org/*' => $sequence]);
}

test('repeated map picks replace old locality values and reconcile nullable locality parents', function () {
    seedInteractionLocationReferences();
    fakeInteractionReverseGeocode([
        ['display_name' => 'Punta, Calamba, Laguna', 'address' => ['suburb' => 'Punta', 'city' => 'Calamba', 'county' => 'Laguna', 'state' => 'CALABARZON']],
        ['display_name' => 'North Luzon, new selection', 'address' => ['state' => 'North Luzon']],
        ['display_name' => 'Fixture Province, Nullable Region City', 'address' => ['city' => 'Nullable Region City', 'county' => 'Fixture Province', 'state' => 'CALABARZON']],
    ]);

    $component = Livewire::test(CustomerCreatePage::class)
        ->call('resolveLocation', 14.2, 121.2)
        ->assertSet('physical_region_id', 7)
        ->assertSet('province_id', 28)
        ->assertSet('municipality_id', 505)
        ->assertSet('barangay_id', 13567)
        ->call('resolveLocation', 15.2, 121.2)
        ->assertSet('physical_region_id', 1)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null)
        ->assertSet('barangay_id', null)
        ->assertSet('customer_region_type_id', 2)
        ->assertSet('region_specific_id', 54)
        ->assertSet('area_cluster_id', 19)
        ->assertSet('address', 'North Luzon, new selection')
        ->assertSet('latitude', '15.2')
        ->assertSet('longitude', '121.2');

    // A successfully matched municipality may legitimately have a nullable
    // physical region when its Province relationship proves the locality.
    $component->call('resolveLocation', 14.3, 121.3)
        ->assertSet('physical_region_id', 7)
        ->assertSet('province_id', 29)
        ->assertSet('municipality_id', 506)
        ->assertSet('barangay_id', null)
        ->assertSet('address', 'Fixture Province, Nullable Region City')
        ->assertSee('Nullable Region City');

    $component->set('physical_region_id', 7)
        ->set('customer_region_type_id', 3)
        ->set('region_specific_id', 53)
        ->set('area_cluster_id', 18)
        ->set('province_id', 29)
        ->set('municipality_id', 506)
        ->set('barangay_id', 13568);
    $createOptionValidator = new ReflectionMethod(CustomerCreatePage::class, 'validateCreateOptionState');
    $createOptionValidator->invoke($component->instance());
    $geographyValidator = new ReflectionMethod(CustomerCreatePage::class, 'physicalGeographyIsValid');
    expect($geographyValidator->invoke($component->instance()))->toBeTrue();

    $component->set('province_id', 28)->set('municipality_id', 506);
    expect(fn () => $createOptionValidator->invoke($component->instance()))
        ->toThrow(ValidationException::class);
    expect($geographyValidator->invoke($component->instance()))->toBeFalse();
});

test('unresolved children from a different parent are cleared but compatible children may remain', function () {
    seedInteractionLocationReferences();
    fakeInteractionReverseGeocode([
        ['display_name' => 'North Luzon, no locality', 'address' => ['state' => 'North Luzon']],
        ['display_name' => 'Partial North result', 'address' => ['state' => 'North Luzon']],
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->set('province_id', 28)
        ->set('municipality_id', 505)
        ->set('barangay_id', 13567)
        ->set('region_specific_id', 53)
        ->set('area_cluster_id', 18)
        ->call('resolveLocation', 15.2, 121.2)
        ->assertSet('physical_region_id', 1)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null)
        ->assertSet('barangay_id', null)
        ->assertSet('region_specific_id', 54)
        ->assertSet('area_cluster_id', 19)
        ->set('physical_region_id', 1)
        ->set('province_id', null)
        ->set('municipality_id', null)
        ->set('barangay_id', null)
        ->set('region_specific_id', 54)
        ->set('area_cluster_id', 19)
        ->call('resolveLocation', 15.3, 121.3)
        ->assertSet('physical_region_id', 1)
        ->assertSet('region_specific_id', 54)
        ->assertSet('area_cluster_id', 19);
});

test('detail loads for authorized Customers and rejects out-of-scope direct requests', function () {
    Role::create(['name' => 'drm']);
    $viewer = User::factory()->create();
    $viewer->assignRole('drm');
    $other = User::factory()->create();
    $authorized = Customer::create(['name' => 'Authorized local Customer', 'is_active' => true]);
    $authorized->users()->attach($viewer->id);
    $outside = Customer::create(['name' => 'Out of scope Customer', 'is_active' => true]);

    $this->actingAs($viewer);
    Livewire::test(CustomerPage::class)
        ->assertSee('Authorized local Customer')
        ->assertDontSee('Out of scope Customer');
    Livewire::test(CustomerPage::class)
        ->call('viewCustomer', $authorized->id)
        ->assertSet('selectedCustomerId', (string) $authorized->id)
        ->assertSee('Authorized local Customer')
        ->assertNotNotified('Customer details could not be loaded.')
        ->call('viewCustomer', $outside->id)
        ->assertSet('selectedCustomerId', null)
        ->assertSet('customerDetail', [])
        ->assertNotNotified('Customer details could not be loaded.');
});

test('detail request guard ignores duplicate in-flight invocation and list button is disabled while loading', function () {
    Role::create(['name' => 'rsm_approver']);
    $viewer = User::factory()->create();
    $viewer->assignRole('rsm_approver');
    $customer = Customer::create(['name' => 'Guarded Customer', 'is_active' => true]);
    $this->actingAs($viewer);

    Livewire::test(CustomerPage::class)
        ->set('loadingCustomerDetails', true)
        ->call('viewCustomer', $customer->id)
        ->assertSet('selectedCustomerId', null)
        ->assertSet('loadingCustomerDetails', true)
        ->assertNotNotified('Customer details could not be loaded.')
        ->assertSee('wire:target="viewCustomer"', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});

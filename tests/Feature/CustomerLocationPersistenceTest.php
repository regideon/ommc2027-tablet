<?php

use App\Filament\Pages\CustomerEditPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerProfileFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedLocationPersistenceFixtures(): Customer
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'name' => 'Region III', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 7, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 9, 'region_id' => 1, 'province_id' => 7, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 55, 'municipality_id' => 9, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 61, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    return Customer::create([
        'name' => 'Shop',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 7,
        'municipality_id' => 9,
        'barangay_id' => 55,
        'area_cluster_id' => 61,
        'is_active' => true,
        'sync_status' => 'synced',
    ]);
}

test('hydrate exposes direct province, barangay and area cluster', function () {
    $customer = seedLocationPersistenceFixtures();

    $state = CustomerProfileFormService::hydrate($customer);

    expect($state['province_id'])->toBe(7)
        ->and($state['barangay_id'])->toBe(55)
        ->and($state['area_cluster_id'])->toBe(61);
});

test('edit save persists province, barangay and area cluster', function () {
    $customer = seedLocationPersistenceFixtures();
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => $customer->id])
        ->set('province_id', 7)
        ->set('barangay_id', 55)
        ->set('area_cluster_id', 61)
        ->call('saveCustomer');

    $customer->refresh();
    expect($customer->province_id)->toBe(7)
        ->and($customer->barangay_id)->toBe(55)
        ->and($customer->area_cluster_id)->toBe(61);
});

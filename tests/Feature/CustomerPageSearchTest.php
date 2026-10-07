<?php

use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('customer search matches partial names codes addresses and local locations and can be cleared', function () {
    Role::create(['name' => 'drm']);
    $user = User::factory()->create();
    $user->assignRole('drm');

    $locationTime = now();
    DB::table('regions')->insert(['id' => 1, 'code' => 'R1', 'name' => 'Region One', 'created_at' => $locationTime, 'updated_at' => $locationTime]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Northern Specific', 'created_at' => $locationTime, 'updated_at' => $locationTime]);
    DB::table('provinces')->insert(['id' => 1, 'region_specific_id' => 1, 'name' => 'Province One', 'enabled' => true, 'created_at' => $locationTime, 'updated_at' => $locationTime]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Township', 'enabled' => true, 'created_at' => $locationTime, 'updated_at' => $locationTime]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'B1', 'name' => 'Barangay One', 'enabled' => true, 'created_at' => $locationTime, 'updated_at' => $locationTime]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'AC1', 'name' => 'Cluster One', 'enabled' => true, 'created_at' => $locationTime, 'updated_at' => $locationTime]);

    $shop = Customer::create([
        'name' => 'Zebra Hardware',
        'unique_id' => 'IB53001',
        'address' => '18 Maple Road',
        'province_id' => 1,
        'municipality_id' => 1,
        'barangay_id' => 1,
        'area_cluster_id' => 1,
        'region_specific_id' => 1,
        'is_active' => true,
    ]);
    $alpha = Customer::create(['name' => 'Alpha Store', 'address' => 'Pine Lane', 'is_active' => true]);
    DB::table('customer_user')->insert([
        ['customer_id' => $shop->id, 'user_id' => $user->id, 'created_at' => $locationTime, 'updated_at' => $locationTime],
        ['customer_id' => $alpha->id, 'user_id' => $user->id, 'created_at' => $locationTime, 'updated_at' => $locationTime],
    ]);

    $this->actingAs($user);
    $component = Livewire::test(CustomerPage::class)->assertSeeInOrder(['Alpha Store', 'Zebra Hardware']);

    foreach (['BRA HARD', 'ib530', 'MAPLE', 'town', 'province', 'barangay one', 'cluster one', 'northern specific'] as $term) {
        $component->set('search', $term)->assertSee('Zebra Hardware')->assertDontSee('Alpha Store');
    }

    $component->set('search', 'not present')->assertSee('No customers match your search.');
    $component->set('search', '')->assertSeeInOrder(['Alpha Store', 'Zebra Hardware']);
});

test('customer search cannot expose a matching customer outside existing access scope', function () {
    Role::create(['name' => 'drm']);
    $user = User::factory()->create();
    $user->assignRole('drm');
    $visible = Customer::create(['name' => 'Visible Shop', 'unique_id' => 'SHARED-CODE', 'is_active' => true]);
    Customer::create(['name' => 'Hidden Shop', 'unique_id' => 'SHARED-CODE', 'is_active' => true]);
    DB::table('customer_user')->insert(['customer_id' => $visible->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($user);

    Livewire::test(CustomerPage::class)
        ->set('search', 'shared-code')
        ->assertSee('Visible Shop')
        ->assertDontSee('Hidden Shop');
});

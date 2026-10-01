<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\User;
use App\Services\LocationReferenceBaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('location baseline imports canonical rows and is idempotent', function () {
    $baseline = app(LocationReferenceBaselineService::class);

    $first = $baseline->apply();
    $second = $baseline->apply();

    expect($first['status'])->toBe('applied')
        ->and($second['status'])->toBe('current')
        ->and(DB::table('regions')->count())->toBe(19)
        ->and(DB::table('region_specifics')->count())->toBe(18)
        ->and(DB::table('provinces')->count())->toBe(85)
        ->and(DB::table('municipalities')->count())->toBe(1643)
        ->and(DB::table('area_clusters')->count())->toBe(44)
        ->and(DB::table('barangays')->count())->toBe(42010);
});

test('location baseline preserves pending customer data', function () {
    DB::table('customers')->insert([
        'id' => -9001,
        'name' => 'Pending Offline Customer',
        'is_active' => true,
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(LocationReferenceBaselineService::class)->apply();

    $customer = DB::table('customers')->where('id', -9001)->first();

    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Pending Offline Customer')
        ->and($customer->sync_status)->toBe('pending');
});

test('current baseline marker repairs incomplete reference tables', function () {
    $baseline = app(LocationReferenceBaselineService::class);

    expect($baseline->apply()['status'])->toBe('applied');

    DB::table('provinces')->where('id', 26)->delete();

    $repaired = $baseline->apply();

    expect($repaired['status'])->toBe('applied')
        ->and(DB::table('provinces')->where('id', 26)->exists())->toBeTrue();
});

test('customer add page uses canonical dependent location controls', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(LocationReferenceBaselineService::class)->apply();

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 3)
        ->set('province_id', 4)
        ->assertSee('Abra')
        ->set('region_specific_id', 52)
        ->assertSee('GMA - Area 6')
        ->set('municipality_id', 19)
        ->assertSee('Agtangao');
});

test('Add Customer Livewire rendering keeps commercial geography independent', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(LocationReferenceBaselineService::class)->apply();
    $areaClusterId = DB::table('area_clusters')->where('region_specific_id', 52)->value('id');

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->assertSee('Batangas')
        ->assertSee('Cavite')
        ->set('province_id', 26)
        ->assertSee('Agoncillo')
        ->set('municipality_id', 444)
        ->assertSee('Adia')
        ->set('region_specific_id', 52)
        ->assertSee('GMA - Area 6')
        ->set('area_cluster_id', $areaClusterId)
        ->set('physical_region_id', 8)
        ->assertSet('region_specific_id', 52)
        ->assertSet('area_cluster_id', $areaClusterId);

    expect($component->get('physical_region_id'))->toBe(8)
        ->and($component->get('province_id'))->toBeNull()
        ->and($component->get('municipality_id'))->toBeNull()
        ->and($component->get('region_specific_id'))->toBe(52);
});

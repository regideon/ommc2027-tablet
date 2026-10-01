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

test('customer add page retains the pre-alignment location controls', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(LocationReferenceBaselineService::class)->apply();

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 3)
        ->set('province_id', 4)
        ->assertSee('Cavite')
        ->assertSee('Unavailable')
        ->assertDontSee('Select barangay')
        ->assertDontSee('Select area cluster');
});

test('Add Customer Livewire rendering exposes dependent location options', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(LocationReferenceBaselineService::class)->apply();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 7)
        ->assertSee('Batangas')
        ->assertSee('Cavite')
        ->set('province_id', 26)
        ->assertSee('Agoncillo')
        ->set('municipality_id', 444)
        ->assertSee('Unavailable');

    expect($component->get('physical_region_id'))->toBe(7)
        ->and($component->get('province_id'))->toBe(26)
        ->and($component->get('municipality_id'))->toBe(444)
        ->and($component->get('region_specific_id'))->toBeNull();
});

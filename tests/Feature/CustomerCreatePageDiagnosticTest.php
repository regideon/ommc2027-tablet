<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedCustomerCreateFixtures(): User
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

function validCustomerCreateState(): array
{
    $years = config('customer_trade_form.category_years');

    return [
        'name' => 'Diagnostic Customer',
        'unique_id' => 'DIAG-001',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'general_category_id' => 1,
        'competitor_volume' => 2,
        'address' => '1 Diagnostic Street, Quezon City',
        'latitude' => '14.6500',
        'longitude' => '121.0500',
        'contact_person' => 'Diagnostic Contact',
        'contact_number' => '09170000000',
        'is_active' => true,
        'trade' => [
            'house_number' => '1',
            'entry_detail' => 'AB',
            'classifications' => ['Battery Specialist'],
            'ommc_brands' => ['Motolite'],
            'ommc_mcb_brands' => [],
            'tpl_pollux' => [],
            'other_competitor_brands' => [],
            'mcb_competitors' => [],
            'other_competitors_note' => null,
            'working_days' => ['Monday'],
            'operating_hours' => ['8:00 AM'],
            'motiv_user' => false,
            'delivery_method' => 'resq_hub',
            'ulab' => 'GRC',
        ],
        'category_histories' => collect($years)->mapWithKeys(fn (int $year) => [
            $year => ['category_year' => $year, 'category' => 'AB Loyal'],
        ])->all(),
    ];
}

test('customer add page renders against the complete migrated sqlite schema', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);

    expect(Schema::hasTable('customers'))->toBeTrue()
        ->and(Schema::hasTable('customer_trade_profiles'))->toBeTrue()
        ->and(Schema::hasTable('customer_category_histories'))->toBeTrue()
        ->and(Schema::hasTable('companies'))->toBeTrue()
        ->and(Schema::hasTable('regions'))->toBeTrue()
        ->and(Schema::hasTable('region_specifics'))->toBeTrue()
        ->and(Schema::hasTable('provinces'))->toBeTrue()
        ->and(Schema::hasTable('municipalities'))->toBeTrue()
        ->and(Schema::hasTable('general_categories'))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['local_uuid', 'server_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at']))->toBeTrue();

    $component = Livewire::test(CustomerCreatePage::class)
        ->assertOk()
        ->assertSee('OMMC')
        ->assertSee('NCR')
        ->assertSee('Mixed Outlet')
        ->assertSee('Store Name')
        ->assertSee('Customer Code')
        ->assertSee('Company')
        ->assertSee('General Category')
        ->assertSee('Contact Person')
        ->assertSee('Contact Number')
        ->assertSee('Address')
        ->assertSee('Region')
        ->assertSee('City / Province')
        ->assertSee('Municipality')
        ->assertSee('Latitude')
        ->assertSee('Longitude')
        ->assertSee('House Number')
        ->assertSee('Entry Detail')
        ->assertSee('Classifications')
        ->assertSee('OMMC Brands')
        ->assertSee('MOTIV User')
        ->assertSee('Delivery Method')
        ->assertSee('ULAB')
        ->assertSee('Annual Categories (2018–2026)')
        ->assertSee('customer-page-layout-scope')
        ->assertSee('class="customer-page-layout-scope bg-white rounded-2xl shadow-sm p-5 space-y-4"', false)
        ->assertSee('class="customer-create-form space-y-5 pb-8"', false)
        ->assertSee('.customer-page-layout-scope .customer-row', false)
        ->assertDontSee('CUSTOMER_LAYOUT_DIAGNOSTIC', false)
        ->assertDontSee('TEMPORARY physical-device layout diagnostics', false)
        ->assertSee('customer-history-grid')
        ->assertSee('customer-span-6')
        ->assertSee('customer-span-4')
        ->assertSee('customer-control-shell')
        ->assertSee('customer-history-header')
        ->assertSee('Year')
        ->assertSee('value="2018"', false)
        ->assertSee('value="2026"', false)
        ->assertSee('wire:model.live="region_specific_id" class="customer-control"', false)
        ->assertSee('wire:model.live="province_id" class="customer-control"', false)
        ->assertSee('wire:model="municipality_id" class="customer-control"', false)
        ->assertSee('wire:model="latitude"', false)
        ->assertSee('wire:model.live="trade.entry_detail"', false)
        ->assertSee('wire:model="trade.classifications"', false)
        ->assertSee('wire:model="trade.ommc_brands"', false)
        ->assertSee('wire:model="category_histories.2018.category"', false);

    $component->set('region_specific_id', 1)
        ->set('province_id', 1)
        ->assertSee('Metro Manila')
        ->assertSee('Quezon City');

    $state = $component->get('category_histories');
    expect(array_keys($state))->toBe(range(2018, 2026));
    foreach (range(2018, 2026) as $year) {
        expect($state[$year])->toBe(['category_year' => $year, 'category' => null]);
    }
});

test('customer add page saves the complete local aggregate without network access', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)->set(validCustomerCreateState());

    $component->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->id)->toBeLessThan(0)
        ->and($customer->local_uuid)->not->toBeNull()
        ->and($customer->server_id)->toBeNull()
        ->and($customer->sync_status)->toBe('pending')
        ->and($customer->sync_attempts)->toBe(0)
        ->and($customer->company_id)->toBe(1)
        ->and($customer->region_specific_id)->toBe(1)
        ->and($customer->municipality_id)->toBe(1);

    $profile = DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->entry_detail)->toBe('AB')
        ->and(json_decode($profile->classifications, true))->toBe(['Battery Specialist']);

    $histories = DB::table('customer_category_histories')
        ->where('customer_id', $customer->id)
        ->orderBy('category_year')
        ->get();

    expect($histories)->toHaveCount(9)
        ->and($histories->pluck('category_year')->all())->toBe(range(2018, 2026))
        ->and($histories->pluck('category')->unique()->all())->toBe(['AB Loyal']);

    Http::assertNothingSent();
});

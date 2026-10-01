<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedCustomerCreateFixtures(): User
{
    $now = now();

    DB::table('companies')->insert([
        ['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'name' => 'LAST MILE', 'code' => 'LAST_MILE', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 3, 'name' => 'CAR CLUBS', 'code' => 'CAR_CLUBS', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 4, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 5, 'name' => 'OE', 'code' => 'OE', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 6, 'name' => 'IB', 'code' => 'IB', 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'MM', 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 2, 'code' => 'R2', 'name' => 'Region II', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'NCR', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 2, 'name' => 'Other Specific Region', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Metro Manila', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 2, 'region_id' => 2, 'region_specific_id' => 2, 'name' => 'Unrelated Province', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Quezon City', 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 2, 'region_id' => 2, 'province_id' => 2, 'name' => 'Unrelated Municipality', 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'name' => 'Barangay Central', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 2, 'municipality_id' => 2, 'name' => 'Unrelated Barangay', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'name' => 'NCR Cluster', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 2, 'name' => 'Other Cluster', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
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
        'physical_region_id' => 1,
        'region_specific_id' => 1,
        'area_cluster_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'barangay_id' => 1,
        'general_category_id' => 1,
        'competitor_volume' => 2,
        'address' => '1 Diagnostic Street, Quezon City',
        'latitude' => '14.6500',
        'longitude' => '121.0500',
        'contact_person' => 'Diagnostic Contact',
        'business_landline_number' => '02-8000000',
        'business_mobile_number' => '09170000000',
        'date_established' => '2018-01-01',
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
        'active' => [
            'conversion_program' => 'MADP',
            'ulab' => 'GRC',
            'operating_hours' => ['start' => '08:00', 'end' => '17:00'],
            'owner' => ['name' => 'Owner', 'birthday' => '1970-01-01', 'relationship' => 'Owner', 'generation' => '1st Gen'],
        ],
        'categories' => ['ab' => collect($years)->mapWithKeys(fn (int $year) => [$year => 'AB Loyal'])->all()],
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
        ->and(Schema::hasTable('barangays'))->toBeTrue()
        ->and(Schema::hasTable('area_clusters'))->toBeTrue()
        ->and(Schema::hasTable('general_categories'))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['local_uuid', 'server_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at']))->toBeTrue();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('trade.entry_detail', 'AB')
        ->assertOk()
        ->assertSee('OMMC')
        ->assertSee('Mixed Outlet')
        ->assertSee('Store Name')
        ->assertSee('Customer Code')
        ->assertSee('Company')
        ->assertSee('General Category')
        ->assertSee('Contact Person')
        ->assertSee('Business Landline Number')
        ->assertSee('Business Mobile Number')
        ->assertSee('Address')
        ->assertSee('Specific Region')
        ->assertSee('Province')
        ->assertSee('City / Municipality')
        ->assertSee('Latitude')
        ->assertSee('Longitude')
        ->assertSee('Entry Detail')
        ->assertSee('Classifications')
        ->assertSee('MOTIV User')
        ->assertSee('ULAB')
        ->assertSee('AB Annual Categories')
        ->assertSee('Owner Profile')
        ->assertSee('2018')
        ->assertSee('2026')
        ->assertSee('wire:model="latitude"', false)
        ->assertSee('wire:model.live="trade.entry_detail"', false)
        ->assertSee('wire:model="trade.classifications"', false)
        ->assertSee('wire:model="categories.ab.2018"', false);

    $component->set('physical_region_id', 1)
        ->assertSee('Metro Manila')
        ->assertDontSee('Unrelated Province')
        ->assertSee('NCR')
        ->assertSee('Other Specific Region')
        ->set('region_specific_id', 1)
        ->assertSee('NCR Cluster')
        ->set('area_cluster_id', 1)
        ->set('province_id', 1)
        ->assertSee('Quezon City')
        ->assertDontSee('Unrelated Municipality')
        ->set('municipality_id', 1)
        ->assertSee('Barangay Central')
        ->set('barangay_id', 1)
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->assertSet('barangay_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->assertSet('region_specific_id', 1)
        ->set('physical_region_id', 1);

    $component
        ->set('region_specific_id', 2)
        ->assertSet('region_specific_id', 2)
        ->assertSet('area_cluster_id', null)
        ->set('physical_region_id', 2)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null)
        ->assertSet('barangay_id', null)
        ->assertSee('customer-form-multi-select', false);

    $component
        ->set('province_id', 2)
        ->set('municipality_id', 2)
        ->assertSee('Unrelated Barangay')
        ->set('barangay_id', 2)
        ->assertSet('barangay_id', 2);

    $state = $component->get('categories.ab');
    expect(array_keys($state))->toBe(range(2018, 2026));
    foreach (range(2018, 2026) as $year) {
        expect($state[$year])->toBeNull();
    }
});

test('company profile mapping preserves accepted namespaces', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class);

    foreach ([
        1 => 'outlet',
        2 => 'outlet',
        3 => 'outlet',
        4 => 'fleet',
        5 => 'oe',
        6 => 'ib',
    ] as $companyId => $profile) {
        $component->set('company_id', $companyId);
        expect($component->instance()->profileType())->toBe($profile);
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
        ->and($customer->province_id)->toBe(1)
        ->and($customer->municipality_id)->toBe(1)
        ->and($customer->area_cluster_id)->toBe(1)
        ->and($customer->barangay_id)->toBe(1);

    $profile = DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->entry_detail)->toBe('AB')
        ->and(json_decode($profile->profile_data, true)['active']['conversion_program'])->toBe('MADP')
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

test('customer code reservation uses the configured authenticated Portal endpoint', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    config()->set('sync.server_url', 'https://portal.test');
    Http::fake([
        'https://portal.test/api/sync/reserve-customer-code' => Http::response([
            'token' => '3b241101-e2bb-4255-8caf-4136c566a962',
            'code' => 'FLEET3728',
        ]),
    ]);

    $reservation = app(SyncService::class)->reserveCustomerCode(6);

    expect($reservation)->toBe([
        'token' => '3b241101-e2bb-4255-8caf-4136c566a962',
        'code' => 'FLEET3728',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://portal.test/api/sync/reserve-customer-code'
        && $request->header('Authorization') === ['Bearer tablet-token']
        && $request['company_id'] === 6);
});

test('company selection displays an online reservation and retains its token', function () {
    $user = seedCustomerCreateFixtures();
    $user->update(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    config()->set('sync.server_url', 'https://portal.test');
    Http::fake([
        'https://portal.test/api/sync/reserve-customer-code' => Http::response([
            'token' => '3b241101-e2bb-4255-8caf-4136c566a962',
            'code' => 'OMMC08739',
        ]),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->assertSet('unique_id', 'OMMC08739')
        ->assertSet('customer_code_reservation_token', '3b241101-e2bb-4255-8caf-4136c566a962');
});

test('failed online reservation leaves Customer Code blank without inventing a local code', function () {
    $user = seedCustomerCreateFixtures();
    $user->update(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    config()->set('sync.server_url', 'https://portal.test');
    Http::fake([
        'https://portal.test/api/sync/reserve-customer-code' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->assertSet('unique_id', null)
        ->assertSet('customer_code_reservation_token', null);
});

test('changing Company clears the prior reservation before requesting a new one', function () {
    $user = seedCustomerCreateFixtures();
    $user->update(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    config()->set('sync.server_url', 'https://portal.test');
    Http::fake([
        'https://portal.test/api/sync/reserve-customer-code' => Http::sequence()
            ->push(['token' => '3b241101-e2bb-4255-8caf-4136c566a962', 'code' => 'OMMC08739'])
            ->push(['token' => '4c352212-f3cc-5366-9b0d-5247d677b073', 'code' => 'FLEET3728']),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->assertSet('unique_id', 'OMMC08739')
        ->set('company_id', 4)
        ->assertSet('unique_id', 'FLEET3728')
        ->assertSet('customer_code_reservation_token', '4c352212-f3cc-5366-9b0d-5247d677b073');
});

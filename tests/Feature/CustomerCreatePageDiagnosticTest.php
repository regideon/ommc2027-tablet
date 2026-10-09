<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerEditPage;
use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
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
    DB::table('regions')->insert(['id' => 1, 'code' => 'MM', 'psgc_code' => '130000000', 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'NCR', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Metro Manila', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Quezon City', 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'cluster-1', 'name' => 'Cluster 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 1])]);
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
        'physical_region_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
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
        ->and(Schema::hasTable('general_categories'))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['local_uuid', 'server_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at']))->toBeTrue();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('trade.entry_detail', 'AB')
        ->assertOk()
        ->assertSee('OMMC')
        ->assertSee('NCR')
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
        ->assertSee('x-model="$wire.trade.entry_detail"', false)
        ->assertSee('x-on:change="$wire.$set(\'trade.entry_detail\', $event.target.value, true)"', false)
        ->assertSee('wire:model.live="company_id"', false)
        ->assertSee('wire:model.live="physical_region_id"', false)
        ->assertSee('wire:model.live="region_specific_id"', false)
        ->assertSee('wire:model.live="province_id"', false)
        ->assertSee('wire:model.live="municipality_id"', false)
        ->assertDontSee('wire:model.live="trade.entry_detail"', false)
        ->assertSee('wire:model="trade.classifications"', false)
        ->assertSee('x-model="$wire.categories.ab.2018"', false)
        ->assertSee('OMMC')
        ->assertSee('NCR')
        ->assertSee('Metro Manila');

    $component->set('physical_region_id', 1)
        ->set('region_specific_id', 1)
        ->set('province_id', 1)
        ->assertSee('Metro Manila')
        ->assertSee('Quezon City');

    $component->set('physical_region_id', null)
        ->assertSet('region_specific_id', 1)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null);

    $state = $component->get('categories.ab');
    expect(array_keys($state))->toBe(range(2018, 2026));
    foreach (range(2018, 2026) as $year) {
        expect($state[$year])->toBeNull();
    }
});

test('customer add page saves the complete aggregate locally when its immediate push fails', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake(['*api/sync/push/customer' => Http::response(['message' => 'Unavailable'], 503)]);

    $component = Livewire::test(CustomerCreatePage::class)->set(validCustomerCreateState());

    $component->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->id)->toBeLessThan(0)
        ->and($customer->local_uuid)->not->toBeNull()
        ->and($customer->server_id)->toBeNull()
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1)
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

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['general_category_id'] === 1
        && $request['region_specific_id'] === 1
        && $request['province_id'] === 1
        && $request['municipality_id'] === 1
        && $request['category_histories'][0]['stream'] === 'ab'
        && count($request['category_histories']) === 9);

    $edit = Livewire::test(CustomerEditPage::class, ['customerId' => $customer->id])
        ->assertOk()
        ->assertSee('wire:model.live="company_id"', false)
        ->assertSee('wire:model.live="physical_region_id"', false)
        ->assertSee('OMMC')
        ->assertSee('NCR')
        ->assertSee('Quezon City');

    expect($edit->get('company_id'))->toBe(1)
        ->and($edit->get('region_specific_id'))->toBe(1)
        ->and($edit->get('physical_region_id'))->toBe(1)
        ->and($edit->get('province_id'))->toBe(1)
        ->and($edit->get('municipality_id'))->toBe(1);
});

test('customer add page defaults the current local user into access without requiring an rsm', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class);

    expect($component->get('access_user_ids'))->toBe([$user->id])
        ->and($component->html())->toContain('selected');

    $component->set(validCustomerCreateState());

    $component->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();

    expect($customer)->not->toBeNull()
        ->and($user->fresh()->rsm_id)->toBeNull()
        ->and(DB::table('customer_user')
            ->where('customer_id', $customer->id)
            ->where('user_id', $user->id)
            ->exists())->toBeTrue()
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->exists())->toBeTrue();
});

test('customer add page preserves the default current user when adding another access user', function () {
    $user = seedCustomerCreateFixtures();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(CustomerCreatePage::class)
        ->set(validCustomerCreateState())
        ->set('access_user_ids', [$user->id, $otherUser->id]);

    expect($component->get('access_user_ids'))->toBe([$user->id, $otherUser->id]);

    $component->call('saveCustomer')->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();

    expect(DB::table('customer_user')->where('customer_id', $customer->id)->pluck('user_id')->sort()->values()->all())
        ->toBe([$user->id, $otherUser->id]);
});

test('customer add page pushes Access and PIC using user emails instead of local numeric ids', function () {
    $user = seedCustomerCreateFixtures();
    $pic = User::factory()->create(['email' => 'pic@example.test']);
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake(['*api/sync/push/customer' => Http::response(['server_id' => 100, 'updated_at' => now()->toISOString()], 200)]);

    Livewire::test(CustomerCreatePage::class)
        ->set(validCustomerCreateState())
        ->set('person_in_charge_id', $pic->id)
        ->set('access_user_ids', [$user->id, $pic->id])
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['person_in_charge_email'] === 'pic@example.test'
        && $request['access_user_emails'] === [$user->email, 'pic@example.test']
        && ! array_key_exists('person_in_charge_id', $request->data())
        && ! array_key_exists('access_user_ids', $request->data()));
});

test('customer add location dependencies keep physical and commercial hierarchies independent', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 1, 'name' => 'Commercial Region 2', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 2, 'code' => 'cluster-2', 'name' => 'Cluster 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 1)
        ->set('region_specific_id', 1)
        ->set('province_id', 1)
        ->set('municipality_id', 1)
        ->set('area_cluster_id', 1)
        ->set('barangay_id', null)
        ->set('physical_region_id', 2)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null)
        ->assertSet('barangay_id', null)
        ->assertSet('region_specific_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->set('physical_region_id', 1)
        ->set('province_id', 1)
        ->set('municipality_id', 1)
        ->set('region_specific_id', 2)
        ->assertSet('physical_region_id', 1)
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->assertSet('area_cluster_id', null);

    expect($component->html())
        ->toContain('Commercial Region 2')
        ->toContain('Cluster 2')
        ->toContain('Metro Manila');
});

test('an exhausted failed Customer can be corrected in the existing edit page without changing its code or local identity', function () {
    $user = seedCustomerCreateFixtures();
    $now = now();
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 1, 'name' => 'Current Commercial Region', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('customers')->insert([
        'id' => -44,
        'local_uuid' => '44444444-4444-4444-8444-444444444444',
        'name' => 'Failed Customer',
        'unique_id' => 'OMMC0044',
        'customer_code_reservation_token' => '44444444-4444-4444-8444-444444444445',
        'company_id' => 1,
        'region_specific_id' => 1,
        'is_active' => true,
        'sync_status' => 'failed',
        'sync_attempts' => 3,
        'sync_error' => '422: selected region-specific id is invalid',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => -44])
        ->assertSet('region_specific_id', 1)
        ->assertSee('NCR')
        ->assertDontSee('historical; unavailable')
        ->set('region_specific_id', 2)
        ->set('area_cluster_id', null)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = Customer::findOrFail(-44);
    expect($customer->region_specific_id)->toBe(2)
        ->and($customer->unique_id)->toBe('OMMC0044')
        ->and($customer->customer_code_reservation_token)->toBe('44444444-4444-4444-8444-444444444445')
        ->and($customer->local_uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(3)
        ->and(DB::table('sync_states')->where('key', 'customer.manual_retry_ready.-44')->exists())->toBeTrue();
});

test('customer add page still rejects an invalid access user id', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set(validCustomerCreateState())
        ->set('access_user_ids', [999999])
        ->call('saveCustomer')
        ->assertHasErrors(['access_user_ids.0']);

    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeFalse();
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer'));
});

test('customer add page accepts blank Warehouse Code when MOTIV User is enabled', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['trade' => ['motiv_user' => true]]))
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();
    $profile = DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first();
    expect($customer)->not->toBeNull()
        ->and(json_decode($profile->profile_data, true)['active']['warehouse_code'] ?? null)->toBeNull();
});

test('customer add page accepts blank Delivery Detail when Delivery Type is Yes', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['delivery_type' => 'yes']]))
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();
    $profile = DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first();
    expect($customer)->not->toBeNull()
        ->and(json_decode($profile->profile_data, true)['active']['delivery_type'])->toBe('yes')
        ->and(json_decode($profile->profile_data, true)['active']['delivery_detail'] ?? null)->toBeNull();
});

test('customer add validates supplied Warehouse Code and Delivery Detail values without conditional requiredness', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake(['*' => Http::response(['server_id' => 100, 'updated_at' => now()->toISOString()], 200)]);

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), [
            'trade' => ['motiv_user' => true],
            'active' => ['warehouse_code' => 'WH-123', 'delivery_type' => 'yes', 'delivery_detail' => 'own_delivery'],
        ]))
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();
    $active = json_decode(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'), true)['active'];
    expect($active['warehouse_code'])->toBe('WH-123')
        ->and($active['delivery_detail'])->toBe('own_delivery');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && data_get($request->data(), 'profile_data.active.warehouse_code') === 'WH-123'
        && data_get($request->data(), 'profile_data.active.delivery_detail') === 'own_delivery');
});

test('customer add rejects malformed populated Warehouse Code and Delivery Detail values', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['delivery_detail' => 'invalid']]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.delivery_detail']);

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['warehouse_code' => str_repeat('x', 256)]]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.warehouse_code']);

    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeFalse();
});

test('customer add minimum Name and Company creation supports every mapped company profile including CAR_CLUBS', function () {
    $user = seedCustomerCreateFixtures();
    $companies = [
        [2, 'LAST MILE', 'LAST_MILE'], [3, 'FLEET', 'FLEET'], [4, 'OE', 'OE'], [5, 'IB', 'IB'], [6, 'CAR CLUBS', 'CAR_CLUBS'],
    ];
    foreach ($companies as [$id, $name, $code]) {
        DB::table('companies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($user);
    Http::fake(['*' => Http::response(['message' => 'offline'], 503)]);

    foreach ([1, 2, 3, 4, 5, 6] as $companyId) {
        Livewire::test(CustomerCreatePage::class)
            ->set('name', "Minimal Customer {$companyId}")
            ->set('company_id', $companyId)
            ->call('saveCustomer')
            ->assertHasNoErrors();
    }

    expect(DB::table('customers')->whereIn('name', collect(range(1, 6))->map(fn ($id) => "Minimal Customer {$id}")->all())->count())->toBe(6)
        ->and(DB::table('customer_trade_profiles')->whereIn('customer_id', DB::table('customers')->whereIn('name', collect(range(1, 6))->map(fn ($id) => "Minimal Customer {$id}")->all())->select('id'))->count())->toBe(6);
});

test('switching company profile clears validation state and does not persist stale outlet values', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert(['id' => 2, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('name', 'Fleet after switch')
        ->set('company_id', 1)
        ->set('active.delivery_detail', 'invalid')
        ->call('saveCustomer')
        ->assertHasErrors(['active.delivery_detail'])
        ->set('company_id', 2)
        ->assertSet('active', [])
        ->assertHasNoErrors()
        ->set('name', 'Fleet after switch')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'Fleet after switch')->first();
    $profile = DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first();
    expect($profile->profile_type)->toBe('fleet')
        ->and(json_decode($profile->profile_data, true)['active'] ?? [])->toBe([]);
});

test('customer add page renders the scoped customer-create-form styling hook', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);

    $component = Livewire::test(CustomerCreatePage::class)
        ->assertOk()
        ->assertSee('customer-create-form', false);

    expect(substr_count($component->html(), '<form '))->toBe(1)
        ->and(substr_count($component->html(), '</form>'))->toBe(1);
});

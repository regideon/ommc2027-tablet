<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerEditPage;
use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
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

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'MM', 'psgc_code' => '130000000', 'name' => 'Metro Manila', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Legacy NCR', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Metro Manila', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Quezon City', 'sort' => 1, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'cluster-1', 'name' => 'Cluster 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('sync_states')->insert([
        ['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 3])],
        ['key' => 'landmass_options', 'value' => json_encode(['LUZON' => 'LUZON', 'MINDANAO' => 'MINDANAO', 'BASILAN' => 'BASILAN'])],
    ]);
    DB::table('general_categories')->insert(['id' => 1, 'name' => 'Mixed Outlet', 'priority_visit' => null, 'duration_per_visit' => null, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);

    foreach (['Metro Manila', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao'] as $index => $name) {
        DB::table('region_types')->insert(['id' => $index + 1, 'name' => $name, 'sort' => $index + 1, 'is_image_required' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    return User::factory()->create();
}

function validCustomerCreateState(): array
{
    $years = config('customer_trade_form.category_years');
    DB::table('region_specifics')->updateOrInsert(['id' => 52], ['region_id' => 1, 'name' => 'GMA', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->updateOrInsert(['id' => 52], ['region_specific_id' => 52, 'code' => 'cluster-52', 'name' => 'Cluster 52', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    return [
        'name' => 'Diagnostic Customer',
        'unique_id' => 'DIAG-001',
        'company_id' => 1,
        'region_specific_id' => 52,
        'customer_region_type_id' => 1,
        'physical_region_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'area_cluster_id' => 52,
        'general_category_id' => 1,
        'competitor_volume' => 2,
        'address' => '1 Diagnostic Street, Quezon City',
        'latitude' => '14.6500',
        'longitude' => '121.0500',
        'contact_person' => 'Diagnostic Contact',
        'business_landline_number' => '02-8000000',
        'business_mobile_number' => '09170000000',
        'date_established' => '2018-01-01',
        'landmass_code' => 'LUZON',
        'person_in_charge_id' => 1,
        'access_user_ids' => [1],
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
            'conversion_program' => 'For Conversion',
            'delivery_type' => 'no',
            'ulab' => 'GRC',
            'operating_hours' => ['start' => '08:00', 'end' => '17:00'],
            'owner' => ['name' => 'Owner', 'birthday' => '1970-01-01', 'nickname' => 'Owner', 'successor_name' => 'Successor', 'successor_birthday' => '2000-01-01', 'relationship' => 'Owner', 'generation' => '1st Gen', 'hobbies' => 'Reading'],
        ],
        'categories' => ['ab' => collect($years)->mapWithKeys(fn (int $year) => [$year => 'AB Loyal'])->all()],
    ];
}

function cacheCustomerAccessMapping(string $scope, string $key, array $users, bool $complete = true): void
{
    DB::table('customer_access_mappings')->insert([
        'scope' => $scope,
        'mapping_key' => $key,
        'is_complete' => $complete,
        'user_references' => json_encode($users, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function customerAccessIdsMatch(array $actual, array $expected): bool
{
    $actual = array_map('intval', $actual);
    $expected = array_map('intval', $expected);
    sort($actual);
    sort($expected);

    return $actual === $expected;
}

test('creator group snapshot resolves multiple email identities once and geography or Company changes do not replace Access or Person in Charge', function (): void {
    $actor = seedCustomerCreateFixtures();
    $actor->update(['name' => 'Auth User']);
    $first = User::factory()->create(['name' => 'First Assigned', 'email' => 'creator.first@example.test']);
    $second = User::factory()->create(['name' => 'Second Assigned', 'email' => 'creator.second@example.test']);
    $manual = User::factory()->create(['name' => 'Manual Choice', 'email' => 'creator.manual@example.test']);
    cacheCustomerAccessMapping('group', 'GROUP-A', [
        ['email' => $actor->email, 'name' => $actor->name],
        ['email' => $first->email, 'name' => $first->name],
    ]);
    cacheCustomerAccessMapping('group', 'GROUP-B', [
        ['email' => $actor->email, 'name' => $actor->name],
        ['email' => $second->email, 'name' => $second->name],
    ]);
    $this->actingAs($actor);
    $syncService = Mockery::mock(SyncService::class)->makePartial();
    $syncService->shouldReceive('reserveCustomerCode')->zeroOrMoreTimes()->andReturn(null);
    app()->instance(SyncService::class, $syncService);

    Livewire::test(CustomerCreatePage::class)
        ->assertSet('person_in_charge_id', $actor->id)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$actor->id, $first->id, $second->id]))
        ->set('access_user_ids', [$manual->id])
        ->set('person_in_charge_id', $manual->id)
        ->set('company_id', 1)
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manual->id])
        ->assertSet('person_in_charge_id', $manual->id)
        ->assertSee('First Assigned')
        ->assertSee('Second Assigned');
});

test('single complete creator group selects the creator and its assigned User', function (): void {
    $actor = seedCustomerCreateFixtures();
    $assigned = User::factory()->create(['name' => 'Single Group User', 'email' => 'creator.single@example.test']);
    cacheCustomerAccessMapping('group', 'SINGLE-GROUP', [
        ['email' => $actor->email, 'name' => $actor->name],
        ['email' => $assigned->email, 'name' => $assigned->name],
    ]);
    $this->actingAs($actor);

    Livewire::test(CustomerCreatePage::class)
        ->assertSet('access_user_ids', [$actor->id, $assigned->id])
        ->assertSet('accessMappingWarning', null);
});

test('unresolved offline group email preserves creator fallback, warns, and does not create a local User', function (): void {
    $actor = seedCustomerCreateFixtures();
    cacheCustomerAccessMapping('group', 'UNRESOLVED-GROUP', [
        ['email' => $actor->email, 'name' => $actor->name],
        ['email' => 'missing.identity@example.test', 'name' => 'Unknown Person'],
    ]);
    $this->actingAs($actor);

    Livewire::test(CustomerCreatePage::class)
        ->assertSet('access_user_ids', [$actor->id])
        ->assertSet('accessMappingWarning', 'One or more creator Access groups are incomplete or unavailable offline (UNRESOLVED-GROUP). Resolvable assigned Users were included; verify remaining memberships manually.')
        ->assertSee('verify remaining memberships manually');

    expect(User::query()->where('email', 'missing.identity@example.test')->exists())->toBeFalse();
});

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
        ->assertSee('wire:model.live="customer_region_type_id"', false)
        ->assertDontSee('wire:model.live="region_specific_id"', false)
        ->assertSee('wire:model.live="province_id"', false)
        ->assertSee('wire:model.live="municipality_id"', false)
        ->assertDontSee('wire:model.live="trade.entry_detail"', false)
        ->assertSee('wire:model="trade.classifications"', false)
        ->assertSee('x-model="$wire.categories[\'ab\'][2018]"', false)
        ->assertDontSee('x-model="$wire.categories.ab.2018"', false)
        ->assertSee('OMMC')
        ->assertSee('Metro Manila');

    $component->set('physical_region_id', 1)
        ->set('region_specific_id', 1)
        ->set('province_id', 1)
        ->assertSee('Metro Manila')
        ->assertSee('Quezon City');

    $component->set('physical_region_id', null)
        ->assertSet('region_specific_id', null)
        ->assertSet('province_id', null)
        ->assertSet('municipality_id', null);

    $state = $component->get('categories.ab');
    expect(array_keys($state))->toBe(range(2018, 2026));
    foreach (range(2018, 2026) as $year) {
        expect($state[$year])->toBeNull();
    }
});

test('Fleet annual category bindings require every available year on Add and preserve saved history on Edit', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'fleet-test-token'])->save();
    DB::table('companies')->insert(['id' => 4, 'name' => 'Fleet', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs($user);

    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => 'fleet-reservation-token', 'code' => 'FLEET90001']);
        }

        return Http::response([
            'server_id' => 99001,
            'local_uuid' => $request['local_uuid'],
            'unique_id' => 'FLEET90001',
            'updated_at' => now()->toISOString(),
        ]);
    });

    $completeState = array_replace_recursive(validCustomerCreateState(), [
        'company_id' => 4,
        'categories' => ['fleet' => collect(config('customer_trade_form.category_streams.fleet.years'))->mapWithKeys(fn (int $year): array => [$year => 'Motolite Only'])->all()],
        'active' => ['account_type' => 'existing', 'classifications' => 'Bus', 'battery_class' => 'maintenance_free', 'status' => 'active'],
    ]);
    $completeState['categories']['fleet'][2026] = 'Mixed';
    $add = Livewire::test(CustomerCreatePage::class)
        ->set($completeState)
        ->assertSee('FLEET Annual Categories')
        ->assertSee('x-model="$wire.categories[\'fleet\'][2018]"', false)
        ->assertSee('x-model="$wire.categories[\'fleet\'][2026]"', false)
        ->assertDontSee('x-model="$wire.categories.fleet.2018"', false)
        ->set('name', 'Fleet Annual Category Contract')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Fleet Annual Category Contract')->first();
    $histories = DB::table('customer_category_histories')->where('customer_id', $customer->id)->orderBy('category_year')->get();

    expect($histories)->toHaveCount(count(config('customer_trade_form.category_streams.fleet.years')))
        ->and($histories->first()->category)->toBe('Motolite Only')
        ->and($customer->server_id)->toBeNull()
        ->and($customer->sync_status)->toBe('pending');

    app(SyncService::class)->pushCustomer((int) $customer->id);

    $customer = DB::table('customers')->where('id', $customer->id)->first();

    expect($customer->server_id)->toBe(99001)
        ->and($customer->sync_status)->toBe('synced');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && collect($request['category_histories'])->pluck('stream')->unique()->all() === ['fleet']);

    $edit = Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customer->id])
        ->assertSee('x-model="$wire.categories[\'fleet\'][2018]"', false)
        ->assertSet('categories.fleet.2018', 'Motolite Only')
        ->assertSet('categories.fleet.2026', 'Mixed')
        ->set('categories.fleet.2026', 'Competitor')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customer_category_histories')->where('customer_id', $customer->id)->where('category_year', 2018)->value('category'))->toBe('Motolite Only')
        ->and(DB::table('customer_category_histories')->where('customer_id', $customer->id)->where('category_year', 2026)->value('category'))->toBe('Competitor');

    $localId = (int) $customer->id;
    app(SyncService::class)->pushCustomer($localId);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['sync_intent'] === 'update'
        && collect($request['category_histories'])->contains(fn (array $row): bool => (int) $row['category_year'] === 2018 && $row['category'] === 'Motolite Only')
        && collect($request['category_histories'])->contains(fn (array $row): bool => (int) $row['category_year'] === 2026 && $row['category'] === 'Competitor'));
});

test('Fleet Customer cannot save while visible annual category options are blank', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert(['id' => 4, 'name' => 'Fleet', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 4)
        ->assertSee('x-model="$wire.categories[\'fleet\'][2018]"', false)
        ->set('name', 'Fleet Empty Category Contract')
        ->call('saveCustomer')
        ->assertHasErrors();

    expect(DB::table('customers')->where('name', 'Fleet Empty Category Contract')->exists())->toBeFalse();
});

test('customer add page saves the complete aggregate locally and records a later push failure', function () {
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
        ->and($customer->sync_status)->toBe('pending')
        ->and($customer->sync_attempts)->toBe(0)
        ->and($customer->company_id)->toBe(1)
        ->and($customer->landmass_code)->toBe('LUZON')
        ->and($customer->region_specific_id)->toBe(52)
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

    app(SyncService::class)->pushCustomer((int) $customer->id);

    $customer = DB::table('customers')->where('id', $customer->id)->first();

    expect($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['customers'][0]['landmass_code'] === 'LUZON');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['general_category_id'] === 1
        && $request['region_specific_id'] === 52
        && $request['province_id'] === 1
        && $request['municipality_id'] === 1
        && $request['category_histories'][0]['stream'] === 'ab'
        && count($request['category_histories']) === 9);

    $edit = Livewire::test(CustomerEditPage::class, ['customerId' => $customer->id])
        ->assertOk()
        ->assertSee('wire:model.live="company_id"', false)
        ->assertSee('wire:model.live="physical_region_id"', false)
        ->assertSee('OMMC')
        ->assertDontSee('Legacy / unresolved Specific Region ID 1')
        ->assertSee('Quezon City');

    expect($edit->get('company_id'))->toBe(1)
        ->and($edit->get('region_specific_id'))->toBe(52)
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

test('Access is searchable and supports multiple local Users without changing the Add default', function () {
    $user = seedCustomerCreateFixtures();
    $otherUser = User::factory()->create(['name' => 'Searchable Access User']);
    $this->actingAs($user);

    $component = Livewire::test(CustomerCreatePage::class)
        ->assertSee('Search Access Users')
        ->assertSee('Search Users')
        ->assertSee('x-model="search"', false)
        ->assertSee('x-show="$el.textContent.toLowerCase().includes(search.toLowerCase())"', false)
        ->assertSee('wire:model="access_user_ids"', false)
        ->assertDontSee('<select wire:model="access_user_ids" multiple', false);

    expect($component->get('access_user_ids'))->toBe([$user->id]);

    $component->set('access_user_ids', [$user->id, $otherUser->id]);

    expect($component->get('access_user_ids'))->toBe([$user->id, $otherUser->id]);
});

test('Edit displays unavailable saved Company and Specific Region references without changing their IDs or Access', function () {
    $user = seedCustomerCreateFixtures();
    $manualUser = User::factory()->create(['email' => 'unavailable-reference-access@example.test']);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Unavailable Reference Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $manualUser->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('companies')->where('id', 1)->delete();
    DB::table('region_specifics')->where('id', 1)->delete();
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('company_id', 1)
        ->assertSet('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSee('Unavailable saved Company reference (ID 1)')
        ->assertSee('Legacy / unresolved Specific Region ID 1')
        ->assertSee('The saved Company reference is unavailable offline. Its stored value is being preserved.')
        ->assertSee('Legacy / unresolved Specific Region ID 1 is preserved on this Customer.');

    expect(DB::table('customers')->where('id', $customerId)->value('company_id'))->toBe(1)
        ->and(DB::table('customers')->where('id', $customerId)->value('region_specific_id'))->toBe(1)
        ->and(DB::table('customer_user')->where('customer_id', $customerId)->pluck('user_id')->all())->toBe([$manualUser->id]);
});

test('Customer Region lists only the five geographic types and filters offline Specific Regions by the approved crosswalk', function () {
    seedCustomerCreateFixtures();
    $now = now();

    foreach (['Metro Manila', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao', 'MCB Team', 'Fleet Team', 'IB Team', 'OE Team', 'MC Parts'] as $index => $name) {
        if (! DB::table('region_types')->where('id', $index + 1)->exists()) {
            DB::table('region_types')->insert([
                'id' => $index + 1,
                'name' => $name,
                'sort' => $index + 1,
                'is_image_required' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    foreach (range(2, 19) as $regionId) {
        DB::table('regions')->insert([
            'id' => $regionId,
            'code' => "R{$regionId}",
            'psgc_code' => str_pad((string) $regionId, 10, '0', STR_PAD_LEFT),
            'name' => $regionId === 6 ? 'Region III (Central Luzon)' : "Physical Region {$regionId}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $parents = [52 => 7, 53 => 7, 54 => 6, 55 => 16, 56 => 15, 57 => 14, 58 => 17, 59 => 18, 60 => 19, 61 => 5, 62 => 3, 63 => 4, 64 => 9, 65 => 8, 66 => 12, 67 => 13, 68 => 10];
    foreach ($parents as $specificId => $parentId) {
        DB::table('region_specifics')->insert([
            'id' => $specificId,
            'region_id' => $parentId,
            'name' => $specificId === 54 ? 'Region III (Central Luzon)' : "Specific Region {$specificId}",
            'sort' => $specificId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    DB::table('area_clusters')->insert([
        ['id' => 201, 'region_specific_id' => 54, 'code' => 'NL-1', 'name' => 'NL Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 202, 'region_specific_id' => 52, 'code' => 'GMA-1', 'name' => 'GMA Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $component = Livewire::test(CustomerCreatePage::class);
    foreach (['Metro Manila', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao'] as $name) {
        $component->assertSee($name);
    }
    foreach (['MCB Team', 'Fleet Team', 'IB Team', 'OE Team', 'MC Parts'] as $name) {
        $component->assertDontSee($name);
    }

    foreach ([
        1 => ['Physical Region 7'],
        2 => ['Region III (Central Luzon)', 'Physical Region 3', 'Physical Region 4', 'Physical Region 5'],
        3 => ['Physical Region 7', 'Physical Region 8', 'Physical Region 9'],
        4 => ['Physical Region 10', 'Physical Region 12', 'Physical Region 13'],
        5 => ['Physical Region 14', 'Physical Region 15', 'Physical Region 16', 'Physical Region 17', 'Physical Region 18', 'Physical Region 19'],
    ] as $typeId => $expectedRegions) {
        $component->set('customer_region_type_id', $typeId);
        foreach ($expectedRegions as $expectedRegion) {
            $component->assertSee($expectedRegion);
        }
        $component->assertDontSee('Physical Region 2');
    }

    $component->set('customer_region_type_id', 2)
        ->assertSee('Region III (Central Luzon)')
        ->assertDontSee('wire:model.live="region_specific_id"', false)
        ->set('physical_region_id', 6)
        ->assertSet('region_specific_id', 54)
        ->assertSee('NL Area 1');
});

test('Tablet map resolves a selected pin once when the Human confirms it', function (): void {
    $contents = (string) file_get_contents(resource_path('views/filament/pages/customer-create-page.blade.php'));
    $coordinateSetter = substr($contents, strpos($contents, 'setCoordinates(latitude, longitude)'), 600);

    expect($coordinateSetter)->not->toContain('$wire.resolveLocation')
        ->and($contents)->toContain('async confirmLocation()')
        ->and($contents)->toContain('await this.$wire.resolveLocation(coordinates[0], coordinates[1])');
});

test('Tablet map applies provider display addresses, preserves geography, replaces repeats, and reports partial responses', function (): void {
    seedCustomerCreateFixtures();
    Http::fakeSequence('nominatim.openstreetmap.org/*')
        ->push(['display_name' => 'Maria Drive, Barandal, City of Calamba, Laguna, CALABARZON, Philippines', 'address' => ['road' => 'Maria Drive', 'suburb' => 'Barandal', 'city' => 'City of Calamba', 'county' => 'Laguna', 'state' => 'CALABARZON']], 200)
        ->push(['display_name' => '2 New Street, City of Calamba, Laguna, Philippines', 'address' => ['house_number' => '2', 'road' => 'New Street', 'city' => 'City of Calamba', 'county' => 'Laguna', 'state' => 'CALABARZON']], 200)
        ->push(['address' => ['road' => 'Partial Lane']], 200)
        ->push(['address' => []], 200);

    $page = Livewire::test(CustomerCreatePage::class)
        ->set('region_specific_id', 1)
        ->set('physical_region_id', 1)
        ->set('province_id', 1)
        ->set('municipality_id', 1)
        ->set('address', 'Old address')
        ->call('resolveLocation', 14.2, 121.2)
        ->assertSet('address', 'Maria Drive, Barandal, City of Calamba, Laguna, CALABARZON, Philippines')
        ->assertSet('region_specific_id', 1)
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->assertSet('locationError', null)
        ->call('resolveLocation', 14.3, 121.3)
        ->assertSet('address', '2 New Street, City of Calamba, Laguna, Philippines')
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->call('resolveLocation', 14.4, 121.4)
        ->assertSet('address', 'Partial Lane')
        ->assertSet('locationError', 'The provider display address was unavailable or exceeded the 500-character field limit; only available details were filled.')
        ->call('resolveLocation', 14.5, 121.5)
        ->assertSet('address', 'Partial Lane')
        ->assertSet('locationError', 'The provider display address was unavailable or exceeded the 500-character field limit; only available details were filled.');

    $page->set('address', 'Manually edited address')
        ->assertSet('address', 'Manually edited address');
});

test('legacy Specific Region 1 is labeled unresolved and remains saved after Edit', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake(['*api/sync/push/customer' => Http::response(['message' => 'Offline'], 503)]);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Legacy NCR Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $edit = Livewire::test(CustomerEditPage::class, ['customerId' => $customerId])
        ->assertSet('region_specific_id', 1)
        ->assertSet('customer_region_type_id', null)
        ->assertSee('Legacy / unresolved Specific Region ID 1 is preserved on this Customer.');

    $edit->call('saveCustomer')->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('id', $customerId)->value('region_specific_id'))->toBe(1);
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
    Http::fake(['*api/sync/push/customer' => function ($request) {
        return Http::response(['server_id' => 100, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()], 200);
    }]);

    Livewire::test(CustomerCreatePage::class)
        ->set(validCustomerCreateState())
        ->set('person_in_charge_id', $pic->id)
        ->set('access_user_ids', [$user->id, $pic->id])
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    app(SyncService::class)->pushCustomer((int) DB::table('customers')->where('name', 'Diagnostic Customer')->value('id'));

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
        ->assertSet('area_cluster_id', 2);

    expect($component->html())
        ->not->toContain('Commercial Region 2')
        ->toContain('Cluster 2')
        ->toContain('Metro Manila');
});

test('unselected Customer Region does not offer legacy Specific Region 1', function () {
    $user = seedCustomerCreateFixtures();
    $mappedUser = User::factory()->create(['email' => 'mapped.gma@example.test']);
    DB::table('regions')->insert(['id' => 2, 'code' => 'R2', 'psgc_code' => '020000000', 'name' => 'Region 2', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 2, 'name' => 'Specific Region 2', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 2, 'code' => 'cluster-2', 'name' => 'Cluster 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $mappedUser->email, 'name' => $mappedUser->name]]);
    $this->actingAs($user);

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 1)
        ->assertDontSee('NCR')
        ->assertDontSee('Specific Region 2')
        ->set('region_specific_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('area_cluster_id', 1);

    $component->set('access_user_ids', [$user->id])
        ->assertSet('access_user_ids', [$user->id]);
});

test('same and repeated Add controller updates preserve manual Access while real changes use complete mappings', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert([
        ['id' => 2, 'name' => 'OE', 'code' => 'OE', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'IB', 'code' => 'IB', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('region_specifics')->insert([
        ['id' => 2, 'region_id' => 1, 'name' => 'Complete Destination', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_id' => 1, 'name' => 'Incomplete Destination', 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $geographicUser = User::factory()->create(['email' => 'same-geographic@example.test']);
    $oeUser = User::factory()->create(['email' => 'same-oe@example.test']);
    $ibUser = User::factory()->create(['email' => 'same-ib@example.test']);
    $manualUser = User::factory()->create(['email' => 'same-manual@example.test']);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $user->email, 'name' => $user->name]]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $geographicUser->email, 'name' => $geographicUser->name]]);
    cacheCustomerAccessMapping('region_specific', '3', [['email' => $geographicUser->email, 'name' => $geographicUser->name]], false);
    cacheCustomerAccessMapping('company', 'OE', [['email' => $oeUser->email, 'name' => $oeUser->name]]);
    cacheCustomerAccessMapping('company', 'IB', [['email' => $ibUser->email, 'name' => $ibUser->name]]);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$user->id])
        ->set('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('access_user_ids', [])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', [])
        ->set('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 3)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('company_id', 2)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('access_user_ids', [$manualUser->id])
        ->set('company_id', 2)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('company_id', 3)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('access_user_ids', [$manualUser->id])
        ->set('company_id', 3)
        ->assertSet('access_user_ids', [$manualUser->id]);
});

test('Add controller changes retain the authenticated User, deduplicate its mapping, and remove prior mapped Users', function () {
    $actor = seedCustomerCreateFixtures();
    DB::table('region_specifics')->insert([
        ['id' => 2, 'region_id' => 1, 'name' => 'SL Destination', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_id' => 1, 'name' => 'NL Destination', 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('area_clusters')->insert([
        ['id' => 2, 'region_specific_id' => 2, 'code' => 'sl-cluster', 'name' => 'SL Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_specific_id' => 3, 'code' => 'nl-cluster', 'name' => 'NL Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $slUser = User::factory()->create(['email' => 'mapped.sl-transition@example.test']);
    $nlUser = User::factory()->create(['email' => 'mapped.nl-transition@example.test']);
    $manualUser = User::factory()->create(['email' => 'manual.transition@example.test']);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $actor->email, 'name' => $actor->name]]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $slUser->email, 'name' => $slUser->name]]);
    cacheCustomerAccessMapping('region_specific', '3', [['email' => $nlUser->email, 'name' => $nlUser->name]]);
    $this->actingAs($actor);

    Livewire::test(CustomerCreatePage::class)
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$actor->id])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', [$actor->id])
        ->set('access_user_ids', [$actor->id, $slUser->id, $manualUser->id])
        ->set('region_specific_id', 3)
        ->assertSet('access_user_ids', [$actor->id, $slUser->id, $manualUser->id]);
});

test('Edit hydration and same or repeated Specific Region updates preserve manual Access', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert([
        ['id' => 2, 'name' => 'OE', 'code' => 'OE', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'IB', 'code' => 'IB', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('region_specifics')->insert([
        ['id' => 2, 'region_id' => 1, 'name' => 'Complete Edit Destination', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_id' => 1, 'name' => 'Incomplete Edit Destination', 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $mappedUser = User::factory()->create(['email' => 'same-edit-mapped@example.test']);
    $manualUser = User::factory()->create(['email' => 'same-edit-manual@example.test']);
    $oeUser = User::factory()->create(['email' => 'same-edit-oe@example.test']);
    $partialCompanyUser = User::factory()->create(['email' => 'same-edit-partial-company@example.test']);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $user->email, 'name' => $user->name]]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $mappedUser->email, 'name' => $mappedUser->name]]);
    cacheCustomerAccessMapping('region_specific', '3', [['email' => $mappedUser->email, 'name' => $mappedUser->name]], false);
    cacheCustomerAccessMapping('company', 'OE', [['email' => $oeUser->email, 'name' => $oeUser->name]]);
    cacheCustomerAccessMapping('company', 'IB', [['email' => $partialCompanyUser->email, 'name' => $partialCompanyUser->name]], false);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Same Value Edit Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $manualUser->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$manualUser->id, $user->id, $mappedUser->id]))
        ->set('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('region_specific_id', 3)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('company_id', 2)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$manualUser->id, $user->id, $oeUser->id]))
        ->set('access_user_ids', [$manualUser->id])
        ->set('company_id', 2)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->set('company_id', 3)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', 'Customer Access mapping is missing or incomplete offline. Existing Access is preserved.');
});

test('multiple enabled Area Clusters are filtered by Specific Region without automatic selection', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 1, 'name' => 'Specific Region 2', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert([
        ['id' => 2, 'region_specific_id' => 1, 'code' => 'cluster-1b', 'name' => 'Cluster 1B', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_specific_id' => 2, 'code' => 'cluster-2', 'name' => 'Cluster 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 1)
        ->set('region_specific_id', 1)
        ->assertSet('area_cluster_id', null)
        ->assertSee('Cluster 1')
        ->assertSee('Cluster 1B')
        ->assertDontSee('Cluster 2');
});

test('incomplete offline mapping preserves the current Add Access selection and displays a warning', function () {
    $user = seedCustomerCreateFixtures();
    $partialUser = User::factory()->create(['email' => 'partial.mapping@example.test']);
    cacheCustomerAccessMapping('group', 'PARTIAL', [['email' => $user->email, 'name' => $user->name], ['email' => $partialUser->email, 'name' => $partialUser->name]], false);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('physical_region_id', 1)
        ->set('region_specific_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->assertSet('access_user_ids', [$user->id, $partialUser->id])
        ->assertSet('accessMappingWarning', 'One or more creator Access groups are incomplete or unavailable offline (PARTIAL). Resolvable assigned Users were included; verify remaining memberships manually.')
        ->assertSee('verify remaining memberships manually');
});

test('Add form shows no mapping warning without a controller when the mapping cache table is absent', function () {
    $user = seedCustomerCreateFixtures();
    Schema::drop('customer_access_mappings');
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('region_specific_id', null)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', 'Creator Access group references are unavailable offline. The creator is selected; Access can be adjusted manually.')
        ->assertSee('Access can be adjusted manually.');
});

test('Add form shows no mapping warning without a controller when the cache has only unrelated incomplete mappings', function () {
    $user = seedCustomerCreateFixtures();
    cacheCustomerAccessMapping('region_specific', '999', [], false);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('region_specific_id', null)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.')
        ->assertSee('Access can be adjusted manually.');
});

test('Add form preserves manual Access and reports a missing cache when a geographic controller is selected', function () {
    $user = seedCustomerCreateFixtures();
    Schema::drop('customer_access_mappings');
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('region_specific_id', 1)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', 'Creator Access group references are unavailable offline. The creator is selected; Access can be adjusted manually.');
});

test('Edit hydration shows no mapping warning without a controller when the mapping cache table is absent', function () {
    $user = seedCustomerCreateFixtures();
    $manualUser = User::factory()->create(['email' => 'no-controller-edit-manual@example.test']);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'No Controller Customer',
        'company_id' => 1,
        'region_specific_id' => null,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $manualUser->id, 'created_at' => now(), 'updated_at' => now()]);
    Schema::drop('customer_access_mappings');
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('region_specific_id', null)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', null)
        ->assertDontSee('Customer Access mappings are unavailable offline.');
});

test('Edit hydration shows no mapping warning without a controller when cached mappings are incomplete', function () {
    $user = seedCustomerCreateFixtures();
    $manualUser = User::factory()->create(['email' => 'no-controller-incomplete-edit-manual@example.test']);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'No Controller Incomplete Mapping Customer',
        'company_id' => 1,
        'region_specific_id' => null,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $manualUser->id, 'created_at' => now(), 'updated_at' => now()]);
    cacheCustomerAccessMapping('region_specific', '999', [], false);
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('region_specific_id', null)
        ->assertSet('access_user_ids', [$manualUser->id])
        ->assertSet('accessMappingWarning', null)
        ->assertDontSee('mapping is missing or incomplete offline');
});

test('OE and IB Company selections resolve their cached mappings by Company code', function (string $code, string $profile) {
    $user = seedCustomerCreateFixtures();
    $companyId = 2;
    DB::table('companies')->insert(['id' => $companyId, 'name' => $code, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    $mappedUser = User::factory()->create(['email' => "mapped.{$code}@example.test"]);
    cacheCustomerAccessMapping('company', $code, [['email' => $mappedUser->email, 'name' => $mappedUser->name]]);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', $companyId)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.')
        ->assertSee(ucfirst($profile).' Profile');
})->with([
    ['OE', 'oe'],
    ['IB', 'ib'],
]);

test('Add form preserves manual Access and warns for incomplete OE and IB Company mappings', function (string $code) {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert(['id' => 2, 'name' => $code, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    $partialUser = User::factory()->create(['email' => "partial.{$code}@example.test"]);
    cacheCustomerAccessMapping('company', $code, [['email' => $partialUser->email, 'name' => $partialUser->name]], false);
    $this->actingAs($user);

    Livewire::test(CustomerCreatePage::class)
        ->set('access_user_ids', [$partialUser->id])
        ->set('company_id', 2)
        ->assertSet('access_user_ids', [$partialUser->id])
        ->assertSet('accessMappingWarning', 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.');
})->with(['OE', 'IB']);

test('editing an unchanged Specific Region preserves manual Access during mapping hydration', function () {
    $user = seedCustomerCreateFixtures();
    $mappedUser = User::factory()->create(['email' => 'mapped.edit@example.test']);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $mappedUser->email, 'name' => $mappedUser->name]]);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Existing Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('region_specific_id', 1)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', null);

    expect(DB::table('customer_user')->where('customer_id', $customerId)->pluck('user_id')->all())->toBe([$user->id]);
});

test('editing a changed Specific Region replaces Access only for a complete offline mapping', function () {
    $user = seedCustomerCreateFixtures();
    $mappedUser = User::factory()->create(['email' => 'mapped.changed@example.test']);
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 1, 'name' => 'Specific Region 2', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 2, 'code' => 'cluster-2', 'name' => 'Cluster 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $mappedUser->email, 'name' => $mappedUser->name]]);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Existing Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);

    $component = Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->set('region_specific_id', 2)
        ->assertSet('area_cluster_id', 2)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$user->id, $mappedUser->id]))
        ->assertSet('accessMappingWarning', null);
    $component->call('saveCustomer')->assertHasNoErrors();

    expect(DB::table('customer_user')->where('customer_id', $customerId)->pluck('user_id')->all())
        ->toContain($user->id, $mappedUser->id)
        ->and(DB::table('customer_user')->where('customer_id', $customerId)->count())->toBe(2);
});

test('Edit saves the authenticated User and destination mapping, preserves manual Access, and removes the previous mapping', function () {
    $actor = seedCustomerCreateFixtures();
    DB::table('region_specifics')->insert([
        ['id' => 2, 'region_id' => 1, 'name' => 'Edit SL Destination', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('area_clusters')->insert([
        ['id' => 2, 'region_specific_id' => 2, 'code' => 'edit-sl-cluster', 'name' => 'Edit SL Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $oldMapped = User::factory()->create(['email' => 'edit-old-mapping@example.test']);
    $newMapped = User::factory()->create(['email' => 'edit-new-mapping@example.test']);
    $manual = User::factory()->create(['email' => 'edit-manual-access@example.test']);
    cacheCustomerAccessMapping('region_specific', '1', [['email' => $oldMapped->email, 'name' => $oldMapped->name]]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $newMapped->email, 'name' => $newMapped->name]]);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Mapped Edit Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert([
        ['customer_id' => $customerId, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
        ['customer_id' => $customerId, 'user_id' => $oldMapped->id, 'created_at' => now(), 'updated_at' => now()],
        ['customer_id' => $customerId, 'user_id' => $manual->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($actor);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->set('region_specific_id', 2)
        ->assertSet('area_cluster_id', 2)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$actor->id, $manual->id, $newMapped->id]))
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $persisted = DB::table('customer_user')->where('customer_id', $customerId)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    sort($persisted);
    expect($persisted)->toBe([$actor->id, $newMapped->id, $manual->id])
        ->and(in_array($oldMapped->id, $persisted, true))->toBeFalse()
        ->and(DB::table('customer_user')->where('customer_id', $customerId)->count())->toBe(3);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->assertSet('region_specific_id', 2)
        ->assertSet('access_user_ids', fn (array $ids): bool => customerAccessIdsMatch($ids, [$actor->id, $manual->id, $newMapped->id]));
});

test('editing a changed Specific Region preserves Access and warns when offline mapping is incomplete', function () {
    $user = seedCustomerCreateFixtures();
    $partialUser = User::factory()->create(['email' => 'partial.edit@example.test']);
    DB::table('region_specifics')->insert(['id' => 2, 'region_id' => 1, 'name' => 'Specific Region 2', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    cacheCustomerAccessMapping('region_specific', '2', [['email' => $partialUser->email, 'name' => $partialUser->name]], false);
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Existing Customer',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 1,
        'municipality_id' => 1,
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert(['customer_id' => $customerId, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => (string) $customerId])
        ->set('region_specific_id', 2)
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('accessMappingWarning', 'Customer Access mapping is missing or incomplete offline. Existing Access is preserved.')
        ->assertSee('Existing Access is preserved.');

    expect(DB::table('customer_user')->where('customer_id', $customerId)->pluck('user_id')->all())->toBe([$user->id]);
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
        ->assertSee('Legacy / unresolved Specific Region ID 1')
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

test('editing a non-outlet Customer accepts Access users without RSM and preserves its hidden PIC relationship', function () {
    $viewer = seedCustomerCreateFixtures();
    $now = now();
    DB::table('companies')->insert(['id' => 4, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => $now, 'updated_at' => $now]);
    $access = User::factory()->create(['rsm_id' => null]);
    $pic = User::factory()->create();
    $customer = new Customer;
    $customer->id = -45;
    $customer->fill([
        'name' => 'Fleet edit customer',
        'company_id' => 4,
        'person_in_charge_id' => $pic->id,
        'is_active' => true,
        'sync_status' => 'synced',
    ]);
    $customer->save();
    $customer->tradeProfile()->create(['profile_type' => 'fleet', 'profile_data' => ['active' => []]]);
    $customer->users()->attach($access->id);
    $this->actingAs($viewer);

    Livewire::test(CustomerEditPage::class, ['customerId' => -45])
        ->set('name', 'Edited Fleet customer')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    expect(Customer::findOrFail(-45)->person_in_charge_id)->toBe($pic->id)
        ->and(DB::table('customer_user')->where('customer_id', -45)->where('user_id', $access->id)->exists())->toBeTrue()
        ->and(DB::table('customers')->where('id', -45)->value('name'))->toBe('Edited Fleet customer')
        ->and($access->fresh()->rsm_id)->toBeNull();
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

test('customer add requires Warehouse Code when MOTIV User is enabled and displays its field error', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['trade' => ['motiv_user' => true]]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.warehouse_code']);

    expect($component->html())->toContain('Warehouse Code <span class="text-danger-600" data-required-indicator="true"')
        ->and($component->html())->toContain('field is required');
    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeFalse();
});

test('customer add requires Delivery Detail when Delivery Type is Yes and displays its field error', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['delivery_type' => 'yes']]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.delivery_detail']);

    expect($component->html())->toContain('Delivery Detail <span class="text-danger-600" data-required-indicator="true"')
        ->and($component->html())->toContain('field is required');
    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeFalse();
    expect(DB::table('customer_trade_profiles')->count())->toBe(0)
        ->and(DB::table('customer_category_histories')->count())->toBe(0)
        ->and(DB::table('customer_user')->count())->toBe(0);
});

test('customer add validates supplied Warehouse Code and Delivery Detail values without conditional requiredness', function () {
    $user = seedCustomerCreateFixtures();
    $user->forceFill(['api_token' => 'tablet-token'])->save();
    $this->actingAs($user);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/push/customer')) {
            return Http::response(['server_id' => 100, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()], 200);
        }

        return Http::response(['server_id' => 100, 'updated_at' => now()->toISOString()], 200);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), [
            'trade' => ['motiv_user' => true],
            'active' => ['warehouse_code' => 'WH-123', 'delivery_type' => 'yes', 'delivery_detail' => 'own_delivery'],
        ]))
        ->assertSee('Warehouse Code', false)
        ->assertSee('Warehouse Code <span class="text-danger-600" data-required-indicator="true"', false)
        ->assertSee('Delivery Detail <span class="text-danger-600" data-required-indicator="true"', false)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'Diagnostic Customer')->first();
    $active = json_decode(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'), true)['active'];
    expect($active['warehouse_code'])->toBe('WH-123')
        ->and($active['delivery_detail'])->toBe('own_delivery');

    app(SyncService::class)->pushCustomer((int) $customer->id);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && data_get($request->data(), 'profile_data.active.warehouse_code') === 'WH-123'
        && data_get($request->data(), 'profile_data.active.delivery_detail') === 'own_delivery');
});

test('customer add does not show or require conditional fields when they are inapplicable', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set(validCustomerCreateState())
        ->assertDontSee('Warehouse Code')
        ->assertDontSee('Delivery Detail')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeTrue();
});

test('customer add rejects malformed populated Warehouse Code and Delivery Detail values', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['delivery_type' => 'yes', 'delivery_detail' => 'invalid']]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.delivery_detail']);

    Livewire::test(CustomerCreatePage::class)
        ->set(array_replace_recursive(validCustomerCreateState(), ['active' => ['warehouse_code' => str_repeat('x', 256)]]))
        ->call('saveCustomer')
        ->assertHasErrors(['active.warehouse_code']);

    expect(DB::table('customers')->where('name', 'Diagnostic Customer')->exists())->toBeFalse();
});

test('customer creation blocks incomplete Name and Company-only submissions for every mapped company profile', function () {
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
            ->assertHasErrors(['general_category_id', 'address', 'latitude', 'longitude', 'contact_person', 'business_landline_number', 'business_mobile_number', 'date_established']);
    }

    expect(DB::table('customers')->whereIn('name', collect(range(1, 6))->map(fn ($id) => "Minimal Customer {$id}")->all())->count())->toBe(0);
});

test('switching company profile clears validation state and does not persist stale outlet values', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert(['id' => 2, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('name', 'Fleet after switch')
        ->set('company_id', 1)
        ->set('active.delivery_type', 'yes')
        ->set('active.delivery_detail', 'invalid')
        ->call('saveCustomer')
        ->assertHasErrors(['active.delivery_detail'])
        ->set('company_id', 2)
        ->assertSet('active', [])
        ->assertHasNoErrors()
        ->set('name', 'Fleet after switch')
        ->call('saveCustomer')
        ->assertHasErrors(['general_category_id', 'address', 'latitude', 'longitude']);

    expect(DB::table('customers')->where('name', 'Fleet after switch')->exists())->toBeFalse();
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

test('Customer Add required indicators follow available options and conditional profile state', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    validCustomerCreateState();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('customer_region_type_id', 1)
        ->set('physical_region_id', 1)
        ->set('general_category_id', 1)
        ->set('trade.entry_detail', 'AB')
        ->assertSet('access_user_ids', [$user->id])
        ->assertSet('person_in_charge_id', $user->id);
    $html = $component->html();

    expect($html)
        ->toContain('Store Name <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Active <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Latitude <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Longitude <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('General Category <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Competitor Volume <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Region <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Specific Region <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Area Cluster <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Province <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Name of Owner <span class="text-danger-600" data-required-indicator="true"')
        ->toContain('Landmass (optional)</label>')
        ->toContain('Customer Code</label>')
        ->not->toContain('Landmass (optional) <span')
        ->not->toContain('Customer Code <span');

    $component->set('customer_region_type_id', 4);
    $emptySpecificHtml = $component->html();
    expect($emptySpecificHtml)
        ->toContain('Specific Region </label>')
        ->not->toContain('Specific Region <span class="text-danger-600" data-required-indicator="true"')
        ->not->toContain('Area Cluster <span class="text-danger-600" data-required-indicator="true"');

    $component->set('general_category_id', null);
    expect($component->html())->not->toContain('Competitor Volume');

    DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->update([
        'value' => json_encode(['completed_at' => now()->toISOString(), 'reference_contract_version' => 2]),
    ]);
    $referenceFailureHtml = Livewire::test(CustomerCreatePage::class)->html();
    expect($referenceFailureHtml)
        ->toContain('Location reference options are unavailable or out of date.')
        ->toContain('Specific Region <span class="text-danger-600" data-required-indicator="true"');
});

test('Customer Edit does not show Add-only required indicators on optional location fields', function () {
    $user = seedCustomerCreateFixtures();
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Indicator Edit Customer',
        'company_id' => 1,
        'unique_id' => 'EDIT-INDICATOR-1',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_trade_profiles')->insert([
        'customer_id' => $customerId,
        'profile_type' => 'outlet',
        'profile_data' => json_encode(['active' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $html = Livewire::test(CustomerEditPage::class, ['customerId' => $customerId])->html();

    expect($html)
        ->toContain('Address </label>')
        ->not->toContain('Address <span class="text-danger-600" data-required-indicator="true"');
});

test('Customer Add hides empty profile section and preserves Owner Profile across company profile changes', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert([
        ['id' => 2, 'name' => 'Unmapped Company', 'code' => 'UNMAPPED', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Fleet Company', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->assertDontSee('Customer Profile')
        ->assertSee('Owner Profile')
        ->set('active.owner.name', 'Shared Owner');

    $component->set('company_id', 2)
        ->assertDontSee('Customer Profile')
        ->assertDontSee('Outlet Profile')
        ->assertSee('Owner Profile');

    $component->set('company_id', 1)
        ->assertSee('Outlet Profile')
        ->assertSee('Owner Profile')
        ->assertSet('active.owner.name', 'Shared Owner')
        ->set('trade.motiv_user', true)
        ->set('active.warehouse_code', 'STALE-OUTLET-VALUE')
        ->set('active.delivery_type', 'yes')
        ->set('active.delivery_detail', 'stale-invalid-value')
        ->set('company_id', 3)
        ->assertDontSee('Outlet Profile')
        ->assertSee('Fleet Profile')
        ->assertSet('active.warehouse_code', null)
        ->assertSet('active.delivery_detail', null)
        ->assertSet('active.owner.name', 'Shared Owner')
        ->call('saveCustomer')
        ->assertHasNoErrors(['active.warehouse_code', 'active.delivery_detail']);
});

test('Tablet Add validates complete and incomplete forms for every Company code in the authoritative profile map', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('barangays')->insert([
        'id' => 1, 'municipality_id' => 1, 'psgc_code' => 'ASSURE-BRG-1', 'code' => 'ASSURE-1',
        'name' => 'Assurance Barangay', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyMap = config('customer_trade_form.company_profile_map');
    $nextCompanyId = (int) DB::table('companies')->max('id') + 1;
    $companyIds = [];
    foreach ($companyMap as $code => $profile) {
        $existingId = DB::table('companies')->where('code', $code)->value('id');
        if ($existingId === null) {
            $existingId = $nextCompanyId++;
            DB::table('companies')->insert(['id' => $existingId, 'name' => str_replace('_', ' ', $code), 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        }
        $companyIds[$code] = (int) $existingId;
    }
    $this->actingAs($user);
    Http::fake();

    $profileState = function (string $profile, string $code) use ($companyIds): array {
        $state = validCustomerCreateState();
        $state['name'] = 'Assurance '.$code;
        $state['unique_id'] = 'ASSURE-'.$code;
        $state['company_id'] = $companyIds[$code] ?? null;
        $state['barangay_id'] = 1;

        if ($profile === 'outlet') {
            return $state;
        }

        $years = config("customer_trade_form.category_streams.{$profile}.years", []);
        $options = config("customer_trade_form.profile_category_options.{$profile}", []);
        $category = array_values(is_array(reset($options)) ? reset($options) : $options)[0] ?? null;
        $state['categories'] = [$profile => array_fill_keys($years, $category)];
        $state['trade'] = $profile === 'oe' ? ['entry_detail' => 'AB'] : [];
        $state['active'] = ['owner' => $state['active']['owner']];

        $state['active'] = array_replace($state['active'], match ($profile) {
            'fleet' => ['account_type' => 'existing', 'classifications' => array_values(config('customer_trade_form.profile_classifications.fleet'))[0], 'battery_class' => 'maintenance_free', 'status' => 'active'],
            'oe' => ['classifications' => array_values(config('customer_trade_form.profile_classifications.oe'))[0], 'battery_class' => 'maintenance_free'],
            'ib' => ['classifications' => array_values(config('customer_trade_form.profile_classifications.ib'))[0]],
        });

        return $state;
    };

    foreach ($companyMap as $code => $profile) {
        $state = $profileState($profile, $code);
        $component = Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->assertSee(ucfirst($profile).' Profile')
            ->assertSee('Owner Profile')
            ->assertSee(match ($profile) {
                'outlet', 'oe' => 'Entry Detail <span class="text-danger-600" data-required-indicator="true"',
                'fleet' => 'Type <span class="text-danger-600" data-required-indicator="true"',
                'ib' => 'Classification <span class="text-danger-600" data-required-indicator="true"',
            }, false)
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertRedirect(CustomerPage::getUrl());

        $saved = DB::table('customers')->where('name', 'Assurance '.$code)->first();
        expect($saved)->not->toBeNull()
            ->and(DB::table('customer_trade_profiles')->where('customer_id', $saved->id)->value('profile_type'))->toBe($profile);
    }

    foreach ($companyMap as $code => $profile) {
        $state = $profileState($profile, $code);
        $missingField = match ($profile) {
            'outlet', 'oe' => 'trade.entry_detail',
            'fleet' => 'active.account_type',
            'ib' => 'active.classifications',
        };
        data_forget($state, $missingField);
        $state['name'] = 'Missing profile field '.$code;
        Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->call('saveCustomer')
            ->assertHasErrors([$missingField]);
        expect(DB::table('customers')->where('name', 'Missing profile field '.$code)->exists())->toBeFalse();
    }

    $sharedRequiredFields = [
        'name', 'company_id', 'access_user_ids', 'person_in_charge_id',
        'general_category_id', 'competitor_volume', 'customer_region_type_id', 'physical_region_id',
        'area_cluster_id', 'province_id', 'municipality_id', 'barangay_id', 'address', 'latitude', 'longitude',
        'contact_person', 'business_landline_number', 'business_mobile_number', 'date_established',
        'active.owner.name', 'active.owner.birthday', 'active.owner.nickname',
        'active.owner.successor_name', 'active.owner.successor_birthday', 'active.owner.relationship',
        'active.owner.generation', 'active.owner.hobbies',
    ];
    $profileRequiredFields = [
        'outlet' => [
            'trade.entry_detail', 'trade.classifications', 'trade.working_days', 'trade.motiv_user',
            'active.conversion_program', 'active.operating_hours.start', 'active.operating_hours.end',
            'active.delivery_type', 'active.ulab',
            ...array_map(fn (int $year): string => "categories.ab.{$year}", config('customer_trade_form.category_streams.outlet.years')),
        ],
        'fleet' => [
            'active.account_type', 'active.classifications', 'active.battery_class', 'active.status',
            ...array_map(fn (int $year): string => "categories.fleet.{$year}", config('customer_trade_form.category_streams.fleet.years')),
        ],
        'oe' => [
            'trade.entry_detail', 'active.classifications', 'active.battery_class', 'active.sulfuric_acid',
            ...array_map(fn (int $year): string => "categories.oe.{$year}", config('customer_trade_form.category_streams.oe.years')),
        ],
        'ib' => [
            'active.classifications',
            ...array_map(fn (int $year): string => "categories.ib.{$year}", config('customer_trade_form.category_streams.ib.years')),
        ],
    ];

    $outletMcbRequiredFields = array_map(
        fn (int $year): string => "categories.mcb.{$year}",
        config('customer_trade_form.category_streams.outlet.years'),
    );

    foreach ($companyMap as $code => $profile) {
        $fields = [...$sharedRequiredFields, ...$profileRequiredFields[$profile]];

        if ($profile === 'outlet') {
            $fields = [...$fields, ...$outletMcbRequiredFields];
        }

        if ($profile === 'outlet') {
            $fields[] = 'active.warehouse_code';
            $fields[] = 'active.delivery_detail';
        }

        if ($profile === 'oe') {
            $fields[] = 'active.sulfuric_acid';
        }

        foreach ($fields as $field) {
            $state = $profileState($profile, $code);
            $state['barangay_id'] = 1;

            if ($profile === 'outlet') {
                data_set($state, 'trade.motiv_user', true);
                data_set($state, 'active.warehouse_code', 'ASSURANCE-WAREHOUSE');
                data_set($state, 'active.delivery_type', 'yes');
                data_set($state, 'active.delivery_detail', 'own_delivery');

                if (str_starts_with($field, 'categories.mcb.')) {
                    data_set($state, 'trade.entry_detail', 'MCB');
                    unset($state['categories']['ab']);
                    $state['categories']['mcb'] = array_fill_keys(
                        config('customer_trade_form.category_streams.outlet.years'),
                        config('customer_trade_form.profile_category_options.outlet.mcb')[0],
                    );
                }
            }

            if ($profile === 'oe') {
                data_set($state, 'trade.entry_detail', 'Acid');
                data_set($state, 'active.sulfuric_acid', false);
            }

            $omissionName = "Omission {$code} {$field}";
            $state['name'] = $field === 'name' ? null : $omissionName;
            $component = Livewire::test(CustomerCreatePage::class)
                ->set($state)
                ->set($field, in_array($field, ['access_user_ids', 'trade.classifications', 'trade.working_days'], true) ? [] : null);
            $component->assertSet($field, in_array($field, ['access_user_ids', 'trade.classifications', 'trade.working_days'], true) ? [] : null)
                ->call('saveCustomer');
            $errorKeys = $component->errors()->keys();
            if (! in_array($field, $errorKeys, true)) {
                throw new RuntimeException("Missing required-field error for {$code}.{$field}; received: ".implode(', ', $errorKeys));
            }

            if ($field !== 'name') {
                expect(DB::table('customers')->where('name', $omissionName)->exists())->toBeFalse();
            }
        }
    }
});

test('Tablet Add accepts a complete MCB baseline for every Outlet Company code', function () {
    $user = seedCustomerCreateFixtures();
    $companyMap = config('customer_trade_form.company_profile_map');
    $outletCompanies = array_filter($companyMap, fn (string $profile): bool => $profile === 'outlet');
    $companyIds = ['OMMC' => 1];
    $nextId = (int) DB::table('companies')->max('id') + 1;
    foreach (array_keys($outletCompanies) as $code) {
        if (isset($companyIds[$code])) {
            continue;
        }
        DB::table('companies')->insert([
            'id' => $nextId, 'name' => str_replace('_', ' ', $code), 'code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $companyIds[$code] = $nextId++;
    }
    $this->actingAs($user);
    Http::fake();

    foreach (array_keys($outletCompanies) as $code) {
        $state = validCustomerCreateState();
        $state['name'] = "Complete MCB {$code}";
        $state['company_id'] = $companyIds[$code];
        $state['trade']['entry_detail'] = 'MCB';
        unset($state['categories']['ab']);
        $mcbOptions = config('customer_trade_form.profile_category_options.outlet.mcb');
        $state['categories']['mcb'] = array_fill_keys(
            config('customer_trade_form.category_streams.outlet.years'),
            $mcbOptions[0],
        );

        $component = Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->assertSee('Outlet Profile')
            ->assertSee('MCB')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertRedirect(CustomerPage::getUrl());

        $customer = DB::table('customers')->where('name', "Complete MCB {$code}")->first();
        expect($customer)->not->toBeNull()
            ->and(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_type'))->toBe('outlet')
            ->and(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'))->not->toContain('AB Loyal')
            ->and(DB::table('customer_category_histories')->where('customer_id', $customer->id)->where('stream', 'mcb')->count())->toBe(count(config('customer_trade_form.category_streams.outlet.years')))
            ->and($component->errors()->keys())->toBe([]);
    }
});

test('Tablet Add profile sections, required indicators, Owner values, and stale profile state follow every mapped Company code', function () {
    $user = seedCustomerCreateFixtures();
    $companyMap = config('customer_trade_form.company_profile_map');
    $companyIds = ['OMMC' => 1];
    $nextId = 2;
    foreach ($companyMap as $code => $profile) {
        if (isset($companyIds[$code])) {
            continue;
        }
        DB::table('companies')->insert([
            'id' => $nextId, 'name' => str_replace('_', ' ', $code), 'code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $companyIds[$code] = $nextId++;
    }
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('active.owner.name', 'Shared Switch Owner');
    $previousProfile = null;

    foreach ($companyMap as $code => $profile) {
        if ($previousProfile === 'outlet' && $profile !== 'outlet') {
            $component->set('trade.motiv_user', true)
                ->set('active.warehouse_code', 'STALE-OUTLET-WAREHOUSE');
        }

        $component->set('company_id', $companyIds[$code])
            ->assertSee(ucfirst($profile).' Profile')
            ->assertSee('Owner Profile')
            ->assertSet('active.owner.name', 'Shared Switch Owner')
            ->assertHasNoErrors();
        $requiredLabel = match ($profile) {
            'outlet', 'oe' => 'Entry Detail',
            'fleet' => 'Type',
            'ib' => 'Classification',
        };
        expect($component->html())->toContain($requiredLabel.' <span class="text-danger-600" data-required-indicator="true"');

        if ($previousProfile === 'outlet' && $profile !== 'outlet') {
            $component->assertSet('active.warehouse_code', null)
                ->assertHasNoErrors(['active.warehouse_code', 'active.delivery_detail']);
        }

        $previousProfile = $profile;
    }
});

test('Tablet Add validates missing and invalid Company selections before profile and reference guards', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $missingCompany = validCustomerCreateState();
    Livewire::test(CustomerCreatePage::class)
        ->set($missingCompany)
        ->set('company_id', null)
        ->call('saveCustomer')
        ->assertHasErrors(['company_id']);

    $invalidCompany = validCustomerCreateState();
    Livewire::test(CustomerCreatePage::class)
        ->set($invalidCompany)
        ->set('company_id', 999)
        ->call('saveCustomer')
        ->assertHasErrors(['company_id']);

    $validCompany = validCustomerCreateState();
    Livewire::test(CustomerCreatePage::class)
        ->set($validCompany)
        ->set('name', '')
        ->call('saveCustomer')
        ->assertHasErrors(['name'])
        ->assertHasNoErrors(['company_id']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add accepts false as a valid Active Boolean value', function () {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Inactive assurance customer';
    $state['is_active'] = false;

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('name', 'Inactive assurance customer')->value('is_active'))->toBe(0);
});

test('Tablet Add allows null for a confirmed empty General Category set and rejects a supplied ID', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('general_categories')->delete();
    expect(DB::table('general_categories')->count())->toBe(0);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'No General Category Options';
    $state['general_category_id'] = null;
    $state['competitor_volume'] = null;

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('name', 'No General Category Options')->value('general_category_id'))->toBeNull();

    $invalid = validCustomerCreateState();
    $invalid['name'] = 'Invalid General Category With Empty Options';
    $invalid['general_category_id'] = 999999;
    Livewire::test(CustomerCreatePage::class)
        ->set($invalid)
        ->call('saveCustomer')
        ->assertHasErrors(['general_category_id']);
    expect(DB::table('customers')->where('name', 'Invalid General Category With Empty Options')->exists())->toBeFalse();
});

test('Tablet Add accepts null locality IDs for a successfully loaded synthetic empty locality set', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('barangays')->delete();
    DB::table('municipalities')->delete();
    DB::table('provinces')->delete();
    expect(DB::table('provinces')->count())->toBe(0)
        ->and(DB::table('municipalities')->count())->toBe(0)
        ->and(DB::table('barangays')->count())->toBe(0);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Synthetic Empty Locality Options';
    $state['province_id'] = null;
    $state['municipality_id'] = null;
    $state['barangay_id'] = null;
    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('name', 'Synthetic Empty Locality Options')->exists())->toBeTrue()
        ->and(DB::table('customers')->where('name', 'Synthetic Empty Locality Options')->value('province_id'))->toBeNull()
        ->and(DB::table('customers')->where('name', 'Synthetic Empty Locality Options')->value('municipality_id'))->toBeNull()
        ->and(DB::table('customers')->where('name', 'Synthetic Empty Locality Options')->value('barangay_id'))->toBeNull();

    $invalid = validCustomerCreateState();
    $invalid['name'] = 'Invalid Province With Empty Locality Options';
    $invalid['province_id'] = 999999;
    $invalid['municipality_id'] = null;
    $invalid['barangay_id'] = null;
    Livewire::test(CustomerCreatePage::class)
        ->set($invalid)
        ->call('saveCustomer')
        ->assertHasErrors(['province_id']);
    expect(DB::table('customers')->where('name', 'Invalid Province With Empty Locality Options')->exists())->toBeFalse();
});

test('Tablet Add rejects physical Region and Area Cluster selections made stale by a parent change', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('regions')->insert(['id' => 6, 'code' => 'III', 'psgc_code' => '060000000', 'name' => 'Central Luzon', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert(['id' => 54, 'region_id' => 6, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 54, 'code' => 'NL-CLUSTER', 'name' => 'North Luzon Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $staleRegion = validCustomerCreateState();
    Livewire::test(CustomerCreatePage::class)
        ->set($staleRegion)
        ->set('customer_region_type_id', 2)
        ->set('physical_region_id', 1)
        ->set('region_specific_id', 52)
        ->call('saveCustomer')
        ->assertHasErrors(['physical_region_id']);

    $staleCluster = validCustomerCreateState();
    $staleCluster['customer_region_type_id'] = 2;
    $staleCluster['physical_region_id'] = 6;
    $staleCluster['region_specific_id'] = 54;
    $staleCluster['area_cluster_id'] = 52;
    $staleCluster['province_id'] = null;
    $staleCluster['municipality_id'] = null;
    $staleCluster['barangay_id'] = null;
    Livewire::test(CustomerCreatePage::class)
        ->set($staleCluster)
        ->call('saveCustomer')
        ->assertHasErrors(['area_cluster_id']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add rejects stale Municipality and Barangay values before inserting a Customer', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('regions')->insert(['id' => 6, 'code' => 'OTHER', 'psgc_code' => 'OTHER-REGION', 'name' => 'Other Physical Region', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('provinces')->insert(['id' => 6, 'region_id' => 6, 'region_specific_id' => 52, 'psgc_code' => 'OTHER-PROVINCE', 'name' => 'Other Province', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('municipalities')->insert(['id' => 6, 'psgc_code' => 'OTHER-MUNICIPALITY', 'region_id' => 6, 'province_id' => 6, 'name' => 'Other Municipality', 'enabled' => true, 'sort' => 6, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('barangays')->insert(['id' => 6, 'municipality_id' => 6, 'code' => 'other', 'name' => 'Other Barangay', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Stale Locality Must Not Save';
    $state['municipality_id'] = 6;
    $state['barangay_id'] = 6;

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasErrors(['municipality_id']);

    expect(DB::table('customers')->where('name', 'Stale Locality Must Not Save')->exists())->toBeFalse();
});

test('Tablet Add reports controlled errors when database-backed reference sources disappear after load', function (string $table, array $fields): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $component = Livewire::test(CustomerCreatePage::class)->set(validCustomerCreateState());
    Schema::drop($table);

    $component->call('saveCustomer')
        ->assertHasErrors($fields)
        ->assertSee('Reference options could not be loaded. Restore or sync the reference data before saving.');

    expect(DB::table('customers')->count())->toBe(0);
})->with([
    'Company' => ['companies', ['company_id']],
    'Access and PIC Users' => ['users', ['access_user_ids', 'person_in_charge_id']],
    'General Category' => ['general_categories', ['general_category_id']],
    'Region grouping' => ['region_types', ['customer_region_type_id']],
    'physical Region' => ['regions', ['physical_region_id']],
    'Specific Region relationship' => ['region_specifics', ['physical_region_id']],
    'Area Cluster' => ['area_clusters', ['area_cluster_id']],
    'Province' => ['provinces', ['province_id']],
    'Municipality' => ['municipalities', ['municipality_id']],
    'Barangay' => ['barangays', ['barangay_id']],
]);

function prepareEmptyTabletAddReferenceOptions(string $family, array &$state): array
{
    return match ($family) {
        'users' => (function () use (&$state): array {
            DB::table('users')->delete();
            $state['access_user_ids'] = [];
            $state['person_in_charge_id'] = null;

            return ['fields' => ['access_user_ids', 'person_in_charge_id'], 'labels' => ['Access', 'Person in Charge']];
        })(),
        'region_types' => (function () use (&$state): array {
            DB::table('region_types')->delete();
            $state['customer_region_type_id'] = null;
            $state['physical_region_id'] = null;
            $state['region_specific_id'] = null;
            $state['area_cluster_id'] = null;
            $state['province_id'] = null;
            $state['municipality_id'] = null;
            $state['barangay_id'] = null;

            return ['fields' => ['customer_region_type_id'], 'labels' => ['Region']];
        })(),
        'specific_regions' => (function () use (&$state): array {
            DB::table('region_specifics')->where('id', 52)->delete();
            $state['physical_region_id'] = null;
            $state['region_specific_id'] = null;
            $state['area_cluster_id'] = null;
            $state['province_id'] = null;
            $state['municipality_id'] = null;
            $state['barangay_id'] = null;

            return ['fields' => ['physical_region_id'], 'labels' => ['Specific Region']];
        })(),
        'area_clusters' => (function () use (&$state): array {
            DB::table('area_clusters')->delete();
            $state['area_cluster_id'] = null;

            return ['fields' => ['area_cluster_id'], 'labels' => ['Area Cluster']];
        })(),
        default => throw new InvalidArgumentException("Unknown reference family [{$family}]."),
    };
}

test('Tablet Add allows null selections and removes required marks for the remaining successfully empty option families', function (string $family): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = "Empty {$family} options";
    $expectations = prepareEmptyTabletAddReferenceOptions($family, $state);

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    foreach ($expectations['labels'] as $label) {
        expect($component->html())->not->toContain("{$label} <span class=\"text-danger-600\" data-required-indicator=\"true\"");
    }
    expect(DB::table('customers')->where('name', "Empty {$family} options")->exists())->toBeTrue();
})->with(['users', 'region_types', 'specific_regions', 'area_clusters']);

test('Tablet Add rejects a Province made stale by changing the physical Region', function (): void {
    $user = seedCustomerCreateFixtures();
    DB::table('regions')->insert(['id' => 2, 'code' => 'R2', 'psgc_code' => '020000000', 'name' => 'Region 2', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert(['id' => 54, 'region_id' => 2, 'name' => 'Specific Region 54', 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 2, 'region_specific_id' => 54, 'code' => 'cluster-2', 'name' => 'Cluster 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('provinces')->insert(['id' => 2, 'region_id' => 2, 'region_specific_id' => 54, 'name' => 'Province 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    config()->set('customer_region_grouping.specific_regions.GMA', [52, 54]);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->set('physical_region_id', 2)
        ->assertSet('province_id', null)
        ->set('province_id', 1)
        ->set('municipality_id', null)
        ->set('barangay_id', null);

    $component->call('saveCustomer')->assertHasErrors(['province_id']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add rejects Municipality and Barangay values made stale by changing their parents', function (): void {
    $user = seedCustomerCreateFixtures();
    DB::table('provinces')->insert(['id' => 2, 'region_id' => 1, 'region_specific_id' => 52, 'name' => 'Province 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('municipalities')->insert([
        ['id' => 2, 'region_id' => 1, 'province_id' => 2, 'name' => 'Municipality 2', 'sort' => 2, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'region_id' => 1, 'province_id' => 1, 'name' => 'Municipality 3', 'sort' => 3, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('barangays')->insert([
        ['id' => 1, 'municipality_id' => 1, 'psgc_code' => 'STALE-BRG-1', 'code' => 'STALE-1', 'name' => 'Barangay 1', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'municipality_id' => 3, 'psgc_code' => 'STALE-BRG-3', 'code' => 'STALE-3', 'name' => 'Barangay 3', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($user);
    Http::fake();

    $municipalityState = validCustomerCreateState();
    $municipality = Livewire::test(CustomerCreatePage::class)
        ->set($municipalityState)
        ->set('province_id', 2)
        ->assertSet('municipality_id', null)
        ->set('municipality_id', 1)
        ->set('barangay_id', null);
    $municipality->call('saveCustomer')->assertHasErrors(['municipality_id']);

    $barangayState = validCustomerCreateState();
    $barangayState['barangay_id'] = 1;
    $barangay = Livewire::test(CustomerCreatePage::class)
        ->set($barangayState)
        ->set('municipality_id', 3)
        ->assertSet('barangay_id', null)
        ->set('barangay_id', 1);
    $barangay->call('saveCustomer')->assertHasErrors(['barangay_id']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add clears and does not persist Competitor Volume after General Category changes', function (): void {
    $user = seedCustomerCreateFixtures();
    DB::table('general_categories')->insert(['id' => 2, 'name' => 'Other Category', 'priority_visit' => null, 'duration_per_visit' => null, 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Changed General Category';
    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->set('general_category_id', 2)
        ->assertSet('competitor_volume', null)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('name', 'Changed General Category')->value('competitor_volume'))->toBeNull();
});

test('Tablet Add clears hidden Outlet Entry Detail when Company switches to a different profile', function (): void {
    $user = seedCustomerCreateFixtures();
    DB::table('companies')->insert(['id' => 2, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Fleet after Outlet Entry Detail';
    $fleetYears = config('customer_trade_form.category_streams.fleet.years');
    $fleetCategories = config('customer_trade_form.profile_category_options.fleet');
    $fleetCategory = array_values(is_array(reset($fleetCategories)) ? reset($fleetCategories) : $fleetCategories)[0];

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->set('company_id', 2)
        ->assertSet('trade', [])
        ->set('categories', ['fleet' => array_fill_keys($fleetYears, $fleetCategory)])
        ->set('active.account_type', 'existing')
        ->set('active.classifications', array_values(config('customer_trade_form.profile_classifications.fleet'))[0])
        ->set('active.battery_class', 'maintenance_free')
        ->set('active.status', 'active')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    expect($customer)->not->toBeNull()
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('entry_detail'))->toBeNull()
        ->and(DB::table('customer_category_histories')->where('customer_id', $customer->id)->where('profile_type', 'outlet')->count())->toBe(0);
});

test('Tablet Add persists annual categories for only the final Outlet Entry Detail', function (array $entryDetailSequence, array $expectedStreams): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Final entry detail '.implode(' then ', $entryDetailSequence);
    $years = config('customer_trade_form.category_streams.outlet.years');
    $abCategory = array_values(config('customer_trade_form.profile_category_options.outlet.ab'))[0];
    $mcbCategory = array_values(config('customer_trade_form.profile_category_options.outlet.mcb'))[0];
    $state['categories']['ab'] = array_fill_keys($years, $abCategory);
    $state['categories']['mcb'] = array_fill_keys($years, $mcbCategory);

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state);

    foreach ($entryDetailSequence as $entryDetail) {
        $component->set('trade.entry_detail', $entryDetail);
    }

    $component->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    expect($customer)->not->toBeNull();

    $rows = DB::table('customer_category_histories')
        ->where('customer_id', $customer->id)
        ->where('profile_type', 'outlet')
        ->orderBy('stream')
        ->orderBy('category_year')
        ->get();
    $actualStreams = $rows->pluck('stream')->unique()->values()->all();
    expect($actualStreams)->toBe($expectedStreams);

    foreach ($expectedStreams as $stream) {
        $streamRows = $rows->where('stream', $stream);
        expect($streamRows)->toHaveCount(count($years))
            ->and($streamRows->pluck('category_year')->unique())->toHaveCount(count($years));
    }
})->with([
    'AB to MCB' => [['AB', 'MCB'], ['mcb']],
    'MCB to AB' => [['MCB', 'AB'], ['ab']],
    'AB to MCB to AB' => [['AB', 'MCB', 'AB'], ['ab']],
]);

test('Tablet Add does not persist stale Delivery Detail after Delivery Type changes to No', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'No delivery after own delivery';
    $state['active']['delivery_type'] = 'yes';
    $state['active']['delivery_detail'] = 'own_delivery';

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->set('active.delivery_type', 'no')
        ->assertDontSee('Delivery Detail')
        ->assertSet('active.delivery_detail', 'own_delivery')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    $profileData = json_decode(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'), true);

    expect(data_get($profileData, 'active.delivery_detail'))->toBeNull();
});

test('Tablet Add restores and persists Delivery Detail after Yes to No to Yes', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'Delivery detail restored after toggle';
    $state['active']['delivery_type'] = 'yes';
    $state['active']['delivery_detail'] = 'own_delivery';

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->assertSee('Delivery Detail <span class="text-danger-600" data-required-indicator="true"', false)
        ->set('active.delivery_type', 'no')
        ->assertDontSee('Delivery Detail')
        ->set('active.delivery_type', 'yes')
        ->assertSee('Delivery Detail <span class="text-danger-600" data-required-indicator="true"', false)
        ->set('active.delivery_detail', 'own_delivery')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    $profileData = json_decode(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'), true);

    expect(data_get($profileData, 'active.delivery_type'))->toBe('yes')
        ->and(data_get($profileData, 'active.delivery_detail'))->toBe('own_delivery');
});

test('Tablet Add ignores and omits invalid stale Delivery Detail when Delivery Type is No', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = 'No delivery with stale invalid detail';
    $state['active']['delivery_type'] = 'no';
    $state['active']['delivery_detail'] = 'invalid-stale-value';

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->assertDontSee('Delivery Detail')
        ->call('saveCustomer')
        ->assertHasNoErrors(['active.delivery_detail'])
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    $profileData = json_decode(DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->value('profile_data'), true);

    expect(array_key_exists('delivery_detail', data_get($profileData, 'active', [])))->toBeFalse();
});

test('Tablet Add rejects non-null selections outside successfully empty option families', function (string $family): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['name'] = "Invalid selection for empty {$family}";
    $expectations = prepareEmptyTabletAddReferenceOptions($family, $state);
    $invalidFields = match ($family) {
        'users' => (function () use (&$state): array {
            $state['access_user_ids'] = [999];
            $state['person_in_charge_id'] = 999;

            return ['access_user_ids.0', 'person_in_charge_id'];
        })(),
        'region_types' => (function () use (&$state): array {
            $state['customer_region_type_id'] = 999;

            return ['customer_region_type_id'];
        })(),
        'specific_regions' => (function () use (&$state): array {
            $state['physical_region_id'] = 1;
            $state['region_specific_id'] = null;

            return ['physical_region_id'];
        })(),
        'area_clusters' => (function () use (&$state): array {
            DB::table('area_clusters')->insert(['id' => 53, 'region_specific_id' => 54, 'code' => 'other-parent', 'name' => 'Other Parent Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $state['area_cluster_id'] = 53;

            return ['area_cluster_id'];
        })(),
    };

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasErrors($invalidFields);

    expect(DB::table('customers')->where('name', "Invalid selection for empty {$family}")->exists())->toBeFalse();
})->with(['users', 'region_types', 'specific_regions', 'area_clusters']);

test('Tablet Add rejects invalid values for every available dropdown family under all mapped Company codes', function () {
    $user = seedCustomerCreateFixtures();
    DB::table('barangays')->insert([
        'id' => 1, 'municipality_id' => 1, 'psgc_code' => 'INVALID-BRG-1', 'code' => 'INVALID-1',
        'name' => 'Assurance Barangay', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyMap = config('customer_trade_form.company_profile_map');
    $companyIds = ['OMMC' => 1];
    $nextId = 2;
    foreach ($companyMap as $code => $profile) {
        if (isset($companyIds[$code])) {
            continue;
        }
        DB::table('companies')->insert([
            'id' => $nextId, 'name' => str_replace('_', ' ', $code), 'code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $companyIds[$code] = $nextId++;
    }
    $this->actingAs($user);
    Http::fake();

    $profileState = function (string $profile, string $code) use ($companyIds): array {
        $state = validCustomerCreateState();
        $state['name'] = "Invalid options {$code}";
        $state['company_id'] = $companyIds[$code];
        $state['barangay_id'] = 1;

        if ($profile === 'outlet') {
            return $state;
        }

        $years = config("customer_trade_form.category_streams.{$profile}.years", []);
        $options = config("customer_trade_form.profile_category_options.{$profile}", []);
        $category = array_values(is_array(reset($options)) ? reset($options) : $options)[0] ?? null;
        $state['categories'] = [$profile => array_fill_keys($years, $category)];
        $state['trade'] = $profile === 'oe' ? ['entry_detail' => 'AB'] : [];
        $state['active'] = ['owner' => $state['active']['owner']];
        $state['active'] = array_replace($state['active'], match ($profile) {
            'fleet' => ['account_type' => 'existing', 'classifications' => array_values(config('customer_trade_form.profile_classifications.fleet'))[0], 'battery_class' => 'maintenance_free', 'status' => 'active'],
            'oe' => ['classifications' => array_values(config('customer_trade_form.profile_classifications.oe'))[0], 'battery_class' => 'maintenance_free'],
            'ib' => ['classifications' => array_values(config('customer_trade_form.profile_classifications.ib'))[0]],
        });

        return $state;
    };
    $commonInvalid = [
        'access_user_ids' => [999], 'person_in_charge_id' => 999, 'general_category_id' => 999,
        'competitor_volume' => 999, 'customer_region_type_id' => 999, 'physical_region_id' => 999,
        'region_specific_id' => 999, 'area_cluster_id' => 999, 'province_id' => 999,
        'municipality_id' => 999, 'barangay_id' => 999, 'landmass_code' => 'INVALID',
    ];
    $profileInvalid = [
        'outlet' => [
            'trade.entry_detail' => 'INVALID', 'trade.classifications' => ['INVALID'],
            'trade.working_days' => ['INVALID'], 'active.conversion_program' => 'INVALID',
            'active.delivery_type' => 'INVALID', 'active.delivery_detail' => 'INVALID', 'active.ulab' => 'INVALID',
            ...array_fill_keys(array_map(fn (int $year): string => "categories.ab.{$year}", config('customer_trade_form.category_streams.outlet.years')), 'INVALID'),
        ],
        'fleet' => [
            'active.account_type' => 'INVALID', 'active.classifications' => 'INVALID',
            'active.battery_class' => 'INVALID', 'active.status' => 'INVALID',
            ...array_fill_keys(array_map(fn (int $year): string => "categories.fleet.{$year}", config('customer_trade_form.category_streams.fleet.years')), 'INVALID'),
        ],
        'oe' => [
            'trade.entry_detail' => 'INVALID', 'active.classifications' => 'INVALID', 'active.battery_class' => 'INVALID',
            ...array_fill_keys(array_map(fn (int $year): string => "categories.oe.{$year}", config('customer_trade_form.category_streams.oe.years')), 'INVALID'),
        ],
        'ib' => [
            'active.classifications' => 'INVALID',
            ...array_fill_keys(array_map(fn (int $year): string => "categories.ib.{$year}", config('customer_trade_form.category_streams.ib.years')), 'INVALID'),
        ],
    ];

    foreach ($companyMap as $code => $profile) {
        foreach ($commonInvalid as $field => $invalid) {
            $state = $profileState($profile, $code);
            $component = Livewire::test(CustomerCreatePage::class)->set($state)->set($field, $invalid)->call('saveCustomer');
            $errorKeys = $component->errors()->keys();
            if (! collect($errorKeys)->contains(fn (string $errorKey): bool => $errorKey === $field || str_starts_with($errorKey, $field.'.'))) {
                throw new RuntimeException("Invalid dropdown {$code}.{$field} was not rejected; errors: ".implode(', ', $errorKeys));
            }
            expect(DB::table('customers')->count())->toBe(0);
        }

        $state = $profileState($profile, $code);
        if ($profile === 'outlet') {
            $state['trade']['motiv_user'] = true;
            $state['active']['warehouse_code'] = 'ASSURANCE-WAREHOUSE';
            $state['active']['delivery_type'] = 'yes';
            $state['active']['delivery_detail'] = 'own_delivery';
        }
        foreach ($profileInvalid[$profile] as $field => $invalid) {
            $component = Livewire::test(CustomerCreatePage::class)
                ->set($state)
                ->set($field, $invalid)
                ->call('saveCustomer');
            $errorKeys = $component->errors()->keys();
            if (! collect($errorKeys)->contains(fn (string $errorKey): bool => $errorKey === $field || str_starts_with($errorKey, $field.'.'))) {
                throw new RuntimeException("Invalid profile dropdown {$code}.{$field} was not rejected; errors: ".implode(', ', $errorKeys));
            }
            expect(DB::table('customers')->count())->toBe(0);
        }

        if ($profile === 'outlet') {
            $mcbState = $profileState($profile, $code);
            $mcbState['trade']['entry_detail'] = 'MCB';
            $mcbState['categories'] = [
                'mcb' => array_fill_keys(
                    config('customer_trade_form.category_streams.outlet.years'),
                    config('customer_trade_form.profile_category_options.outlet.mcb')[0],
                ),
            ];
            $mcbState['trade']['motiv_user'] = true;
            $mcbState['active']['warehouse_code'] = 'ASSURANCE-WAREHOUSE';
            $mcbState['active']['delivery_type'] = 'yes';
            $mcbState['active']['delivery_detail'] = 'own_delivery';

            foreach (config('customer_trade_form.category_streams.outlet.years') as $year) {
                $field = "categories.mcb.{$year}";
                $component = Livewire::test(CustomerCreatePage::class)
                    ->set($mcbState)
                    ->set($field, 'INVALID')
                    ->call('saveCustomer');
                $errorKeys = $component->errors()->keys();
                if (! in_array($field, $errorKeys, true)) {
                    throw new RuntimeException("Invalid Outlet MCB dropdown {$code}.{$field} was not rejected; errors: ".implode(', ', $errorKeys));
                }
                expect(DB::table('customers')->count())->toBe(0);
            }
        }
    }
});

test('Tablet Add permits null Outlet classifications when the successfully loaded option set is empty', function (): void {
    $user = seedCustomerCreateFixtures();
    config()->set('customer_trade_form.profile_classifications.outlet', []);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['trade']['classifications'] = [];

    $component = Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->assertSee('Classifications </label>', false)
        ->assertDontSee('Classifications <span class="text-danger-600" data-required-indicator="true"', false)
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', $state['name'])->first();
    $profile = $customer ? DB::table('customer_trade_profiles')->where('customer_id', $customer->id)->first() : null;

    expect(DB::table('customers')->count())->toBe(1)
        ->and($profile)->not->toBeNull()
        ->and(json_decode($profile->classifications, true))->toBe([]);
});

test('Tablet Add still requires a selection when Outlet classification options exist', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['trade']['classifications'] = [];

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasErrors(['trade.classifications']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add rejects an Outlet classification that became stale when options became empty', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $component = Livewire::test(CustomerCreatePage::class)->set($state);
    config()->set('customer_trade_form.profile_classifications.outlet', []);

    $component->call('saveCustomer');

    expect(collect($component->errors()->keys())->contains(fn (string $key): bool => str_starts_with($key, 'trade.classifications.')))->toBeTrue()
        ->and(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add permits null Outlet Entry Detail when the successfully loaded option set is empty', function (): void {
    $user = seedCustomerCreateFixtures();
    config()->set('customer_trade_form.profile_entry_details.outlet', []);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['trade']['entry_detail'] = null;

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->assertSee('Entry Detail </label>', false)
        ->assertDontSee('Entry Detail <span class="text-danger-600" data-required-indicator="true"', false)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect(DB::table('customers')->count())->toBe(1);
});

test('Tablet Add rejects a supplied Outlet Entry Detail when the successfully loaded option set is empty', function (): void {
    $user = seedCustomerCreateFixtures();
    config()->set('customer_trade_form.profile_entry_details.outlet', []);
    $this->actingAs($user);
    Http::fake();

    $state = validCustomerCreateState();
    $state['trade']['entry_detail'] = 'AB';

    Livewire::test(CustomerCreatePage::class)
        ->set($state)
        ->call('saveCustomer')
        ->assertHasErrors(['trade.entry_detail']);

    expect(DB::table('customers')->count())->toBe(0);
});

test('Tablet Add allows null profile dropdowns and annual categories when their configured option sets are empty', function (): void {
    $user = seedCustomerCreateFixtures();
    $companyIds = ['OMMC' => 1];
    foreach (['FLEET', 'OE', 'IB'] as $index => $code) {
        $companyIds[$code] = $index + 2;
        DB::table('companies')->insert(['id' => $index + 2, 'name' => $code, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($user);
    Http::fake();

    $outlet = validCustomerCreateState();
    $outlet['name'] = 'Empty Outlet Options';
    $outlet['trade'] = array_replace($outlet['trade'], ['entry_detail' => null, 'classifications' => [], 'working_days' => []]);
    $outlet['active']['conversion_program'] = null;
    config()->set('customer_trade_form.profile_entry_details.outlet', []);
    config()->set('customer_trade_form.profile_classifications.outlet', []);
    config()->set('customer_trade_form.working_days', []);
    config()->set('customer_trade_form.conversion_programs', []);
    config()->set('customer_trade_form.profile_category_options.outlet.ab', []);
    $outlet['categories']['ab'] = array_fill_keys(config('customer_trade_form.category_streams.outlet.years'), null);

    $outletComponent = Livewire::test(CustomerCreatePage::class)
        ->set($outlet)
        ->assertDontSee('Entry Detail <span class="text-danger-600" data-required-indicator="true"', false)
        ->assertDontSee('Classifications <span class="text-danger-600" data-required-indicator="true"', false)
        ->assertDontSee('Conversion Program <span class="text-danger-600" data-required-indicator="true"', false)
        ->assertDontSee('Working Days <span class="text-danger-600" data-required-indicator="true"', false);
    foreach (config('customer_trade_form.category_streams.outlet.years') as $year) {
        $outletComponent->assertDontSee("{$year} <span class=\"text-danger-600\" data-required-indicator=\"true\"", false);
    }
    $outletComponent->call('saveCustomer')->assertHasNoErrors();

    $mcb = validCustomerCreateState();
    $mcb['name'] = 'Empty MCB Category Options';
    $mcb['trade']['entry_detail'] = 'MCB';
    $mcb['categories'] = ['mcb' => array_fill_keys(config('customer_trade_form.category_streams.outlet.years'), null)];
    config()->set('customer_trade_form.profile_entry_details.outlet', ['AB', 'MCB', 'AB and MCB']);
    config()->set('customer_trade_form.profile_classifications.outlet', ['Battery Specialist']);
    config()->set('customer_trade_form.working_days', ['Monday']);
    config()->set('customer_trade_form.conversion_programs', ['For Conversion' => 'For Conversion']);
    config()->set('customer_trade_form.profile_category_options.outlet.mcb', []);
    $mcbComponent = Livewire::test(CustomerCreatePage::class)
        ->set($mcb);
    foreach (config('customer_trade_form.category_streams.outlet.years') as $year) {
        $mcbComponent->assertDontSee("{$year} <span class=\"text-danger-600\" data-required-indicator=\"true\"", false);
    }
    $mcbComponent->call('saveCustomer')->assertHasNoErrors();

    $profileCases = [
        'FLEET' => ['active.classifications', 'customer_trade_form.profile_classifications.fleet'],
        'OE' => ['active.classifications', 'customer_trade_form.profile_classifications.oe'],
        'IB' => ['active.classifications', 'customer_trade_form.profile_classifications.ib'],
    ];
    foreach ($profileCases as $code => [$field, $optionConfig]) {
        $state = validCustomerCreateState();
        $state['name'] = "Empty {$code} Options";
        $state['company_id'] = $companyIds[$code];
        $years = config('customer_trade_form.category_streams.'.strtolower($code).'.years', []);
        $state['categories'] = [strtolower($code) => array_fill_keys($years, null)];
        $state['trade'] = $code === 'OE' ? ['entry_detail' => null] : [];
        $state['active'] = ['owner' => $state['active']['owner']];
        if ($code === 'FLEET') {
            $state['active'] += ['account_type' => 'existing', 'classifications' => null, 'battery_class' => 'maintenance_free', 'status' => 'active'];
        } elseif ($code === 'OE') {
            $state['active'] += ['classifications' => null, 'battery_class' => 'maintenance_free'];
        } else {
            $state['active'] += ['classifications' => null];
        }
        config()->set($optionConfig, []);
        config()->set('customer_trade_form.profile_category_options.'.strtolower($code), []);
        if ($code === 'OE') {
            config()->set('customer_trade_form.profile_entry_details.oe', []);
        }

        $profileComponent = Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->assertDontSee('Classification <span class="text-danger-600" data-required-indicator="true"', false);
        foreach ($years as $year) {
            $profileComponent->assertDontSee("{$year} <span class=\"text-danger-600\" data-required-indicator=\"true\"", false);
        }
        $profileComponent->call('saveCustomer')->assertHasNoErrors();
    }

    expect(DB::table('customers')->count())->toBe(5);
});

test('Tablet Add rejects supplied values that are stale against successfully empty profile option sets', function (): void {
    $user = seedCustomerCreateFixtures();
    $this->actingAs($user);
    Http::fake();

    config()->set('customer_trade_form.profile_entry_details.outlet', []);
    config()->set('customer_trade_form.profile_classifications.outlet', []);
    config()->set('customer_trade_form.working_days', []);
    config()->set('customer_trade_form.conversion_programs', []);
    config()->set('customer_trade_form.profile_category_options.outlet.ab', []);

    $outletValues = [
        'trade.entry_detail' => 'AB',
        'trade.classifications' => ['Battery Specialist'],
        'trade.working_days' => ['Monday'],
        'active.conversion_program' => 'For Conversion',
        'categories.ab.'.config('customer_trade_form.category_streams.outlet.years.0') => 'AB Loyal',
    ];
    foreach ($outletValues as $field => $value) {
        $state = validCustomerCreateState();
        $state['active']['conversion_program'] = null;
        $component = Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->set($field, $value)
            ->call('saveCustomer');

        if (! $component->errors()->has($field)) {
            throw new RuntimeException("Expected {$field}; got: ".implode(', ', $component->errors()->keys()));
        }
        expect(DB::table('customers')->count())->toBe(0);
    }

    foreach ([
        ['FLEET', 'fleet', 'active.classifications', 'customer_trade_form.profile_classifications.fleet'],
        ['OE', 'oe', 'active.classifications', 'customer_trade_form.profile_classifications.oe'],
        ['IB', 'ib', 'active.classifications', 'customer_trade_form.profile_classifications.ib'],
    ] as [$code, $profile, $field, $optionConfig]) {
        $companyId = DB::table('companies')->max('id') + 1;
        DB::table('companies')->insert(['id' => $companyId, 'name' => $code, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        config()->set($optionConfig, []);
        config()->set("customer_trade_form.profile_category_options.{$profile}", []);
        if ($profile === 'oe') {
            config()->set('customer_trade_form.profile_entry_details.oe', []);
        }

        $state = validCustomerCreateState();
        $state['name'] = "Stale {$code} Selection";
        $state['company_id'] = $companyId;
        $state['categories'] = [$profile => array_fill_keys(config("customer_trade_form.category_streams.{$profile}.years"), null)];
        $state['trade'] = $profile === 'oe' ? ['entry_detail' => null] : [];
        $state['active'] = ['owner' => $state['active']['owner']];
        $state['active'] += match ($profile) {
            'fleet' => ['account_type' => 'existing', 'classifications' => 'Bus', 'battery_class' => 'maintenance_free', 'status' => 'active'],
            'oe' => ['classifications' => 'Car Manufacturer', 'battery_class' => 'maintenance_free'],
            'ib' => ['classifications' => 'Bus'],
        };

        Livewire::test(CustomerCreatePage::class)
            ->set($state)
            ->call('saveCustomer')
            ->assertHasErrors([$field]);
        expect(DB::table('customers')->count())->toBe(0);
    }
});

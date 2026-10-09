<?php

use App\Filament\Pages\CustomerCreatePage;
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

beforeEach(function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    $this->user = User::factory()->create(['api_token' => 'snapshot-test-token']);
    $this->actingAs($this->user);
    $this->snapshot = function (array $overrides = []): array {
        $sections = array_replace(array_fill_keys(['regions', 'region_specifics', 'area_clusters', 'provinces', 'municipalities', 'barangays'], []), $overrides);

        return [
            'reference_contract_version' => 1,
            ...$sections,
            'reference_counts' => array_map('count', $sections),
        ];
    };
});

function syncLocationSnapshot(): SyncService
{
    return app(SyncService::class);
}

test('a complete location response applies validated references without changing membership rows or historical references', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'OLD', 'name' => 'Historical Region'],
        ['id' => 2, 'code' => 'R2', 'name' => 'Current Region'],
        ['id' => 3, 'code' => 'R3', 'name' => 'Commercial Parent Region'],
    ]);
    DB::table('region_specifics')->insert(['id' => 20, 'region_id' => 2, 'name' => 'Commercial Region']);
    DB::table('area_clusters')->insert(['id' => 30, 'region_specific_id' => 20, 'code' => 'A30', 'name' => 'Cluster', 'enabled' => true]);
    DB::table('provinces')->insert(['id' => 40, 'region_id' => 2, 'name' => 'Province', 'enabled' => true]);
    DB::table('municipalities')->insert(['id' => 50, 'region_id' => 2, 'province_id' => 40, 'name' => 'Municipality']);
    DB::table('barangays')->insert(['id' => 60, 'municipality_id' => 50, 'code' => 'B60', 'name' => 'Barangay', 'enabled' => true]);
    DB::table('customers')->insert(['id' => -60, 'name' => 'Historical Customer', 'region_specific_id' => 20, 'municipality_id' => 50, 'is_active' => true, 'sync_status' => 'synced']);

    Http::fake(['portal.test/api/sync/pull/locations' => Http::response(($this->snapshot)([
        'regions' => [['id' => 2, 'code' => 'R2', 'name' => 'Current Region'], ['id' => 3, 'code' => 'R3', 'name' => 'Commercial Parent Region']],
        'region_specifics' => [['id' => 20, 'region_id' => 3, 'name' => 'Commercial Region']],
        'area_clusters' => [['id' => 30, 'region_specific_id' => 20, 'code' => 'A30', 'name' => 'Cluster', 'enabled' => true]],
        'provinces' => [['id' => 40, 'region_id' => 2, 'name' => 'Province', 'enabled' => true]],
        'municipalities' => [['id' => 50, 'region_id' => 2, 'province_id' => 40, 'name' => 'Municipality']],
        'barangays' => [['id' => 60, 'municipality_id' => 50, 'code' => 'B60', 'name' => 'Barangay', 'enabled' => true]],
    ]))]);

    $result = syncLocationSnapshot()->pullLocations();
    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77])
        ->and(DB::table('regions')->where('id', 2)->value('name'))->toBe('Current Region')
        ->and(DB::table('regions')->where('id', 1)->value('name'))->toBe('Historical Region')
        ->and(DB::table('customers')->where('id', -60)->value('region_specific_id'))->toBe(20)
        ->and(DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->exists())->toBeTrue();
});

test('a complete location pull succeeds when the historical membership table is empty or absent', function (): void {
    Http::fake(['portal.test/api/sync/pull/locations' => Http::sequence()
        ->push(($this->snapshot)(['regions' => [['id' => 91, 'code' => 'R91', 'name' => 'Pulled Region']]]))
        ->push(($this->snapshot)(['regions' => [['id' => 92, 'code' => 'R92', 'name' => 'Pulled Without Membership Table']]]))]);

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeTrue()
        ->and(DB::table('location_reference_memberships')->count())->toBe(0)
        ->and(DB::table('regions')->where('id', 91)->value('name'))->toBe('Pulled Region');

    Schema::dropIfExists('location_reference_memberships');
    $withoutMembershipTable = syncLocationSnapshot()->pullLocations();
    expect($withoutMembershipTable->success)->toBeTrue($withoutMembershipTable->message)
        ->and(DB::table('regions')->where('id', 92)->value('name'))->toBe('Pulled Without Membership Table')
        ->and(DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->exists())->toBeTrue();
});

test('malformed json, missing sections, invalid rows, and count mismatches preserve prior reference and membership data', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);
    $valid = ($this->snapshot)(['regions' => [['id' => 88, 'code' => 'R88', 'name' => 'New Region']]]);
    $responses = [
        '{broken json',
        json_encode(['reference_contract_version' => 1, 'reference_counts' => []]),
        json_encode(($this->snapshot)(['regions' => [['name' => 'Missing ID']]])),
        json_encode([...$valid, 'reference_counts' => ['regions' => 0, 'region_specifics' => 0, 'area_clusters' => 0, 'provinces' => 0, 'municipalities' => 0, 'barangays' => 0]]),
        json_encode(($this->snapshot)(['region_specifics' => [['id' => 89, 'region_id' => 999, 'name' => 'Broken Parent']]])),
    ];
    $responseIndex = 0;
    Http::fake(function () use (&$responseIndex, $responses) {
        return Http::response($responses[$responseIndex++], 200, ['Content-Type' => 'application/json']);
    });

    foreach (range(1, 5) as $_) {
        expect(syncLocationSnapshot()->pullLocations()->success)->toBeFalse()
            ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77])
            ->and(DB::table('regions')->where('id', 88)->exists())->toBeFalse();
    }
});

test('a failed refresh leaves the last complete membership unchanged', function (): void {
    DB::table('location_reference_memberships')->insert(['reference_type' => 'regions', 'reference_id' => 77]);
    Http::fake(['portal.test/api/sync/pull/locations' => Http::sequence()
        ->push(($this->snapshot)(['regions' => [['id' => 77, 'code' => 'R77', 'psgc_code' => '770000000', 'name' => 'Previously Confirmed Region']]]))
        ->push(['message' => 'unavailable'], 503)]);

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeTrue();

    expect(syncLocationSnapshot()->pullLocations()->success)->toBeFalse()
        ->and(DB::table('location_reference_memberships')->where('reference_type', 'regions')->pluck('reference_id')->all())->toBe([77]);

    Livewire::test(CustomerCreatePage::class)
        ->assertSee('Previously Confirmed Region');
});

test('a new Customer can be saved with blank optional geography before its first complete snapshot', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => '11111111-1111-4111-8111-111111111111', 'code' => 'FLEET1001']);
        }

        return Http::response(['message' => 'Expected test push failure'], 503);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Offline FLEET')
        ->assertDontSee('Portal location references')
        ->assertDontSee('membership')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect(DB::table('customers')->where('name', 'Offline FLEET')->value('region_specific_id'))->toBeNull()
        ->and(DB::table('customers')->where('name', 'Offline FLEET')->value('sync_status'))->toBe('failed');
});

test('Add Customer saves blank optional geography when the membership table is not present', function (): void {
    Schema::dropIfExists('location_reference_memberships');
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => '22222222-2222-4222-8222-222222222222', 'code' => 'FLEET1002']);
        }

        return Http::response(['message' => 'Portal unavailable during isolated test'], 503);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'No Reference Dataset Customer')
        ->assertOk()
        ->assertDontSee('Portal location references')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = DB::table('customers')->where('name', 'No Reference Dataset Customer')->first();
    expect($customer)->not->toBeNull()
        ->and($customer?->region_specific_id)->toBeNull()
        ->and($customer?->province_id)->toBeNull()
        ->and($customer?->municipality_id)->toBeNull()
        ->and($customer?->barangay_id)->toBeNull()
        ->and($customer?->area_cluster_id)->toBeNull();
});

test('new Customer dropdowns load every locally retained reference regardless of snapshot membership', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert([
        ['id' => 1, 'code' => 'OLD', 'psgc_code' => '010000000', 'name' => 'Stale Region'],
        ['id' => 2, 'code' => 'R2', 'psgc_code' => '020000000', 'name' => 'Current Region'],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);

    Livewire::test(CustomerCreatePage::class)
        ->assertSee('Current Region')
        ->assertSee('Stale Region')
        ->assertDontSee('historical; unavailable')
        ->assertDontSee('membership')
        ->assertDontSee('Portal location references');
});

test('Portal rejection of a locally selectable stale reference leaves a recoverable failed Customer', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R1', 'psgc_code' => '010000000', 'name' => 'Physical Region']);
    DB::table('region_specifics')->insert([
        ['id' => 53, 'region_id' => 1, 'name' => 'Locally Retained Specific Region'],
        ['id' => 54, 'region_id' => 1, 'name' => 'Other Specific Region'],
    ]);
    DB::table('sync_states')->insert(['key' => 'location_reference_snapshot_complete', 'value' => json_encode(['completed_at' => now()->toISOString()])]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => '11111111-1111-4111-8111-111111111111', 'code' => 'FLEET1001']);
        }

        return Http::response([
            'message' => 'The selected reference is invalid.',
            'errors' => ['region_specific_id' => ['Specific Region does not exist in Portal.']],
        ], 422);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Stale Specific Region Customer')
        ->set('region_specific_id', 53)
        ->assertSee('Locally Retained Specific Region')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl())
        ->assertSessionHas('filament.notifications.0.body', 'Portal returned HTTP 422: The selected reference is invalid. region_specific_id: Specific Region does not exist in Portal.');

    $customer = Customer::query()->where('name', 'Stale Specific Region Customer')->firstOrFail();
    expect($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1)
        ->and($customer->server_id)->toBeNull()
        ->and($customer->region_specific_id)->toBe(53)
        ->and($customer->unique_id)->toBe('FLEET1001')
        ->and($customer->customer_code_reservation_token)->toBe('11111111-1111-4111-8111-111111111111')
        ->and(Customer::query()->where('name', 'Stale Specific Region Customer')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['region_specific_id'] === 53
        && $request['unique_id'] === 'FLEET1001'
        && $request['customer_code_reservation_token'] === '11111111-1111-4111-8111-111111111111');
});

test('all six location selectors load clean local names and push valid references without snapshot membership', function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'FLEET', 'code' => 'FLEET', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('regions')->insert(['id' => 11, 'code' => 'R11', 'psgc_code' => '110000000', 'name' => 'Physical Region', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert(['id' => 21, 'region_id' => 11, 'name' => 'Commercial Region', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('area_clusters')->insert(['id' => 31, 'region_specific_id' => 21, 'code' => 'AC31', 'name' => 'Commercial Cluster', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('provinces')->insert(['id' => 41, 'region_id' => 11, 'name' => 'Province One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('municipalities')->insert(['id' => 51, 'region_id' => 11, 'province_id' => 41, 'name' => 'City One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('barangays')->insert(['id' => 61, 'municipality_id' => 51, 'code' => 'B61', 'name' => 'Barangay One', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/reserve-customer-code')) {
            return Http::response(['token' => '33333333-3333-4333-8333-333333333333', 'code' => 'FLEET1003']);
        }

        return Http::response(['server_id' => 991, 'unique_id' => 'FLEET1003', 'updated_at' => now()->toISOString()]);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('region_specific_id', 21)
        ->set('physical_region_id', 11)
        ->set('province_id', 41)
        ->set('municipality_id', 51)
        ->set('barangay_id', 61)
        ->set('area_cluster_id', 31)
        ->set('name', 'Local Reference Customer')
        ->assertSee('Physical Region')
        ->assertSee('Commercial Region')
        ->assertSee('Commercial Cluster')
        ->assertSee('Province One')
        ->assertSee('City One')
        ->assertSee('Barangay One')
        ->assertDontSee('historical; unavailable')
        ->assertDontSee('Portal location references')
        ->assertDontSee('membership')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertRedirect(CustomerPage::getUrl());

    $customer = Customer::query()->where('name', 'Local Reference Customer')->firstOrFail();
    expect($customer->sync_status)->toBe('synced')
        ->and($customer->server_id)->toBe(991)
        ->and($customer->region_specific_id)->toBe(21)
        ->and($customer->area_cluster_id)->toBe(31)
        ->and($customer->province_id)->toBe(41)
        ->and($customer->municipality_id)->toBe(51)
        ->and($customer->barangay_id)->toBe(61);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer')
        && $request['region_specific_id'] === 21
        && $request['area_cluster_id'] === 31
        && $request['province_id'] === 41
        && $request['municipality_id'] === 51
        && $request['barangay_id'] === 61);
});

<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('pull stores location reference tables and customer location foreign keys', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/*' => Http::response([
            'reference_contract_version' => 2,
            'customer_access_mappings' => [],
            'companies' => [],
            'general_categories' => [],
            'regions' => [['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)']],
            'region_specifics' => [['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1]],
            'provinces' => [['id' => 7, 'region_id' => 1, 'name' => 'Pampanga', 'enabled' => true]],
            'municipalities' => [['id' => 9, 'region_id' => 1, 'province_id' => 7, 'name' => 'City of Angeles', 'enabled' => true]],
            'barangays' => [['id' => 55, 'municipality_id' => 9, 'psgc_code' => '0305401001', 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true]],
            'area_clusters' => [['id' => 61, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true]],
            'reference_counts' => ['regions' => 1, 'region_specifics' => 1, 'area_clusters' => 1, 'provinces' => 1, 'municipalities' => 1, 'barangays' => 1],
            'customers' => [[
                'id' => 99,
                'company_id' => null,
                'name' => 'Synced Customer',
                'region_specific_id' => 1,
                'province_id' => 7,
                'municipality_id' => 9,
                'barangay_id' => 55,
                'area_cluster_id' => 61,
                'person_in_charge_email' => 'pic@example.test',
                'access_user_emails' => ['access@example.test'],
                'address' => '123 Roxas Street',
                'latitude' => 15.1456,
                'longitude' => 120.5887,
                'is_active' => true,
            ]],
            'customer_users' => [
                ['name' => 'Access User', 'email' => 'access@example.test'],
                ['name' => 'PIC User', 'email' => 'pic@example.test'],
            ],
            'customer_trade_profiles' => [], 'customer_category_histories' => [], 'customer_category_events' => [],
            'salescall_statuses' => [], 'salescall_types' => [], 'material_groups' => [], 'brands' => [],
            'categories' => [], 'sub_categories' => [], 'sub_sub_categories' => [], 'salescall_image_categories' => [],
            'salescall_image_types' => [], 'itineraries' => [], 'customer_brands' => [], 'customer_categories' => [], 'customer_notes' => [],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('barangays')->where('id', 55)->value('name'))->toBe('Barangay Pulung Maragul')
        ->and(DB::table('area_clusters')->where('id', 61)->value('name'))->toBe('GMA - Area 1');

    $customer = Customer::findOrFail(99);
    $accessUser = User::where('email', 'access@example.test')->firstOrFail();
    $personInCharge = User::where('email', 'pic@example.test')->firstOrFail();
    expect($customer->province_id)->toBe(7)
        ->and($customer->barangay_id)->toBe(55)
        ->and($customer->area_cluster_id)->toBe(61)
        ->and($customer->person_in_charge_id)->toBe($personInCharge->id)
        ->and($personInCharge->id)->not->toBe(999)
        ->and($accessUser->id)->not->toBe(999)
        ->and($accessUser->rsm_id)->toBeNull()
        ->and(DB::table('customer_user')->where('customer_id', 99)->where('user_id', $accessUser->id)->exists())->toBeTrue();
});

test('pullLocations sends the known version and skips writes when unchanged', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/locations*' => Http::response([
            'status' => 'unchanged',
            'reference_version' => 'v1',
        ], 200),
    ]);

    $result = app(SyncService::class)->pullLocations('v1');

    expect($result->success)->toBeTrue($result->message)
        ->and(json_decode(DB::table('sync_states')->where('key', 'location_reference_version')->value('value'), true))->toBe('v1')
        ->and(DB::table('regions')->count())->toBe(0);

    Http::assertSent(function ($request): bool {
        $url = parse_url($request->url());
        parse_str($url['query'] ?? '', $query);

        return ($url['path'] ?? null) === '/api/sync/pull/locations'
            && ($query['known_version'] ?? null) === 'v1';
    });
});

test('pullLocations stores the payload version on a full download', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    $payload = emptyLocationReferenceSnapshot();
    $payload['regions'] = [['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)']];
    $payload['reference_counts']['regions'] = 1;
    $payload['reference_version'] = 'v2';

    Http::fake([
        'portal.test/api/sync/pull/locations*' => Http::response($payload, 200),
    ]);

    $result = app(SyncService::class)->pullLocations();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('regions')->where('id', 1)->value('name'))->toBe('Region III (Central Luzon)')
        ->and(json_decode(DB::table('sync_states')->where('key', 'location_reference_version')->value('value'), true))->toBe('v2');
});

test('pullCustomersStep passes the stored version to the locations phase', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    DB::table('sync_states')->insert([
        'key' => 'location_reference_version',
        'value' => json_encode('v1'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'portal.test/api/sync/pull/locations*' => Http::response([
            'status' => 'unchanged',
            'reference_version' => 'v1',
        ], 200),
        'portal.test/api/sync/pull/*' => Http::response(['customers' => []], 200),
    ]);

    $step = app(SyncService::class)->pullCustomersStep();

    expect($step['success'])->toBeTrue($step['message']);

    Http::assertSent(function ($request): bool {
        $url = parse_url($request->url());
        parse_str($url['query'] ?? '', $query);

        return ($url['path'] ?? null) === '/api/sync/pull/locations'
            && ($query['known_version'] ?? null) === 'v1';
    });
});

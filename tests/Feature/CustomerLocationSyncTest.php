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
        'portal.test/api/sync/pull' => Http::response([
            'companies' => [],
            'general_categories' => [],
            'regions' => [['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)']],
            'region_specifics' => [['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1]],
            'provinces' => [['id' => 7, 'region_id' => 1, 'name' => 'Pampanga', 'enabled' => true]],
            'municipalities' => [['id' => 9, 'region_id' => 1, 'province_id' => 7, 'name' => 'City of Angeles', 'enabled' => true]],
            'barangays' => [['id' => 55, 'municipality_id' => 9, 'psgc_code' => '0305401001', 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true]],
            'area_clusters' => [['id' => 61, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true]],
            'customers' => [[
                'id' => 99,
                'company_id' => null,
                'name' => 'Synced Customer',
                'region_specific_id' => 1,
                'province_id' => 7,
                'municipality_id' => 9,
                'barangay_id' => 55,
                'area_cluster_id' => 61,
                'address' => '123 Roxas Street',
                'latitude' => 15.1456,
                'longitude' => 120.5887,
                'is_active' => true,
            ]],
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
    expect($customer->province_id)->toBe(7)
        ->and($customer->barangay_id)->toBe(55)
        ->and($customer->area_cluster_id)->toBe(61);
});

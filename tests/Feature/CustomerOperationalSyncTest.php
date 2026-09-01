<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('customer operational fields and trade tables are present in the retained SQLite schema', function () {
    expect(Schema::hasColumns('customers', ['general_category_id', 'competitor_volume', 'company_id']))->toBeTrue()
        ->and(Schema::hasTable('customer_trade_profiles'))->toBeTrue()
        ->and(Schema::hasTable('customer_category_histories'))->toBeTrue();
});

test('pull persists customer category and competitor volume without affecting selection fields', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response([
            'companies' => [['id' => 7, 'name' => 'OMMC', 'code' => 'OMMC']],
            'general_categories' => [['id' => 1, 'name' => 'Mixed Outlet', 'sort' => 1]],
            'regions' => [['id' => 1, 'code' => 'R1', 'name' => 'Region 1']],
            'region_specifics' => [['id' => 2, 'region_id' => 1, 'name' => 'Region Specific']],
            'municipalities' => [['id' => 3, 'region_id' => 1, 'name' => 'Municipality', 'enabled' => true]],
            'customers' => [[
                'id' => 99,
                'company_id' => 7,
                'unique_id' => 'C-99',
                'name' => 'Synced Customer',
                'address' => 'Customer Address',
                'contact_person' => 'Contact Person',
                'contact_number' => '09171234567',
                'region_specific_id' => 2,
                'municipality_id' => 3,
                'latitude' => 10.123456,
                'longitude' => 123.123456,
                'general_category_id' => 1,
                'competitor_volume' => 2,
                'is_active' => true,
            ]],
            'customer_trade_profiles' => [[
                'customer_id' => 99, 'house_number' => '12A', 'entry_detail' => 'AB',
                'classifications' => ['Battery Specialist'], 'ommc_brands' => ['Motolite'],
                'working_days' => ['Monday'], 'delivery_method' => 'resq_hub',
            ]],
            'customer_category_histories' => [
                ['customer_id' => 99, 'category_year' => 2018, 'category' => 'AB Loyal'],
                ['customer_id' => 99, 'category_year' => 2024, 'category' => 'AB Loyal'],
            ],
            'salescall_statuses' => [], 'salescall_types' => [], 'material_groups' => [], 'brands' => [],
            'categories' => [], 'sub_categories' => [], 'sub_sub_categories' => [],
            'salescall_image_categories' => [], 'salescall_image_types' => [], 'itineraries' => [],
            'customer_brands' => [], 'customer_categories' => [], 'customer_notes' => [],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
    $customer = Customer::findOrFail(99);
    expect($customer->general_category_id)->toBe(1)
        ->and($customer->competitor_volume)->toBe(2)
        ->and($customer->unique_id)->toBe('C-99')
        ->and($customer->name)->toBe('Synced Customer')
        ->and($customer->address)->toBe('Customer Address')
        ->and((float) $customer->latitude)->toBe(10.123456)
        ->and((float) $customer->longitude)->toBe(123.123456);

    $profile = DB::table('customer_trade_profiles')->where('customer_id', 99)->first();
    expect($profile->entry_detail)->toBe('AB')
        ->and(json_decode($profile->classifications, true))->toBe(['Battery Specialist'])
        ->and(DB::table('customer_category_histories')->where('customer_id', 99)->count())->toBe(2);
});

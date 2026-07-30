<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Regression test: on a fresh install, material_groups/brands/categories/
 * sub_categories/customers are all empty locally. If the first pull's
 * itineraries already carry salescall_brands/salescall_category data (e.g.
 * an RSM-added call synced from the portal), inserting those rows must not
 * fail with a foreign key violation just because the reference tables they
 * point at haven't been populated yet in the same pull.
 */
test('pull populates reference tables before rows that hold foreign keys into them', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    expect(MaterialGroup::count())->toBe(0);
    expect(Brand::count())->toBe(0);
    expect(Category::count())->toBe(0);
    expect(SubCategory::count())->toBe(0);
    expect(Customer::count())->toBe(0);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response([
            'customers' => [
                ['id' => 501, 'name' => 'Fresh Customer', 'is_active' => true],
            ],
            'salescall_statuses' => [['id' => 1, 'name' => 'Pending']],
            'salescall_types' => [['id' => 1, 'name' => 'Scheduled']],
            'material_groups' => [['id' => 1, 'name' => 'Batteries']],
            'brands' => [
                ['id' => 10, 'material_group_id' => 1, 'name' => 'Motolite', 'enabled' => true],
            ],
            'categories' => [['id' => 20, 'name' => 'Retail']],
            'sub_categories' => [['id' => 30, 'category_id' => 20, 'name' => 'Battery Shop']],
            'sub_sub_categories' => [],
            'salescall_image_categories' => [],
            'salescall_image_types' => [],
            'customer_brands' => [],
            'customer_categories' => [],
            'customer_notes' => [],
            'itineraries' => [
                [
                    'id' => 100,
                    'local_uuid' => 'itin-uuid-1',
                    'date_month' => 7,
                    'date_year' => 2026,
                    'itinerary_status_id' => 2,
                    'salescalls' => [
                        [
                            'id' => 200,
                            'local_uuid' => 'sc-uuid-1',
                            'customer_id' => 501,
                            'salescall_type_id' => 1,
                            'salescall_status_id' => 1,
                            // Already has brand + category data attached on the portal
                            // (e.g. an RSM-added call) — this is what previously broke
                            // a fresh install's first pull.
                            'salescall_brands' => [
                                ['material_group_id' => 1, 'brand_id' => 10, 'quantity' => 5],
                            ],
                            'salescall_category' => ['category_id' => 20, 'sub_category_id' => 30],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
    expect(Customer::count())->toBe(1);
    expect(MaterialGroup::count())->toBe(1);
    expect(Brand::count())->toBe(1);
    expect(Category::count())->toBe(1);
    expect(SubCategory::count())->toBe(1);

    $salescall = Salescall::where('local_uuid', 'sc-uuid-1')->firstOrFail();
    expect(SalescallBrand::where('salescall_id', $salescall->id)->count())->toBe(1);
    expect(SalescallCategory::where('salescall_id', $salescall->id)->count())->toBe(1);

    expect(Itinerary::where('local_uuid', 'itin-uuid-1')->exists())->toBeTrue();
});

/**
 * Defense in depth: even if a single salescall_brands row references a
 * material_group_id/brand_id that genuinely doesn't exist anywhere in the
 * payload (e.g. a brand disabled on the portal after the data was recorded),
 * that one row must be skipped — not abort the whole pull and lose every
 * other itinerary/customer for this user.
 */
test('pull skips a single dangling brand reference instead of aborting the whole sync', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response([
            'customers' => [
                ['id' => 501, 'name' => 'Customer A', 'is_active' => true],
                ['id' => 502, 'name' => 'Customer B', 'is_active' => true],
            ],
            'salescall_statuses' => [['id' => 1, 'name' => 'Pending']],
            'salescall_types' => [['id' => 1, 'name' => 'Scheduled']],
            'material_groups' => [['id' => 1, 'name' => 'Batteries']],
            'brands' => [
                ['id' => 10, 'material_group_id' => 1, 'name' => 'Motolite', 'enabled' => true],
            ],
            'categories' => [],
            'sub_categories' => [],
            'sub_sub_categories' => [],
            'salescall_image_categories' => [],
            'salescall_image_types' => [],
            'customer_brands' => [],
            'customer_categories' => [],
            'customer_notes' => [],
            'itineraries' => [
                [
                    'id' => 100,
                    'local_uuid' => 'itin-uuid-1',
                    'date_month' => 7,
                    'date_year' => 2026,
                    'itinerary_status_id' => 2,
                    'salescalls' => [
                        [
                            'id' => 200,
                            'local_uuid' => 'sc-uuid-dangling',
                            'customer_id' => 501,
                            'salescall_type_id' => 1,
                            'salescall_status_id' => 1,
                            // References a brand that doesn't exist anywhere in this
                            // payload's 'brands' array — must be skipped, not fatal.
                            'salescall_brands' => [
                                ['material_group_id' => 1, 'brand_id' => 999, 'quantity' => 5],
                            ],
                        ],
                        [
                            'id' => 201,
                            'local_uuid' => 'sc-uuid-valid',
                            'customer_id' => 502,
                            'salescall_type_id' => 1,
                            'salescall_status_id' => 1,
                            'salescall_brands' => [
                                ['material_group_id' => 1, 'brand_id' => 10, 'quantity' => 3],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
    expect(Itinerary::where('local_uuid', 'itin-uuid-1')->exists())->toBeTrue();
    expect(Customer::count())->toBe(2);

    $dangling = Salescall::where('local_uuid', 'sc-uuid-dangling')->firstOrFail();
    expect(SalescallBrand::where('salescall_id', $dangling->id)->count())->toBe(0);

    $valid = Salescall::where('local_uuid', 'sc-uuid-valid')->firstOrFail();
    expect(SalescallBrand::where('salescall_id', $valid->id)->count())->toBe(1);
});

<?php

use App\Models\User;
use App\Services\CustomerAggregateReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function customerAggregateRow(int $portalId, string $uuid, array $attributes = []): array
{
    return array_replace([
        'id' => $portalId,
        'local_uuid' => $uuid,
        'name' => 'Portal Customer',
        'unique_id' => 'PORTAL-CODE',
        'company_id' => null,
        'general_category_id' => null,
        'region_specific_id' => null,
        'area_cluster_id' => null,
        'province_id' => null,
        'municipality_id' => null,
        'barangay_id' => null,
        'contact_person' => null,
        'contact_number' => null,
        'business_landline_number' => null,
        'business_mobile_number' => null,
        'date_established' => null,
        'address' => 'Portal address',
        'latitude' => 14.5,
        'longitude' => 121.0,
        'is_active' => true,
        'competitor_volume' => null,
        'landmass_code' => null,
        'person_in_charge_email' => null,
        'access_user_emails' => [],
        'updated_at' => '2026-10-13T01:00:00.000000Z',
    ], $attributes);
}

function completeCustomerAggregate(array $customer, array $sections = []): array
{
    return array_replace([
        'contract_version' => 1,
        'capabilities' => ['customer.aggregate.snapshot.v1'],
        'complete_collections' => CustomerAggregateReconciler::COMPLETE_COLLECTIONS,
        'customers' => [$customer],
        'customer_users' => [],
        'customer_brands' => [],
        'customer_categories' => [],
        'customer_trade_profiles' => [],
        'customer_category_histories' => [],
        'customer_category_events' => [],
    ], $sections);
}

function seedCustomerAggregateDependencies(): void
{
    DB::table('material_groups')->insert([
        ['id' => 1, 'name' => 'Group 1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Group 2', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('brands')->insert([
        ['id' => 1, 'material_group_id' => 1, 'name' => 'Brand 1', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'material_group_id' => 2, 'name' => 'Brand 2', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('categories')->insert([
        ['id' => 1, 'name' => 'Category 1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Category 2', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('sub_categories')->insert([
        ['id' => 1, 'category_id' => 1, 'name' => 'Subcategory 1', 'with_form' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'category_id' => 2, 'name' => 'Subcategory 2', 'with_form' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function seedAggregateCustomer(int $localId, int $serverId, string $uuid, string $status = 'synced', array $attributes = []): void
{
    DB::table('customers')->insert(array_replace([
        'id' => $localId,
        'server_id' => $serverId,
        'local_uuid' => $uuid,
        'name' => 'Local Customer',
        'unique_id' => 'LOCAL-CODE',
        'company_id' => null,
        'is_active' => true,
        'sync_status' => $status,
        'sync_attempts' => 0,
        'server_updated_at' => '2026-10-01 00:00:00',
        'customer_code_reservation_token' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

test('complete Portal aggregate replaces synchronized fields and children idempotently while retaining local ID and local-only metadata', function () {
    seedCustomerAggregateDependencies();
    $pic = User::factory()->create(['email' => 'pic.aggregate@example.test']);
    $access = User::factory()->create(['email' => 'access.aggregate@example.test']);
    $localId = -883001;
    $portalId = 883001;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'syncing', [
        'name' => 'Before acknowledgment',
        'customer_code_reservation_token' => '71a136cc-61bb-4dbf-aecb-ce8606bd9bb7',
        'person_in_charge_id' => null,
    ]);
    DB::table('customer_user')->insert(['customer_id' => $localId, 'user_id' => $pic->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_trade_profiles')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'entry_detail' => 'old',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_category_histories')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'stream' => 'ib', 'category_year' => 2025,
        'category' => 'Old history', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_category_events')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'stream' => 'ib', 'category' => 'Old event',
        'effective_at' => '2025-01-01 00:00:00', 'event_key' => 'old-event', 'source' => 'portal',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_brands')->insert([
        'customer_id' => $localId, 'material_group_id' => 2, 'brand_id' => 2, 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_categories')->insert([
        'customer_id' => $localId, 'category_id' => 1, 'sub_category_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = customerAggregateRow($portalId, $uuid, [
        'name' => 'Portal-authoritative Customer',
        'unique_id' => 'PORTAL-AUTHORITATIVE-CODE',
        'address' => 'Portal complete address',
        'person_in_charge_email' => $pic->email,
        'access_user_emails' => [$access->email],
    ]);
    $snapshot = completeCustomerAggregate($customer, [
        'customer_users' => [
            ['name' => $pic->name, 'email' => $pic->email],
            ['name' => $access->name, 'email' => $access->email],
        ],
        'customer_brands' => [['customer_id' => $portalId, 'material_group_id' => 1, 'brand_id' => 1, 'quantity' => 7]],
        'customer_categories' => [['customer_id' => $portalId, 'category_id' => 2, 'sub_category_id' => 2]],
        'customer_trade_profiles' => [[
            'customer_id' => $portalId,
            'profile_type' => 'outlet',
            'entry_detail' => 'AB',
            'profile_data' => ['active' => ['delivery_type' => 'no']],
            'classifications' => ['Battery Specialist'],
        ]],
        'customer_category_histories' => [[
            'customer_id' => $portalId, 'profile_type' => 'outlet', 'stream' => 'ab',
            'category_year' => 2026, 'category' => 'AB Loyal',
        ]],
        'customer_category_events' => [[
            'customer_id' => $portalId, 'profile_type' => 'outlet', 'stream' => 'ab',
            'category' => 'AB Loyal', 'effective_at' => '2026-10-01T00:00:00.000000Z',
            'event_key' => 'portal-event', 'source' => 'portal', 'supersedes_event_key' => null,
        ]],
    ]);

    $reconciler = app(CustomerAggregateReconciler::class);
    $fingerprint = $reconciler->fingerprint($localId);
    $first = $reconciler->reconcile($snapshot, [], $localId, $fingerprint, true);
    $second = $reconciler->reconcile($snapshot, [], $localId, null, true);
    $saved = DB::table('customers')->where('id', $localId)->first();

    expect($first['status'])->toBe('applied')
        ->and($second['status'])->toBe('applied')
        ->and($saved->id)->toBe($localId)
        ->and($saved->server_id)->toBe($portalId)
        ->and($saved->name)->toBe('Portal-authoritative Customer')
        ->and($saved->unique_id)->toBe('PORTAL-AUTHORITATIVE-CODE')
        ->and($saved->customer_code_reservation_token)->toBe('71a136cc-61bb-4dbf-aecb-ce8606bd9bb7')
        ->and($saved->person_in_charge_id)->toBe($pic->id)
        ->and(DB::table('customer_user')->where('customer_id', $localId)->pluck('user_id')->all())->toBe([$access->id])
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $localId)->value('entry_detail'))->toBe('AB')
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $localId)->value('profile_type'))->toBe('outlet')
        ->and(DB::table('customer_category_histories')->where('customer_id', $localId)->count())->toBe(1)
        ->and(DB::table('customer_category_histories')->where('customer_id', $localId)->value('category'))->toBe('AB Loyal')
        ->and(DB::table('customer_category_events')->where('customer_id', $localId)->pluck('event_key')->all())->toBe(['portal-event'])
        ->and(DB::table('customer_brands')->where('customer_id', $localId)->count())->toBe(1)
        ->and(DB::table('customer_brands')->where('customer_id', $localId)->value('quantity'))->toBe(7)
        ->and(DB::table('customer_categories')->where('customer_id', $localId)->value('category_id'))->toBe(2)
        ->and(DB::table('customers')->where('server_id', $portalId)->count())->toBe(1);
});

test('Pull preserves pending local edits and records a conflict when Portal advanced from the saved base', function () {
    $localId = -883002;
    $portalId = 883002;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'pending', [
        'name' => 'Unsynced local edit',
        'server_updated_at' => '2026-10-01 00:00:00',
    ]);
    DB::table('customer_trade_profiles')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'entry_detail' => 'local',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $customer = customerAggregateRow($portalId, $uuid, ['name' => 'New Portal value', 'updated_at' => '2026-10-02T00:00:00.000000Z']);

    $result = app(CustomerAggregateReconciler::class)->reconcile(['customers' => [$customer]], [], null, null, false);
    $saved = DB::table('customers')->where('id', $localId)->first();

    expect($result['status'])->toBe('conflict')
        ->and($saved->name)->toBe('Unsynced local edit')
        ->and($saved->sync_status)->toBe('conflict')
        ->and($saved->sync_error)->toContain('pending local edits')
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $localId)->value('entry_detail'))->toBe('local');
});

test('Pull keeps local pending edits without flagging a conflict when Portal has not advanced', function () {
    $localId = -883003;
    $portalId = 883003;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'pending', [
        'name' => 'Unsynced local edit',
        'server_updated_at' => '2026-10-01 00:00:00',
    ]);
    $customer = customerAggregateRow($portalId, $uuid, ['updated_at' => '2026-10-01T00:00:00.000000Z']);

    $result = app(CustomerAggregateReconciler::class)->reconcile(['customers' => [$customer]], [], null, null, false);
    $saved = DB::table('customers')->where('id', $localId)->first();

    expect($result['status'])->toBe('preserved_local')
        ->and($saved->name)->toBe('Unsynced local edit')
        ->and($saved->sync_status)->toBe('pending');
});

test('an in-flight acknowledgment preserves edits made after the Push payload was sent', function () {
    $localId = -883004;
    $portalId = 883004;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'syncing', ['server_id' => null, 'name' => 'Sent value']);
    $reconciler = app(CustomerAggregateReconciler::class);
    $sentFingerprint = $reconciler->fingerprint($localId);
    DB::table('customers')->where('id', $localId)->update(['name' => 'Edited while request ran', 'sync_status' => 'pending']);
    $customer = customerAggregateRow($portalId, $uuid, ['name' => 'Portal accepted sent value']);
    $snapshot = completeCustomerAggregate($customer);

    $result = $reconciler->reconcile($snapshot, [], $localId, $sentFingerprint, true);
    $saved = DB::table('customers')->where('id', $localId)->first();

    expect($result['status'])->toBe('conflict')
        ->and($saved->name)->toBe('Edited while request ran')
        ->and($saved->server_id)->toBe($portalId)
        ->and($saved->sync_status)->toBe('conflict')
        ->and($saved->sync_error)->toContain('changed while Push was in flight');
});

test('legacy Pull fields remain compatible but incomplete child collections are never cleared', function () {
    seedCustomerAggregateDependencies();
    $localId = -883005;
    $portalId = 883005;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'synced');
    DB::table('customer_trade_profiles')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'entry_detail' => 'keep profile',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_category_histories')->insert([
        'customer_id' => $localId, 'profile_type' => 'ib', 'stream' => 'ib', 'category_year' => 2025,
        'category' => 'Keep history', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_brands')->insert([
        'customer_id' => $localId, 'material_group_id' => 1, 'brand_id' => 1, 'quantity' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_categories')->insert([
        'customer_id' => $localId, 'category_id' => 1, 'sub_category_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = customerAggregateRow($portalId, $uuid, ['name' => 'Legacy Portal update']);
    unset($customer['access_user_emails']);
    $result = app(CustomerAggregateReconciler::class)->reconcile([
        'customers' => [$customer],
        'customer_users' => [],
        'customer_trade_profiles' => [],
        'customer_category_histories' => [],
        'customer_category_events' => [],
        'customer_brands' => [],
        'customer_categories' => [],
    ]);

    expect($result['status'])->toBe('applied')
        ->and(DB::table('customers')->where('id', $localId)->value('name'))->toBe('Legacy Portal update')
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $localId)->value('entry_detail'))->toBe('keep profile')
        ->and(DB::table('customer_category_histories')->where('customer_id', $localId)->value('category'))->toBe('Keep history')
        ->and(DB::table('customer_brands')->where('customer_id', $localId)->value('quantity'))->toBe(3)
        ->and(DB::table('customer_categories')->where('customer_id', $localId)->value('category_id'))->toBe(1);
});

test('complete Portal history snapshots preserve a local Tablet event and its derived history row', function () {
    $localId = -883007;
    $portalId = 883007;
    $uuid = (string) Str::uuid();
    seedAggregateCustomer($localId, $portalId, $uuid, 'synced');
    DB::table('customer_category_events')->insert([
        'customer_id' => $localId, 'profile_type' => 'outlet', 'stream' => 'ab',
        'category' => 'Local pending category', 'effective_at' => '2026-02-01 00:00:00',
        'event_key' => 'tablet-local-event', 'source' => 'tablet',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_category_histories')->insert([
        'customer_id' => $localId, 'profile_type' => 'outlet', 'stream' => 'ab', 'category_year' => 2026,
        'category' => 'Local pending category', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = completeCustomerAggregate(customerAggregateRow($portalId, $uuid));
    app(CustomerAggregateReconciler::class)->reconcile($snapshot);

    expect(DB::table('customer_category_events')->where('customer_id', $localId)->where('event_key', 'tablet-local-event')->exists())->toBeTrue()
        ->and(DB::table('customer_category_histories')->where('customer_id', $localId)->where('category_year', 2026)->value('category'))->toBe('Local pending category');
});

test('complete Portal snapshots retain Customer brand and category summaries with pending Sales Call children', function () {
    seedCustomerAggregateDependencies();
    $localId = -883008;
    $portalId = 883008;
    $uuid = (string) Str::uuid();
    $creator = User::factory()->create();
    seedAggregateCustomer($localId, $portalId, $uuid);
    $itineraryId = DB::table('itineraries')->insertGetId([
        'itinerary_status_id' => 1, 'created_by' => $creator->id,
        'local_uuid' => (string) Str::uuid(), 'sync_status' => 'synced',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $salescallId = DB::table('salescalls')->insertGetId([
        'itinerary_id' => $itineraryId, 'customer_id' => $localId, 'created_by' => $creator->id,
        'local_uuid' => (string) Str::uuid(), 'sync_status' => 'synced',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('salescall_brands')->insert([
        'salescall_id' => $salescallId, 'customer_id' => $localId,
        'material_group_id' => 1, 'brand_id' => 1, 'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('salescall_categories')->insert([
        'salescall_id' => $salescallId, 'customer_id' => $localId,
        'category_id' => 1, 'sub_category_id' => 1, 'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_brands')->insert([
        'customer_id' => $localId, 'material_group_id' => 2, 'brand_id' => 2, 'quantity' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_categories')->insert([
        'customer_id' => $localId, 'category_id' => 2, 'sub_category_id' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = completeCustomerAggregate(customerAggregateRow($portalId, $uuid), [
        'customer_brands' => [['customer_id' => $portalId, 'material_group_id' => 1, 'brand_id' => 1, 'quantity' => 9]],
        'customer_categories' => [['customer_id' => $portalId, 'category_id' => 1, 'sub_category_id' => 1]],
    ]);
    app(CustomerAggregateReconciler::class)->reconcile($snapshot);

    expect(DB::table('customer_brands')->where('customer_id', $localId)->value('quantity'))->toBe(5)
        ->and(DB::table('customer_categories')->where('customer_id', $localId)->value('category_id'))->toBe(2)
        ->and(DB::table('salescall_brands')->where('customer_id', $localId)->where('sync_status', 'pending')->exists())->toBeTrue()
        ->and(DB::table('salescall_categories')->where('customer_id', $localId)->where('sync_status', 'pending')->exists())->toBeTrue();
});

test('different UUIDs sharing a server ID remain a conflict and no Customer values change', function () {
    $localId = 883006;
    $otherUuid = (string) Str::uuid();
    seedAggregateCustomer($localId, 883006, $otherUuid, 'synced', ['name' => 'Unrelated numeric collision']);
    $incoming = customerAggregateRow(883006, (string) Str::uuid(), ['name' => 'Different Portal identity']);

    expect(fn () => app(CustomerAggregateReconciler::class)->reconcile(['customers' => [$incoming]]))
        ->toThrow(RuntimeException::class, 'server_id_has_different_local_uuid')
        ->and(DB::table('customers')->where('id', $localId)->value('name'))->toBe('Unrelated numeric collision');
});

test('Customer 23591 duplicate server association is rejected without merging either local aggregate', function () {
    $canonicalId = -687143025929916172;
    $canonicalUuid = (string) Str::uuid();
    $separateUuid = (string) Str::uuid();
    seedAggregateCustomer($canonicalId, 23591, $canonicalUuid, 'synced', [
        'name' => 'Canonical Portal identity', 'unique_id' => 'PORTAL-23591',
    ]);
    seedAggregateCustomer(23591, 23591, $separateUuid, 'synced', [
        'name' => 'Different local Customer', 'unique_id' => 'LOCAL-DIFFERENT-CUSTOMER',
    ]);
    $incoming = customerAggregateRow(23591, $canonicalUuid, [
        'name' => 'Portal Customer 23591', 'unique_id' => 'PORTAL-23591',
    ]);

    expect(fn () => app(CustomerAggregateReconciler::class)->reconcile(['customers' => [$incoming]]))
        ->toThrow(RuntimeException::class, 'duplicate_server_id')
        ->and(DB::table('customers')->where('id', $canonicalId)->value('name'))->toBe('Canonical Portal identity')
        ->and(DB::table('customers')->where('id', 23591)->value('name'))->toBe('Different local Customer')
        ->and(DB::table('customers')->where('server_id', 23591)->count())->toBe(2);
});

<?php

use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function insertPushCustomer(string $name, string $status = 'pending', int $attempts = 0, ?string $reservationToken = null): int
{
    return DB::table('customers')->insertGetId([
        'local_uuid' => (string) Str::uuid(),
        'name' => $name,
        'unique_id' => 'CODE-'.$name,
        'customer_code_reservation_token' => $reservationToken,
        'is_active' => true,
        'sync_status' => $status,
        'sync_attempts' => $attempts,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('Customer-only push sends pending and retryable failed customers and reconciles success', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $token = '11111111-1111-4111-8111-111111111111';
    $pendingId = insertPushCustomer('Pending Shop', 'pending', 0, $token);
    $retryId = insertPushCustomer('Retry Shop', 'failed', 2);
    insertPushCustomer('Exhausted Shop', 'failed', 3);
    DB::table('itineraries')->insert(['local_uuid' => (string) Str::uuid(), 'sync_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $ackIds = [301, 302];
    $ackIndex = 0;
    Http::fake(function ($request) use (&$ackIndex, $ackIds) {
        $serverId = $ackIds[$ackIndex++];

        return Http::response([
            'server_id' => $serverId,
            'local_uuid' => $request['local_uuid'],
            'unique_id' => $serverId === 301 ? 'PORTAL-CODE' : null,
            'updated_at' => now()->toISOString(),
        ]);
    });

    $result = app(SyncService::class)->pushPendingCustomers();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(2)
        ->and(DB::table('customers')->where('id', $pendingId)->value('server_id'))->toBe(301)
        ->and(DB::table('customers')->where('id', $pendingId)->value('unique_id'))->toBe('PORTAL-CODE')
        ->and(DB::table('customers')->where('id', $pendingId)->value('customer_code_reservation_token'))->toBeNull()
        ->and(DB::table('customers')->where('id', $pendingId)->value('sync_status'))->toBe('synced')
        ->and(DB::table('customers')->where('id', $retryId)->value('sync_status'))->toBe('synced')
        ->and(DB::table('itineraries')->value('sync_status'))->toBe('pending');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://portal.test/api/customers/sync'
        && $request->data()['customer_code_reservation_token'] === $token);
});

test('Customer-only push retains a failed local record as retryable', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Offline Shop');
    Http::fake(['portal.test/api/customers/sync' => Http::response(['message' => 'Unavailable'], 503)]);

    $result = app(SyncService::class)->pushCustomer($customerId);
    $customer = DB::table('customers')->where('id', $customerId)->first();

    expect($result->success)->toBeFalse()
        ->and($customer)->not->toBeNull()
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1)
        ->and($customer->sync_error)->not->toBeNull();
});

test('Customer Push acknowledgment resolves the existing local row by UUID and preserves its local key', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Acknowledged Offline Shop');
    $localUuid = DB::table('customers')->where('id', $customerId)->value('local_uuid');
    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'server_id' => 72011,
        'local_uuid' => $localUuid,
        'unique_id' => 'PORTAL-72011',
        'updated_at' => now()->toISOString(),
    ])]);

    $result = app(SyncService::class)->pushCustomer($customerId);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toContain('Portal lacks the authoritative aggregate snapshot contract')
        ->and(DB::table('customers')->where('id', $customerId)->value('id'))->toBe($customerId)
        ->and(DB::table('customers')->where('id', $customerId)->value('server_id'))->toBe(72011)
        ->and(DB::table('customers')->where('id', $customerId)->value('local_uuid'))->toBe($localUuid);
});

test('versioned Customer Push acknowledgment applies the authoritative aggregate to the existing local row', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Offline name');
    $localUuid = DB::table('customers')->where('id', $customerId)->value('local_uuid');
    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'server_id' => 72021,
        'local_uuid' => $localUuid,
        'unique_id' => 'PORTAL-72021',
        'updated_at' => now()->toISOString(),
        'customer_snapshot' => [
            'contract_version' => 1,
            'capabilities' => ['customer.aggregate.snapshot.v1'],
            'complete_collections' => [
                'customers', 'customer_users', 'customer_brands', 'customer_categories',
                'customer_trade_profiles', 'customer_category_histories', 'customer_category_events',
            ],
            'customers' => [[
                'id' => 72021,
                'local_uuid' => $localUuid,
                'name' => 'Portal-authoritative name',
                'unique_id' => 'PORTAL-72021',
                'company_id' => null,
                'is_active' => true,
                'access_user_emails' => [],
                'person_in_charge_email' => null,
                'updated_at' => now()->toISOString(),
            ]],
            'customer_users' => [],
            'customer_brands' => [],
            'customer_categories' => [],
            'customer_trade_profiles' => [],
            'customer_category_histories' => [],
            'customer_category_events' => [],
        ],
    ])]);

    $result = app(SyncService::class)->pushCustomer($customerId);
    $saved = DB::table('customers')->where('id', $customerId)->first();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(1)
        ->and($saved->id)->toBe($customerId)
        ->and($saved->server_id)->toBe(72021)
        ->and($saved->local_uuid)->toBe($localUuid)
        ->and($saved->name)->toBe('Portal-authoritative name')
        ->and($saved->sync_status)->toBe('synced');
});

test('Customer Push acknowledgment with a conflicting UUID does not bind the Portal identity', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Conflicting Acknowledgment Shop');
    $unrelatedId = insertPushCustomer('Unrelated Existing Portal Shop', 'synced');
    DB::table('customers')->where('id', $unrelatedId)->update(['server_id' => 72012]);
    $localUuid = DB::table('customers')->where('id', $customerId)->value('local_uuid');
    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'server_id' => 72012,
        'local_uuid' => (string) Str::uuid(),
        'unique_id' => 'UNRELATED-CODE',
        'updated_at' => now()->toISOString(),
    ])]);

    $result = app(SyncService::class)->pushCustomer($customerId);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('customer_identity_conflict')
        ->and(DB::table('customers')->where('id', $customerId)->value('server_id'))->toBeNull()
        ->and(DB::table('customers')->where('id', $customerId)->value('local_uuid'))->toBe($localUuid)
        ->and(DB::table('customers')->where('id', $customerId)->value('sync_status'))->toBe('conflict')
        ->and(DB::table('customers')->where('id', $unrelatedId)->value('server_id'))->toBe(72012);
});

test('Customer-only push reports partial success and failure accurately', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Synced Shop');
    $failedId = insertPushCustomer('Failed Shop');
    $ackCount = 0;
    Http::fake(function ($request) use (&$ackCount) {
        if ($ackCount++ > 0) {
            return Http::response(['message' => 'Unavailable'], 503);
        }

        return Http::response(['server_id' => 305, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
    });

    $result = app(SyncService::class)->pushPendingCustomers();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(1)
        ->and($result->failedCount)->toBe(1)
        ->and($result->message)->toContain('1 item synced. 1 item could not be uploaded')
        ->and(DB::table('customers')->where('id', $failedId)->value('sync_status'))->toBe('failed');
});

test('Customer-only push targets the requested customer and excludes ineligible records', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $targetId = insertPushCustomer('Target Shop');
    insertPushCustomer('Other Shop');
    Http::fake(function ($request) {
        return Http::response(['server_id' => 303, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
    });

    $result = app(SyncService::class)->pushCustomer($targetId);

    expect($result->syncedCount)->toBe(1);
    Http::assertSentCount(1);
});

test('an exhausted Customer requires a saved correction and retries once with its existing identity and reservation', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $token = '22222222-2222-4222-8222-222222222222';
    $customerId = insertPushCustomer('Before Correction', 'failed', 3, $token);
    DB::table('customers')->where('id', $customerId)->update(['local_uuid' => '11111111-1111-4111-8111-111111111111', 'sync_error' => '422: invalid optional value']);
    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'server_id' => 991,
        'local_uuid' => '11111111-1111-4111-8111-111111111111',
        'unique_id' => 'CODE-Before Correction',
        'updated_at' => now()->toISOString(),
    ])]);

    $service = app(SyncService::class);
    expect($service->retryExhaustedCustomer($customerId)->errorCode)->toBe('correction_required');
    Http::assertNothingSent();

    DB::table('customers')->where('id', $customerId)->update(['name' => 'Corrected Shop']);
    $corrected = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->findOrFail($customerId);
    DB::table('sync_states')->insert([
        'key' => 'customer.manual_retry_ready.'.$customerId,
        'value' => json_encode($service->customerPayloadFingerprint($corrected)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = $service->retryExhaustedCustomer($customerId);
    $saved = DB::table('customers')->where('id', $customerId)->first();

    expect($result->syncedCount)->toBe(1)
        ->and($saved->sync_status)->toBe('synced')
        ->and($saved->sync_attempts)->toBe(3)
        ->and($saved->server_id)->toBe(991)
        ->and($saved->local_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($saved->customer_code_reservation_token)->toBeNull()
        ->and($saved->sync_error)->toContain('invalid optional value');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['name'] === 'Corrected Shop'
        && $request['unique_id'] === 'CODE-Before Correction'
        && $request['customer_code_reservation_token'] === $token
        && $request['local_uuid'] === '11111111-1111-4111-8111-111111111111');
});

test('an in-flight claim prevents a concurrent Customer push from issuing another request', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Claimed Shop', 'pending', 0);
    $concurrent = null;
    Http::fake(function ($request) use ($customerId, &$concurrent) {
        if (str_ends_with($request->url(), '/api/customers/sync')) {
            $concurrent = app(SyncService::class)->pushCustomer($customerId);

            return Http::response(['server_id' => 992, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
        }

        return Http::response([], 404);
    });

    $result = app(SyncService::class)->pushCustomer($customerId);

    expect($result->syncedCount)->toBe(1)
        ->and($concurrent?->syncedCount)->toBe(0)
        ->and(DB::table('customers')->where('id', $customerId)->value('sync_status'))->toBe('synced');
    Http::assertSentCount(1);
});

test('Portal rejects an invalid local reference as a recoverable failure and explicit retry accepts its correction', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    DB::table('regions')->insert(['id' => 7, 'code' => 'R7', 'name' => 'Region 7', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('region_specifics')->insert([
        ['id' => 53, 'region_id' => 7, 'name' => 'Retained Historical Row', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 54, 'region_id' => 7, 'name' => 'Corrected Current Row', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $customerId = insertPushCustomer('Stale Reference Customer', 'failed', 3);
    $localUuid = '77777777-7777-4777-8777-777777777777';
    $reservationToken = '77777777-7777-4777-8777-777777777778';
    DB::table('customers')->where('id', $customerId)->update([
        'local_uuid' => $localUuid,
        'region_specific_id' => 53,
        'customer_code_reservation_token' => $reservationToken,
        'sync_error' => 'Earlier failed Portal validation',
    ]);
    $service = app(SyncService::class);
    $customer = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->findOrFail($customerId);
    DB::table('sync_states')->insert([
        'key' => 'customer.manual_retry_ready.'.$customerId,
        'value' => json_encode($service->customerPayloadFingerprint($customer)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake(['portal.test/api/customers/sync' => Http::sequence()
        ->push(['message' => 'Invalid Specific Region ID.', 'errors' => ['region_specific_id' => ['ID 53 does not exist in Portal.']]], 422)
        ->push(['server_id' => 993, 'local_uuid' => $localUuid, 'unique_id' => 'CODE-Stale Reference Customer', 'updated_at' => now()->toISOString()])]);

    $rejected = $service->retryExhaustedCustomer($customerId);
    $afterRejection = DB::table('customers')->where('id', $customerId)->first();

    expect($rejected->errorCode)->toBe('push_failed')
        ->and($rejected->message)->toContain('HTTP 422', 'ID 53 does not exist in Portal')
        ->and($afterRejection->region_specific_id)->toBe(53)
        ->and($afterRejection->sync_status)->toBe('failed')
        ->and($afterRejection->sync_attempts)->toBe(4)
        ->and($afterRejection->unique_id)->toBe('CODE-Stale Reference Customer')
        ->and($afterRejection->customer_code_reservation_token)->toBe($reservationToken);

    DB::table('customers')->where('id', $customerId)->update(['name' => 'Corrected Reference Customer', 'region_specific_id' => 54]);
    $corrected = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->findOrFail($customerId);
    DB::table('sync_states')->updateOrInsert(
        ['key' => 'customer.manual_retry_ready.'.$customerId],
        ['value' => json_encode($service->customerPayloadFingerprint($corrected)), 'created_at' => now(), 'updated_at' => now()],
    );

    $retried = $service->retryExhaustedCustomer($customerId);
    $afterCorrection = DB::table('customers')->where('id', $customerId)->first();

    expect($retried->syncedCount)->toBe(1)
        ->and($afterCorrection->id)->toBe($customerId)
        ->and($afterCorrection->local_uuid)->toBe($localUuid)
        ->and($afterCorrection->sync_status)->toBe('synced')
        ->and($afterCorrection->sync_attempts)->toBe(4)
        ->and($afterCorrection->region_specific_id)->toBe(54)
        ->and($afterCorrection->unique_id)->toBe('CODE-Stale Reference Customer')
        ->and($afterCorrection->customer_code_reservation_token)->toBeNull()
        ->and(DB::table('customers')->where('local_uuid', $localUuid)->count())->toBe(1);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/customers/sync')
        && $request['region_specific_id'] === 53
        && $request['local_uuid'] === $localUuid
        && $request['customer_code_reservation_token'] === $reservationToken);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/customers/sync')
        && $request['region_specific_id'] === 54
        && $request['local_uuid'] === $localUuid
        && $request['unique_id'] === 'CODE-Stale Reference Customer'
        && $request['customer_code_reservation_token'] === $reservationToken);
});

test('Customer page manual action uses Customer-only push and exposes loading controls', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Manual Shop');
    $customerId = DB::table('customers')->latest('id')->value('id');
    $localUuid = DB::table('customers')->where('id', $customerId)->value('local_uuid');
    Http::fake(['portal.test/api/customers/sync' => Http::response(['server_id' => 304, 'local_uuid' => $localUuid, 'updated_at' => now()->toISOString()])]);

    Livewire::test(CustomerPage::class)
        ->assertSee('Push Customers')
        ->assertSee('wire:loading.attr="disabled"', false)
        ->call('pushCustomers');

    Http::assertSentCount(1);
});

test('Push Customers is neutral when no customer is eligible', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    Livewire::test(CustomerPage::class)
        ->assertSee('text-[#434654] hover:text-[#890f00]', false)
        ->assertDontSee('text-red-500 hover:text-red-600', false);
});

test('a pending customer makes Push Customers red', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Pending Indicator Shop');

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false);
});

test('a retryable failed customer makes Push Customers red', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Retry Indicator Shop', 'failed', 2);

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false);
});

test('synced customers do not make Push Customers red', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Synced Indicator Shop', 'synced');

    Livewire::test(CustomerPage::class)
        ->assertSee('text-[#434654] hover:text-[#890f00]', false)
        ->assertDontSee('text-red-500 hover:text-red-600', false);
});

test('exhausted failed customers do not make Push Customers red', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Exhausted Indicator Shop', 'failed', 3);

    Livewire::test(CustomerPage::class)
        ->assertSee('text-[#434654] hover:text-[#890f00]', false)
        ->assertDontSee('text-red-500 hover:text-red-600', false);
});

test('indicator eligibility matches records attempted by Customer-only push', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Pending Parity Shop');
    insertPushCustomer('Retry Parity Shop', 'failed', 2);
    insertPushCustomer('Exhausted Parity Shop', 'failed', 3);
    insertPushCustomer('Synced Parity Shop', 'synced');
    $ackIds = [401, 402];
    $ackIndex = 0;
    Http::fake(function ($request) use (&$ackIndex, $ackIds) {
        return Http::response(['server_id' => $ackIds[$ackIndex++], 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
    });

    $sync = app(SyncService::class);
    expect($sync->hasPendingCustomerPushWork())->toBeTrue();

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false);

    $result = $sync->pushPendingCustomers();

    expect($result->syncedCount)->toBe(2)
        ->and($sync->hasPendingCustomerPushWork())->toBeFalse();
    Http::assertSentCount(2);
});

test('successful manual push immediately clears red state when no customer work remains', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Successful Indicator Shop');
    Http::fake(function ($request) {
        return Http::response(['server_id' => 403, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
    });

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false)
        ->call('pushCustomers')
        ->assertSee('text-[#434654] hover:text-[#890f00]', false)
        ->assertDontSee('text-red-500 hover:text-red-600', false);
});

test('failed manual push keeps Push Customers red while customer work remains', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Failed Indicator Shop');
    Http::fake(['portal.test/api/customers/sync' => Http::response(['message' => 'Unavailable'], 503)]);

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false)
        ->call('pushCustomers')
        ->assertSee('text-red-500 hover:text-red-600', false);
});

test('partial manual push keeps Push Customers red while retryable customer work remains', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Partial Success Indicator Shop');
    insertPushCustomer('Partial Failure Indicator Shop');
    $ackCount = 0;
    Http::fake(function ($request) use (&$ackCount) {
        if ($ackCount++ > 0) {
            return Http::response(['message' => 'Unavailable'], 503);
        }

        return Http::response(['server_id' => 404, 'local_uuid' => $request['local_uuid'], 'updated_at' => now()->toISOString()]);
    });

    Livewire::test(CustomerPage::class)
        ->assertSee('text-red-500 hover:text-red-600', false)
        ->call('pushCustomers')
        ->assertSee('text-red-500 hover:text-red-600', false);
});

test('successful push stores the Portal ID and synced state while retaining a large negative local key', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $localId = -7_166_839_558_920_329_640;
    $localUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => $localId,
        'local_uuid' => $localUuid,
        'name' => 'Negative Local Key Customer',
        'is_active' => true,
        'sync_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'server_id' => 98003,
        'local_uuid' => $localUuid,
        'updated_at' => now()->toISOString(),
    ])]);

    $result = app(SyncService::class)->pushCustomer($localId);
    $saved = DB::table('customers')->where('local_uuid', $localUuid)->first();

    expect($result->success)->toBeTrue()
        ->and($saved->id)->toBe($localId)
        ->and($saved->server_id)->toBe(98003)
        ->and($saved->sync_status)->toBe('synced');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/customers/sync')
        && $request['local_uuid'] === $localUuid
        && $request['server_id'] === null
        && $request['sync_intent'] === 'create');
});

test('a successful HTTP response without a valid Portal ID is never marked synced', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $localId = insertPushCustomer('Missing Portal ID Customer');
    Http::fake(['portal.test/api/customers/sync' => Http::response(['message' => 'Malformed acknowledgment'], 200)]);

    $result = app(SyncService::class)->pushCustomer($localId);
    $saved = DB::table('customers')->where('id', $localId)->first();

    expect($result->success)->toBeFalse()
        ->and($saved->server_id)->toBeNull()
        ->and($saved->sync_status)->toBe('failed');
});

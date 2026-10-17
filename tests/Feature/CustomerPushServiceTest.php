<?php

use App\Models\User;
use App\Services\Sync\Push\CustomerPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function insertPusherCustomer(string $name, string $status = 'pending', int $attempts = 0): int
{
    return DB::table('customers')->insertGetId([
        'local_uuid' => (string) Str::uuid(),
        'name' => $name,
        'unique_id' => 'CODE-'.$name,
        'is_active' => true,
        'sync_status' => $status,
        'sync_attempts' => $attempts,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function actingPusherUser(): User
{
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    Auth::login($user);

    return $user;
}

test('a 409 conflict is applied as a local conflict', function () {
    actingPusherUser();
    $id = insertPusherCustomer('Conflict Shop');
    $localUuid = DB::table('customers')->where('id', $id)->value('local_uuid');

    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'code' => 'customer_conflict',
        'message' => 'Customer changed on Portal.',
        'server_id' => 9001,
        'local_uuid' => $localUuid,
        'updated_at' => now()->toISOString(),
    ], 409)]);

    $result = app(CustomerPushService::class)->pushCustomer($id);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('customer_conflict')
        ->and(DB::table('customers')->where('id', $id)->value('sync_status'))->toBe('conflict');
});

test('a 422 rejection records a retryable failure with the Portal reason', function () {
    actingPusherUser();
    $id = insertPusherCustomer('Rejected Shop');

    Http::fake(['portal.test/api/customers/sync' => Http::response([
        'message' => 'The name field is required.',
        'errors' => ['name' => ['The name field is required.']],
    ], 422)]);

    $result = app(CustomerPushService::class)->pushCustomer($id);
    $row = DB::table('customers')->where('id', $id)->first();

    expect($result->success)->toBeFalse()
        ->and($row->sync_status)->toBe('failed')
        ->and((int) $row->sync_attempts)->toBe(1)
        ->and((string) $row->sync_error)->toContain('name');
});

test('a 401 stops the run and restores the original status', function () {
    actingPusherUser();
    $id = insertPusherCustomer('Expired Shop');

    Http::fake(['portal.test/api/customers/sync' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $result = app(CustomerPushService::class)->pushCustomer($id);

    expect($result->errorCode)->toBe('token_expired')
        ->and(DB::table('customers')->where('id', $id)->value('sync_status'))->toBe('pending');
});

test('a local edit during the request leaves the row pending', function () {
    actingPusherUser();
    $id = insertPusherCustomer('Edited Shop');

    Http::fake(['portal.test/api/customers/sync' => function ($request) use ($id) {
        DB::table('customers')->where('id', $id)->update([
            'name' => 'Edited while in flight',
            'sync_status' => 'pending',
            'updated_at' => now(),
        ]);

        return Http::response([
            'server_id' => 9100,
            'local_uuid' => $request['local_uuid'],
            'unique_id' => 'PORTAL-9100',
            'updated_at' => now()->toISOString(),
        ]);
    }]);

    $result = app(CustomerPushService::class)->pushCustomer($id);
    $row = DB::table('customers')->where('id', $id)->first();

    expect($row->sync_status)->toBe('pending')
        ->and($row->server_id)->toBeNull()
        ->and($result->success)->toBeTrue();
});

test('pending records are pushed one request per record', function () {
    actingPusherUser();
    insertPusherCustomer('First Shop');
    insertPusherCustomer('Second Shop');

    $next = 9500;
    Http::fake(['portal.test/api/customers/sync' => function ($request) use (&$next) {
        return Http::response([
            'server_id' => $next++,
            'local_uuid' => $request['local_uuid'],
            'unique_id' => 'PORTAL-'.$next,
            'updated_at' => now()->toISOString(),
        ]);
    }]);

    $result = app(CustomerPushService::class)->pushPendingCustomers();

    Http::assertSentCount(2);
    expect($result->syncedCount)->toBe(2);
});

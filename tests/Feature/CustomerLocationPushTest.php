<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('push sends the customer location foreign keys', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    $customerId = DB::table('customers')->insertGetId([
        'local_uuid' => (string) Str::uuid(),
        'name' => 'Offline Shop',
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        'province_id' => 7,
        'municipality_id' => 9,
        'barangay_id' => 55,
        'area_cluster_id' => 61,
        'address' => '123 Roxas Street',
        'latitude' => 15.1456,
        'longitude' => 120.5887,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        return Http::response(['results' => [[
            'status' => 'synced',
            'server_id' => 501,
            'local_uuid' => $request['customers'][0]['local_uuid'],
            'updated_at' => now()->toISOString(),
        ]]]);
    });

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        $payload = $request['customers'][0];

        return str_ends_with($request->url(), '/api/sync/push/customer')
            && $payload['province_id'] === 7
            && $payload['municipality_id'] === 9
            && $payload['barangay_id'] === 55
            && $payload['area_cluster_id'] === 61;
    });
});

test('push omits unknown location foreign keys instead of sending null', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    DB::table('customers')->insert([
        'local_uuid' => (string) Str::uuid(),
        'name' => 'Offline Shop',
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        'province_id' => null,
        'barangay_id' => null,
        'area_cluster_id' => null,
        'address' => '123 Roxas Street',
        'latitude' => 15.1456,
        'longitude' => 120.5887,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        return Http::response(['results' => [[
            'status' => 'synced',
            'server_id' => 501,
            'local_uuid' => $request['customers'][0]['local_uuid'],
            'updated_at' => now()->toISOString(),
        ]]]);
    });

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        $data = $request['customers'][0];

        return str_ends_with($request->url(), '/api/sync/push/customer')
            && ! array_key_exists('province_id', $data)
            && ! array_key_exists('barangay_id', $data)
            && ! array_key_exists('area_cluster_id', $data);
    });
});

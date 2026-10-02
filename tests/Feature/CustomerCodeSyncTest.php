<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('customer sync sends the reservation token and reconciles Portal unique_id', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $token = '11111111-1111-4111-8111-111111111111';

    DB::table('customers')->insert([
        'id' => -123,
        'local_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'unique_id' => null,
        'customer_code_reservation_token' => $token,
        'name' => 'Pending Customer',
        'company_id' => null,
        'is_active' => true,
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'portal.test/api/sync/push/customer' => Http::response([
            'server_id' => 456,
            'unique_id' => 'IB53383',
            'updated_at' => now()->toISOString(),
        ]),
    ]);

    $this->actingAs($user);
    $result = app(SyncService::class)->push();

    expect($result->success)->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->url() === 'http://portal.test/api/sync/push/customer'
        && $request->data()['customer_code_reservation_token'] === $token);

    $customer = DB::table('customers')->where('id', -123)->first();
    expect($customer->server_id)->toBe(456)
        ->and($customer->unique_id)->toBe('IB53383')
        ->and($customer->customer_code_reservation_token)->toBeNull()
        ->and($customer->sync_status)->toBe('synced');
});

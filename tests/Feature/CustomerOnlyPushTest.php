<?php

use App\Filament\Pages\CustomerPage;
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

    Http::fake(['portal.test/api/sync/push/customer' => Http::sequence()
        ->push(['server_id' => 301, 'unique_id' => 'PORTAL-CODE', 'updated_at' => now()->toISOString()])
        ->push(['server_id' => 302, 'updated_at' => now()->toISOString()])]);

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
    Http::assertSent(fn ($request): bool => $request->url() === 'http://portal.test/api/sync/push/customer'
        && $request->data()['customer_code_reservation_token'] === $token);
});

test('Customer-only push retains a failed local record as retryable', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    $customerId = insertPushCustomer('Offline Shop');
    Http::fake(['portal.test/api/sync/push/customer' => Http::response(['message' => 'Unavailable'], 503)]);

    $result = app(SyncService::class)->pushCustomer($customerId);
    $customer = DB::table('customers')->where('id', $customerId)->first();

    expect($result->success)->toBeFalse()
        ->and($customer)->not->toBeNull()
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1)
        ->and($customer->sync_error)->not->toBeNull();
});

test('Customer-only push reports partial success and failure accurately', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Synced Shop');
    $failedId = insertPushCustomer('Failed Shop');
    Http::fake(['portal.test/api/sync/push/customer' => Http::sequence()
        ->push(['server_id' => 305, 'updated_at' => now()->toISOString()])
        ->push(['message' => 'Unavailable'], 503)]);

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
    Http::fake(['portal.test/api/sync/push/customer' => Http::response(['server_id' => 303, 'updated_at' => now()->toISOString()])]);

    $result = app(SyncService::class)->pushCustomer($targetId);

    expect($result->syncedCount)->toBe(1);
    Http::assertSentCount(1);
});

test('Customer page manual action uses Customer-only push and exposes loading controls', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);
    insertPushCustomer('Manual Shop');
    Http::fake(['portal.test/api/sync/push/customer' => Http::response(['server_id' => 304, 'updated_at' => now()->toISOString()])]);

    Livewire::test(CustomerPage::class)
        ->assertSee('Push Customers')
        ->assertSee('wire:loading.attr="disabled"', false)
        ->call('pushCustomers');

    Http::assertSentCount(1);
});

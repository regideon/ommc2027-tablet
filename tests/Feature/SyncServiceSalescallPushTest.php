<?php

use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function salescallPushRep(): User
{
    return User::factory()->create(['api_token' => 'tablet-token']);
}

/**
 * A server-known Customer and Itinerary so only the salescall itself is
 * pending in each scenario.
 *
 * @return array{0: Customer, 1: Itinerary}
 */
function salescallPushDependencies(?int $customerServerId = 501, string $customerStatus = 'synced'): array
{
    $customer = Customer::create([
        'local_uuid' => (string) Str::uuid(),
        'name' => 'Salescall Push Customer',
        'is_active' => true,
        'server_id' => $customerServerId,
        'sync_status' => $customerStatus,
    ]);

    $itinerary = Itinerary::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 77,
        'sync_status' => 'synced',
    ]);

    return [$customer, $itinerary];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeSalescallForPush(Customer $customer, Itinerary $itinerary, array $overrides = []): Salescall
{
    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        ...$overrides,
    ]);
}

test('it pushes a pending salescall with its visit payload and stores the server id and reference number', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies();

    $salescall = makeSalescallForPush($customer, $itinerary, [
        'salescall_type_id' => 2,
        'salescall_status_id' => 4,
        'route_start_at' => '2026-10-16 08:00:00',
        'latitude' => 15.1456,
        'longitude' => 120.5887,
        'actual_in' => '2026-10-16 08:05:00',
        'actual_out' => '2026-10-16 09:45:00',
        'latitude_actual_in' => 15.1456,
        'longitude_actual_in' => 120.5887,
        'latitude_actual_out' => 15.146,
        'longitude_actual_out' => 120.589,
        'remarks' => 'Shelves restocked',
        'concerns' => 'None',
        'collection_amount' => 1520.50,
    ]);

    Http::fake([
        'portal.test/api/salescalls/push' => Http::response(['server_id' => 901, 'ref_number' => 100901], 200),
    ]);

    $result = app(SyncService::class)->push();

    Http::assertSent(function ($request) use ($salescall, $itinerary): bool {
        $data = $request->data();

        return str_ends_with($request->url(), '/api/salescalls/push')
            && $data['local_uuid'] === $salescall->local_uuid
            && $data['itinerary_uuid'] === $itinerary->local_uuid
            && $data['itinerary_server_id'] === 77
            && $data['customer_id'] === 501
            && $data['salescall_type_id'] === 2
            && $data['salescall_status_id'] === 4
            && $data['route_start_at'] === '2026-10-16 08:00:00'
            && $data['actual_in'] === '2026-10-16 08:05:00'
            && $data['actual_out'] === '2026-10-16 09:45:00'
            && (float) $data['latitude_actual_out'] === 15.146
            && (float) $data['collection_amount'] === 1520.5
            && $data['remarks'] === 'Shelves restocked'
            && $data['concerns'] === 'None';
    });

    $salescall->refresh();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(1)
        ->and($salescall->sync_status)->toBe('synced')
        ->and($salescall->server_id)->toBe(901)
        ->and((int) $salescall->ref_number)->toBe(100901)
        ->and($salescall->sync_error)->toBeNull();
});

test('a rejected salescall push records the portal error as a retryable failure', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies();

    $salescall = makeSalescallForPush($customer, $itinerary);

    Http::fake([
        'portal.test/api/salescalls/push' => Http::response(['message' => 'Salescall payload was rejected'], 422),
    ]);

    $result = app(SyncService::class)->push();
    $salescall->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->failedCount)->toBe(1)
        ->and($result->retryableCount)->toBe(1)
        ->and($result->failureReasons)->toContain('portal_rejected')
        ->and($salescall->sync_status)->toBe('failed')
        ->and($salescall->sync_attempts)->toBe(1)
        ->and($salescall->sync_error)->toContain('portal_rejected')
        ->and($salescall->sync_error)->toContain('Salescall payload was rejected');
});

test('failed salescalls are pushed again on the next sync regardless of attempts', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies();

    $retryable = makeSalescallForPush($customer, $itinerary, ['sync_status' => 'failed', 'sync_attempts' => 2]);
    $exhausted = makeSalescallForPush($customer, $itinerary, ['sync_status' => 'failed', 'sync_attempts' => 3]);

    Http::fake([
        'portal.test/api/salescalls/push' => Http::response(['server_id' => 902, 'ref_number' => 100902], 200),
    ]);

    $result = app(SyncService::class)->push();

    Http::assertSentCount(2);

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(2)
        ->and($retryable->fresh()->sync_status)->toBe('synced')
        ->and($retryable->fresh()->server_id)->toBe(902)
        ->and($exhausted->fresh()->sync_status)->toBe('synced')
        ->and($exhausted->fresh()->server_id)->toBe(902);
});

test('a salescall waits while its customer is only queued on the portal', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies(null, 'queued');

    $salescall = makeSalescallForPush($customer, $itinerary);

    Http::fake();

    $result = app(SyncService::class)->push();
    $salescall->refresh();

    Http::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(0)
        ->and($salescall->sync_status)->toBe('pending')
        ->and($salescall->sync_attempts)->toBe(0)
        ->and($salescall->sync_error)->toBeNull();
});

test('a salescall waits while its itinerary has no local uuid', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies();
    $itinerary->update(['local_uuid' => null]);

    $salescall = makeSalescallForPush($customer, $itinerary);

    Http::fake();

    $result = app(SyncService::class)->push();
    $salescall->refresh();

    Http::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(0)
        ->and($salescall->sync_status)->toBe('pending')
        ->and($salescall->sync_attempts)->toBe(0);
});

test('a 401 on the salescall push aborts with a token expired result and leaves the row pending', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(salescallPushRep());
    [$customer, $itinerary] = salescallPushDependencies();

    $salescall = makeSalescallForPush($customer, $itinerary);

    Http::fake([
        'portal.test/api/salescalls/push' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $result = app(SyncService::class)->push();
    $salescall->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('token_expired')
        ->and($result->message)->toContain('Session expired')
        ->and($salescall->sync_status)->toBe('pending')
        ->and($salescall->sync_attempts)->toBe(0)
        ->and($salescall->sync_error)->toBeNull();
});

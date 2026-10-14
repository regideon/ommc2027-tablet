<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedCustomerCodePageFixtures(?string $apiToken = null): User
{
    $now = now();

    DB::table('companies')->insert([
        ['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'name' => 'IB', 'code' => 'IB', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return User::factory()->create(['api_token' => $apiToken]);
}

test('Customer Code starts blank and uses the Portal reservation on Company selection', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(function ($request) {
        return Http::response($request->data()['company_id'] === 2
            ? ['token' => '11111111-1111-4111-8111-111111111111', 'code' => 'IB53383']
            : ['token' => '22222222-2222-4222-8222-222222222222', 'code' => 'OMMC08738']);
    });

    $component = Livewire::test(CustomerCreatePage::class)
        ->assertSet('unique_id', null)
        ->assertSet('customer_code_reservation_token', null)
        ->assertSee('readonly', false);

    $component->set('company_id', 2)
        ->assertSet('unique_id', 'IB53383')
        ->assertSet('customer_code_reservation_token', '11111111-1111-4111-8111-111111111111');
});

test('changing Company clears the old reservation before displaying the new namespace', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(function ($request) {
        return Http::response($request->data()['company_id'] === 2
            ? ['token' => '11111111-1111-4111-8111-111111111111', 'code' => 'IB53383']
            : ['token' => '22222222-2222-4222-8222-222222222222', 'code' => 'OMMC08738']);
    });

    $component = Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 2)
        ->assertSet('unique_id', 'IB53383');

    $component->set('unique_id', 'STALE-CODE')
        ->set('company_id', 1)
        ->assertSet('unique_id', 'OMMC08738')
        ->assertSet('customer_code_reservation_token', '22222222-2222-4222-8222-222222222222');

    Http::assertSentCount(2);
});

test('reservation failure leaves Customer Code blank without local allocation', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['portal.test/api/sync/reserve-customer-code' => Http::response([], 503)]);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->assertSet('unique_id', null)
        ->assertSet('customer_code_reservation_token', null);
});

test('creation keeps the reservation token on the local Customer when its automatic push fails', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['portal.test/api/sync/push/customer' => Http::response(['message' => 'Specific Region ID is invalid'], 500)]);

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Pending Customer')
        ->set('unique_id', 'OMMC08738')
        ->set('customer_code_reservation_token', '11111111-1111-4111-8111-111111111111')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl())
        ->assertSessionHas('filament.notifications.0.body', 'Portal returned HTTP 500: Specific Region ID is invalid');

    $customer = DB::table('customers')->where('name', 'Pending Customer')->first();
    expect($customer->customer_code_reservation_token)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/sync/push/customer'));
});

test('creation commits locally before successful automatic Customer reconciliation', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/push/customer')) {
            expect(DB::table('customers')->where('name', 'Created Shop')->exists())->toBeTrue();

            return Http::response(['server_id' => 881, 'unique_id' => 'OMMC0881', 'updated_at' => now()->toISOString()]);
        }

        return Http::response(['token' => '33333333-3333-4333-8333-333333333333', 'code' => 'OMMC0881']);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Created Shop')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Created Shop')->first();
    expect($customer->sync_status)->toBe('synced')
        ->and($customer->server_id)->toBe(881)
        ->and($customer->unique_id)->toBe('OMMC0881')
        ->and($customer->customer_code_reservation_token)->toBeNull();
    Http::assertSentCount(2);
});

test('creation remains successful and locally stored when automatic push throws offline', function () {
    $user = seedCustomerCodePageFixtures('tablet-token');
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/api/sync/push/customer')) {
            throw new ConnectionException('Offline');
        }

        return Http::response(['token' => '44444444-4444-4444-8444-444444444444', 'code' => 'OMMC0882']);
    });

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Offline Created Shop')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    $customer = DB::table('customers')->where('name', 'Offline Created Shop')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->sync_status)->toBe('failed')
        ->and($customer->sync_attempts)->toBe(1);
});

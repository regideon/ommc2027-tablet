<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('reservation token is persisted with an offline Customer', function () {
    $user = seedCustomerCodePageFixtures();
    $this->actingAs($user);
    Http::fake();

    Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set('name', 'Pending Customer')
        ->set('unique_id', 'OMMC08738')
        ->set('customer_code_reservation_token', '11111111-1111-4111-8111-111111111111')
        ->call('saveCustomer')
        ->assertRedirect(CustomerPage::getUrl());

    expect(DB::table('customers')->where('name', 'Pending Customer')->value('customer_code_reservation_token'))
        ->toBe('11111111-1111-4111-8111-111111111111');
});

<?php

use App\Filament\Pages\CustomerEditPage;
use App\Filament\Pages\SalescallPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Customer Edit retains a large local key as a string across Livewire state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => now(), 'updated_at' => now()]);
    $localId = '-7166839558920329640';
    DB::table('customers')->insert([
        'id' => (int) $localId,
        'local_uuid' => (string) Str::uuid(),
        'company_id' => 1,
        'name' => 'Large Key Edit Customer',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(CustomerEditPage::class, ['customerId' => (int) $localId])
        ->assertOk()
        ->assertSet('customerId', $localId)
        ->set('name', 'Edited Without ID Rounding')
        ->assertSet('customerId', $localId);

    expect($component->snapshot['data']['customerId'])->toBe($localId);
});

test('Salescall customer data and note actions preserve large negative local IDs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $localId = -7_166_839_558_920_329_640;
    DB::table('customers')->insert([
        'id' => $localId,
        'local_uuid' => (string) Str::uuid(),
        'name' => 'Large Key Salescall Customer',
        'unique_id' => 'TEST-LARGE-ID',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('itineraries')->insert([
        'id' => 1,
        'created_by' => $user->id,
        'date_month' => now()->startOfMonth()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('salescall_types')->insert(['id' => 1, 'name' => 'Unplanned', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('salescall_statuses')->insert(['id' => 1, 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('salescalls')->insert([
        'itinerary_id' => 1,
        'customer_id' => $localId,
        'visit_date' => now(),
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(SalescallPage::class)->assertOk();
    $rendered = html_entity_decode($component->html(), ENT_QUOTES);

    expect($rendered)
        ->toContain('"customer_id":"'.$localId.'"')
        ->toContain('"id":"'.$localId.'"');

    $component->call('saveCustomerNote', (string) $localId, null, 'Large key note');

    $component->call('createUnplannedSalescall', (string) $localId, now()->format('Y-m-d\\TH:i'));

    expect(DB::table('customer_notes')->where('body', 'Large key note')->value('customer_id'))
        ->toBe($localId)
        ->and(DB::table('salescalls')->where('customer_id', $localId)->count())->toBe(2);
});

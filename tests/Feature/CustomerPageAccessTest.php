<?php

use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeCustomer(string $name): Customer
{
    return Customer::create(['name' => $name, 'is_active' => true]);
}

test('drm only sees customers assigned to them', function () {
    Role::create(['name' => 'drm']);

    $drm = User::factory()->create();
    $drm->assignRole('drm');

    $otherDrm = User::factory()->create();
    $otherDrm->assignRole('drm');

    $ownCustomer = makeCustomer('Assigned To Me');
    $othersCustomer = makeCustomer('Assigned To Someone Else');

    DB::table('customer_user')->insert([
        ['customer_id' => $ownCustomer->id, 'user_id' => $drm->id, 'created_at' => now(), 'updated_at' => now()],
        ['customer_id' => $othersCustomer->id, 'user_id' => $otherDrm->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($drm);

    Livewire::test(CustomerPage::class)
        ->assertSee('Assigned To Me')
        ->assertDontSee('Assigned To Someone Else');
});

test('rsm sees all customers assigned to their drms', function () {
    Role::create(['name' => 'rsm']);
    Role::create(['name' => 'drm_approver']);
    Role::create(['name' => 'drm']);

    $rsm = User::factory()->create();
    $rsm->assignRole(['rsm', 'drm_approver']);

    $ownDrm = User::factory()->create(['rsm_id' => $rsm->id]);
    $ownDrm->assignRole('drm');

    $unrelatedDrm = User::factory()->create();
    $unrelatedDrm->assignRole('drm');

    $customerUnderMyDrm = makeCustomer('Under My DRM');
    $customerUnderOtherDrm = makeCustomer('Under Unrelated DRM');

    DB::table('customer_user')->insert([
        ['customer_id' => $customerUnderMyDrm->id, 'user_id' => $ownDrm->id, 'created_at' => now(), 'updated_at' => now()],
        ['customer_id' => $customerUnderOtherDrm->id, 'user_id' => $unrelatedDrm->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($rsm);

    Livewire::test(CustomerPage::class)
        ->assertSee('Under My DRM')
        ->assertDontSee('Under Unrelated DRM');
});

test('rsm approver sees every customer regardless of assignment', function () {
    Role::create(['name' => 'rsm_approver']);
    Role::create(['name' => 'drm']);

    $vp = User::factory()->create();
    $vp->assignRole('rsm_approver');

    $someDrm = User::factory()->create();
    $someDrm->assignRole('drm');

    $assignedCustomer = makeCustomer('Assigned Customer');
    makeCustomer('Never Assigned To Anyone');

    DB::table('customer_user')->insert([
        ['customer_id' => $assignedCustomer->id, 'user_id' => $someDrm->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($vp);

    Livewire::test(CustomerPage::class)
        ->assertSee('Assigned Customer')
        ->assertSee('Never Assigned To Anyone');
});

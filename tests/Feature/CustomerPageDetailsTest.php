<?php

use App\Filament\Pages\CustomerPage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('customer detail renders owner business contact access PIC DRM RSM and annual categories', function () {
    Role::create(['name' => 'rsm_approver']);
    Role::create(['name' => 'drm']);
    $viewer = User::factory()->create();
    $viewer->assignRole('rsm_approver');
    $rsm = User::factory()->create(['name' => 'Regional Manager']);
    $drm = User::factory()->create(['name' => 'District Manager', 'email' => 'drm@example.test', 'rsm_id' => $rsm->id]);
    $drm->assignRole('drm');
    $pic = User::factory()->create(['name' => 'Customer PIC']);
    $customer = Customer::create([
        'name' => 'Detail Contract Store',
        'unique_id' => 'OMMC90001',
        'is_active' => true,
        'business_landline_number' => '02-555-0123',
        'business_mobile_number' => '09170000001',
        'date_established' => '2001-02-03',
        'person_in_charge_id' => $pic->id,
    ]);
    $customer->users()->attach($drm->id);
    $customer->tradeProfile()->create([
        'profile_type' => 'outlet',
        'profile_data' => ['active' => [
            'owner' => [
                'name' => 'Owner Example',
                'birthday' => '1970-01-02',
                'nickname' => 'Owner Nickname',
                'successor_name' => 'Successor Example',
                'successor_birthday' => '1995-06-07',
                'relationship' => 'Child',
                'generation' => '2nd Gen',
                'hobbies' => 'Gardening',
            ],
            'warehouse_code' => 'WH-DETAIL',
        ]],
    ]);
    $customer->categoryHistories()->create([
        'profile_type' => 'outlet',
        'stream' => 'ab',
        'category_year' => 2026,
        'category' => 'AB Loyal',
    ]);

    $this->actingAs($viewer);
    Livewire::test(CustomerPage::class)
        ->call('viewCustomer', $customer->id)
        ->assertSee('Business Landline')
        ->assertSee('02-555-0123')
        ->assertSee('Business Mobile')
        ->assertSee('09170000001')
        ->assertSee('2001-02-03')
        ->assertSee('Customer PIC')
        ->assertSee('Customer Access')
        ->assertSee('District Manager')
        ->assertDontSee('drm@example.test')
        ->assertSee('Regional Manager')
        ->assertSee('Owner Profile')
        ->assertSee('Owner Example')
        ->assertSee('Owner Nickname')
        ->assertSee('Successor Example')
        ->assertSee('1995-06-07')
        ->assertSee('Child')
        ->assertSee('2nd Gen')
        ->assertSee('Gardening')
        ->assertSee('WH-DETAIL')
        ->assertSee('AB Loyal');
});

test('customer detail load failures are logged without customer data and return a support reference', function () {
    Role::create(['name' => 'rsm_approver']);
    $viewer = User::factory()->create();
    $viewer->assignRole('rsm_approver');
    $customer = Customer::create(['name' => 'Private Customer Name', 'is_active' => true]);
    $this->actingAs($viewer);
    $component = Livewire::test(CustomerPage::class);
    Log::spy();

    $shouldFail = true;
    DB::listen(function (QueryExecuted $query) use (&$shouldFail): void {
        if ($shouldFail && str_contains($query->sql, 'customer_profiles')) {
            $shouldFail = false;
            throw new RuntimeException('simulated detail query failure');
        }
    });

    $component->call('viewCustomer', $customer->id)
        ->assertSet('selectedCustomerId', null)
        ->assertSet('customerDetail', [])
        ->assertNotified('Customer details could not be loaded.');

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Tablet Customer detail load failed.', Mockery::on(function (array $context) use ($customer): bool {
            return $context['customer_id'] === $customer->id
                && $context['exception_class'] === RuntimeException::class
                && isset($context['reference'])
                && ! array_key_exists('exception_message', $context);
        }));
});

test('customer detail handles optional relationships and profile data when they are blank', function () {
    Role::create(['name' => 'rsm_approver']);
    $viewer = User::factory()->create();
    $viewer->assignRole('rsm_approver');
    $customer = Customer::create([
        'name' => 'Sparse Customer',
        'is_active' => true,
        'business_landline_number' => null,
        'business_mobile_number' => null,
        'date_established' => null,
    ]);

    $this->actingAs($viewer);
    Livewire::test(CustomerPage::class)
        ->call('viewCustomer', $customer->id)
        ->assertSet('selectedCustomerId', $customer->id)
        ->assertSee('Sparse Customer')
        ->assertSee('Business Landline')
        ->assertSee('None assigned')
        ->assertNotNotified('Customer details could not be loaded.');
});

test('customer detail transports large local IDs as strings without JavaScript rounding', function () {
    Role::create(['name' => 'rsm_approver']);
    $viewer = User::factory()->create();
    $viewer->assignRole('rsm_approver');
    $customerId = '-7166839558920329640';
    $customer = new Customer;
    $customer->id = (int) $customerId;
    $customer->fill([
        'name' => 'Large ID Customer',
        'unique_id' => 'OMMC90002',
        'server_id' => 9007199254740993,
        'is_active' => true,
    ]);
    $customer->save();

    $this->actingAs($viewer);
    expect(Customer::find($customerId)?->id)->toBe((int) $customerId);

    $component = Livewire::test(CustomerPage::class)
        ->call('viewCustomer', $customerId)
        ->assertNotNotified('Customer details could not be loaded.')
        ->assertSet('selectedCustomerId', $customerId)
        ->assertSet('customerDetail.customer.server_id', '9007199254740993')
        ->assertSee('Large ID Customer');

    expect(html_entity_decode($component->html(), ENT_QUOTES))
        ->toContain('wire:click="viewCustomer(\''.$customerId.'\')"');

    $component->call('loadCustomerPhotos', $customerId)
        ->assertSet('showPhotos', true)
        ->assertSet('customerPhotos', []);

    expect($customer->exists)->toBeTrue();
});

<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallStatus;
use App\Models\SalescallType;
use App\Models\User;
use App\Support\SalescallBrandDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Salescall, 2: Customer, 3: MaterialGroup, 4: Brand}
 */
function salescallBrandSaveFixture(): array
{
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Private Fixture Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);
    $salescall = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $materialGroup = MaterialGroup::create(['name' => 'Paint']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Brand One',
        'enabled' => true,
    ]);

    return [$user, $salescall, $customer, $materialGroup, $brand];
}

function salescallBrandSelection(int|string $materialGroupId, int|string $brandId, int $quantity = 3): array
{
    return [$materialGroupId => [[
        'brand_id' => (string) $brandId,
        'quantity' => $quantity,
        'brand_other' => '',
    ]]];
}

test('saving Brand associations succeeds when all local parents exist', function () {
    [$user, $salescall, $customer, $materialGroup, $brand] = salescallBrandSaveFixture();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $brand->id))
        ->assertReturned(true)
        ->assertNotified('Brands saved.');

    $saved = SalescallBrand::query()->sole();
    expect($saved->salescall_id)->toBe($salescall->id)
        ->and($saved->customer_id)->toBe($customer->id)
        ->and($saved->material_group_id)->toBe($materialGroup->id)
        ->and($saved->brand_id)->toBe($brand->id);
    expect(CustomerBrand::query()->sole()->brand_id)->toBe($brand->id);
});

test('missing Sales Call is rejected without an unhandled request failure', function () {
    [$user, , , $materialGroup, $brand] = salescallBrandSaveFixture();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', 999999, salescallBrandSelection($materialGroup->id, $brand->id))
        ->assertReturned(false)
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->count())->toBe(0);
});

test('missing Customer is rejected before existing Brand rows are changed', function () {
    [$user, $salescall, $customer, $materialGroup, $brand] = salescallBrandSaveFixture();
    $existing = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    DB::table('salescalls')->where('id', $salescall->id)->update(['customer_id' => 23594]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $brand->id))
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->sole()->id)->toBe($existing->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending');
});

test('missing Material Group is rejected before existing Brand rows are changed', function () {
    [$user, $salescall, $customer, $materialGroup, $brand] = salescallBrandSaveFixture();
    $existing = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection(88, $brand->id))
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->sole()->id)->toBe($existing->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending');
});

test('missing Brand is rejected before existing Brand rows are changed', function () {
    [$user, $salescall, $customer, $materialGroup, $oldBrand] = salescallBrandSaveFixture();
    $existing = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $oldBrand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, 999999))
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->sole()->id)->toBe($existing->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending');
});

test('Brand from another Material Group is rejected before existing rows are changed', function () {
    [$user, $salescall, , $materialGroup] = salescallBrandSaveFixture();
    $otherGroup = MaterialGroup::create(['name' => 'Other']);
    $otherBrand = Brand::create(['material_group_id' => $otherGroup->id, 'name' => 'Other Brand', 'enabled' => true]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $otherBrand->id))
        ->assertNotified('Brands were not saved. A selected Sales Call, Customer, Material Group, or Brand is missing or no longer matches.');

    expect(SalescallBrand::query()->count())->toBe(0);
});

test('an insertion error after deletion rolls back and preserves pending Brand associations', function () {
    [$user, $salescall, $customer, $materialGroup, $oldBrand] = salescallBrandSaveFixture();
    $newBrand = Brand::create(['material_group_id' => $materialGroup->id, 'name' => 'Brand Two', 'enabled' => true]);
    $oldRow = SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $oldBrand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $oldCustomerBrand = CustomerBrand::create([
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $oldBrand->id,
        'quantity' => 2,
        'last_salescall_id' => $salescall->id,
    ]);
    DB::statement("CREATE TRIGGER fail_brand_insert BEFORE INSERT ON salescall_brands WHEN NEW.brand_id = {$newBrand->id} BEGIN SELECT RAISE(ABORT, 'forced test insert failure'); END");
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $newBrand->id))
        ->assertReturned(false)
        ->assertNotified('Brands could not be saved. Your previously saved Brands were kept.');

    expect(SalescallBrand::query()->sole()->id)->toBe($oldRow->id)
        ->and(SalescallBrand::query()->sole()->sync_status)->toBe('pending')
        ->and(CustomerBrand::query()->sole()->id)->toBe($oldCustomerBrand->id)
        ->and($salescall->fresh()->customer_id)->toBe($customer->id);
});

test('valid replacement replaces the old association atomically', function () {
    [$user, $salescall, $customer, $materialGroup, $oldBrand] = salescallBrandSaveFixture();
    $newBrand = Brand::create(['material_group_id' => $materialGroup->id, 'name' => 'Brand Two', 'enabled' => true]);
    SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $oldBrand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $newBrand->id))
        ->assertNotified('Brands saved.');

    expect(SalescallBrand::query()->sole()->brand_id)->toBe($newBrand->id);
});

test('dynamic read-only diagnostics use local IDs and report server ID without personal data', function () {
    [$user, $salescall, , $materialGroup, $brand] = salescallBrandSaveFixture();
    DB::table('customers')->where('id', $salescall->customer_id)->update(['server_id' => 23595]);
    $this->actingAs($user);

    $before = [
        'salescalls' => DB::table('salescalls')->count(),
        'customers' => DB::table('customers')->count(),
        'salescall_brands' => DB::table('salescall_brands')->count(),
        'customer_brands' => DB::table('customer_brands')->count(),
    ];
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ltrim($query->sql);
    });

    $component = Livewire::test(SalescallPage::class)
        ->call('collectSalescallBrandDiagnostics', $salescall->id, salescallBrandSelection($materialGroup->id, $brand->id));
    $diagnostics = json_decode($component->get('salescallBrandDiagnosticsJson'), true, flags: JSON_THROW_ON_ERROR);
    $after = [
        'salescalls' => DB::table('salescalls')->count(),
        'customers' => DB::table('customers')->count(),
        'salescall_brands' => DB::table('salescall_brands')->count(),
        'customer_brands' => DB::table('customer_brands')->count(),
    ];

    expect($diagnostics['database']['driver'])->toBe('sqlite')
        ->and($diagnostics['database']['schema_version'])->not->toBeNull()
        ->and($diagnostics['database']['foreign_keys_enabled'])->toBeTrue()
        ->and($diagnostics['salescall_brands_foreign_keys'])->not->toBeEmpty()
        ->and(collect($diagnostics['salescall_brands_foreign_keys'])->map(fn ($foreignKey): string => data_get($foreignKey, 'table').'.'.data_get($foreignKey, 'to'))->all())
        ->toContain('salescalls.id', 'customers.id', 'material_groups.id', 'brands.id')
        ->and($diagnostics['references']['salescall']['local_id'])->toBe($salescall->id)
        ->and($diagnostics['references']['salescall']['exists'])->toBeTrue()
        ->and($diagnostics['references']['customer']['local_id'])->toBe($salescall->customer_id)
        ->and($diagnostics['references']['customer']['server_id'])->toBe(23595)
        ->and($diagnostics['references']['customer']['exists'])->toBeTrue()
        ->and($diagnostics['references']['selected_brands'][0]['material_group_exists'])->toBeTrue()
        ->and($diagnostics['references']['selected_brands'][0]['brand_exists'])->toBeTrue()
        ->and($diagnostics['references']['selected_brands'][0]['brand_matches_selected_material_group'])->toBeTrue()
        ->and($diagnostics['foreign_key_check']['truncated'])->toBeFalse()
        ->and($diagnostics['migrations'])->not->toBeEmpty()
        ->and($diagnostics['app'])->toHaveKeys(['version', 'build'])
        ->and($diagnostics['lookup_sync_status']['available'])->toBeFalse()
        ->and(json_encode($diagnostics))->not->toContain('Private Fixture Customer')
        ->and($after)->toBe($before);

    foreach ($queries as $query) {
        expect(strtolower($query))->toMatch('/^(select|pragma)\b/');
    }
});

test('diagnostics identify missing current references and a mismatched Brand group', function () {
    [$user, $salescall, , $materialGroup, $brand] = salescallBrandSaveFixture();
    $otherGroup = MaterialGroup::create(['name' => 'Other Group']);
    $otherBrand = Brand::create(['material_group_id' => $otherGroup->id, 'name' => 'Other Brand', 'enabled' => true]);
    $this->actingAs($user);

    $selection = [
        '999999' => [['brand_id' => '888888', 'quantity' => 1]],
        (string) $materialGroup->id => [['brand_id' => (string) $otherBrand->id, 'quantity' => 1]],
    ];
    $diagnostics = app(SalescallBrandDiagnostics::class)->collect($salescall->id, $selection);
    $missingSalescall = app(SalescallBrandDiagnostics::class)->collect(999999, []);
    DB::table('salescalls')->where('id', $salescall->id)->update(['customer_id' => 777777]);
    $missingCustomer = app(SalescallBrandDiagnostics::class)->collect($salescall->id, []);

    expect($missingSalescall['references']['salescall']['exists'])->toBeFalse()
        ->and($missingCustomer['references']['customer']['local_id'])->toBe(777777)
        ->and($missingCustomer['references']['customer']['exists'])->toBeFalse()
        ->and($diagnostics['references']['selected_brands'][0]['material_group_exists'])->toBeFalse()
        ->and($diagnostics['references']['selected_brands'][0]['brand_exists'])->toBeFalse()
        ->and($diagnostics['references']['selected_brands'][1]['brand_matches_selected_material_group'])->toBeFalse();
});

test('new negative local Customer survives Unplanned Sales Call Brand save using local ID', function () {
    [$user, , , $materialGroup, $brand] = salescallBrandSaveFixture();
    DB::table('customers')->insert([
        'id' => -94001,
        'server_id' => null,
        'name' => 'Offline Customer',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customer = Customer::findOrFail(-94001);
    $itinerary = Itinerary::query()->first();
    $itinerary->update(['date_month' => now()->startOfMonth()->toDateString()]);
    SalescallType::create(['id' => 2, 'name' => SalescallType::UNPLANNED]);
    SalescallStatus::create(['id' => 1, 'name' => SalescallStatus::PENDING]);
    $this->actingAs($user);

    $component = Livewire::test(SalescallPage::class);
    $component->call('createUnplannedSalescall', $customer->id, now()->toDateTimeString());
    $salescall = Salescall::query()->latest('id')->firstOrFail();

    $component->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $brand->id))
        ->assertNotified('Brands saved.');
    $diagnostics = app(SalescallBrandDiagnostics::class)->collect($salescall->id, salescallBrandSelection($materialGroup->id, $brand->id));

    expect((int) $salescall->fresh()->customer_id)->toBe(-94001)
        ->and((int) SalescallBrand::query()->sole()->customer_id)->toBe(-94001)
        ->and($diagnostics['references']['customer']['local_id'])->toBe(-94001)
        ->and($diagnostics['references']['customer']['server_id'])->toBeNull()
        ->and(DB::select('PRAGMA foreign_key_check'))->toBeEmpty();
});

test('existing Customer Unplanned Sales Call uses the local Customer key for Brand save', function () {
    [$user, , $customer, $materialGroup, $brand] = salescallBrandSaveFixture();
    DB::table('customers')->where('id', $customer->id)->update(['server_id' => 57321]);
    $itinerary = Itinerary::query()->first();
    $itinerary->update(['date_month' => now()->startOfMonth()->toDateString()]);
    SalescallType::create(['id' => 2, 'name' => SalescallType::UNPLANNED]);
    SalescallStatus::create(['id' => 1, 'name' => SalescallStatus::PENDING]);
    $this->actingAs($user);

    $component = Livewire::test(SalescallPage::class);
    $component->call('createUnplannedSalescall', $customer->id, now()->toDateTimeString());
    $salescall = Salescall::query()->latest('id')->firstOrFail();
    $component->call('saveBrands', $salescall->id, salescallBrandSelection($materialGroup->id, $brand->id))
        ->assertNotified('Brands saved.');

    expect((int) $salescall->customer_id)->toBe($customer->id)
        ->and((int) SalescallBrand::query()->sole()->customer_id)->toBe($customer->id)
        ->and((int) $salescall->customer_id)->not->toBe(57321);
});

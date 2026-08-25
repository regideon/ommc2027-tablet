<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedFinishFlowSalescallStatuses(): void
{
    DB::table('salescall_statuses')->insert([
        ['id' => 1, 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'In Progress', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 8, 'name' => 'Partially Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 9, 'name' => 'Cancelled', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function makeFinishFlowCheckedInSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Finish Flow Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);
}

function attachFinishFlowBrand(Salescall $salescall): void
{
    $materialGroup = MaterialGroup::create(['name' => 'Paint']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Boysen',
        'enabled' => true,
    ]);

    SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $salescall->customer_id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);
}

test('finish flow persists exit gps for completed, partially completed, and cancelled outcomes after selection is cleared', function (
    string $outcome,
    ?string $reason,
    string $expectedStatus,
    bool $requiresBrand,
) {
    seedFinishFlowSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = makeFinishFlowCheckedInSalescall($user);

    if ($requiresBrand) {
        attachFinishFlowBrand($salescall);
    }

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, $outcome, $reason)
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: $outcome)
        ->call('finishLocation', (string) $salescall->id, 14.6, 120.98, false);

    $salescall->refresh();

    expect($salescall->status)->toBe($expectedStatus)
        ->and($salescall->sync_status)->toBe('pending')
        ->and((float) $salescall->latitude_actual_out)->toBe(14.6)
        ->and((float) $salescall->longitude_actual_out)->toBe(120.98);

    if ($reason !== null) {
        expect($salescall->outcome_reason)->toBe($reason);
    }
})->with([
    'completed' => ['completed', null, 'completed', true],
    'partially completed' => ['partially_completed', 'Owner not around', 'partially_completed', false],
    'cancelled' => ['cancelled', 'Customer cancelled visit', 'cancelled', false],
]);

test('finish location ignores a null sales call id without crashing livewire or corrupting saved outcome state', function () {
    seedFinishFlowSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = makeFinishFlowCheckedInSalescall($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'cancelled', 'Customer requested reschedule')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'cancelled')
        ->call('finishLocation', null, 14.7, 121.01, false);

    $salescall->refresh();

    expect($salescall->status)->toBe('cancelled')
        ->and($salescall->outcome_reason)->toBe('Customer requested reschedule')
        ->and($salescall->sync_status)->toBe('pending')
        ->and($salescall->latitude_actual_out)->toBeNull()
        ->and($salescall->longitude_actual_out)->toBeNull();
});

test('finish location ignores a missing sales call id without mutating another saved visit', function () {
    seedFinishFlowSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = makeFinishFlowCheckedInSalescall($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'partially_completed', 'Owner not around')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'partially_completed')
        ->call('finishLocation', '999999', 14.8, 121.02, false);

    $salescall->refresh();

    expect($salescall->status)->toBe('partially_completed')
        ->and($salescall->outcome_reason)->toBe('Owner not around')
        ->and($salescall->sync_status)->toBe('pending')
        ->and($salescall->latitude_actual_out)->toBeNull()
        ->and($salescall->longitude_actual_out)->toBeNull();
});

test('browser submit gps fallback uses the finish event sales call id instead of the mutable selection state', function () {
    $contents = (string) file_get_contents(resource_path('views/filament/pages/salescall-page.blade.php'));

    expect($contents)
        ->toContain('_persistFinishLocation(salescallId, lat, lng)')
        ->toContain('$wire.finishLocation(salescallId, lat, lng, this.isOnline);')
        ->toContain('const id = $event.detail.salescallId;')
        ->toContain('_persistFinishLocation(id, 0, 0);')
        ->toContain('_persistFinishLocation(id, pos.coords.latitude, pos.coords.longitude)')
        ->not->toContain('$wire.finishLocation(this.selected, lat, lng, this.isOnline);');
});

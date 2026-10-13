<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function salescallFormRequirementsStatuses(): void
{
    foreach ([
        1 => SalescallStatus::PENDING,
        2 => SalescallStatus::IN_PROGRESS,
        4 => SalescallStatus::COMPLETED,
        8 => SalescallStatus::PARTIALLY_COMPLETED,
        9 => SalescallStatus::CANCELLED,
    ] as $id => $name) {
        SalescallStatus::create(['id' => $id, 'name' => $name]);
    }
}

/**
 * An in-progress visit by a rep whose region type is image-exempt and with
 * brands saved, so only the required-forms rule can block completion.
 *
 * @return array{0: User, 1: Salescall}
 */
function salescallFormRequirementsVisit(): array
{
    $region = RegionType::create(['name' => 'IB Team', 'sort' => 1, 'is_image_required' => false]);
    $user = User::factory()->create(['region_type_id' => $region->id]);
    $customer = Customer::create(['name' => 'Required Forms Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);

    $salescall = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);

    $materialGroup = MaterialGroup::create(['name' => 'Paint']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Boysen',
        'enabled' => true,
    ]);

    SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);

    return [$user, $salescall];
}

test('completing a visit is blocked until the Signage, Marketing, and Trade Check forms exist for the sales call', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    expect($salescall->fresh()->status)->not->toBe('completed');

    attachSignageSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    attachMarketingSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    attachTradeCheckSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('forms linked to a different sales call do not unlock completion', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    $otherSalescall = Salescall::create([
        'itinerary_id' => $salescall->itinerary_id,
        'customer_id' => $salescall->customer_id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);

    attachRequiredSalescallForms($user, $otherSalescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    expect($salescall->fresh()->status)->not->toBe('completed');
});

test('forms created by another rep do not count toward the submit requirement', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    attachRequiredSalescallForms($otherUser, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    expect($salescall->fresh()->status)->not->toBe('completed');
});

test('partially completing a visit does not require the three forms', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'partially_completed', 'Owner not around')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'partially_completed');

    expect($salescall->fresh()->status)->toBe('partially_completed');
});

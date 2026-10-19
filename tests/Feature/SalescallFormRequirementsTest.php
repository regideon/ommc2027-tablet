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

test('completing a visit does not require the Trade Request form', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('CRR Signage and Marketing submissions do not affect completion', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    attachSignageSubmission($user, $salescall);
    attachMarketingSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('a Trade Check submission does not affect completion', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    attachTradeCheckSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('a Trade Request linked to a different sales call does not affect completion', function () {
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
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('a Trade Request created by another rep does not affect completion', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    attachRequiredSalescallForms($otherUser, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('partially completing a visit does not require the Trade Request form', function () {
    salescallFormRequirementsStatuses();
    [$user, $salescall] = salescallFormRequirementsVisit();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'partially_completed', 'Owner not around')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'partially_completed');

    expect($salescall->fresh()->status)->toBe('partially_completed');
});

test('the page tracks the Trade Request of the selected sales call without counting other calls or reps', function () {
    [$user, $salescall] = salescallFormRequirementsVisit();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $otherSalescall = Salescall::create([
        'itinerary_id' => $salescall->itinerary_id,
        'customer_id' => $salescall->customer_id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);

    attachRequiredSalescallForms($otherUser, $salescall);
    attachRequiredSalescallForms($user, $otherSalescall);

    Livewire::test(SalescallPage::class)
        ->call('loadFormRequirements', $salescall->id)
        ->assertSet('hasTradeRequestForm', false);

    attachTradeRequestSubmission($user, $salescall);

    Livewire::test(SalescallPage::class)
        ->call('loadFormRequirements', $salescall->id)
        ->assertSet('hasTradeRequestForm', true);
});

test('the submit requirements list keeps Photo and drops every form requirement', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(SalescallPage::class)
        ->assertDontSeeHtml('<span>CRR Signage<span x-show="!hasSignageForm"> (missing)</span></span>')
        ->assertDontSeeHtml('<span>Marketing<span x-show="!hasMarketingForm"> (missing)</span></span>')
        ->assertDontSeeHtml('<span>Trade Request<span x-show="!hasTradeRequestForm"> (missing)</span></span>')
        ->assertSeeHtml('<span>Photo<span x-show="!photosComplete"> (missing)</span></span>');
});

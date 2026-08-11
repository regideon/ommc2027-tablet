<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedSalescallStatuses(): void
{
    DB::table('salescall_statuses')->insert([
        ['id' => 1, 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'In Progress', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 8, 'name' => 'Partially Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 9, 'name' => 'Cancelled', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function makeCheckedInSalescall(User $user, ?Carbon\Carbon $actualIn = null): Salescall
{
    $customer = Customer::create(['name' => 'Test Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => $actualIn ?? now(),
    ]);
}

test('initiateFinish stamps partial-completion audit fields when marked Partially Completed', function () {
    seedSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = makeCheckedInSalescall($user);

    (new SalescallPage)->initiateFinish($salescall->id, 'partially_completed', 'Owner not around');

    $salescall->refresh();

    expect($salescall->status)->toBe('partially_completed');
    expect($salescall->outcome_reason)->toBe('Owner not around');
    expect($salescall->partially_completed_at)->not->toBeNull();
    expect($salescall->partially_completed_reason)->toBe('Owner not around');
    expect($salescall->partially_completed_by)->toBe($user->id);
    expect($salescall->resumed_at)->toBeNull();
});

test('resumeVisit clears actual_out and stamps resumed fields, flipping status back to in_progress', function () {
    seedSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = makeCheckedInSalescall($user);
    (new SalescallPage)->initiateFinish($salescall->id, 'partially_completed', 'Owner not around');
    $salescall->refresh();

    (new SalescallPage)->resumeVisit($salescall->id);
    $salescall->refresh();

    expect($salescall->status)->toBe('in_progress');
    expect($salescall->actual_out)->toBeNull();
    expect($salescall->resumed_at)->not->toBeNull();
    expect($salescall->resumed_by)->toBe($user->id);

    // The original partial-completion record must survive the resume untouched.
    expect($salescall->partially_completed_reason)->toBe('Owner not around');
});

test('resumeVisit is blocked when another visit is already in progress', function () {
    seedSalescallStatuses();
    $user = User::factory()->create();
    $this->actingAs($user);

    $partial = makeCheckedInSalescall($user);
    (new SalescallPage)->initiateFinish($partial->id, 'partially_completed', 'Owner not around');
    $partial->refresh();

    // A second, unrelated visit is currently in progress (checked in, not finished).
    makeCheckedInSalescall($user);

    (new SalescallPage)->resumeVisit($partial->id);
    $partial->refresh();

    expect($partial->status)->toBe('partially_completed');
    expect($partial->resumed_at)->toBeNull();
});

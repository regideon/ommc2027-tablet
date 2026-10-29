<?php

use App\Enums\Marketing\RealizationStage;
use App\Enums\SPSStatus;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\MarketingFormPage;
use App\Models\Customer;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingMechanic;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\Marketing\MarketingRealization;
use App\Models\Forms\Marketing\MarketingVolume;
use App\Models\Itinerary;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/forms/marketing/programs' => Http::response(['marketing_programs' => []], 200)]);
});

function marketingFormPageUser(): User
{
    return User::factory()->create();
}

function marketingFormPageProgram(string $name = 'Price Support'): MarketingProgram
{
    return MarketingProgram::create(['name' => $name]);
}

function marketingFormPageRegion(string $name = 'Metro Manila'): RegionType
{
    return RegionType::create(['name' => $name]);
}

function marketingFormPageSubmission(User $user, MarketingProgram $program, RegionType $region): Marketing
{
    $marketing = Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web'])->id,
        'program_id' => $program->id,
        'duration_start_at' => '2026-11-01',
        'duration_end_at' => '2027-01-31',
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'sps_status' => 1,
        'rac_status' => 1,
        'rac_return_count' => 0,
        'sync_status' => 'pending',
        'sync_attempts' => 0,
    ]);

    MarketingVolume::create([
        'marketing_id' => $marketing->id,
        'before_program' => 1000,
        'during_program' => 1500,
        'volume' => 500,
    ]);

    MarketingRealization::create([
        'marketing_id' => $marketing->id,
        'stage' => RealizationStage::FIRST_STAGE,
        'start_at' => '2026-11-01',
        'end_at' => '2026-12-31',
        'volume' => 5000,
    ]);

    MarketingMechanic::create([
        'marketing_id' => $marketing->id,
        'period' => '2026-11-15',
        'unit_price' => 1500,
        'sub_total' => 7500,
    ]);

    return $marketing;
}

test('the Marketing create page renders for an authenticated user', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $this->actingAs($user);

    Livewire::test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSee('Add Marketing Recommendation')
        ->assertSee('Price Support')
        ->assertSee('Metro Manila')
        ->assertSet('duration_start_at', now()->toDateString())
        ->assertSet('duration_end_at', now()->addMonth()->toDateString())
        ->assertCount('volumes', 1)
        ->assertCount('realizations', 1)
        ->assertCount('mechanics', 1);
});

test('saving a Marketing recommendation with valid data creates it locally and redirects', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2026-11-01')
        ->set('duration_end_at', '2027-01-31')
        ->set('objective', 'Grow monthly case volume')
        ->set('name', 'Branch Expansion Support')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('volumes', [['before_program' => '1000', 'during_program' => '1500', 'volume' => '500']])
        ->set('realizations', [[
            'stage' => RealizationStage::FIRST_STAGE->value,
            'start_at' => '2026-11-01',
            'end_at' => '2026-12-31',
            'volume' => '5000',
        ]])
        ->set('mechanics', [['period' => '2026-11-15', 'unit_price' => '1500', 'sub_total' => '7500']])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'marketing']));

    $marketing = Marketing::query()->first();

    expect($marketing)->not->toBeNull()
        ->and($marketing->submitted_by)->toBe($user->id)
        ->and($marketing->program_id)->toBe($program->id)
        ->and($marketing->region_type_id)->toBe($region->id)
        ->and($marketing->name)->toBe('Branch Expansion Support')
        ->and($marketing->sync_status)->toBe('pending')
        ->and($marketing->volumes)->toHaveCount(1)
        ->and($marketing->realizations)->toHaveCount(1)
        ->and($marketing->mechanics)->toHaveCount(1);
});

test('saving a Marketing recommendation without a name reports a form error and creates nothing', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    $component = Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2026-11-01')
        ->set('duration_end_at', '2027-01-31')
        ->set('objective', 'Grow monthly case volume')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('volumes', [['before_program' => '1000', 'during_program' => '1500', 'volume' => '500']])
        ->set('realizations', [[
            'stage' => RealizationStage::FIRST_STAGE->value,
            'start_at' => '2026-11-01',
            'end_at' => '2026-12-31',
            'volume' => '5000',
        ]])
        ->set('mechanics', [['period' => '2026-11-15', 'unit_price' => '1500', 'sub_total' => '7500']])
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('name')
        ->and(Marketing::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Marketing validation messages use friendly field names instead of row indexes', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    $component = Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2026-11-01')
        ->set('duration_end_at', '2027-01-31')
        ->set('objective', 'Grow monthly case volume')
        ->set('name', 'Branch Expansion Support')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('volumes', [['before_program' => null, 'during_program' => null, 'volume' => null]])
        ->set('realizations', [['stage' => null, 'start_at' => null, 'end_at' => null, 'volume' => null]])
        ->set('mechanics', [['period' => null, 'unit_price' => null, 'sub_total' => null]])
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['volumes.0.before_program'][0])->toBe('The before program field is required.')
        ->and($errors['realizations.0.stage'][0])->toBe('The stage field is required.')
        ->and($errors['mechanics.0.period'][0])->toBe('The period field is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./');

    expect(Marketing::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Marketing rejects end dates before their start dates', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    $component = Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2027-01-31')
        ->set('duration_end_at', '2026-11-01')
        ->set('objective', 'Grow monthly case volume')
        ->set('name', 'Branch Expansion Support')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('volumes', [['before_program' => 1000, 'during_program' => 1500, 'volume' => 500]])
        ->set('realizations', [[
            'stage' => RealizationStage::FIRST_STAGE->value,
            'start_at' => '2026-12-31',
            'end_at' => '2026-11-01',
            'volume' => 5000,
        ]])
        ->set('mechanics', [['period' => '2026-11-15', 'unit_price' => 1500, 'sub_total' => 7500]])
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['duration_end_at'][0])->toBe('The duration end must be on or after the duration start.')
        ->and($errors['realizations.0.end_at'][0])->toBe('The end date must be on or after the start date.')
        ->and(Marketing::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Marketing accepts end dates equal to their start dates', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2026-11-01')
        ->set('duration_end_at', '2026-11-01')
        ->set('objective', 'Grow monthly case volume')
        ->set('name', 'Branch Expansion Support')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('volumes', [['before_program' => 1000, 'during_program' => 1500, 'volume' => 500]])
        ->set('realizations', [[
            'stage' => RealizationStage::FIRST_STAGE->value,
            'start_at' => '2026-11-01',
            'end_at' => '2026-11-01',
            'volume' => 5000,
        ]])
        ->set('mechanics', [['period' => '2026-11-15', 'unit_price' => 1500, 'sub_total' => 7500]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'marketing']));

    expect(Marketing::query()->count())->toBe(1);
});

test('the Marketing date pickers prevent end dates before their start dates', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $this->actingAs($user);

    Livewire::test(MarketingFormPage::class)
        ->assertOk()
        ->assertDontSee('nextDate', false)
        ->assertSee('x-bind:min="$refs.durationStart.value"', false)
        ->assertSee('x-bind:min="$refs.realizationStart0.value"', false)
        ->assertSee('x-on:change="$refs.durationEnd.min = $event.target.value"', false)
        ->assertSee('x-on:change="$refs.realizationEnd0.min = $event.target.value"', false);
});

test('the edit route loads a pending Marketing recommendation and saves an update', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();

    $marketing = marketingFormPageSubmission($user, $program, $region);

    $this->actingAs($user);

    Livewire::withQueryParams(['marketing' => $marketing->id])
        ->test(MarketingFormPage::class)
        ->assertSet('isEdit', true)
        ->assertSet('program_id', $program->id)
        ->assertSet('region_type_id', $region->id)
        ->assertSet('name', 'Branch Expansion Support')
        ->assertSet('duration_start_at', '2026-11-01')
        ->assertSet('salescall_uuid', null)
        ->assertCount('volumes', 1)
        ->assertCount('realizations', 1)
        ->assertCount('mechanics', 1)
        ->assertSet('realizations.0.stage', RealizationStage::FIRST_STAGE->value)
        ->set('name', 'Updated Marketing Name')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'marketing']));

    $marketing->refresh();

    expect($marketing->name)->toBe('Updated Marketing Name')
        ->and($marketing->sync_status)->toBe('pending')
        ->and((float) $marketing->volumes()->value('volume'))->toBe(500.0);
});

test('the edit route aborts 403 for a non-pending Marketing recommendation', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();

    $marketing = marketingFormPageSubmission($user, $program, $region);
    $marketing->update([
        'sps_status' => SPSStatus::ENDORSED->value,
        'sync_status' => 'synced',
    ]);

    $this->actingAs($user)
        ->withoutMiddleware(Authenticate::class)
        ->get(MarketingFormPage::getUrl(['marketing' => $marketing->id]))
        ->assertForbidden();
});

test('the edit route allows a synced Marketing recommendation returned to DRM', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();

    $marketing = marketingFormPageSubmission($user, $program, $region);
    $marketing->update([
        'sps_status' => SPSStatus::RETURNED->value,
        'sync_status' => 'synced',
    ]);

    $this->actingAs($user)
        ->withoutMiddleware(Authenticate::class)
        ->get(MarketingFormPage::getUrl(['marketing' => $marketing->id]))
        ->assertOk();
});

function marketingFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Marketing Salescall Customer', 'is_active' => true]);
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

test('opening the Marketing form from a sales call preselects and locks it without requiring a CRR', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $this->actingAs($user);

    $salescall = marketingFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertDontSee('Create a CRR for this sales call first')
        ->assertSee('Started from this Sales Call')
        ->assertSee('Mechanics Description')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the Marketing form with an unknown sales call uuid does not preselect or lock', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('a sales call owned by another rep is ignored when opening the Marketing form', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $foreignSalescall = marketingFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('opening the Marketing form from a sales call without a CRR still shows the form', function () {
    $user = marketingFormPageUser();
    marketingFormPageProgram();
    marketingFormPageRegion();
    $this->actingAs($user);

    $salescall = marketingFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertDontSee('Create a CRR for this sales call first')
        ->assertSee('Mechanics Description');
});

test('saving a Marketing recommendation linked to a sales call without a CRR creates it linked to the visit', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();
    $this->actingAs($user);

    $salescall = marketingFormPageSalescall($user);

    Livewire::test(MarketingFormPage::class)
        ->set('program_id', $program->id)
        ->set('region_type_id', $region->id)
        ->set('duration_start_at', '2026-11-01')
        ->set('duration_end_at', '2027-01-31')
        ->set('objective', 'Grow monthly case volume')
        ->set('name', 'Branch Expansion Support')
        ->set('mechanics_description', 'Volume rebate per incremental case.')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->set('volumes', [['before_program' => '1000', 'during_program' => '1500', 'volume' => '500']])
        ->set('realizations', [[
            'stage' => RealizationStage::FIRST_STAGE->value,
            'start_at' => '2026-11-01',
            'end_at' => '2026-12-31',
            'volume' => '5000',
        ]])
        ->set('mechanics', [['period' => '2026-11-15', 'unit_price' => '1500', 'sub_total' => '7500']])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'marketing']));

    expect(Marketing::query()->first()->salescall_id)->toBe($salescall->id);
});

test('editing an existing Marketing recommendation does not require a CRR for its sales call', function () {
    $user = marketingFormPageUser();
    $program = marketingFormPageProgram();
    $region = marketingFormPageRegion();

    $salescall = marketingFormPageSalescall($user);
    $marketing = marketingFormPageSubmission($user, $program, $region);
    $marketing->update(['salescall_id' => $salescall->id]);

    $this->actingAs($user);

    Livewire::withQueryParams(['marketing' => $marketing->id])
        ->test(MarketingFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->set('name', 'Updated Without CRR')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'marketing']));

    expect($marketing->fresh()->name)->toBe('Updated Without CRR');
});

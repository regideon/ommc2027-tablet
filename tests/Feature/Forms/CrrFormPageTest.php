<?php

use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\MarketingFormPage;
use App\Models\Customer;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Itinerary;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
        '*/api/forms/marketing/pull' => Http::response(['marketings' => []], 200),
    ]);
});

/**
 * @param  array<string, mixed>  $stubs
 */
function crrFormPageFake(array $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

function crrFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'crr-form-test-token']);
}

function crrFormPageProgram(string $name = 'Price Support'): CrrProgram
{
    return CrrProgram::create(['name' => $name]);
}

test('the CRR create page renders for an authenticated user', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    Livewire::test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSee('Add CRR')
        ->assertSee('Price Support')
        ->assertSet('date_implemented', now()->toDateString());
});

test('saving a CRR with valid data creates it locally and redirects', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();
    $this->actingAs($user);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('date_implemented', '2026-10-11')
        ->set('amount', '1500.50')
        ->set('details', 'Sample details')
        ->set('remarks', 'Sample remarks')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    $crr = Crr::query()->first();

    expect($crr)->not->toBeNull()
        ->and($crr->submitted_by)->toBe($user->id)
        ->and($crr->crr_program_id)->toBe($program->id)
        ->and($crr->date_implemented)->toBe('2026-10-11')
        ->and((float) $crr->amount)->toBe(1500.50)
        ->and($crr->details)->toBe('Sample details')
        ->and($crr->remarks)->toBe('Sample remarks')
        ->and($crr->sync_status)->toBe('pending');
});

test('saving a CRR with a zero amount reports an amount error and creates nothing', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();
    $this->actingAs($user);

    $component = Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '0')
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('amount')
        ->and(Crr::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('the edit route loads a pending CRR and saves an update', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-01',
        'amount' => '100.00',
        'details' => 'Original details',
        'remarks' => 'Original remarks',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    $this->actingAs($user);

    Livewire::withQueryParams(['crr' => $crr->id])
        ->test(CrrFormPage::class)
        ->assertSet('isEdit', true)
        ->assertSet('crr_program_id', $program->id)
        ->assertSet('date_implemented', '2026-10-01')
        ->assertSet('amount', '100.00')
        ->set('amount', '250.75')
        ->set('details', 'Updated details')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    $crr->refresh();

    expect((float) $crr->amount)->toBe(250.75)
        ->and($crr->details)->toBe('Updated details')
        ->and($crr->sync_status)->toBe('pending');
});

test('the edit route aborts 403 for a non-pending CRR', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 2,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    $this->actingAs($user)
        ->get(CrrFormPage::getUrl(['crr' => $crr->id]))
        ->assertForbidden();
});

test('the CRR form surfaces a warning when programs cannot be loaded', function () {
    $user = crrFormPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    crrFormPageFake(['*/api/forms/crr/programs' => Http::response([], 500)]);

    Livewire::test(CrrFormPage::class)
        ->assertSee('CRR programs could not be loaded');
});

function crrFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'CRR Salescall Customer', 'is_active' => true]);
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

function crrFormPageMarketing(
    User $user,
    Salescall $salescall,
    int $racStatus = RACStatus::APPROVED->value,
    int $spsStatus = SPSStatus::ENDORSED->value,
): Marketing {
    $program = MarketingProgram::query()->firstOrCreate(['name' => 'Price Support']);
    $region = RegionType::query()->firstOrCreate(['name' => 'Metro Manila']);
    $role = Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web']);

    return Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $role->id,
        'program_id' => $program->id,
        'salescall_id' => $salescall->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'sps_status' => $spsStatus,
        'rac_status' => $racStatus,
        'rac_return_count' => 0,
        'sync_status' => 'pending',
    ]);
}

test('the create page lists only sales calls whose Marketing is locally approved', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $approved = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $approved);

    $pending = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $pending, RACStatus::PENDING->value, SPSStatus::PENDING->value);

    crrFormPageSalescall($user);

    $otherUser = User::factory()->create();
    $otherRepsSalescall = crrFormPageSalescall($otherUser);
    crrFormPageMarketing($otherUser, $otherRepsSalescall);

    $options = Livewire::test(CrrFormPage::class)->get('salescalls');

    expect(collect($options)->pluck('uuid')->all())->toBe([$approved->local_uuid]);
});

test('opening the CRR form from a sales call with an approved Marketing preselects and locks that sales call', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSet('marketingApprovalState', 'approved')
        ->assertSee('Started from this Sales Call')
        ->assertSee('CRR Program')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the CRR form accepts an approved Marketing submitted from another device', function () {
    $user = crrFormPageUser();
    $user->update(['server_id' => 77]);
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    $salescall->update(['server_id' => 88]);

    crrFormPageFake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
        '*/api/forms/marketing/pull' => Http::response([
            'marketings' => [[
                'id' => 5,
                'salescall_id' => 88,
                'submitted_by' => 77,
                'sps_status' => SPSStatus::ENDORSED->value,
                'rac_status' => RACStatus::APPROVED->value,
            ]],
        ], 200),
    ]);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('marketingApprovalState', 'approved')
        ->assertSee('CRR Program')
        ->assertDontSee('Create a Marketing Recommendation for this sales call first');
});

test('saving a CRR accepts an approved Marketing submitted from another device', function () {
    $user = crrFormPageUser();
    $user->update(['server_id' => 77]);
    $program = crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    $salescall->update(['server_id' => 88]);

    crrFormPageFake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
        '*/api/forms/marketing/pull' => Http::response([
            'marketings' => [[
                'id' => 5,
                'salescall_id' => 88,
                'submitted_by' => 77,
                'sps_status' => SPSStatus::ENDORSED->value,
                'rac_status' => RACStatus::APPROVED->value,
            ]],
        ], 200),
    ]);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '1500')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect(Crr::query()->first()->salescall_id)->toBe($salescall->id);
});

test('opening the CRR form from a sales call without a Marketing shows the create-Marketing warning', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSet('marketingApprovalState', 'none')
        ->assertSee('Create a Marketing Recommendation for this sales call first')
        ->assertSeeHtml('href="'.MarketingFormPage::getUrl(['salescall' => $salescall->local_uuid]).'"')
        ->assertDontSee('CRR Program');
});

test('opening the CRR form from a sales call with a Marketing that is not RAC-approved shows the approval warning', function (int $racStatus) {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall, $racStatus, SPSStatus::PENDING->value);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('marketingApprovalState', 'pending')
        ->assertSee('must be approved before you can create a CRR')
        ->assertDontSee('CRR Program');
})->with([
    'pending' => [RACStatus::PENDING->value],
    'returned to DRM' => [RACStatus::RETURNED_TO_DRM->value],
    'returned to SPS' => [RACStatus::RETURNED_TO_SPS->value],
    'disapproved' => [RACStatus::DISAPPROVED->value],
]);

test('opening the CRR form without a confirmable Marketing approval shows a reconnect warning', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);

    crrFormPageFake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
        '*/api/forms/marketing/pull' => Http::response([], 500),
    ]);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('marketingApprovalState', 'unknown')
        ->assertSee('Marketing approval could not be confirmed')
        ->assertDontSee('CRR Program');
});

test('opening the CRR form with an unknown sales call uuid does not preselect or lock', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true)
        ->assertSet('marketingApprovalState', 'not_required');
});

test('a sales call owned by another rep is ignored when opening the CRR form', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $foreignSalescall = crrFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true)
        ->assertSet('marketingApprovalState', 'not_required');
});

test('saving a CRR linked to a sales call without an approved Marketing reports an error and creates nothing', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall, RACStatus::PENDING->value, SPSStatus::PENDING->value);

    $component = Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '1500')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('salescall_uuid')
        ->and(Crr::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('saving a CRR linked to a sales call with an approved Marketing creates it', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '1500')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect(Crr::query()->first()->salescall_id)->toBe($salescall->id);
});

test('a Marketing for another sales call or another rep does not unlock the CRR form', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    $otherSalescall = crrFormPageSalescall($user);
    crrFormPageMarketing($otherUser, $salescall);
    crrFormPageMarketing($user, $otherSalescall);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('marketingApprovalState', 'none')
        ->assertSee('Create a Marketing Recommendation for this sales call first')
        ->assertDontSee('CRR Program');
});

test('editing an existing CRR does not require an approved Marketing', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();

    $salescall = crrFormPageSalescall($user);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    $this->actingAs($user);

    Livewire::withQueryParams(['crr' => $crr->id])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('marketingApprovalState', 'not_required')
        ->assertSee('CRR Program')
        ->set('amount', '250.75')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect((float) $crr->fresh()->amount)->toBe(250.75);
});

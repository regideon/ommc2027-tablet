<?php

use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Models\Customer;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Itinerary;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
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

function crrFormPageProgram(string $name = 'Price Support', bool $isMarketingRequired = false): CrrProgram
{
    return CrrProgram::create([
        'name' => $name,
        'is_marketing_required' => $isMarketingRequired,
    ]);
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

test('saving a CRR marks the Forms page for automatic sync', function () {
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

    expect(session('forms.sync_after_save'))->toBeTrue();
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

test('CRR validation messages use friendly field names', function () {
    $user = crrFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(CrrFormPage::class)
        ->assertOk()
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['crr_program_id'][0])->toBe('The CRR program field is required.')
        ->and($errors['amount'][0])->toBe('The amount field is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./');

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
        ->withoutMiddleware(Authenticate::class)
        ->get(CrrFormPage::getUrl(['crr' => $crr->id]))
        ->assertForbidden();
});

test('the edit route allows a synced CRR returned to DRM', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => SPSStatus::RETURNED->value,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    $this->actingAs($user)
        ->withoutMiddleware(Authenticate::class)
        ->get(CrrFormPage::getUrl(['crr' => $crr->id]))
        ->assertOk();
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

test('the create page lists all of the rep\'s sales calls regardless of Marketing approval', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $approved = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $approved);

    $pending = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $pending, RACStatus::PENDING->value, SPSStatus::PENDING->value);

    $withoutMarketing = crrFormPageSalescall($user);

    $otherUser = User::factory()->create();
    $otherRepsSalescall = crrFormPageSalescall($otherUser);
    crrFormPageMarketing($otherUser, $otherRepsSalescall);

    $options = Livewire::test(CrrFormPage::class)->get('salescalls');

    expect(collect($options)->pluck('uuid')->all())->toBe([
        $withoutMarketing->local_uuid,
        $pending->local_uuid,
        $approved->local_uuid,
    ]);
});

test('opening the CRR form from a sales call with an approved Marketing preselects and locks that sales call', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSet('marketingApprovalState', 'not_required')
        ->set('crr_program_id', $program->id)
        ->assertSet('marketingApprovalState', 'approved')
        ->assertSet('saveBlocked', false)
        ->assertSee('Started from this Sales Call')
        ->assertSee('CRR Program')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the CRR form accepts an approved Marketing submitted from another device', function () {
    $user = crrFormPageUser();
    $user->update(['server_id' => 77]);
    $program = crrFormPageProgram(isMarketingRequired: true);
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
        ->set('crr_program_id', $program->id)
        ->assertSet('marketingApprovalState', 'approved')
        ->assertSet('saveBlocked', false)
        ->assertSee('CRR Program')
        ->assertDontSee('Create a Marketing Recommendation for this sales call first');
});

test('saving a CRR accepts an approved Marketing submitted from another device', function () {
    $user = crrFormPageUser();
    $user->update(['server_id' => 77]);
    $program = crrFormPageProgram(isMarketingRequired: true);
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

test('opening the CRR form from a sales call without a Marketing opens the form', function () {
    $user = crrFormPageUser();
    crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSet('marketingApprovalState', 'not_required')
        ->assertSet('saveBlocked', false)
        ->assertSee('Started from this Sales Call')
        ->assertSee('CRR Program')
        ->assertDontSee('Create a Marketing Recommendation for this sales call first');
});

test('opening the CRR form with a requiring program and a Marketing that is not RAC-approved blocks save', function (int $racStatus) {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall, $racStatus, SPSStatus::PENDING->value);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->set('crr_program_id', $program->id)
        ->assertSet('marketingApprovalState', 'pending')
        ->assertSet('saveBlocked', true)
        ->assertSee('Marketing approval required')
        ->assertSee('CRR Program');
})->with([
    'pending' => [RACStatus::PENDING->value],
    'returned to DRM' => [RACStatus::RETURNED_TO_DRM->value],
    'returned to SPS' => [RACStatus::RETURNED_TO_SPS->value],
    'disapproved' => [RACStatus::DISAPPROVED->value],
]);

test('opening the CRR form with a requiring program and no confirmable Marketing approval blocks save', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);

    crrFormPageFake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
        '*/api/forms/marketing/pull' => Http::response([], 500),
    ]);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->set('crr_program_id', $program->id)
        ->assertSet('marketingApprovalState', 'unknown')
        ->assertSet('saveBlocked', true)
        ->assertSee('Marketing approval could not be confirmed')
        ->assertSee('CRR Program');
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

test('saving a CRR linked to a sales call without Marketing creates it for a non-requiring program', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall, RACStatus::PENDING->value, SPSStatus::PENDING->value);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '1500')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect(Crr::query()->first()->salescall_id)->toBe($salescall->id);
});

test('saving a CRR linked to a sales call with an approved Marketing creates it for a requiring program', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    crrFormPageMarketing($user, $salescall);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->set('amount', '1500')
        ->set('salescall_uuid', $salescall->local_uuid)
        ->assertSet('saveBlocked', false)
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect(Crr::query()->first()->salescall_id)->toBe($salescall->id);
});

test('a Marketing for another sales call or another rep does not satisfy a requiring program', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);
    $otherSalescall = crrFormPageSalescall($user);
    crrFormPageMarketing($otherUser, $salescall);
    crrFormPageMarketing($user, $otherSalescall);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->set('crr_program_id', $program->id)
        ->assertSet('marketingApprovalState', 'none')
        ->assertSet('saveBlocked', true)
        ->assertSee('Create a Marketing Recommendation for this sales call first')
        ->assertSee('CRR Program');
});

test('selecting a requiring program without Marketing warns and blocks save', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    $salescall = crrFormPageSalescall($user);

    $component = Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(CrrFormPage::class)
        ->assertSet('programRequiresMarketing', false)
        ->assertSet('saveBlocked', false)
        ->set('crr_program_id', $program->id)
        ->assertSet('programRequiresMarketing', true)
        ->assertSet('marketingApprovalState', 'none')
        ->assertSet('saveBlocked', true)
        ->assertSee('Create a Marketing Recommendation for this sales call first')
        ->assertSee('CRR Program');

    $component->set('amount', '1500')->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('crr_program_id')
        ->and(Crr::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('a standalone CRR with a requiring program saves without Marketing', function () {
    $user = crrFormPageUser();
    $program = crrFormPageProgram(isMarketingRequired: true);
    $this->actingAs($user);

    Livewire::test(CrrFormPage::class)
        ->set('crr_program_id', $program->id)
        ->assertSet('programRequiresMarketing', true)
        ->assertSet('salescall_uuid', null)
        ->assertSet('saveBlocked', false)
        ->set('amount', '1500')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr']));

    expect(Crr::query()->first()->salescall_id)->toBeNull();
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

test('editing a CRR keeps its stale program visible when the Portal no longer lists it', function () {
    $user = crrFormPageUser();
    $staleProgram = crrFormPageProgram('Legacy Program');
    crrFormPageProgram('Price Support');
    $salescall = crrFormPageSalescall($user);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'crr_program_id' => $staleProgram->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    crrFormPageFake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 500, 'name' => 'Price Support', 'is_marketing_required' => true],
            ],
        ], 200),
        '*/api/forms/marketing/pull' => Http::response(['marketings' => []], 200),
    ]);

    $this->actingAs($user);

    Livewire::withQueryParams(['crr' => $crr->id])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('crr_program_id', $staleProgram->id)
        ->assertSee('Legacy Program');
});

test('editing a linked CRR cannot switch to a requiring program without Marketing approval', function () {
    $user = crrFormPageUser();
    $exemptProgram = crrFormPageProgram('Battery Swap');
    $requiringProgram = crrFormPageProgram('Price Support', true);

    $salescall = crrFormPageSalescall($user);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'crr_program_id' => $exemptProgram->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    $this->actingAs($user);

    $component = Livewire::withQueryParams(['crr' => $crr->id])
        ->test(CrrFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('programRequiresMarketing', false)
        ->assertSet('saveBlocked', false)
        ->set('crr_program_id', $requiringProgram->id)
        ->assertSet('programRequiresMarketing', true)
        ->assertSet('marketingApprovalState', 'none')
        ->assertSet('saveBlocked', true)
        ->set('amount', '250.75')
        ->call('save');

    expect($component->get('formErrors'))->toHaveKey('crr_program_id')
        ->and($crr->fresh()->crr_program_id)->toBe($exemptProgram->id);
});

<?php

use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/sync/forms/crr/programs' => Http::response(['crr_programs' => []], 200)]);
});

function crrFormPageUser(): User
{
    return User::factory()->create();
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
        ->assertRedirect(FormsPage::getUrl());

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
        ->assertRedirect(FormsPage::getUrl());

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
    Http::fake(['*/api/sync/forms/crr/programs' => Http::response([], 500)]);

    Livewire::test(CrrFormPage::class)
        ->assertSee('CRR programs could not be loaded');
});

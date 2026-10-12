<?php

use App\Enums\FormKey;
use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\User;
use App\Services\Forms\LocalCrrCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function formsPageUser(): User
{
    return User::factory()->create(['api_token' => 'forms-page-token']);
}

/**
 * Fake every Portal call the Forms page can make so no test ever hits the network.
 *
 * @param  array<int, array<string, mixed>>  $crrs
 * @param  array<int, array<string, mixed>>  $programs
 */
function formsPageFakePortal(array $crrs = [], array $programs = []): void
{
    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/api/sync/forms/crr/programs') => Http::response(['crr_programs' => $programs], 200),
        str_contains($request->url(), '/api/sync/forms/crr/pull') => Http::response(['crrs' => $crrs], 200),
        default => Http::response([], 200),
    });
}

function formsPageProgram(string $name = 'Price Support'): CrrProgram
{
    return CrrProgram::create(['name' => $name]);
}

test('the Forms page renders for an authenticated user', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['app.env' => 'local', 'sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    Livewire::test(FormsPage::class)
        ->assertOk()
        ->assertSee('Create CRR')
        ->assertSee('No CRR submissions found.');

    $this->get(FormsPage::getUrl())->assertOk();
});

test('the rail exposes all eight forms with only CRR available', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    expect(FormKey::cases())->toHaveCount(8)
        ->and(FormKey::CRR->available())->toBeTrue();

    $component = Livewire::test(FormsPage::class);

    foreach (FormKey::cases() as $form) {
        $component->assertSee($form->label());

        if ($form !== FormKey::CRR) {
            expect($form->available())->toBeFalse();
        }
    }

    $component
        ->assertSee('Coming soon')
        ->assertSeeHtml("wire:click=\"selectForm('crr')\"")
        ->assertDontSeeHtml("selectForm('marketing')")
        ->assertSet('selectedForm', 'crr');

    // Selecting an unavailable form is ignored.
    $component->call('selectForm', 'marketing')->assertSet('selectedForm', 'crr');
    $component->call('selectForm', 'crr')->assertSet('selectedForm', 'crr');
});

test('a locally created pending CRR appears in the list with a Pending Sync badge', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(LocalCrrCreationService::class)->create($user, [
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => '1500.50',
        'details' => 'Sample details',
        'remarks' => 'Sample remarks',
        'salescall_uuid' => null,
        'attachments' => [],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->assertSee('Pending Sync')
        ->assertSee('Price Support');

    $rows = $component->get('crrRows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $crr->local_uuid))->toBeTrue();

    $key = 'local:'.$crr->local_uuid;
    $component->call('selectCrr', $key)
        ->assertSet('selectedCrrKey', $key)
        ->assertSee('CRR Details');
});

test('editing a non-editable CRR is refused', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 2,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Livewire::test(FormsPage::class)
        ->call('selectCrr', 'local:'.$crr->local_uuid)
        ->call('editSelectedCrr')
        ->assertNoRedirect()
        ->assertSet('actionError', '');
});

test('editing an editable CRR redirects to the CRR form', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(LocalCrrCreationService::class)->create($user, [
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => '100.00',
        'details' => null,
        'remarks' => null,
        'salescall_uuid' => null,
        'attachments' => [],
    ]);

    Livewire::test(FormsPage::class)
        ->call('selectCrr', 'local:'.$crr->local_uuid)
        ->call('editSelectedCrr')
        ->assertRedirect(CrrFormPage::getUrl(['crr' => $crr->id]));
});

test('the Forms page Sync action pushes pending CRRs to the server', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/api/sync/forms/crr/programs') => Http::response(['crr_programs' => []], 200),
        str_contains($request->url(), '/api/sync/forms/crr/pull') => Http::response(['crrs' => []], 200),
        str_contains($request->url(), '/api/sync/forms/crr/push') => Http::response(['server_id' => 77], 200),
        str_contains($request->url(), '/api/ping') => Http::response(['status' => 'ok'], 200),
        default => Http::response([], 200),
    });

    $program = CrrProgram::create(['name' => 'Price Support', 'server_id' => 55]);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Livewire::test(FormsPage::class)
        ->call('syncNow')
        ->assertSet('syncing', false);

    expect($crr->fresh()->server_id)->toBe(77)
        ->and($crr->fresh()->sync_status)->toBe('synced');
});

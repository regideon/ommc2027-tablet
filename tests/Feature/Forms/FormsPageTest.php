<?php

use App\Enums\FormKey;
use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\TradeCheckFormPage;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\RegionType;
use App\Models\User;
use App\Services\Forms\CrrService;
use App\Services\Forms\MarketingService;
use App\Services\Forms\TradeCheckService;
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
 * @param  array<int, array<string, mixed>>  $tradeChecks
 */
function formsPageFakePortal(array $crrs = [], array $programs = [], array $tradeChecks = []): void
{
    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/api/sync/forms/crr/programs') => Http::response(['crr_programs' => $programs], 200),
        str_contains($request->url(), '/api/sync/forms/crr/pull') => Http::response(['crrs' => $crrs], 200),
        str_contains($request->url(), '/api/sync/forms/marketing/programs') => Http::response(['marketing_programs' => []], 200),
        str_contains($request->url(), '/api/sync/forms/marketing/pull') => Http::response(['marketings' => []], 200),
        str_contains($request->url(), '/api/sync/forms/trade-check/pull') => Http::response(['trade_checks' => $tradeChecks], 200),
        default => Http::response([], 200),
    });
}

function formsPageProgram(string $name = 'Price Support'): CrrProgram
{
    return CrrProgram::create(['name' => $name]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function formsPageTradeCheckTemplates(): array
{
    return [[
        'id' => 20,
        'name' => 'Trade Check Evaluation',
        'group' => [[
            'id' => 1,
            'name' => 'External',
            'category' => 1,
            'description' => null,
            'order' => 1,
            'items' => [
                [
                    'id' => 101,
                    'name' => 'DF Signage - Available',
                    'description' => null,
                    'type' => 1,
                    'is_required' => true,
                    'is_image_required' => false,
                    'options' => null,
                    'order' => 1,
                    'answer' => null,
                    'attachments' => [],
                ],
                [
                    'id' => 102,
                    'name' => 'DF Signage - Condition',
                    'description' => null,
                    'type' => 4,
                    'is_required' => false,
                    'is_image_required' => false,
                    'options' => ['Good', 'Bad'],
                    'order' => 2,
                    'answer' => null,
                    'attachments' => [],
                ],
            ],
        ]],
        'answers' => [],
    ]];
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

test('the rail exposes all eight forms with CRR, Marketing, and Trade Check available', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    expect(FormKey::cases())->toHaveCount(8)
        ->and(FormKey::CRR->available())->toBeTrue()
        ->and(FormKey::MARKETING->available())->toBeTrue()
        ->and(FormKey::TRADE_CHECK->available())->toBeTrue();

    $available = [FormKey::CRR, FormKey::MARKETING, FormKey::TRADE_CHECK];
    $component = Livewire::test(FormsPage::class);

    foreach (FormKey::cases() as $form) {
        $component->assertSee($form->label());

        if (! in_array($form, $available, true)) {
            expect($form->available())->toBeFalse();
        }
    }

    $component
        ->assertSee('Coming soon')
        ->assertSeeHtml("wire:click=\"selectForm('crr')\"")
        ->assertSeeHtml("wire:click=\"selectForm('marketing')\"")
        ->assertSeeHtml("wire:click=\"selectForm('trade-check')\"")
        ->assertSet('selectedForm', 'crr');

    // The remaining five forms render as unavailable.
    expect(substr_count($component->html(), 'Coming soon'))->toBe(5);

    // Selecting an unavailable form is ignored.
    $component->call('selectForm', 'giveaway')->assertSet('selectedForm', 'crr');
    $component->call('selectForm', 'crr')->assertSet('selectedForm', 'crr');

    // Selecting another available form switches the pane.
    $component->call('selectForm', 'marketing')->assertSet('selectedForm', 'marketing');
    $component->call('selectForm', 'trade-check')->assertSet('selectedForm', 'trade-check');
});

test('a locally created pending CRR appears in the list with a Pending Sync badge', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(CrrService::class)->create($user, [
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

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $crr->local_uuid))->toBeTrue();

    $key = 'local:'.$crr->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('CRR Details');
});

test('selecting Marketing loads locally created recommendations into the list', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = MarketingProgram::create(['name' => 'Price Support']);
    $region = RegionType::firstOrCreate(['name' => 'Metro Manila']);

    $marketing = app(MarketingService::class)->create($user, [
        'program_id' => $program->id,
        'region_type_id' => $region->id,
        'duration_start_at' => '2026-11-01',
        'duration_end_at' => '2027-01-31',
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'submitted_at' => null,
        'salescall_uuid' => null,
        'volumes' => [
            ['before_program' => '1000', 'during_program' => '1500', 'volume' => '500'],
        ],
        'realizations' => [
            ['stage' => 1, 'start_at' => '2026-11-01', 'end_at' => '2026-12-31', 'volume' => '5000'],
        ],
        'mechanics' => [
            ['period' => '2026-11-15', 'unit_price' => '1500', 'sub_total' => '7500'],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'marketing')
        ->assertSet('selectedForm', 'marketing')
        ->assertSee('Create Marketing')
        ->assertSee('Branch Expansion Support')
        ->assertSee('Price Support')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $marketing->local_uuid))->toBeTrue();

    $key = 'local:'.$marketing->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Marketing Details')
        ->assertSee('Volume rebate per incremental case.')
        ->assertSee('Edit Marketing');
});

test('selecting Trade Check loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal(tradeChecks: formsPageTradeCheckTemplates());

    $template = app(TradeCheckService::class)->programs()->firstOrFail();
    $items = $template->group->flatMap->items;

    $submission = app(TradeCheckService::class)->create($user, [
        'trade_check_id' => $template->id,
        'salescall_uuid' => null,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'trade-check')
        ->assertSet('selectedForm', 'trade-check')
        ->assertSee('Create Trade Check')
        ->assertSee('Your Trade Checks')
        ->assertSee('Trade Check Evaluation')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $submission->local_uuid))->toBeTrue()
        ->and($submission->server_id)->toBe(20);

    $key = 'server:'.$submission->server_id;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Trade Check Details')
        ->assertSee('External')
        ->assertSee('DF Signage - Available')
        ->assertSee('Yes')
        ->assertSee('Good')
        ->assertSee('Edit Trade Check');

    $component->call('editSelectedRow')
        ->assertRedirect(TradeCheckFormPage::getUrl(['submission' => $submission->id]));
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
        ->call('selectRow', 'local:'.$crr->local_uuid)
        ->call('editSelectedRow')
        ->assertNoRedirect()
        ->assertSet('actionError', '');
});

test('editing an editable CRR redirects to the CRR form', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(CrrService::class)->create($user, [
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => '100.00',
        'details' => null,
        'remarks' => null,
        'salescall_uuid' => null,
        'attachments' => [],
    ]);

    Livewire::test(FormsPage::class)
        ->call('selectRow', 'local:'.$crr->local_uuid)
        ->call('editSelectedRow')
        ->assertRedirect(CrrFormPage::getUrl(['crr' => $crr->id]));
});

test('the Forms page Sync action pushes pending CRRs to the server', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/api/sync/forms/crr/programs') => Http::response(['crr_programs' => []], 200),
        str_contains($request->url(), '/api/sync/forms/crr/pull') => Http::response(['crrs' => [[
            'id' => 77,
            'crr_program_id' => 55,
            'amount' => '100.00',
            'rsm_status' => 1,
            'sps_status' => 1,
            'spm_status' => 1,
            'attachments' => [],
        ]]], 200),
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

test('opening the Forms page with a form parameter selects that form', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal(tradeChecks: formsPageTradeCheckTemplates());

    Livewire::withQueryParams(['form' => 'trade-check'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'trade-check')
        ->assertSee('Trade Check Evaluation');

    // Unavailable or unknown forms fall back to the default.
    Livewire::withQueryParams(['form' => 'giveaway'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'crr');

    Livewire::withQueryParams(['form' => 'nonsense'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'crr');
});

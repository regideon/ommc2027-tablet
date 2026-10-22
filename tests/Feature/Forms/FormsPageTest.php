<?php

use App\Enums\FormKey;
use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\GiveawayFormPage;
use App\Filament\Pages\RepairFormPage;
use App\Filament\Pages\SignageFormPage;
use App\Filament\Pages\TradeCheckFormPage;
use App\Filament\Pages\TradeRequestFormPage;
use App\Filament\Pages\TrainingFormPage;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\RegionType;
use App\Models\User;
use App\Services\Forms\CrrService;
use App\Services\Forms\GiveawayService;
use App\Services\Forms\MarketingService;
use App\Services\Forms\RepairService;
use App\Services\Forms\SignageService;
use App\Services\Forms\TradeCheckService;
use App\Services\Forms\TradeRequestService;
use App\Services\Forms\TrainingService;
use App\Services\StagedAttachmentService;
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
        str_contains($request->url(), '/api/forms/crr/programs') => Http::response(['crr_programs' => $programs], 200),
        str_contains($request->url(), '/api/forms/crr/pull') => Http::response(['crrs' => $crrs], 200),
        str_contains($request->url(), '/api/forms/marketing/programs') => Http::response(['marketing_programs' => []], 200),
        str_contains($request->url(), '/api/forms/marketing/pull') => Http::response(['marketings' => []], 200),
        str_contains($request->url(), '/api/forms/trade-check/pull') => Http::response(['trade_checks' => $tradeChecks], 200),
        str_contains($request->url(), '/api/forms/giveaway/pull') => Http::response(['giveaways' => []], 200),
        str_contains($request->url(), '/api/forms/repair/pull') => Http::response(['repairs' => []], 200),
        str_contains($request->url(), '/api/forms/crr-signage/products') => Http::response(['signage_products' => [['id' => 301, 'name' => 'Tarpaulin']]], 200),
        str_contains($request->url(), '/api/forms/crr-signage/types') => Http::response(['signage_types' => [['id' => 401, 'name' => 'Horizontal']]], 200),
        str_contains($request->url(), '/api/forms/crr-signage/pull') => Http::response(['signages' => []], 200),
        str_contains($request->url(), '/api/forms/trade-request/products') => Http::response(['trade_products' => [['id' => 501, 'name' => 'Jacket']]], 200),
        str_contains($request->url(), '/api/forms/trade-request/types') => Http::response(['trade_types' => [['id' => 601, 'name' => 'Apparel']]], 200),
        str_contains($request->url(), '/api/forms/trade-request/pull') => Http::response(['trade_requests' => []], 200),
        str_contains($request->url(), '/api/forms/training/topics') => Http::response(['training_topics' => [['id' => 801, 'name' => 'Product Knowledge']]], 200),
        str_contains($request->url(), '/api/forms/training/positions') => Http::response(['training_positions' => [['id' => 901, 'name' => 'Driver']]], 200),
        str_contains($request->url(), '/api/forms/training/pull') => Http::response(['trainings' => []], 200),
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

test('the rail exposes all eight forms with every form available', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    expect(FormKey::cases())->toHaveCount(8)
        ->and(FormKey::CRR->available())->toBeTrue()
        ->and(FormKey::MARKETING->available())->toBeTrue()
        ->and(FormKey::TRADE_CHECK->available())->toBeTrue()
        ->and(FormKey::GIVEAWAY->available())->toBeTrue()
        ->and(FormKey::REPAIR->available())->toBeTrue()
        ->and(FormKey::SIGNAGE->available())->toBeTrue()
        ->and(FormKey::TRADE_REQUEST->available())->toBeTrue()
        ->and(FormKey::TRAINING->available())->toBeTrue();

    $component = Livewire::test(FormsPage::class);

    $component->assertDontSee('Coming soon');

    foreach (FormKey::cases() as $form) {
        $component->assertSee($form->label())
            ->assertSeeHtml("wire:click=\"selectForm('{$form->slug()}')\"");
    }

    $component->assertSet('selectedForm', 'crr');

    // Selecting any form switches the pane.
    foreach (FormKey::cases() as $form) {
        $component->call('selectForm', $form->slug())->assertSet('selectedForm', $form->slug());
    }

    // Unknown slugs are ignored.
    $component->call('selectForm', 'nonsense')->assertSet('selectedForm', FormKey::TRAINING->slug());
    $component->call('selectForm', 'crr')->assertSet('selectedForm', 'crr');
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

test('the CRR list and details render Date Implemented in the readable date format', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(CrrService::class)->create($user, [
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-22',
        'amount' => '1500.50',
        'details' => 'Sample details',
        'remarks' => null,
        'salescall_uuid' => null,
        'attachments' => [],
    ]);

    Livewire::test(FormsPage::class)
        ->assertSee('Oct 22, 2026')
        ->assertDontSee('2026-10-22')
        ->call('selectRow', 'local:'.$crr->local_uuid)
        ->assertSee('Date Implemented')
        ->assertSee('Oct 22, 2026')
        ->assertDontSee('2026-10-22');
});

test('the CRR Date Implemented preserves a month-only value', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $program = formsPageProgram();
    $crr = app(CrrService::class)->create($user, [
        'crr_program_id' => $program->id,
        'date_implemented' => 'Oct 2026',
        'amount' => '1500.50',
        'details' => null,
        'remarks' => null,
        'salescall_uuid' => null,
        'attachments' => [],
    ]);

    Livewire::test(FormsPage::class)
        ->call('selectRow', 'local:'.$crr->local_uuid)
        ->assertSee('Oct 2026');
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

function formsPageGiveawaySignatureStagedId(User $user): int
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'signature.png')
        ->id;
}

test('selecting Giveaway loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $giveaway = app(GiveawayService::class)->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => 1, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => formsPageGiveawaySignatureStagedId($user)],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'giveaway')
        ->assertSet('selectedForm', 'giveaway')
        ->assertSee('Create Giveaway')
        ->assertSee('Your Giveaways')
        ->assertSee('Store Giveaway')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $giveaway->local_uuid))->toBeTrue();

    $key = 'local:'.$giveaway->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Giveaway Details')
        ->assertSee('Gift Check')
        ->assertSee('Edit Giveaway');

    $component->call('editSelectedRow')
        ->assertRedirect(GiveawayFormPage::getUrl(['submission' => $giveaway->id]));
});

function formsPageRepairStagedId(User $user): int
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'invoice.png')
        ->id;
}

test('selecting Repair loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $repair = app(RepairService::class)->create($user, [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'details' => 'Replace brake pads',
        'amount' => '500.25',
        'remarks' => null,
        'staged_attachments' => [
            ['type' => 1, 'staged_id' => formsPageRepairStagedId($user)],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'repair')
        ->assertSet('selectedForm', 'repair')
        ->assertSee('Create Repair')
        ->assertSee('Your Repairs')
        ->assertSee('ABC 1234')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $repair->local_uuid))->toBeTrue();

    $key = 'local:'.$repair->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Repair Details')
        ->assertSee('Replace brake pads')
        ->assertSee('Edit Repair');

    $component->call('editSelectedRow')
        ->assertRedirect(RepairFormPage::getUrl(['submission' => $repair->id]));
});

function formsPageSignageStagedId(User $user): int
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'request.png')
        ->id;
}

test('selecting CRR Signage loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();

    $signage = $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => 1,
        'name' => 'Store Front Signage',
        'staged_attachments' => [
            ['type' => 1, 'staged_id' => formsPageSignageStagedId($user)],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'crr-signage')
        ->assertSet('selectedForm', 'crr-signage')
        ->assertSee('Create Signage')
        ->assertSee('Your CRR Signages')
        ->assertSee('Store Front Signage')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $signage->local_uuid))->toBeTrue();

    $key = 'local:'.$signage->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Signage Details')
        ->assertSee('Tarpaulin')
        ->assertSee('Edit Signage');

    $component->call('editSelectedRow')
        ->assertRedirect(SignageFormPage::getUrl(['submission' => $signage->id]));
});

function formsPageTradeRequestStagedId(User $user): int
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'request.png')
        ->id;
}

test('selecting Trade Request loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();

    $tradeRequest = $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            ['trade_type_id' => $type->id, 'quantity' => 3, 'size' => 3, 'description' => 'Blue'],
        ],
        'staged_attachments' => [
            ['type' => 1, 'staged_id' => formsPageTradeRequestStagedId($user)],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'trade-request')
        ->assertSet('selectedForm', 'trade-request')
        ->assertSee('Create CRR - LSM')
        ->assertSee('Your CRR - LSM submissions')
        ->assertSee('Jacket')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $tradeRequest->local_uuid))->toBeTrue();

    $key = 'local:'.$tradeRequest->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('CRR - LSM Details')
        ->assertSee('Apparel')
        ->assertSee('Edit CRR - LSM');

    $component->call('editSelectedRow')
        ->assertRedirect(TradeRequestFormPage::getUrl(['submission' => $tradeRequest->id]));
});

function formsPageTrainingStagedId(User $user): int
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'request.png')
        ->id;
}

test('selecting Training loads locally created submissions into the list and detail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();

    $training = $service->create($user, [
        'salescall_uuid' => null,
        'topic_id' => $topic->id,
        'other_topic' => null,
        'preferred_at' => '2026-11-01 09:00:00',
        'omcc_volume' => 100,
        'competitor_volume' => 50,
        'participants' => [
            ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ],
        'staged_attachments' => [
            ['type' => 1, 'staged_id' => formsPageTrainingStagedId($user)],
        ],
    ]);

    $component = Livewire::test(FormsPage::class)
        ->call('selectForm', 'training')
        ->assertSet('selectedForm', 'training')
        ->assertSee('Create Training')
        ->assertSee('Your Trainings')
        ->assertSee('Product Knowledge')
        ->assertSee('Pending Sync');

    $rows = $component->get('rows');

    expect(collect($rows)->contains(fn (array $row): bool => ($row['local_uuid'] ?? null) === $training->local_uuid))->toBeTrue();

    $key = 'local:'.$training->local_uuid;
    $component->call('selectRow', $key)
        ->assertSet('selectedRowKey', $key)
        ->assertSee('Training Details')
        ->assertSee('Preferred At')
        ->assertSee('Nov 1, 2026 9:00 AM')
        ->assertDontSee('2026-11-01 09:00:00')
        ->assertSee('Juan Dela Cruz')
        ->assertSee('Edit Training');

    $component->call('editSelectedRow')
        ->assertRedirect(TrainingFormPage::getUrl(['submission' => $training->id]));
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
        str_contains($request->url(), '/api/forms/crr/programs') => Http::response(['crr_programs' => []], 200),
        str_contains($request->url(), '/api/forms/crr/pull') => Http::response(['crrs' => [[
            'id' => 77,
            'crr_program_id' => 55,
            'amount' => '100.00',
            'rsm_status' => 1,
            'sps_status' => 1,
            'spm_status' => 1,
            'attachments' => [],
        ]]], 200),
        str_contains($request->url(), '/api/forms/crr/push') => Http::response(['server_id' => 77], 200),
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

    Livewire::withQueryParams(['form' => 'giveaway'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'giveaway')
        ->assertSee('Your Giveaways');

    Livewire::withQueryParams(['form' => 'repair'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'repair')
        ->assertSee('Your Repairs');

    Livewire::withQueryParams(['form' => 'crr-signage'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'crr-signage')
        ->assertSee('Your CRR Signages');

    Livewire::withQueryParams(['form' => 'trade-request'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'trade-request')
        ->assertSee('Your CRR - LSM submissions');

    Livewire::withQueryParams(['form' => 'training'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'training')
        ->assertSee('Your Trainings');

    // Unknown forms fall back to the default.
    Livewire::withQueryParams(['form' => 'nonsense'])
        ->test(FormsPage::class)
        ->assertSet('selectedForm', 'crr');
});

test('every form list and details split only from 900px up inside the rail', function () {
    $user = formsPageUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    formsPageFakePortal();

    $component = Livewire::test(FormsPage::class);

    foreach (FormKey::cases() as $form) {
        $component->call('selectForm', $form->slug())
            ->assertSeeHtml('min-[900px]:grid-cols-[minmax(13rem,0.42fr)_minmax(0,0.58fr)]')
            ->assertSeeHtml('hidden min-[900px]:block')
            ->assertSeeHtml('flex flex-wrap items-center justify-between gap-x-3 gap-y-2')
            ->assertSeeHtml('flex shrink-0 items-center gap-2 ml-auto')
            ->assertDontSeeHtml('md:grid md:grid-cols-[minmax(13rem,0.42fr)');
    }
});

test('the back to submissions button hides only from 900px up', function () {
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

    Livewire::test(FormsPage::class)
        ->call('selectRow', 'local:'.$crr->local_uuid)
        ->assertSeeHtml('class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 min-[900px]:hidden"')
        ->assertDontSeeHtml('rounded-full bg-gray-100 md:hidden');
});

test('compiled theme styles carry the 900px form list and details split', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $themePath = public_path('build/'.$manifest['resources/css/filament/saleshub/theme.css']['file']);
    $themeCss = file_get_contents($themePath);

    expect($themeCss)
        ->toContain('@media (width>=900px)')
        ->toContain('.min-\[900px\]\:grid{display:grid}')
        ->toContain('.min-\[900px\]\:grid-cols-\[minmax\(13rem\,0\.42fr\)_minmax\(0\,0\.58fr\)\]{grid-template-columns:minmax(13rem,.42fr) minmax(0,.58fr)}');
});

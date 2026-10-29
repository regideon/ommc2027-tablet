<?php

use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\User;
use App\Services\Forms\CrrService;
use App\Services\Forms\MarketingService;
use App\Services\Forms\SignageService;
use App\Services\Forms\TradeRequestService;
use App\Services\Forms\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reconciliationUser(): User
{
    return User::factory()->create(['api_token' => 'reconciliation-token']);
}

test('CRR program sync preserves local ids on Portal id churn and cleans stale rows', function () {
    $user = reconciliationUser();
    $program = CrrProgram::create([
        'server_id' => 1,
        'name' => 'Price Support',
        'is_marketing_required' => true,
    ]);
    $referenced = CrrProgram::create(['server_id' => 2, 'name' => 'Legacy Referenced']);
    $unused = CrrProgram::create(['server_id' => 3, 'name' => 'Legacy Unused']);

    $programId = $program->id;

    Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $referenced->id,
        'amount' => '100.00',
        'rsm_status' => RSMStatus::PENDING->value,
        'sps_status' => SPSStatus::PENDING->value,
        'spm_status' => SPMStatus::PENDING->value,
        'sync_status' => 'pending',
    ]);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 101, 'name' => 'Price Support', 'is_marketing_required' => true],
                ['id' => 102, 'name' => 'Battery Swap', 'is_marketing_required' => false],
            ],
        ], 200),
    ]);

    $this->actingAs($user);
    $programs = app(CrrService::class)->programs();

    expect($program->fresh()->id)->toBe($programId)
        ->and($program->fresh()->server_id)->toBe(101)
        ->and(CrrProgram::query()->where('name', 'Battery Swap')->first()?->server_id)->toBe(102)
        ->and(CrrProgram::find($unused->id))->toBeNull()
        ->and(CrrProgram::find($referenced->id))->not->toBeNull()
        ->and($programs->pluck('name')->all())->toBe(['Battery Swap', 'Price Support']);
});

test('Marketing program sync preserves local ids on Portal id churn', function () {
    $user = reconciliationUser();
    $program = MarketingProgram::create(['server_id' => 1, 'name' => 'Price Support']);
    $programId = $program->id;

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/marketing/programs' => Http::response([
            'marketing_programs' => [
                ['id' => 101, 'name' => 'Price Support'],
                ['id' => 102, 'name' => 'MCB Volume Growth Incentive'],
            ],
        ], 200),
    ]);

    $this->actingAs($user);
    app(MarketingService::class)->programs();

    expect($program->fresh()->id)->toBe($programId)
        ->and($program->fresh()->server_id)->toBe(101)
        ->and(MarketingProgram::query()->where('name', 'MCB Volume Growth Incentive')->exists())->toBeTrue();
});

test('Signage master data sync preserves local ids on Portal id churn', function () {
    $user = reconciliationUser();
    $product = SignageProduct::create(['server_id' => 1, 'name' => 'Tarpaulin']);
    $type = SignageType::create(['server_id' => 2, 'name' => 'Horizontal']);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr-signage/products' => Http::response([
            'signage_products' => [['id' => 101, 'name' => 'Tarpaulin']],
        ], 200),
        '*/api/forms/crr-signage/types' => Http::response([
            'signage_types' => [['id' => 201, 'name' => 'Horizontal']],
        ], 200),
    ]);

    $this->actingAs($user);
    app(SignageService::class)->programs();

    expect($product->fresh()->server_id)->toBe(101)
        ->and($type->fresh()->server_id)->toBe(201);
});

test('Trade request master data sync preserves local ids on Portal id churn', function () {
    $user = reconciliationUser();
    $product = TradeProduct::create(['server_id' => 1, 'name' => 'Jacket']);
    $type = TradeType::create(['server_id' => 2, 'name' => 'Apparel']);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/trade-request/products' => Http::response([
            'trade_products' => [['id' => 101, 'name' => 'Jacket']],
        ], 200),
        '*/api/forms/trade-request/types' => Http::response([
            'trade_types' => [['id' => 201, 'name' => 'Apparel']],
        ], 200),
    ]);

    $this->actingAs($user);
    app(TradeRequestService::class)->programs();

    expect($product->fresh()->server_id)->toBe(101)
        ->and($type->fresh()->server_id)->toBe(201);
});

test('Training master data sync preserves local ids on Portal id churn', function () {
    $user = reconciliationUser();
    $topic = TrainingTopic::create(['server_id' => 1, 'name' => 'Product Knowledge']);
    $position = TrainingPosition::create(['server_id' => 2, 'name' => 'Driver']);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/training/topics' => Http::response([
            'training_topics' => [['id' => 101, 'name' => 'Product Knowledge']],
        ], 200),
        '*/api/forms/training/positions' => Http::response([
            'training_positions' => [['id' => 201, 'name' => 'Driver']],
        ], 200),
    ]);

    $this->actingAs($user);
    app(TrainingService::class)->programs();

    expect($topic->fresh()->server_id)->toBe(101)
        ->and($position->fresh()->server_id)->toBe(201);
});

test('CRR program reconciliation removes duplicate names and reassigns existing CRRs', function () {
    $user = reconciliationUser();
    $old = CrrProgram::create(['server_id' => 1, 'name' => 'Price Support']);
    $new = CrrProgram::create(['server_id' => 12, 'name' => 'Price Support']);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $old->id,
        'amount' => '100.00',
        'rsm_status' => RSMStatus::PENDING->value,
        'sps_status' => SPSStatus::PENDING->value,
        'spm_status' => SPMStatus::PENDING->value,
        'sync_status' => 'pending',
    ]);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 12, 'name' => 'Price Support', 'is_marketing_required' => true],
            ],
        ], 200),
    ]);

    $this->actingAs($user);
    app(CrrService::class)->programs();

    expect(CrrProgram::query()->where('name', 'Price Support')->count())->toBe(1)
        ->and(CrrProgram::query()->where('name', 'Price Support')->first()?->id)->toBe($new->id)
        ->and($crr->fresh()->crr_program_id)->toBe($new->id);
});

test('CRR program reconciliation checks references in one query', function () {
    $user = reconciliationUser();

    foreach (range(1, 5) as $index) {
        CrrProgram::create([
            'server_id' => $index,
            'name' => 'Legacy '.$index,
        ]);
    }

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 101, 'name' => 'Price Support', 'is_marketing_required' => true],
            ],
        ], 200),
    ]);

    $this->actingAs($user);

    DB::enableQueryLog();
    app(CrrService::class)->programs();
    $referenceQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "crrs"'));
    DB::disableQueryLog();

    expect($referenceQueries)->toHaveCount(1);
});

test('a failed master data fetch leaves local rows untouched', function () {
    $user = reconciliationUser();
    $program = CrrProgram::create(['server_id' => 1, 'name' => 'Legacy Unused']);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/forms/crr/programs' => Http::response([], 500)]);

    $this->actingAs($user);
    $service = app(CrrService::class);
    $service->programs();

    expect($service->programReadFailed())->toBeTrue()
        ->and(CrrProgram::find($program->id))->not->toBeNull();
});

test('an empty master data response leaves local rows untouched', function () {
    $user = reconciliationUser();
    $program = CrrProgram::create(['server_id' => 1, 'name' => 'Legacy Unused']);

    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        '*/api/forms/crr/programs' => Http::response(['crr_programs' => []], 200),
    ]);

    $this->actingAs($user);
    app(CrrService::class)->programs();

    expect(CrrProgram::find($program->id))->not->toBeNull();
});

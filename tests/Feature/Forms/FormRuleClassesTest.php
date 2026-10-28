<?php

use App\Support\Forms\CrrRules;
use App\Support\Forms\GiveawayRules;
use App\Support\Forms\MarketingRules;
use App\Support\Forms\RepairRules;
use App\Support\Forms\SignageRules;
use App\Support\Forms\TradeCheckRules;
use App\Support\Forms\TradeCheckSubmissionRules;
use App\Support\Forms\TradeRequestRules;
use App\Support\Forms\TrainingRules;

test('per-form rule classes provide friendly validation names', function (
    string $rulesClass,
    string $key,
    string $label,
): void {
    $attributes = $rulesClass::attributes();

    expect($attributes)->toHaveKey($key)
        ->and($attributes[$key])->toBe($label);
})->with([
    'CRR program' => [CrrRules::class, 'crr_program_id', 'CRR program'],
    'Giveaway item name' => [GiveawayRules::class, 'items.*.name', 'item name'],
    'Marketing realization stage' => [MarketingRules::class, 'realizations.*.stage', 'stage'],
    'Repair plate number' => [RepairRules::class, 'plate_number', 'plate number'],
    'Signage product' => [SignageRules::class, 'product_id', 'product'],
    'Trade Check template name' => [TradeCheckRules::class, 'name', 'name'],
    'Trade Check submission answer' => [TradeCheckSubmissionRules::class, 'answers', 'answer'],
    'Trade Request trade type' => [TradeRequestRules::class, 'items.*.trade_type_id', 'trade type'],
    'Training participant position' => [TrainingRules::class, 'participants.*.position_id', 'position'],
]);

test('per-form rule classes provide custom validation messages', function (string $rulesClass): void {
    expect($rulesClass::messages())->toBeArray();
})->with([
    CrrRules::class,
    GiveawayRules::class,
    MarketingRules::class,
    RepairRules::class,
    SignageRules::class,
    TradeCheckRules::class,
    TradeCheckSubmissionRules::class,
    TradeRequestRules::class,
    TrainingRules::class,
]);

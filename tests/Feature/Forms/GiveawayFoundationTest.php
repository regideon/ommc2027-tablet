<?php

use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\RSMStatus;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\User;
use App\Support\Forms\GiveawayRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the giveaway tables and key columns exist', function () {
    expect(Schema::hasColumns('giveaways', [
        'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id', 'name',
        'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('giveaway_items', [
            'id', 'giveaway_id', 'category', 'name',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('giveaway_answers', [
            'id', 'giveaway_id', 'item_id', 'salescall_id', 'submitted_by', 'rsm_status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('giveaway_answer_items', [
            'id', 'giveaway_id', 'item_id', 'answer',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('giveaway_signatures', [
            'id', 'local_uuid', 'server_id', 'giveaway_id', 'answer_id', 'salescall_id',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

/**
 * @return array{user: User, giveaway: Giveaway, item: GiveawayItem, answer: GiveawayAnswer, answerItem: GiveawayAnswerItem, signature: GiveawaySignature}
 */
function giveawayFoundationRecords(): array
{
    $user = User::factory()->create();

    $giveaway = Giveaway::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'name' => 'Foundation Giveaway',
    ]);

    $item = GiveawayItem::create([
        'giveaway_id' => $giveaway->id,
        'category' => GiveawayCategory::GC->value,
        'name' => 'Gift Check',
    ]);

    $answer = GiveawayAnswer::create([
        'giveaway_id' => $giveaway->id,
        'item_id' => $item->id,
        'submitted_by' => $user->id,
        'rsm_status' => RSMStatus::APPROVED->value,
    ]);

    $answerItem = GiveawayAnswerItem::create([
        'giveaway_id' => $giveaway->id,
        'item_id' => $item->id,
        'answer' => 'Delivered',
    ]);

    $signature = GiveawaySignature::create([
        'local_uuid' => (string) Str::uuid(),
        'giveaway_id' => $giveaway->id,
        'answer_id' => $answer->id,
        'local_path' => 'giveaway_signatures/'.$giveaway->local_uuid.'.png',
        'original_name' => 'signature.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 512,
    ]);

    return compact('user', 'giveaway', 'item', 'answer', 'answerItem', 'signature');
}

test('giveaway models cast enums and resolve their relationships', function () {
    [
        'user' => $user,
        'giveaway' => $giveaway,
        'item' => $item,
        'answer' => $answer,
        'answerItem' => $answerItem,
        'signature' => $signature,
    ] = giveawayFoundationRecords();

    $giveaway = $giveaway->fresh();

    expect($item->fresh()->category)->toBe(GiveawayCategory::GC)
        ->and($answer->fresh()->rsm_status)->toBe(RSMStatus::APPROVED)
        ->and($giveaway->submittedBy->is($user))->toBeTrue()
        ->and($giveaway->items()->where('id', $item->id)->exists())->toBeTrue()
        ->and($giveaway->answers()->where('id', $answer->id)->exists())->toBeTrue()
        ->and($giveaway->firstAnswer->is($answer))->toBeTrue()
        ->and($giveaway->signatures()->where('id', $signature->id)->exists())->toBeTrue();

    expect($item->giveaway->is($giveaway))->toBeTrue()
        ->and($item->answers()->where('id', $answer->id)->exists())->toBeTrue()
        ->and($item->answerItem->is($answerItem))->toBeTrue();

    expect($answer->giveaway->is($giveaway))->toBeTrue()
        ->and($answer->item->is($item))->toBeTrue()
        ->and($answer->answerItem->is($answerItem))->toBeTrue()
        ->and($answer->signature->is($signature))->toBeTrue()
        ->and($answer->submittedBy->is($user))->toBeTrue();

    expect($answerItem->giveaway->is($giveaway))->toBeTrue()
        ->and($answerItem->item->is($item))->toBeTrue();

    expect($signature->fresh()->byte_size)->toBe(512)
        ->and($signature->giveaway->is($giveaway))->toBeTrue()
        ->and($signature->answer->is($answer))->toBeTrue();
});

test('giveaway rules accept a valid payload and reject an invalid one', function () {
    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'name' => 'Foundation Giveaway',
        'items' => [
            [
                'category' => GiveawayCategory::CALENDAR->value,
                'name' => 'Calendar',
                'answer' => 'Delivered',
            ],
        ],
    ];

    expect(Validator::make($valid, GiveawayRules::rules())->passes())->toBeTrue();

    $invalid = [
        'name' => null,
        'items' => [
            [
                'name' => 'Missing category',
            ],
        ],
    ];

    expect(Validator::make($invalid, GiveawayRules::rules())->passes())->toBeFalse();
});

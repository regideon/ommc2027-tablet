<?php

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TradeCheck\FieldType;
use App\Enums\TradeCheck\GroupCategory;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAnswer;
use App\Models\Forms\TradeCheck\TradeCheckAnswerItems;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\Forms\TradeCheck\TradeCheckGroup;
use App\Models\Forms\TradeCheck\TradeCheckItem;
use App\Models\Forms\TradeCheck\TradeCheckSubgroup;
use App\Models\User;
use App\Support\Forms\TradeCheckRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('trade check form tables and key columns exist', function () {
    expect(Schema::hasColumns('trade_checks', [
        'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id', 'name',
        'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_groups', [
            'id', 'trade_check_id', 'category', 'name', 'description', 'order',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_subgroups', [
            'id', 'trade_check_id', 'group_id', 'name', 'description',
            'is_image_required', 'order',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_items', [
            'id', 'subgroup_id', 'name', 'description', 'type', 'is_required',
            'options', 'order',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_answers', [
            'id', 'trade_check_id', 'item_id', 'submitted_by', 'salescall_id',
            'rsm_status', 'tdh_status', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_answer_items', [
            'id', 'trade_check_id', 'item_id', 'answer',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_attachments', [
            'id', 'local_uuid', 'server_id', 'trade_check_id', 'subgroup_id',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

/**
 * @return array{user: User, tradeCheck: TradeCheck, group: TradeCheckGroup, subgroup: TradeCheckSubgroup, item: TradeCheckItem, answer: TradeCheckAnswer, answerItem: TradeCheckAnswerItems, attachment: TradeCheckAttachment}
 */
function tradeCheckFoundationRecords(): array
{
    $user = User::factory()->create();

    $tradeCheck = TradeCheck::create([
        'name' => 'Weekly Trade Check',
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
    ]);

    $group = TradeCheckGroup::create([
        'trade_check_id' => $tradeCheck->id,
        'category' => GroupCategory::STORE_FRONT->value,
        'name' => 'Store Front',
        'order' => 1,
    ]);

    $subgroup = TradeCheckSubgroup::create([
        'trade_check_id' => $tradeCheck->id,
        'group_id' => $group->id,
        'name' => 'Facade',
        'is_image_required' => true,
        'order' => 1,
    ]);

    $item = TradeCheckItem::create([
        'subgroup_id' => $subgroup->id,
        'name' => 'With signage',
        'type' => FieldType::BOOLEAN->value,
        'is_required' => true,
        'options' => ['yes', 'no'],
        'order' => 1,
    ]);

    $answer = TradeCheckAnswer::create([
        'trade_check_id' => $tradeCheck->id,
        'item_id' => $item->id,
        'submitted_by' => $user->id,
    ]);

    $answerItem = TradeCheckAnswerItems::create([
        'trade_check_id' => $tradeCheck->id,
        'item_id' => $item->id,
        'answer' => 'yes',
    ]);

    $attachment = TradeCheckAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'trade_check_id' => $tradeCheck->id,
        'subgroup_id' => $subgroup->id,
        'local_path' => 'trade_check_attachments/'.$tradeCheck->local_uuid.'.png',
    ]);

    return [
        'user' => $user,
        'tradeCheck' => $tradeCheck,
        'group' => $group,
        'subgroup' => $subgroup,
        'item' => $item,
        'answer' => $answer,
        'answerItem' => $answerItem,
        'attachment' => $attachment,
    ];
}

test('trade check models cast enums and resolve their relationships', function () {
    [
        'user' => $user,
        'tradeCheck' => $tradeCheck,
        'group' => $group,
        'subgroup' => $subgroup,
        'item' => $item,
        'answer' => $answer,
        'answerItem' => $answerItem,
        'attachment' => $attachment,
    ] = tradeCheckFoundationRecords();

    $tradeCheck = $tradeCheck->fresh();
    $group = $group->fresh();
    $subgroup = $subgroup->fresh();
    $item = $item->fresh();
    $answer = $answer->fresh();

    expect($group->category)->toBe(GroupCategory::STORE_FRONT)
        ->and($subgroup->is_image_required)->toBeTrue()
        ->and($item->type)->toBe(FieldType::BOOLEAN)
        ->and($item->is_required)->toBeTrue()
        ->and($item->options)->toBe(['yes', 'no'])
        ->and($answer->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($answer->tdh_status)->toBe(TDHStatus::PENDING)
        ->and($answerItem->answer)->toBe('yes');

    expect($tradeCheck->group()->where('id', $group->id)->exists())->toBeTrue()
        ->and($group->tradeCheck->is($tradeCheck))->toBeTrue()
        ->and($group->subgroups()->where('id', $subgroup->id)->exists())->toBeTrue()
        ->and($subgroup->group->is($group))->toBeTrue()
        ->and($subgroup->items()->where('id', $item->id)->exists())->toBeTrue()
        ->and($item->subgroup->is($subgroup))->toBeTrue()
        ->and($tradeCheck->answers()->where('id', $answer->id)->exists())->toBeTrue()
        ->and($answer->item->is($item))->toBeTrue()
        ->and($answer->submittedBy->is($user))->toBeTrue()
        ->and($item->answerItem->is($answerItem))->toBeTrue()
        ->and($subgroup->attachments()->where('id', $attachment->id)->exists())->toBeTrue()
        ->and($attachment->tradeCheck->is($tradeCheck))->toBeTrue()
        ->and($attachment->subgroup->is($subgroup))->toBeTrue()
        ->and($tradeCheck->firstAnswer->is($answer))->toBeTrue();
});

test('trade check rules accept a valid payload and reject an invalid one', function () {
    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'name' => 'Weekly Trade Check',
        'groups' => [
            [
                'name' => 'External',
                'category' => GroupCategory::EXTERNAL->value,
                'order' => 1,
                'subgroups' => [
                    [
                        'name' => 'DF Signage',
                        'is_image_required' => true,
                        'order' => 1,
                        'items' => [
                            [
                                'name' => 'Available',
                                'type' => FieldType::BOOLEAN->value,
                                'is_required' => true,
                                'options' => ['yes', 'no'],
                                'order' => 1,
                                'answer' => 'yes',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect(Validator::make($valid, TradeCheckRules::rules())->passes())->toBeTrue();

    $invalid = [
        'name' => '',
        'groups' => [
            ['category' => 'not-an-integer'],
        ],
    ];

    $validator = Validator::make($invalid, TradeCheckRules::rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('name'))->toBeTrue()
        ->and($validator->errors()->has('groups.0.name'))->toBeTrue()
        ->and($validator->errors()->has('groups.0.category'))->toBeTrue();
});

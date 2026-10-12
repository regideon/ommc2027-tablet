<?php

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\TradeRequest\Size;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeRequest;
use App\Models\Forms\TradeRequest\TradeRequestAttachment;
use App\Models\Forms\TradeRequest\TradeRequestItem;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\User;
use App\Support\Forms\TradeRequestRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('trade request form tables and key columns exist', function () {
    expect(Schema::hasTable('trade_products'))->toBeTrue()
        ->and(Schema::hasTable('trade_types'))->toBeTrue()
        ->and(Schema::hasTable('trade_requests'))->toBeTrue()
        ->and(Schema::hasTable('trade_request_items'))->toBeTrue()
        ->and(Schema::hasTable('trade_request_attachments'))->toBeTrue();

    expect(Schema::hasColumns('trade_products', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('trade_types', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('trade_requests', [
            'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id', 'product_id',
            'rsm_status', 'tdh_status', 'tds_status',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_request_items', [
            'id', 'trade_request_id', 'trade_type_id', 'quantity', 'size', 'description',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('trade_request_attachments', [
            'id', 'local_uuid', 'server_id', 'trade_request_id', 'type',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

/**
 * @return array{user: User, product: TradeProduct, type: TradeType, tradeRequest: TradeRequest, item: TradeRequestItem}
 */
function tradeRequestFoundationRecords(): array
{
    $user = User::factory()->create();
    $product = TradeProduct::create(['name' => 'T-Shirt']);
    $type = TradeType::create(['name' => 'Apparel']);

    $tradeRequest = TradeRequest::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'product_id' => $product->id,
    ]);

    $item = TradeRequestItem::create([
        'trade_request_id' => $tradeRequest->id,
        'trade_type_id' => $type->id,
        'quantity' => 10,
        'size' => Size::MEDIUM->value,
        'description' => 'Medium shirts',
    ]);

    return [
        'user' => $user,
        'product' => $product,
        'type' => $type,
        'tradeRequest' => $tradeRequest,
        'item' => $item,
    ];
}

test('trade request models cast enums and resolve their relationships', function () {
    [
        'user' => $user,
        'product' => $product,
        'type' => $type,
        'tradeRequest' => $tradeRequest,
        'item' => $item,
    ] = tradeRequestFoundationRecords();

    $tradeRequest = $tradeRequest->fresh();
    $item = $item->fresh();

    expect($tradeRequest->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($tradeRequest->tdh_status)->toBe(TDHStatus::PENDING)
        ->and($tradeRequest->tds_status)->toBe(TDSStatus::PENDING)
        ->and($tradeRequest->submittedBy->is($user))->toBeTrue()
        ->and($tradeRequest->product->is($product))->toBeTrue()
        ->and($tradeRequest->items()->where('id', $item->id)->exists())->toBeTrue()
        ->and($product->requests()->where('id', $tradeRequest->id)->exists())->toBeTrue()
        ->and($item->size)->toBe(Size::MEDIUM)
        ->and($item->tradeRequest->is($tradeRequest))->toBeTrue()
        ->and($item->tradeType->is($type))->toBeTrue()
        ->and($type->items()->where('id', $item->id)->exists())->toBeTrue();

    $attachment = TradeRequestAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'trade_request_id' => $tradeRequest->id,
        'type' => TradeRequestAttachmentType::REQUEST->value,
        'local_path' => 'trade_request_attachments/'.$tradeRequest->local_uuid.'.png',
    ]);

    expect($attachment->type)->toBe(TradeRequestAttachmentType::REQUEST)
        ->and($attachment->tradeRequest->is($tradeRequest))->toBeTrue()
        ->and($tradeRequest->attachments()->where('id', $attachment->id)->exists())->toBeTrue();
});

test('trade request rules accept a valid payload and reject an invalid one', function () {
    $product = TradeProduct::create(['name' => 'T-Shirt']);
    $type = TradeType::create(['name' => 'Apparel']);

    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            [
                'trade_type_id' => $type->id,
                'quantity' => 5,
                'size' => Size::MEDIUM->value,
                'description' => 'Medium shirts',
            ],
        ],
    ];

    expect(Validator::make($valid, TradeRequestRules::rules())->passes())->toBeTrue();

    $invalid = [
        'product_id' => 999999,
        'items' => [
            ['trade_type_id' => 999999, 'quantity' => 'many'],
        ],
    ];

    $validator = Validator::make($invalid, TradeRequestRules::rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('product_id'))->toBeTrue()
        ->and($validator->errors()->has('items.0.trade_type_id'))->toBeTrue()
        ->and($validator->errors()->has('items.0.quantity'))->toBeTrue();
});

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
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\TradeRequestService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function tradeRequestWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'trade-request-test-token']);
}

/**
 * @return array<int, array<string, mixed>>
 */
function tradeRequestPullRows(): array
{
    return [[
        'id' => 60,
        'product_id' => 501,
        'rsm_status' => RSMStatus::PENDING->value,
        'tdh_status' => TDHStatus::PENDING->value,
        'tds_status' => TDSStatus::PENDING->value,
        'salescall_id' => null,
        'items' => [
            ['id' => 1, 'trade_type_id' => 601, 'quantity' => 3, 'size' => Size::LARGE->value, 'description' => 'Blue'],
        ],
        'attachments' => [],
    ]];
}

/**
 * @param  array<int, array<string, mixed>>  $tradeRequests
 */
function tradeRequestFakePortal(array $tradeRequests = []): void
{
    Http::fake([
        '*/api/forms/trade-request/products' => Http::response(['trade_products' => [['id' => 501, 'name' => 'Jacket']]], 200),
        '*/api/forms/trade-request/types' => Http::response(['trade_types' => [['id' => 601, 'name' => 'Apparel']]], 200),
        '*/api/forms/trade-request/pull' => Http::response(['trade_requests' => $tradeRequests], 200),
    ]);
}

function tradeRequestPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function tradeRequestStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, tradeRequestPngDataUri(), $name);
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<int, array{type: int, staged_id: int}>  $attachments
 * @return array<string, mixed>
 */
function tradeRequestCreatePayload(int $productId, array $items, array $attachments): array
{
    return [
        'salescall_uuid' => null,
        'product_id' => $productId,
        'items' => $items,
        'staged_attachments' => $attachments,
    ];
}

test('trade request master data is cached from the pull', function () {
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $products = $service->programs();

    expect($products)->toHaveCount(1)
        ->and($products->first()->name)->toBe('Jacket')
        ->and($service->programReadFailed())->toBeFalse();

    expect(TradeProduct::query()->firstOrFail()->server_id)->toBe(501)
        ->and(TradeType::query()->firstOrFail()->server_id)->toBe(601)
        ->and(TradeType::query()->firstOrFail()->name)->toBe('Apparel');
});

test('pulled trade requests are cached locally with their items and statuses', function () {
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal(tradeRequestPullRows());

    $service = app(TradeRequestService::class);
    $service->programs();

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['product'])->toBe('Jacket')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['items'][0]['trade_type'])->toBe('Apparel')
        ->and($rows->first()['items'][0]['quantity'])->toBe(3)
        ->and($rows->first()['items'][0]['size_label'])->toBe('Large');

    $tradeRequest = TradeRequest::query()->firstOrFail();

    expect($tradeRequest->server_id)->toBe(60)
        ->and($tradeRequest->sync_status)->toBe('synced')
        ->and($tradeRequest->submitted_by)->toBe($user->id)
        ->and($tradeRequest->product->name)->toBe('Jacket')
        ->and($tradeRequest->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($tradeRequest->items()->count())->toBe(1)
        ->and($tradeRequest->items()->firstOrFail()->tradeType->name)->toBe('Apparel')
        ->and($tradeRequest->items()->firstOrFail()->size)->toBe(Size::LARGE);
});

test('creating a Trade Request requires at least one item', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    try {
        $service->create($user, tradeRequestCreatePayload($product->id, [], [
            ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ]));

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items');
    }

    expect(TradeRequest::query()->count())->toBe(0);
});

test('creating a Trade Request persists the header, items, and typed staged attachments', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user, 'request.png');
    $completed = tradeRequestStagedAttachment($user, 'completed.png');

    $tradeRequest = $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 3, 'size' => Size::LARGE->value, 'description' => 'Blue'],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TradeRequestAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $item = $tradeRequest->fresh()->items()->firstOrFail();

    expect($tradeRequest->fresh()->sync_status)->toBe('pending')
        ->and($tradeRequest->fresh()->product_id)->toBe($product->id)
        ->and($tradeRequest->fresh()->items()->count())->toBe(1)
        ->and($item->trade_type_id)->toBe($type->id)
        ->and($item->quantity)->toBe(3)
        ->and($item->size)->toBe(Size::LARGE)
        ->and($item->description)->toBe('Blue')
        ->and($tradeRequest->fresh()->attachments()->count())->toBe(2)
        ->and(StagedAttachment::find($request->id))->toBeNull()
        ->and(StagedAttachment::find($completed->id))->toBeNull();

    foreach (TradeRequestAttachment::query()->get() as $attachment) {
        expect(is_file($attachment->local_path))->toBeTrue();
    }
});

test('a locally created Trade Request appears in submissions with its items and attachments', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 2, 'size' => Size::MEDIUM->value, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['items'][0]['quantity'])->toBe(2)
        ->and($rows->first()['items'][0]['size_label'])->toBe('Medium')
        ->and($rows->first()['attachments'][0]['type'])->toBe(TradeRequestAttachmentType::REQUEST->value)
        ->and($rows->first()['attachments'][0]['url'])->toBeString()->not->toBeEmpty();
});

test('updating a Trade Request replaces its items wholesale', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => Size::SMALL->value, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $tradeRequest = TradeRequest::query()->firstOrFail();
    $originalItemId = $tradeRequest->items()->firstOrFail()->id;

    $service->update($tradeRequest->fresh(), [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            ['trade_type_id' => $type->id, 'quantity' => 2, 'size' => Size::LARGE->value, 'description' => 'Red'],
            ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => Size::MEDIUM->value, 'description' => null],
        ],
    ]);

    $items = $tradeRequest->fresh()->items()->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('id')->all())->not->toContain($originalItemId)
        ->and($items->first()->quantity)->toBe(2)
        ->and($items->first()->size)->toBe(Size::LARGE)
        ->and($tradeRequest->fresh()->sync_status)->toBe('pending');
});

test('updating a Trade Request cannot remove the last Request attachment', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user, 'request.png');
    $completed = tradeRequestStagedAttachment($user, 'completed.png');

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TradeRequestAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $tradeRequest = TradeRequest::query()->firstOrFail();
    $requestAttachment = TradeRequestAttachment::query()->where('type', TradeRequestAttachmentType::REQUEST->value)->firstOrFail();
    $completedAttachment = TradeRequestAttachment::query()->where('type', TradeRequestAttachmentType::COMPLETED->value)->firstOrFail();

    $base = [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
        ],
    ];

    // Removing the Completed photo is allowed.
    $service->update($tradeRequest->fresh(), [
        ...$base,
        'remove_attachments' => [$completedAttachment->id],
    ]);

    expect(TradeRequestAttachment::query()->count())->toBe(1)
        ->and(TradeRequestAttachment::query()->firstOrFail()->type)->toBe(TradeRequestAttachmentType::REQUEST);

    // Removing the last Request photo without a replacement is rejected.
    try {
        $service->update($tradeRequest->fresh(), [
            ...$base,
            'remove_attachments' => [$requestAttachment->id],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(TradeRequestAttachment::query()->count())->toBe(1);
});

test('updating a Trade Request is blocked once an approver has acted', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);
    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    TradeRequest::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    expect(fn () => $service->update(TradeRequest::query()->firstOrFail(), [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
        ],
    ]))->toThrow(ValidationException::class);
});

test('pulled status changes and item snapshots are cached for synced trade requests', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    $rows = tradeRequestPullRows();
    $rows[0]['rsm_status'] = RSMStatus::APPROVED->value;
    $rows[0]['tdh_status'] = TDHStatus::RETURNED->value;

    tradeRequestFakePortal($rows);

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => Size::SMALL->value, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    TradeRequest::query()->update(['server_id' => 60, 'sync_status' => 'synced']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['rsm_status'])->toBe(RSMStatus::APPROVED->value)
        ->and($result->first()['tdh_status'])->toBe(TDHStatus::RETURNED->value)
        ->and($result->first()['is_editable'])->toBeFalse()
        ->and($result->first()['items'][0]['quantity'])->toBe(3)
        ->and($result->first()['items'][0]['size_label'])->toBe('Large');
});

test('an unsynced local Trade Request is not overwritten by the pull', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal(tradeRequestPullRows());

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => Size::SMALL->value, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    TradeRequest::query()->update(['server_id' => 60, 'sync_status' => 'pending']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['rsm_status'])->toBe(RSMStatus::PENDING->value)
        ->and($result->first()['items'][0]['quantity'])->toBe(1)
        ->and($result->first()['items'][0]['size_label'])->toBe('Small');
});

test('local trade requests deleted on the Portal are removed on pull', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal(tradeRequestPullRows());

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $local = $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $attachmentPath = $local->attachments()->firstOrFail()->local_path;
    $local->update(['server_id' => 99, 'sync_status' => 'synced']);

    $service->submissions();

    expect(TradeRequest::query()->find($local->id))->toBeNull()
        ->and(is_file($attachmentPath))->toBeFalse();
});

test('adopting a server row returns the cached local Trade Request', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $local = $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $local->update(['server_id' => 60, 'sync_status' => 'synced']);

    expect($service->adopt($user, ['server_id' => 60])->is($local))->toBeTrue();

    expect(fn () => $service->adopt($user, ['server_id' => 404]))
        ->toThrow(ValidationException::class);
});

test('pushing a Trade Request posts the header and item snapshot with master server ids', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $tradeRequest = $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 3, 'size' => Size::LARGE->value, 'description' => 'Blue'],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    Http::fake([
        '*/api/forms/trade-request/push' => Http::response(['server_id' => 1001], 200),
        '*/api/forms/trade-request/attach' => Http::response(['server_id' => 1050, 'uploaded_file_id' => 1050], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/forms/trade-request/push')
            && $request['product_id'] === 501
            && $request['items'][0]['trade_type_id'] === 601
            && $request['items'][0]['quantity'] === 3
            && $request['items'][0]['size'] === Size::LARGE->value
            && $request['items'][0]['description'] === 'Blue';
    });

    expect($tradeRequest->fresh()->sync_status)->toBe('synced')
        ->and($tradeRequest->fresh()->server_id)->toBe(1001);
});

test('Trade Request attachments push after the parent with their type', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user, 'request.png');
    $completed = tradeRequestStagedAttachment($user, 'completed.png');

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TradeRequestAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $attachment = TradeRequestAttachment::query()
        ->where('type', TradeRequestAttachmentType::COMPLETED->value)
        ->firstOrFail();

    Http::fake([
        '*/api/forms/trade-request/push' => Http::response(['server_id' => 1001], 200),
        '*/api/forms/trade-request/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 77], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/forms/trade-request/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['server_id']['contents'] ?? null) == 1001
            && ($parts['type']['contents'] ?? null) == TradeRequestAttachmentType::COMPLETED->value;
    });

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88)
        ->and(TradeRequest::query()->firstOrFail()->server_id)->toBe(1001);
});

test('reconciliation eager loads attachments for deleted trade requests', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();

    foreach ([1, 2] as $index) {
        $request = tradeRequestStagedAttachment($user, "request-$index.png");

        $local = $service->create($user, tradeRequestCreatePayload($product->id, [
            ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
        ], [
            ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ]));

        $local->update(['server_id' => 90 + $index, 'sync_status' => 'synced']);
    }

    DB::enableQueryLog();

    $service->submissions();

    $attachmentQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "trade_request_attachments"'))
        ->count();

    DB::disableQueryLog();

    expect(TradeRequest::query()->count())->toBe(0)
        ->and($attachmentQueries)->toBe(1);
});

test('pull caching batches master and row lookups instead of one query per row', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    $rows = tradeRequestPullRows();
    $second = $rows[0];
    $second['id'] = 61;
    $rows[] = $second;

    tradeRequestFakePortal($rows);

    $service = app(TradeRequestService::class);
    $service->programs();

    DB::enableQueryLog();

    $result = $service->submissions();

    $queries = collect(DB::getQueryLog());

    DB::disableQueryLog();

    $singleRowLookups = fn (string $table): int => $queries
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"')
            && str_contains(strtolower((string) $query['query']), 'limit 1'))
        ->count();

    expect($result)->toHaveCount(2)
        ->and($singleRowLookups('trade_products'))->toBe(0)
        ->and($singleRowLookups('trade_types'))->toBe(0)
        ->and($singleRowLookups('trade_requests'))->toBe(0);
});

test('pull caching replaces child item snapshots with a single delete and insert', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    $rows = tradeRequestPullRows();
    $second = $rows[0];
    $second['id'] = 61;
    $rows[] = $second;

    tradeRequestFakePortal($rows);

    $service = app(TradeRequestService::class);
    $service->programs();

    DB::enableQueryLog();

    $service->submissions();

    $queries = collect(DB::getQueryLog());

    DB::disableQueryLog();

    $itemDeletes = $queries
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'delete from "trade_request_items"'))
        ->count();

    $itemInserts = $queries
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'insert into "trade_request_items"'))
        ->count();

    expect(TradeRequestItem::query()->count())->toBe(2)
        ->and($itemDeletes)->toBe(1)
        ->and($itemInserts)->toBe(1);
});

test('has pending changes includes Trade Requests', function () {
    Storage::fake('local');
    $user = tradeRequestWorkflowUser();
    $this->actingAs($user);

    tradeRequestFakePortal();

    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $request = tradeRequestStagedAttachment($user);

    $service->create($user, tradeRequestCreatePayload($product->id, [
        ['trade_type_id' => $type->id, 'quantity' => 1, 'size' => null, 'description' => null],
    ], [
        ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

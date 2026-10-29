<?php

use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAnswer;
use App\Models\Forms\TradeCheck\TradeCheckAnswerItems;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\TradeCheckService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function tradeCheckWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'trade-check-test-token']);
}

/**
 * @return array{trade_checks: array<int, array<string, mixed>>}
 */
function tradeCheckPullPayload(): array
{
    return [
        'trade_checks' => [[
            'id' => 20,
            'name' => 'Trade Check Evaluation',
            'group' => [[
                'id' => 1,
                'name' => 'External',
                'category' => 1,
                'description' => null,
                'order' => 1,
                'subgroups' => [
                    [
                        'id' => 11,
                        'name' => 'DF Signage',
                        'description' => null,
                        'is_image_required' => false,
                        'order' => 1,
                        'attachments' => [],
                        'items' => [
                            [
                                'id' => 101,
                                'name' => 'Available',
                                'description' => null,
                                'type' => 1,
                                'is_required' => true,
                                'options' => null,
                                'order' => 1,
                                'answer' => null,
                            ],
                            [
                                'id' => 102,
                                'name' => 'Good condition',
                                'description' => null,
                                'type' => 4,
                                'is_required' => false,
                                'options' => ['Good', 'Bad'],
                                'order' => 2,
                                'answer' => null,
                            ],
                        ],
                    ],
                ],
            ]],
            'answers' => [],
        ]],
    ];
}

function tradeCheckFakePull(?array $payload = null): void
{
    Http::fake(['*/api/forms/trade-check/pull' => Http::response($payload ?? tradeCheckPullPayload(), 200)]);
}

function tradeCheckPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

test('Trade Check templates and their fields are cached from the pull', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $templates = $service->programs();

    expect($templates)->toHaveCount(1)
        ->and($templates->first()->name)->toBe('Trade Check Evaluation');

    $group = $templates->first()->group->first();
    $subgroup = $group->subgroups->first();
    $items = $subgroup->items;

    expect($items)->toHaveCount(2)
        ->and($subgroup->name)->toBe('DF Signage')
        ->and($subgroup->is_image_required)->toBeFalse()
        ->and($items->first()->name)->toBe('Available')
        ->and($items->first()->is_required)->toBeTrue()
        ->and($items->last()->options)->toBe(['Good', 'Bad']);
});

test('submissions list every template with a has_submission flag', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $service->programs();

    $before = $service->submissions();

    expect($before)->toHaveCount(1)
        ->and($before->first()['has_submission'])->toBeFalse();

    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    $service->create($user, [
        'trade_check_id' => $template->id,
        'salescall_uuid' => null,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Trade Check Evaluation')
        ->and($rows->first()['has_submission'])->toBeTrue()
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['groups'][0]['subgroups'][0]['items'][0]['answer'])->toBe('Yes')
        ->and($rows->first()['groups'][0]['subgroups'][0]['items'][1]['answer'])->toBe('Good');
});

test('submission details map photos under their subgroup', function () {
    Storage::fake('local');
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $subgroup = $template->group->first()->subgroups->first();
    $items = $subgroup->items;

    $staged = app(StagedAttachmentService::class)
        ->stageFromDataUri($user, tradeCheckPngDataUri(), 'signage-photo.png');

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'attachments' => [$subgroup->id => [$staged->id]],
    ]);

    $attachment = TradeCheckAttachment::query()->firstOrFail();
    $row = $service->submissions()->first();

    expect($row['groups'][0]['subgroups'][0]['is_image_required'])->toBeFalse()
        ->and($row['groups'][0]['subgroups'][0]['attachments'])->toHaveCount(1)
        ->and($row['groups'][0]['subgroups'][0]['attachments'][0]['id'])->toBe($attachment->id)
        ->and($row['groups'][0]['subgroups'][0]['items'][0])->not->toHaveKey('attachments');
});

test('creating a Trade Check requires required items and persists the answers', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    expect(fn () => $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[1]->id => 'Good'],
    ]))->toThrow(ValidationException::class);

    $service->create($user, [
        'trade_check_id' => $template->id,
        'salescall_uuid' => null,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    expect(TradeCheckAnswer::where('trade_check_id', $template->id)->count())->toBe(2)
        ->and(TradeCheckAnswerItems::where('trade_check_id', $template->id)->count())->toBe(2)
        ->and($template->fresh()->sync_status)->toBe('pending');
});

test('a Trade Check answer must match the item options', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    // Boolean item without explicit options still accepts only Yes/No, not 1/0.
    expect(fn () => $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => '1', $items[1]->id => 'Good'],
    ]))->toThrow(ValidationException::class);

    // Select item must match one of its options.
    expect(fn () => $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Meh'],
    ]))->toThrow(ValidationException::class);

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    expect(TradeCheckAnswerItems::where('trade_check_id', $template->id)->where('item_id', $items[0]->id)->value('answer'))->toBe('Yes');
});

test('updating a Trade Check replaces its answers idempotently and is blocked once acted on', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    $service->update($template->fresh(), [
        'answers' => [$items[0]->id => 'No', $items[1]->id => 'Bad'],
    ]);
    $service->update($template->fresh(), [
        'answers' => [$items[0]->id => 'No', $items[1]->id => 'Bad'],
    ]);

    expect(TradeCheckAnswerItems::where('trade_check_id', $template->id)->count())->toBe(2)
        ->and(TradeCheckAnswerItems::where('trade_check_id', $template->id)->where('item_id', $items[1]->id)->value('answer'))->toBe('Bad');

    TradeCheckAnswer::where('trade_check_id', $template->id)->update(['rsm_status' => 2]);

    expect(fn () => $service->update($template->fresh(), [
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]))->toThrow(ValidationException::class);
});

test('updating a Trade Check can remove an existing local attachment', function () {
    Storage::fake('local');
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $subgroup = $template->group->first()->subgroups->first();
    $items = $subgroup->items;

    $staged = app(StagedAttachmentService::class)
        ->stageFromDataUri($user, tradeCheckPngDataUri(), 'photo.png');

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'attachments' => [$subgroup->id => [$staged->id]],
    ]);

    $attachment = TradeCheckAttachment::query()->first();

    expect($attachment)->not->toBeNull()
        ->and(is_file($attachment->local_path))->toBeTrue();

    $service->update($template->fresh(['group.subgroups.items']), [
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'remove_attachments' => [$attachment->id],
    ]);

    expect(TradeCheckAttachment::find($attachment->id))->toBeNull()
        ->and(is_file($attachment->local_path))->toBeFalse();
});

test('server status changes for the mapped user are cached on pull', function () {
    $payload = tradeCheckPullPayload();
    $payload['trade_checks'][0]['answers'] = [
        ['submitted_by' => 2, 'rsm_status' => 2, 'tdh_status' => 2, 'item' => ['name' => 'Available', 'subgroup_name' => 'DF Signage']],
        ['submitted_by' => 2, 'rsm_status' => 2, 'tdh_status' => 2, 'item' => ['name' => 'Good condition', 'subgroup_name' => 'DF Signage']],
    ];

    Http::fake(['*/api/forms/trade-check/pull' => Http::response($payload, 200)]);

    $user = tradeCheckWorkflowUser();
    $user->update(['server_id' => 2]);
    $this->actingAs($user);

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    // Simulate a completed push, then an admin approval on the Portal.
    TradeCheck::query()
        ->whereKey($template->id)
        ->update(['server_id' => 20, 'sync_status' => 'synced']);

    $rows = app(TradeCheckService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['rsm_status'])->toBe(2)
        ->and($rows->first()['tdh_status'])->toBe(2)
        ->and($rows->first()['is_editable'])->toBeFalse();
});

test('pushing a Trade Check posts the template, answers and stores the server id', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    Http::fake(fn ($request) => str_contains($request->url(), '/api/forms/trade-check/push')
        ? Http::response(['server_id' => 501], 200)
        : Http::response([], 200));

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/forms/trade-check/push')
            && $request['name'] === 'Trade Check Evaluation'
            && count($request['groups']) === 1
            && count($request['groups'][0]['subgroups']) === 1
            && $request['groups'][0]['subgroups'][0]['is_image_required'] === false
            && count($request['groups'][0]['subgroups'][0]['items']) === 2
            && $request['groups'][0]['subgroups'][0]['items'][0]['answer'] === 'Yes'
            && $request['groups'][0]['subgroups'][0]['items'][1]['answer'] === 'Good';
    });

    expect($template->fresh()->sync_status)->toBe('synced')
        ->and($template->fresh()->server_id)->toBe(501);
});

test('Trade Check attachments push after the parent with the sub-group reference', function () {
    Storage::fake('local');
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $subgroup = $template->group->first()->subgroups->first();
    $items = $subgroup->items;

    $staged = app(StagedAttachmentService::class)->stageFromDataUri($user, tradeCheckPngDataUri(), 'signage.png');

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'attachments' => [$subgroup->id => [$staged->id]],
    ]);

    $attachment = TradeCheckAttachment::query()->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->subgroup_id)->toBe($subgroup->id)
        ->and($attachment->sync_status)->toBe('pending')
        ->and(StagedAttachment::find($staged->id))->toBeNull();

    // One push syncs the parent, then its attachment in the same run.
    Http::fake([
        '*/api/forms/trade-check/push' => Http::response(['server_id' => 501], 200),
        '*/api/forms/trade-check/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 88], 200),
        '*' => Http::response([], 200),
    ]);
    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/forms/trade-check/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['group_ref']['contents'] ?? null) === 'External'
            && ($parts['subgroup_ref']['contents'] ?? null) === 'DF Signage';
    });

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88)
        ->and($template->fresh()->sync_status)->toBe('synced')
        ->and($template->fresh()->server_id)->toBe(501);
});

test('a subgroup marked image-required needs at least one photo before saving', function () {
    Storage::fake('local');
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $subgroup = $template->group->first()->subgroups->first();
    $items = $subgroup->items;

    $subgroup->update(['is_image_required' => true]);

    expect(fn () => $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]))->toThrow(ValidationException::class);

    $staged = app(StagedAttachmentService::class)
        ->stageFromDataUri($user, tradeCheckPngDataUri(), 'signage-photo.png');

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'attachments' => [$subgroup->id => [$staged->id]],
    ]);

    expect(TradeCheckAttachment::query()->where('subgroup_id', $subgroup->id)->count())->toBe(1);
});

test('has pending changes includes Trade Checks', function () {
    $user = tradeCheckWorkflowUser();
    $this->actingAs($user);
    tradeCheckFakePull();

    $service = app(TradeCheckService::class);
    $template = $service->programs()->first();
    $items = $template->group->first()->subgroups->first()->items;

    $service->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

<?php

use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\RSMStatus;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\GiveawayService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function giveawayWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'giveaway-test-token']);
}

/**
 * @return array{giveaways: array<int, array<string, mixed>>}
 */
function giveawayPullPayload(): array
{
    return [
        'giveaways' => [[
            'id' => 30,
            'name' => 'Store Giveaway',
            'items' => [
                ['id' => 201, 'giveaway_id' => 30, 'category' => GiveawayCategory::GC->value, 'name' => 'Gift Check'],
                ['id' => 202, 'giveaway_id' => 30, 'category' => GiveawayCategory::SHIRT->value, 'name' => 'Shirt'],
            ],
            'answers' => [],
        ]],
    ];
}

function giveawayFakePull(?array $payload = null): void
{
    Http::fake(['*/api/forms/giveaway/pull' => Http::response($payload ?? giveawayPullPayload(), 200)]);
}

function giveawayPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function giveawayStagedSignature(User $user, string $name = 'signature.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, giveawayPngDataUri(), $name);
}

/**
 * @return array<int, array<string, mixed>>
 */
function giveawayItemRows(?int $firstSignatureId = null, ?int $secondSignatureId = null): array
{
    return [
        [
            'category' => GiveawayCategory::GC->value,
            'name' => 'Gift Check',
            'answer' => '2',
            'staged_signature_id' => $firstSignatureId,
        ],
        [
            'category' => GiveawayCategory::SHIRT->value,
            'name' => 'Shirt',
            'answer' => '1',
            'staged_signature_id' => $secondSignatureId,
        ],
    ];
}

test('pulled giveaways are cached locally with their items', function () {
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $rows = app(GiveawayService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Store Giveaway')
        ->and($rows->first()['is_editable'])->toBeFalse();

    $giveaway = Giveaway::query()->firstOrFail();

    expect($giveaway->server_id)->toBe(30)
        ->and($giveaway->sync_status)->toBe('synced')
        ->and($giveaway->submitted_by)->toBe($user->id)
        ->and($giveaway->items()->orderBy('id')->pluck('name')->all())->toBe(['Gift Check', 'Shirt'])
        ->and($giveaway->items()->orderBy('id')->first()->category)->toBe(GiveawayCategory::GC);
});

test('creating a Giveaway requires rows and signatures and persists them', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);

    expect(fn () => $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [],
    ]))->toThrow(ValidationException::class);

    $staged = giveawayStagedSignature($user);

    $giveaway = $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            [
                'category' => GiveawayCategory::GC->value,
                'name' => 'Gift Check',
                'answer' => '2',
                'staged_signature_id' => $staged->id,
            ],
        ],
    ]);

    $item = GiveawayItem::query()->firstOrFail();
    $answer = GiveawayAnswer::query()->firstOrFail();
    $signature = GiveawaySignature::query()->firstOrFail();

    expect($item->name)->toBe('Gift Check')
        ->and($item->category)->toBe(GiveawayCategory::GC)
        ->and($answer->item_id)->toBe($item->id)
        ->and($answer->submitted_by)->toBe($user->id)
        ->and($answer->rsm_status)->toBe(RSMStatus::PENDING)
        ->and(GiveawayAnswerItem::query()->firstOrFail()->answer)->toBe('2')
        ->and($signature->answer_id)->toBe($answer->id)
        ->and($signature->sync_status)->toBe('pending')
        ->and(is_file($signature->local_path))->toBeTrue()
        ->and(StagedAttachment::find($staged->id))->toBeNull()
        ->and($giveaway->fresh()->sync_status)->toBe('pending');
});

test('creating a Giveaway rejects duplicate item names', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);

    try {
        $service->create($user, [
            'name' => 'Store Giveaway',
            'items' => [
                ['category' => GiveawayCategory::SHIRT->value, 'name' => 'Shirt', 'signature_id' => 1],
                ['category' => GiveawayCategory::SHIRT->value, 'name' => 'shirt', 'signature_id' => 2],
            ],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.1.name');
    }

    expect(Giveaway::query()->count())->toBe(0);
});

test('a Giveaway item requires a signature', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);

    try {
        $service->create($user, [
            'name' => 'Store Giveaway',
            'items' => [
                ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => null],
            ],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.signature_id');
    }

    expect(Giveaway::query()->count())->toBe(0);
});

test('a locally created Giveaway appears in submissions with its signatures', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull(['giveaways' => []]);

    $service = app(GiveawayService::class);
    $staged = giveawayStagedSignature($user);

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            [
                'category' => GiveawayCategory::GC->value,
                'name' => 'Gift Check',
                'answer' => '2',
                'staged_signature_id' => $staged->id,
            ],
        ],
    ]);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Store Giveaway')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['items'][0]['answer'])->toBe('2')
        ->and($rows->first()['items'][0]['category_label'])->toBe('Gift Check')
        ->and($rows->first()['items'][0]['signature']['url'])->toBeString()->not->toBeEmpty();
});

test('updating a Giveaway replaces its rows idempotently and re-marks signatures pending', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $first = giveawayStagedSignature($user);
    $second = giveawayStagedSignature($user, 'shirt.png');

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => giveawayItemRows($first->id, $second->id),
    ]);

    $giveaway = Giveaway::query()->firstOrFail();
    $items = $giveaway->items()->orderBy('id')->get();
    $signatures = GiveawaySignature::query()->orderBy('id')->get();

    // Simulate a completed push and a later admin decision that is still pending.
    $giveaway->update(['server_id' => 30, 'sync_status' => 'synced']);
    GiveawaySignature::query()->update(['server_id' => 88, 'sync_status' => 'synced']);

    $rows = [
        ['id' => $items[0]->id, 'category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '5', 'signature_id' => $signatures[0]->id],
        ['id' => $items[1]->id, 'category' => GiveawayCategory::SHIRT->value, 'name' => 'Shirt', 'answer' => '1', 'signature_id' => $signatures[1]->id],
    ];

    $service->update($giveaway->fresh(), ['name' => 'Store Giveaway Updated', 'items' => $rows]);
    $service->update($giveaway->fresh(), ['name' => 'Store Giveaway Updated', 'items' => $rows]);

    expect(GiveawayItem::query()->count())->toBe(2)
        ->and(GiveawayAnswer::query()->count())->toBe(2)
        ->and(GiveawayAnswerItem::query()->count())->toBe(2)
        ->and(GiveawaySignature::query()->count())->toBe(2)
        ->and(GiveawayAnswerItem::query()->where('item_id', $items[0]->id)->value('answer'))->toBe('5')
        ->and(GiveawaySignature::query()->where('sync_status', 'pending')->count())->toBe(2)
        ->and($giveaway->fresh()->name)->toBe('Store Giveaway Updated')
        ->and($giveaway->fresh()->sync_status)->toBe('pending');

    GiveawayAnswer::query()->update(['rsm_status' => RSMStatus::APPROVED->value]);

    expect(fn () => $service->update($giveaway->fresh(), ['name' => 'Blocked', 'items' => $rows]))
        ->toThrow(ValidationException::class);
});

test('updating a Giveaway removes dropped rows and their signature files', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $first = giveawayStagedSignature($user);
    $second = giveawayStagedSignature($user, 'shirt.png');

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => giveawayItemRows($first->id, $second->id),
    ]);

    $giveaway = Giveaway::query()->firstOrFail();
    $items = $giveaway->items()->orderBy('id')->get();
    $signatures = GiveawaySignature::query()->orderBy('id')->get();
    $removedSignaturePath = $signatures[1]->local_path;

    $service->update($giveaway->fresh(), [
        'name' => 'Store Giveaway',
        'items' => [
            ['id' => $items[0]->id, 'category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'signature_id' => $signatures[0]->id],
        ],
    ]);

    expect(GiveawayItem::query()->count())->toBe(1)
        ->and(GiveawayAnswer::query()->count())->toBe(1)
        ->and(GiveawayAnswerItem::query()->count())->toBe(1)
        ->and(GiveawaySignature::query()->count())->toBe(1)
        ->and(is_file($removedSignaturePath))->toBeFalse();
});

test('updating a Giveaway can add a new row with a staged signature', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $first = giveawayStagedSignature($user);

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $first->id],
        ],
    ]);

    $giveaway = Giveaway::query()->firstOrFail();
    $item = $giveaway->items()->firstOrFail();
    $signature = GiveawaySignature::query()->firstOrFail();

    $newSignature = giveawayStagedSignature($user, 'calendar.png');

    $service->update($giveaway->fresh(), [
        'name' => 'Store Giveaway',
        'items' => [
            ['id' => $item->id, 'category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'signature_id' => $signature->id],
            ['category' => GiveawayCategory::CALENDAR->value, 'name' => 'Calendar', 'answer' => '1', 'staged_signature_id' => $newSignature->id],
        ],
    ]);

    $calendar = GiveawayItem::query()->where('name', 'Calendar')->firstOrFail();

    expect(GiveawayItem::query()->count())->toBe(2)
        ->and(GiveawaySignature::query()->count())->toBe(2)
        ->and(GiveawayAnswer::query()->where('item_id', $calendar->id)->exists())->toBeTrue()
        ->and(GiveawayAnswerItem::query()->where('item_id', $calendar->id)->value('answer'))->toBe('1');
});

test('server status changes for the mapped user are cached on pull', function () {
    $payload = giveawayPullPayload();
    $payload['giveaways'][0]['answers'] = [
        ['id' => 1, 'giveaway_id' => 30, 'item_id' => 201, 'submitted_by' => 2, 'rsm_status' => RSMStatus::APPROVED->value, 'answer_item' => ['answer' => '2']],
        ['id' => 2, 'giveaway_id' => 30, 'item_id' => 202, 'submitted_by' => 2, 'rsm_status' => RSMStatus::APPROVED->value, 'answer_item' => ['answer' => '1']],
    ];

    Http::fake(['*/api/forms/giveaway/pull' => Http::response($payload, 200)]);

    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $user->update(['server_id' => 2]);
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $first = giveawayStagedSignature($user);

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $first->id],
        ],
    ]);

    Giveaway::query()->update(['server_id' => 30, 'sync_status' => 'synced']);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['rsm_status'])->toBe(RSMStatus::APPROVED->value)
        ->and($rows->first()['is_editable'])->toBeFalse()
        ->and($rows->first()['items'][0]['answer'])->toBe('2');
});

test('an unsynced local Giveaway is not overwritten by the pull', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $staged = giveawayStagedSignature($user);

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $staged->id],
        ],
    ]);

    Giveaway::query()->update(['server_id' => 30, 'sync_status' => 'pending']);

    $payload = giveawayPullPayload();
    $payload['giveaways'][0]['name'] = 'Server Renamed';
    $payload['giveaways'][0]['items'][0]['name'] = 'Server Item';

    Http::fake(['*/api/forms/giveaway/pull' => Http::response($payload, 200)]);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Store Giveaway')
        ->and($rows->first()['items'][0]['name'])->toBe('Gift Check');
});

test('local giveaways deleted on the Portal are removed on pull', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $staged = giveawayStagedSignature($user);

    $local = $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Old Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $staged->id],
        ],
    ]);

    $signaturePath = GiveawaySignature::query()->firstOrFail()->local_path;
    $local->update(['server_id' => 99, 'sync_status' => 'synced']);

    giveawayFakePull();

    $service->submissions();

    expect(Giveaway::query()->find($local->id))->toBeNull()
        ->and(is_file($signaturePath))->toBeFalse();
});

test('adopting a server row returns the cached local Giveaway', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $staged = giveawayStagedSignature($user);

    $local = $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $staged->id],
        ],
    ]);

    $local->update(['server_id' => 30, 'sync_status' => 'synced']);

    expect($service->adopt($user, ['server_id' => 30])->is($local))->toBeTrue();

    expect(fn () => $service->adopt($user, ['server_id' => 404]))
        ->toThrow(ValidationException::class);
});

test('pushing a Giveaway posts its items and stores the server id', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $first = giveawayStagedSignature($user);
    $second = giveawayStagedSignature($user, 'shirt.png');

    $giveaway = $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => giveawayItemRows($first->id, $second->id),
    ]);

    Http::fake([
        '*/api/forms/giveaway/push' => Http::response(['server_id' => 601], 200),
        '*/api/forms/giveaway/attach' => Http::response(['server_id' => 700, 'uploaded_file_id' => 700], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/forms/giveaway/push')
            && $request['name'] === 'Store Giveaway'
            && count($request['items']) === 2
            && $request['items'][0]['category'] === GiveawayCategory::GC->value
            && $request['items'][0]['name'] === 'Gift Check'
            && $request['items'][0]['answer'] === '2'
            && $request['items'][1]['name'] === 'Shirt';
    });

    expect($giveaway->fresh()->sync_status)->toBe('synced')
        ->and($giveaway->fresh()->server_id)->toBe(601);
});

test('Giveaway signatures push after the parent with the item reference', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $service = app(GiveawayService::class);
    $staged = giveawayStagedSignature($user);

    $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $staged->id],
        ],
    ]);

    $signature = GiveawaySignature::query()->firstOrFail();

    Http::fake([
        '*/api/forms/giveaway/push' => Http::response(['server_id' => 601], 200),
        '*/api/forms/giveaway/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 77], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/forms/giveaway/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['server_id']['contents'] ?? null) == 601
            && ($parts['item_ref']['contents'] ?? null) === 'Gift Check';
    });

    expect($signature->fresh()->sync_status)->toBe('synced')
        ->and($signature->fresh()->server_id)->toBe(88)
        ->and(Giveaway::query()->firstOrFail()->server_id)->toBe(601);
});

test('has pending changes includes Giveaways', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);

    $staged = giveawayStagedSignature($user);

    app(GiveawayService::class)->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => [
            ['category' => GiveawayCategory::GC->value, 'name' => 'Gift Check', 'answer' => '2', 'staged_signature_id' => $staged->id],
        ],
    ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

<?php

use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\RSMStatus;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawayPhoto;
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
 * @return array{items: array<int, array<string, mixed>>}
 */
function giveawayMasterDataPayload(): array
{
    return [
        'items' => [
            ['id' => 1, 'category' => GiveawayCategory::GC->value, 'category_label' => 'Gift Certificates', 'name' => 'KFC GC', 'order' => 1],
            ['id' => 2, 'category' => GiveawayCategory::GC->value, 'category_label' => 'Gift Certificates', 'name' => 'SM GC', 'order' => 2],
            ['id' => 3, 'category' => GiveawayCategory::SHIRT->value, 'category_label' => 'Shirts', 'name' => 'Motolite Shirt', 'order' => 1],
        ],
    ];
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
                [
                    'id' => 201,
                    'giveaway_item_id' => 1,
                    'master_item' => ['name' => 'KFC GC', 'category' => GiveawayCategory::GC->value],
                ],
                [
                    'id' => 202,
                    'giveaway_item_id' => 3,
                    'master_item' => ['name' => 'Motolite Shirt', 'category' => GiveawayCategory::SHIRT->value],
                ],
            ],
            'answers' => [
                [
                    'item_id' => 201,
                    'submitted_by' => 2,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'answer_item' => ['answer' => '2'],
                ],
                [
                    'item_id' => 202,
                    'submitted_by' => 2,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'answer_item' => ['answer' => '1'],
                ],
            ],
            'signature' => ['id' => 88, 'uploadedFile' => null],
            'photo' => ['id' => 99, 'uploadedFile' => null],
        ]],
    ];
}

function giveawayFakePull(): void
{
    Http::fake([
        '*/api/forms/giveaway/master-data' => Http::response(giveawayMasterDataPayload(), 200),
        '*/api/forms/giveaway/pull' => Http::response(giveawayPullPayload(), 200),
    ]);
}

function giveawayPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function giveawayStagedAttachment(User $user, string $name): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, giveawayPngDataUri(), $name);
}

/**
 * @return array<int, array{giveaway_item_id: int, answer: string}>
 */
function giveawayItemRows(GiveawayService $service): array
{
    return $service->programs()
        ->take(2)
        ->map(fn (GiveawayItem $item, int $index): array => [
            'giveaway_item_id' => $item->id,
            'answer' => (string) ($index + 1),
        ])
        ->values()
        ->all();
}

test('pulled giveaways are cached with master items and proof', function () {
    $user = giveawayWorkflowUser();
    $user->update(['server_id' => 2]);
    $this->actingAs($user);
    giveawayFakePull();

    $rows = app(GiveawayService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Store Giveaway')
        ->and($rows->first()['items'][0]['name'])->toBe('KFC GC')
        ->and($rows->first()['items'][0]['answer'])->toBe('2')
        ->and($rows->first()['signature']['server_id'])->toBe(88)
        ->and($rows->first()['photo']['server_id'])->toBe(99);

    $giveaway = Giveaway::query()->firstOrFail();

    expect($giveaway->server_id)->toBe(30)
        ->and($giveaway->items()->count())->toBe(2)
        ->and(GiveawayItem::query()->where('server_id', 1)->value('name'))->toBe('KFC GC')
        ->and(GiveawaySignature::where('giveaway_id', $giveaway->id)->count())->toBe(1)
        ->and(GiveawaySignature::where('giveaway_id', $giveaway->id)->value('sync_status'))->toBe('synced')
        ->and(GiveawayPhoto::where('giveaway_id', $giveaway->id)->count())->toBe(1)
        ->and(GiveawayPhoto::where('giveaway_id', $giveaway->id)->value('sync_status'))->toBe('synced');
});

test('a Giveaway is editable before sync and read-only after sync', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $service = app(GiveawayService::class);
    $items = giveawayItemRows($service);
    $signature = giveawayStagedAttachment($user, 'signature.png');
    $photo = giveawayStagedAttachment($user, 'photo.png');

    $giveaway = $service->create($user, [
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
        'staged_photo_id' => $photo->id,
    ]);

    expect($giveaway->fresh()->is_editable)->toBeTrue();

    $giveaway->update(['sync_status' => 'synced']);

    expect($giveaway->fresh()->is_editable)->toBeFalse();
});

test('creating a giveaway requires a signature and persists master item quantities', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $service = app(GiveawayService::class);
    $items = giveawayItemRows($service);

    expect(fn () => $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => $items,
    ]))->toThrow(ValidationException::class);

    $signature = giveawayStagedAttachment($user, 'signature.png');
    $photo = giveawayStagedAttachment($user, 'photo.png');

    $giveaway = $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
        'staged_photo_id' => $photo->id,
    ]);

    expect($giveaway->items()->count())->toBe(2)
        ->and(GiveawayAnswer::where('giveaway_id', $giveaway->id)->count())->toBe(2)
        ->and(GiveawayAnswerItem::where('giveaway_id', $giveaway->id)->pluck('answer')->all())->toBe(['1', '2'])
        ->and(GiveawaySignature::where('giveaway_id', $giveaway->id)->count())->toBe(1)
        ->and(GiveawayPhoto::where('giveaway_id', $giveaway->id)->count())->toBe(1)
        ->and(StagedAttachment::find($signature->id))->toBeNull()
        ->and(StagedAttachment::find($photo->id))->toBeNull();
});

test('updating a giveaway replaces quantities and can remove the photo', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $service = app(GiveawayService::class);
    $items = giveawayItemRows($service);
    $signature = giveawayStagedAttachment($user, 'signature.png');
    $photo = giveawayStagedAttachment($user, 'photo.png');

    $giveaway = $service->create($user, [
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
        'staged_photo_id' => $photo->id,
    ]);

    $service->update($giveaway->fresh(), [
        'name' => 'Updated Giveaway',
        'items' => [
            ['giveaway_item_id' => $items[0]['giveaway_item_id'], 'answer' => '9'],
        ],
        'remove_photo' => true,
    ]);

    $giveaway->refresh();

    expect($giveaway->name)->toBe('Updated Giveaway')
        ->and($giveaway->items()->count())->toBe(1)
        ->and(GiveawayAnswerItem::where('giveaway_id', $giveaway->id)->value('answer'))->toBe('9')
        ->and(GiveawayPhoto::where('giveaway_id', $giveaway->id)->count())->toBe(0)
        ->and(GiveawaySignature::where('giveaway_id', $giveaway->id)->count())->toBe(1);
});

test('pushing a giveaway posts master item quantities and syncs proof after the parent', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $service = app(GiveawayService::class);
    $items = giveawayItemRows($service);
    $signature = giveawayStagedAttachment($user, 'signature.png');
    $photo = giveawayStagedAttachment($user, 'photo.png');

    $service->create($user, [
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
        'staged_photo_id' => $photo->id,
    ]);

    Http::fake([
        '*/api/forms/giveaway/push' => Http::response(['server_id' => 501], 200),
        '*/api/forms/giveaway/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 88], 200),
        '*/api/forms/giveaway/photo' => Http::response(['server_id' => 99, 'uploaded_file_id' => 99], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/forms/giveaway/push')
            && $request['items'][0]['giveaway_item_id'] === 1
            && $request['items'][0]['answer'] === '1';
    });

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/forms/giveaway/attach'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/forms/giveaway/photo'));

    expect(GiveawaySignature::query()->firstOrFail()->sync_status)->toBe('synced')
        ->and(GiveawayPhoto::query()->firstOrFail()->sync_status)->toBe('synced');
});

test('has pending changes includes giveaways and their proof', function () {
    Storage::fake('local');
    $user = giveawayWorkflowUser();
    $this->actingAs($user);
    giveawayFakePull();

    $service = app(GiveawayService::class);
    $items = giveawayItemRows($service);
    $signature = giveawayStagedAttachment($user, 'signature.png');

    $service->create($user, [
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
    ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

<?php

use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageAttachment;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\SignageService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function signageWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'signage-test-token']);
}

/**
 * @return array<int, array<string, mixed>>
 */
function signagePullRows(): array
{
    return [[
        'id' => 50,
        'product_id' => 301,
        'type_id' => 401,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'rsm_status' => RSMStatus::PENDING->value,
        'tdh_status' => TDHStatus::PENDING->value,
        'tds_status' => TDSStatus::PENDING->value,
        'salescall_id' => null,
        'attachment' => [],
    ]];
}

/**
 * @param  array<int, array<string, mixed>>  $signages
 */
function signageFakePortal(array $signages = []): void
{
    Http::fake([
        '*/api/sync/forms/crr-signage/products' => Http::response(['signage_products' => [['id' => 301, 'name' => 'Tarpaulin']]], 200),
        '*/api/sync/forms/crr-signage/types' => Http::response(['signage_types' => [['id' => 401, 'name' => 'Horizontal']]], 200),
        '*/api/sync/forms/crr-signage/pull' => Http::response(['signages' => $signages], 200),
    ]);
}

function signagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function signageStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, signagePngDataUri(), $name);
}

/**
 * @return array<string, mixed>
 */
function signageCreatePayload(int $productId, int $typeId, StagedAttachment $attachment, int $attachmentType = SignageAttachmentType::REQUEST->value): array
{
    return [
        'salescall_uuid' => null,
        'product_id' => $productId,
        'type_id' => $typeId,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'staged_attachments' => [
            ['type' => $attachmentType, 'staged_id' => $attachment->id],
        ],
    ];
}

test('signage master data is cached from the pull', function () {
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $products = $service->programs();

    expect($products)->toHaveCount(1)
        ->and($products->first()->name)->toBe('Tarpaulin')
        ->and($service->programReadFailed())->toBeFalse();

    expect(SignageProduct::query()->firstOrFail()->server_id)->toBe(301)
        ->and(SignageType::query()->firstOrFail()->server_id)->toBe(401)
        ->and(SignageType::query()->firstOrFail()->name)->toBe('Horizontal');
});

test('pulled signages are cached locally with their masters and statuses', function () {
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal(signagePullRows());

    $service = app(SignageService::class);
    $service->programs();

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Store Front Signage')
        ->and($rows->first()['product'])->toBe('Tarpaulin')
        ->and($rows->first()['type'])->toBe('Horizontal')
        ->and($rows->first()['is_editable'])->toBeTrue();

    $signage = Signage::query()->firstOrFail();

    expect($signage->server_id)->toBe(50)
        ->and($signage->sync_status)->toBe('synced')
        ->and($signage->submitted_by)->toBe($user->id)
        ->and($signage->product->name)->toBe('Tarpaulin')
        ->and($signage->type->name)->toBe('Horizontal')
        ->and($signage->rsm_status)->toBe(RSMStatus::PENDING);
});

test('creating a Signage requires a Request attachment', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $completed = signageStagedAttachment($user, 'completed.png');

    try {
        $service->create($user, signageCreatePayload($product->id, $type->id, $completed, SignageAttachmentType::COMPLETED->value));

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(Signage::query()->count())->toBe(0);
});

test('creating a Signage persists the header and its typed staged attachments', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user, 'request.png');
    $completed = signageStagedAttachment($user, 'completed.png');

    $signage = $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::REPLACEMENT->value,
        'name' => 'Store Front Signage',
        'staged_attachments' => [
            ['type' => SignageAttachmentType::REQUEST->value, 'staged_id' => $request->id],
            ['type' => SignageAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
        ],
    ]);

    expect($signage->fresh()->sync_status)->toBe('pending')
        ->and($signage->fresh()->category)->toBe(SignageCategory::REPLACEMENT)
        ->and($signage->fresh()->product_id)->toBe($product->id)
        ->and($signage->fresh()->type_id)->toBe($type->id)
        ->and($signage->attachments()->count())->toBe(2)
        ->and(SignageAttachment::query()->where('type', SignageAttachmentType::REQUEST->value)->exists())->toBeTrue()
        ->and(SignageAttachment::query()->where('type', SignageAttachmentType::COMPLETED->value)->exists())->toBeTrue()
        ->and(StagedAttachment::find($request->id))->toBeNull()
        ->and(StagedAttachment::find($completed->id))->toBeNull();

    foreach (SignageAttachment::query()->get() as $attachment) {
        expect(is_file($attachment->local_path))->toBeTrue();
    }
});

test('a locally created Signage appears in submissions with its attachments', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['attachments'][0]['type'])->toBe(SignageAttachmentType::REQUEST->value)
        ->and($rows->first()['attachments'][0]['type_label'])->toBe('Request')
        ->and($rows->first()['attachments'][0]['url'])->toBeString()->not->toBeEmpty();
});

test('updating a Signage cannot remove the last Request attachment', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user, 'request.png');
    $completed = signageStagedAttachment($user, 'completed.png');

    $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'staged_attachments' => [
            ['type' => SignageAttachmentType::REQUEST->value, 'staged_id' => $request->id],
            ['type' => SignageAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
        ],
    ]);

    $signage = Signage::query()->firstOrFail();
    $requestAttachment = SignageAttachment::query()->where('type', SignageAttachmentType::REQUEST->value)->firstOrFail();
    $completedAttachment = SignageAttachment::query()->where('type', SignageAttachmentType::COMPLETED->value)->firstOrFail();

    // Removing the Completed photo is allowed.
    $service->update($signage->fresh(), [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'remove_attachments' => [$completedAttachment->id],
    ]);

    expect(SignageAttachment::query()->count())->toBe(1)
        ->and(SignageAttachment::query()->firstOrFail()->type)->toBe(SignageAttachmentType::REQUEST);

    // Removing the last Request photo without a replacement is rejected.
    try {
        $service->update($signage->fresh(), [
            'salescall_uuid' => null,
            'product_id' => $product->id,
            'type_id' => $type->id,
            'category' => SignageCategory::NEW->value,
            'name' => 'Store Front Signage',
            'remove_attachments' => [$requestAttachment->id],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(SignageAttachment::query()->count())->toBe(1);

    // Replacing it with a new Request photo is allowed.
    $replacement = signageStagedAttachment($user, 'request-2.png');

    $service->update($signage->fresh(), [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'remove_attachments' => [$requestAttachment->id],
        'staged_attachments' => [
            ['type' => SignageAttachmentType::REQUEST->value, 'staged_id' => $replacement->id],
        ],
    ]);

    expect(SignageAttachment::query()->count())->toBe(1)
        ->and(SignageAttachment::query()->firstOrFail()->type)->toBe(SignageAttachmentType::REQUEST)
        ->and(SignageAttachment::query()->firstOrFail()->original_name)->toBe('request-2.png');
});

test('updating a Signage is blocked once an approver has acted', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    Signage::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    expect(fn () => $service->update(Signage::query()->firstOrFail(), [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
    ]))->toThrow(ValidationException::class);
});

test('pulled status changes are cached for synced local signages', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);

    $rows = signagePullRows();
    $rows[0]['rsm_status'] = RSMStatus::APPROVED->value;
    $rows[0]['tdh_status'] = TDHStatus::RETURNED->value;
    $rows[0]['tds_status'] = TDSStatus::PENDING->value;

    signageFakePortal($rows);

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    Signage::query()->update(['server_id' => 50, 'sync_status' => 'synced']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['rsm_status'])->toBe(RSMStatus::APPROVED->value)
        ->and($result->first()['tdh_status'])->toBe(TDHStatus::RETURNED->value)
        ->and($result->first()['is_editable'])->toBeFalse();
});

test('an unsynced local Signage is not overwritten by the pull', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);

    $rows = signagePullRows();
    $rows[0]['name'] = 'SERVER RENAMED';

    signageFakePortal($rows);

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    Signage::query()->update(['server_id' => 50, 'sync_status' => 'pending']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['name'])->toBe('Store Front Signage');
});

test('local signages deleted on the Portal are removed on pull', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $local = $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    $attachmentPath = $local->attachments()->firstOrFail()->local_path;
    $local->update(['server_id' => 99, 'sync_status' => 'synced']);

    signageFakePortal(signagePullRows());

    $service->submissions();

    expect(Signage::query()->find($local->id))->toBeNull()
        ->and(is_file($attachmentPath))->toBeFalse();
});

test('adopting a server row returns the cached local Signage', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $local = $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    $local->update(['server_id' => 50, 'sync_status' => 'synced']);

    expect($service->adopt($user, ['server_id' => 50])->is($local))->toBeTrue();

    expect(fn () => $service->adopt($user, ['server_id' => 404]))
        ->toThrow(ValidationException::class);
});

test('pushing a Signage posts the master server ids and stores the server id', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $signage = $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    Http::fake([
        '*/api/sync/forms/crr-signage/push' => Http::response(['server_id' => 901], 200),
        '*/api/sync/forms/crr-signage/attach' => Http::response(['server_id' => 950, 'uploaded_file_id' => 950], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/sync/forms/crr-signage/push')
            && $request['product_id'] === 301
            && $request['type_id'] === 401
            && $request['category'] === SignageCategory::NEW->value
            && $request['name'] === 'Store Front Signage';
    });

    expect($signage->fresh()->sync_status)->toBe('synced')
        ->and($signage->fresh()->server_id)->toBe(901);
});

test('Signage attachments push after the parent with their type', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    $attachment = SignageAttachment::query()->firstOrFail();

    Http::fake([
        '*/api/sync/forms/crr-signage/push' => Http::response(['server_id' => 901], 200),
        '*/api/sync/forms/crr-signage/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 77], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/sync/forms/crr-signage/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['server_id']['contents'] ?? null) == 901
            && ($parts['type']['contents'] ?? null) == SignageAttachmentType::REQUEST->value;
    });

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88)
        ->and(Signage::query()->firstOrFail()->server_id)->toBe(901);
});

test('has pending changes includes Signages', function () {
    Storage::fake('local');
    $user = signageWorkflowUser();
    $this->actingAs($user);
    signageFakePortal();

    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $request = signageStagedAttachment($user);

    $service->create($user, signageCreatePayload($product->id, $type->id, $request));

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

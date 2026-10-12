<?php

use App\Enums\Repair\RepairAttachmentType;
use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\Repair\Repair;
use App\Models\Forms\Repair\RepairAttachment;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\RepairService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function repairWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'repair-test-token']);
}

/**
 * @return array{repairs: array<int, array<string, mixed>>}
 */
function repairPullPayload(): array
{
    return [
        'repairs' => [[
            'id' => 40,
            'plate_number' => 'ABC 1234',
            'invoice_number' => 'INV-1001',
            'details' => 'Replace brake pads',
            'amount' => '500.00',
            'remarks' => null,
            'sap_doc_number' => null,
            'rsm_status' => RSMStatus::PENDING->value,
            'sps_status' => SPSStatus::PENDING->value,
            'spm_status' => SPMStatus::PENDING->value,
            'salescall_id' => null,
            'repairAttachments' => [],
        ]],
    ];
}

function repairFakePull(?array $payload = null): void
{
    Http::fake(['*/api/sync/forms/repair/pull' => Http::response($payload ?? repairPullPayload(), 200)]);
}

function repairPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function repairStagedAttachment(User $user, string $name = 'invoice.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, repairPngDataUri(), $name);
}

/**
 * @return array<string, mixed>
 */
function repairCreatePayload(StagedAttachment $attachment, int $type = 1): array
{
    return [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'details' => 'Replace brake pads',
        'amount' => '500.25',
        'remarks' => 'Urgent',
        'staged_attachments' => [
            ['type' => $type, 'staged_id' => $attachment->id],
        ],
    ];
}

test('pulled repairs are cached locally with their statuses', function () {
    $user = repairWorkflowUser();
    $this->actingAs($user);
    repairFakePull();

    $rows = app(RepairService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['plate_number'])->toBe('ABC 1234')
        ->and($rows->first()['amount'])->toBe('500.00')
        ->and($rows->first()['is_editable'])->toBeTrue();

    $repair = Repair::query()->firstOrFail();

    expect($repair->server_id)->toBe(40)
        ->and($repair->sync_status)->toBe('synced')
        ->and($repair->submitted_by)->toBe($user->id)
        ->and($repair->rsm_status)->toBe(RSMStatus::PENDING);
});

test('creating a Repair requires at least one attachment', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);

    try {
        $service->create($user, [
            'salescall_uuid' => null,
            'plate_number' => 'ABC 1234',
            'details' => 'Replace brake pads',
            'amount' => '500.25',
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(Repair::query()->count())->toBe(0);
});

test('creating a Repair persists the header and its typed staged attachments', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user, 'invoice.png');
    $request = repairStagedAttachment($user, 'request.png');

    $repair = $service->create($user, [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'details' => 'Replace brake pads',
        'amount' => '500.25',
        'remarks' => 'Urgent',
        'staged_attachments' => [
            ['type' => RepairAttachmentType::INVOICE->value, 'staged_id' => $invoice->id],
            ['type' => RepairAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ],
    ]);

    expect($repair->fresh()->sync_status)->toBe('pending')
        ->and($repair->fresh()->amount)->toBe('500.25')
        ->and($repair->attachments()->count())->toBe(2)
        ->and(RepairAttachment::query()->where('type', RepairAttachmentType::INVOICE->value)->exists())->toBeTrue()
        ->and(RepairAttachment::query()->where('type', RepairAttachmentType::REQUEST->value)->exists())->toBeTrue()
        ->and(RepairAttachment::query()->firstOrFail()->sync_status)->toBe('pending')
        ->and(StagedAttachment::find($invoice->id))->toBeNull()
        ->and(StagedAttachment::find($request->id))->toBeNull();

    foreach (RepairAttachment::query()->get() as $attachment) {
        expect(is_file($attachment->local_path))->toBeTrue();
    }
});

test('a locally created Repair appears in submissions with its attachments', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);
    repairFakePull(['repairs' => []]);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['plate_number'])->toBe('ABC 1234')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['attachments'][0]['type'])->toBe(RepairAttachmentType::INVOICE->value)
        ->and($rows->first()['attachments'][0]['type_label'])->toBe('Invoice')
        ->and($rows->first()['attachments'][0]['url'])->toBeString()->not->toBeEmpty();
});

test('updating a Repair can add and remove attachments', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    $repair = Repair::query()->firstOrFail();
    $attachment = $repair->attachments()->firstOrFail();
    $removedPath = $attachment->local_path;
    $newStaged = repairStagedAttachment($user, 'request.png');

    $service->update($repair->fresh(), [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'details' => 'Replace brake pads and rotors',
        'amount' => '750.00',
        'remove_attachments' => [$attachment->id],
        'staged_attachments' => [
            ['type' => RepairAttachmentType::REQUEST->value, 'staged_id' => $newStaged->id],
        ],
    ]);

    expect(RepairAttachment::query()->count())->toBe(1)
        ->and(RepairAttachment::query()->firstOrFail()->type)->toBe(RepairAttachmentType::REQUEST)
        ->and(is_file($removedPath))->toBeFalse()
        ->and($repair->fresh()->details)->toBe('Replace brake pads and rotors')
        ->and($repair->fresh()->sync_status)->toBe('pending');
});

test('updating a Repair cannot remove the last attachment', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    $repair = Repair::query()->firstOrFail();
    $attachment = $repair->attachments()->firstOrFail();

    try {
        $service->update($repair->fresh(), [
            'salescall_uuid' => null,
            'plate_number' => 'ABC 1234',
            'details' => 'Replace brake pads',
            'amount' => '500.25',
            'remove_attachments' => [$attachment->id],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(RepairAttachment::query()->count())->toBe(1);
});

test('updating a Repair is blocked once an approver has acted', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    Repair::query()->update(['rsm_status' => RSMStatus::APPROVED->value]);

    expect(fn () => $service->update(Repair::query()->firstOrFail(), [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'details' => 'Replace brake pads',
        'amount' => '500.25',
    ]))->toThrow(ValidationException::class);
});

test('pulled status changes are cached for synced local repairs', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    Repair::query()->update(['server_id' => 40, 'sync_status' => 'synced']);

    $payload = repairPullPayload();
    $payload['repairs'][0]['rsm_status'] = RSMStatus::APPROVED->value;
    $payload['repairs'][0]['sps_status'] = SPSStatus::ENDORSED->value;
    $payload['repairs'][0]['spm_status'] = SPMStatus::ENDORSED->value;

    Http::fake(['*/api/sync/forms/repair/pull' => Http::response($payload, 200)]);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['rsm_status'])->toBe(RSMStatus::APPROVED->value)
        ->and($rows->first()['sps_status'])->toBe(SPSStatus::ENDORSED->value)
        ->and($rows->first()['spm_status'])->toBe(SPMStatus::ENDORSED->value)
        ->and($rows->first()['is_editable'])->toBeFalse();
});

test('an unsynced local Repair is not overwritten by the pull', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    Repair::query()->update(['server_id' => 40, 'sync_status' => 'pending']);

    $payload = repairPullPayload();
    $payload['repairs'][0]['plate_number'] = 'SERVER 9999';

    Http::fake(['*/api/sync/forms/repair/pull' => Http::response($payload, 200)]);

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['plate_number'])->toBe('ABC 1234');
});

test('local repairs deleted on the Portal are removed on pull', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $local = $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    $attachmentPath = $local->attachments()->firstOrFail()->local_path;
    $local->update(['server_id' => 99, 'sync_status' => 'synced']);

    repairFakePull();

    $service->submissions();

    expect(Repair::query()->find($local->id))->toBeNull()
        ->and(is_file($attachmentPath))->toBeFalse();
});

test('adopting a server row returns the cached local Repair', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $local = $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    $local->update(['server_id' => 40, 'sync_status' => 'synced']);

    expect($service->adopt($user, ['server_id' => 40])->is($local))->toBeTrue();

    expect(fn () => $service->adopt($user, ['server_id' => 404]))
        ->toThrow(ValidationException::class);
});

test('pushing a Repair posts the header and stores the server id', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $repair = $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    Http::fake([
        '*/api/sync/forms/repair/push' => Http::response(['server_id' => 701], 200),
        '*/api/sync/forms/repair/attach' => Http::response(['server_id' => 800, 'uploaded_file_id' => 800], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/sync/forms/repair/push')
            && $request['plate_number'] === 'ABC 1234'
            && $request['invoice_number'] === 'INV-1001'
            && $request['details'] === 'Replace brake pads'
            && (float) $request['amount'] === 500.25
            && $request['remarks'] === 'Urgent';
    });

    expect($repair->fresh()->sync_status)->toBe('synced')
        ->and($repair->fresh()->server_id)->toBe(701);
});

test('Repair attachments push after the parent with their type', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $service = app(RepairService::class);
    $invoice = repairStagedAttachment($user);

    $service->create($user, repairCreatePayload($invoice, RepairAttachmentType::REQUEST->value));

    $attachment = RepairAttachment::query()->firstOrFail();

    Http::fake([
        '*/api/sync/forms/repair/push' => Http::response(['server_id' => 701], 200),
        '*/api/sync/forms/repair/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 77], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/sync/forms/repair/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['server_id']['contents'] ?? null) == 701
            && ($parts['type']['contents'] ?? null) == RepairAttachmentType::REQUEST->value;
    });

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88)
        ->and(Repair::query()->firstOrFail()->server_id)->toBe(701);
});

test('has pending changes includes Repairs', function () {
    Storage::fake('local');
    $user = repairWorkflowUser();
    $this->actingAs($user);

    $invoice = repairStagedAttachment($user);

    app(RepairService::class)->create($user, repairCreatePayload($invoice, RepairAttachmentType::INVOICE->value));

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

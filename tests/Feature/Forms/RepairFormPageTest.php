<?php

use App\Enums\Repair\RepairAttachmentType;
use App\Enums\SPSStatus;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\RepairFormPage;
use App\Models\Forms\Repair\Repair;
use App\Models\Forms\Repair\RepairAttachment;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\RepairService;
use App\Services\StagedAttachmentService;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function repairFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'repair-test-token']);
}

function repairFormPagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function repairFormPageStagedAttachment(User $user, string $name = 'invoice.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, repairFormPagePngDataUri(), $name);
}

function repairFormPageSubmission(User $user): Repair
{
    $staged = repairFormPageStagedAttachment($user);

    return app(RepairService::class)->create($user, [
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'details' => 'Replace brake pads',
        'amount' => '500.25',
        'remarks' => 'Urgent',
        'staged_attachments' => [
            ['type' => RepairAttachmentType::INVOICE->value, 'staged_id' => $staged->id],
        ],
    ]);
}

test('the Repair form opens with empty fields', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    Livewire::test(RepairFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSet('plate_number', '')
        ->assertSee('Add Repair')
        ->assertSee('Invoice');
});

test('saving without an attachment reports an error and creates nothing', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(RepairFormPage::class)
        ->assertOk()
        ->set('plate_number', 'ABC 1234')
        ->set('details', 'Replace brake pads')
        ->set('amount', '500.25')
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(Repair::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Repair validation messages use friendly field names', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(RepairFormPage::class)
        ->assertOk()
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['plate_number'][0])->toBe('The plate number field is required.')
        ->and($errors['details'][0])->toBe('The details field is required.')
        ->and($errors['amount'][0])->toBe('The amount field is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./');

    $component->assertNoRedirect();
});

test('saving with a staged attachment creates the Repair and redirects', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $staged = repairFormPageStagedAttachment($user);

    Livewire::test(RepairFormPage::class)
        ->assertOk()
        ->set('plate_number', 'ABC 1234')
        ->set('invoice_number', 'INV-1001')
        ->set('details', 'Replace brake pads')
        ->set('amount', '500.25')
        ->set('remarks', 'Urgent')
        ->set('attachments.'.RepairAttachmentType::INVOICE->value, [[
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'invoice.png',
            'mime_type' => 'image/png',
            'url' => null,
        ]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'repair']));

    expect(Repair::query()->count())->toBe(1)
        ->and(RepairAttachment::query()->count())->toBe(1)
        ->and(RepairAttachment::query()->firstOrFail()->type)->toBe(RepairAttachmentType::INVOICE)
        ->and(StagedAttachment::find($staged->id))->toBeNull();
});

test('staging an attachment through the Livewire action stores it for the chosen type', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(RepairFormPage::class)->assertOk();

    $component->call('stageTypedAttachment', RepairAttachmentType::INVOICE->value, [
        'data' => repairFormPagePngDataUri(),
        'original_name' => 'invoice.png',
    ]);

    $staged = $component->get('attachments')[RepairAttachmentType::INVOICE->value][0] ?? null;

    expect($staged)->not->toBeNull()
        ->and($staged['source'])->toBe('staged')
        ->and(StagedAttachment::find($staged['id']))->not->toBeNull();
});

test('editing a pending Repair loads its fields and attachments and saves an update', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $repair = repairFormPageSubmission($user);
    $attachment = RepairAttachment::query()->firstOrFail();

    Livewire::withQueryParams(['submission' => $repair->id])
        ->test(RepairFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('plate_number', 'ABC 1234')
        ->assertSet('amount', '500.25')
        ->assertSet('salescall_uuid', null)
        ->assertSet('attachments.'.RepairAttachmentType::INVOICE->value.'.0.source', 'existing')
        ->set('remarks', 'Updated remarks')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'repair']));

    expect(Repair::query()->firstOrFail()->remarks)->toBe('Updated remarks')
        ->and(Repair::query()->firstOrFail()->sync_status)->toBe('pending')
        ->and(RepairAttachment::query()->count())->toBe(1)
        ->and(RepairAttachment::query()->firstOrFail()->id)->toBe($attachment->id);
});

test('removing the only attachment blocks the save', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $repair = repairFormPageSubmission($user);

    $component = Livewire::withQueryParams(['submission' => $repair->id])
        ->test(RepairFormPage::class)
        ->assertOk()
        ->call('removeAttachment', RepairAttachmentType::INVOICE->value, 0)
        ->set('remarks', 'Updated')
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(RepairAttachment::query()->count())->toBe(1);

    $component->assertNoRedirect();
});

test('the edit route aborts 403 for a non-pending submission', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $repair = repairFormPageSubmission($user);

    Repair::query()->update(['sync_status' => 'synced']);

    $this->withoutMiddleware(Authenticate::class)
        ->get(RepairFormPage::getUrl(['submission' => $repair->id]))
        ->assertForbidden();
});

test('the edit route allows a synced Repair returned to DRM', function () {
    Storage::fake('local');
    $user = repairFormPageUser();
    $this->actingAs($user);

    $repair = repairFormPageSubmission($user);
    Repair::query()->update([
        'sps_status' => SPSStatus::RETURNED->value,
        'sync_status' => 'synced',
    ]);

    $this->withoutMiddleware(Authenticate::class)
        ->get(RepairFormPage::getUrl(['submission' => $repair->id]))
        ->assertOk();
});

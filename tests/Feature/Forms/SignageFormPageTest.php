<?php

use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\TDHStatus;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\SignageFormPage;
use App\Models\Customer;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageAttachment;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\SignageService;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        '*/api/forms/crr-signage/products' => Http::response(['signage_products' => [['id' => 301, 'name' => 'Tarpaulin']]], 200),
        '*/api/forms/crr-signage/types' => Http::response(['signage_types' => [['id' => 401, 'name' => 'Horizontal']]], 200),
    ]);
});

function signageFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'signage-test-token']);
}

function signageFormPagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function signageFormPageStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, signageFormPagePngDataUri(), $name);
}

function signageFormPageSubmission(User $user): Signage
{
    $service = app(SignageService::class);
    $service->programs();

    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();
    $staged = signageFormPageStagedAttachment($user);

    return $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'staged_attachments' => [
            ['type' => SignageAttachmentType::REQUEST->value, 'staged_id' => $staged->id],
        ],
    ]);
}

function signageFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Signage Salescall Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);
}

test('the Signage form opens with the cached masters and empty fields', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    Livewire::test(SignageFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSet('name', '')
        ->assertSet('category', SignageCategory::NEW->value)
        ->assertSee('Add Signage')
        ->assertSee('Tarpaulin')
        ->assertSee('Horizontal');
});

test('saving without a Request attachment reports an error and creates nothing', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(SignageFormPage::class)->assertOk();
    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();

    $component
        ->set('name', 'Store Front Signage')
        ->set('product_id', $product->id)
        ->set('type_id', $type->id)
        ->set('category', SignageCategory::NEW->value)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(Signage::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Signage validation messages use friendly field names', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(SignageFormPage::class)
        ->assertOk()
        ->set('name', '')
        ->set('product_id', null)
        ->set('type_id', null)
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['product_id'][0])->toBe('The product field is required.')
        ->and($errors['type_id'][0])->toBe('The type field is required.')
        ->and($errors['name'][0])->toBe('The name field is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./');

    $component->assertNoRedirect();
});

test('saving with a Request attachment creates the Signage and redirects', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $staged = signageFormPageStagedAttachment($user);

    $component = Livewire::test(SignageFormPage::class)->assertOk();
    $product = SignageProduct::query()->firstOrFail();
    $type = SignageType::query()->firstOrFail();

    $component
        ->set('name', 'Store Front Signage')
        ->set('product_id', $product->id)
        ->set('type_id', $type->id)
        ->set('category', SignageCategory::NEW->value)
        ->set('attachments.'.SignageAttachmentType::REQUEST->value, [[
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'request.png',
            'mime_type' => 'image/png',
            'url' => null,
        ]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr-signage']));

    expect(Signage::query()->count())->toBe(1)
        ->and(SignageAttachment::query()->count())->toBe(1)
        ->and(SignageAttachment::query()->firstOrFail()->type)->toBe(SignageAttachmentType::REQUEST)
        ->and(StagedAttachment::find($staged->id))->toBeNull();
});

test('staging through the Livewire action stores the attachment for the chosen type', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(SignageFormPage::class)->assertOk();

    $component->call('stageTypedAttachment', SignageAttachmentType::REQUEST->value, [
        'data' => signageFormPagePngDataUri(),
        'original_name' => 'request.png',
    ]);

    $staged = $component->get('attachments')[SignageAttachmentType::REQUEST->value][0] ?? null;

    expect($staged)->not->toBeNull()
        ->and($staged['source'])->toBe('staged')
        ->and(StagedAttachment::find($staged['id']))->not->toBeNull();
});

test('editing a pending Signage loads its fields and attachments and saves an update', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $signage = signageFormPageSubmission($user);
    $attachment = SignageAttachment::query()->firstOrFail();

    Livewire::withQueryParams(['submission' => $signage->id])
        ->test(SignageFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('name', 'Store Front Signage')
        ->assertSet('category', SignageCategory::NEW->value)
        ->assertSet('attachments.'.SignageAttachmentType::REQUEST->value.'.0.source', 'existing')
        ->set('name', 'Store Front Signage Updated')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'crr-signage']));

    expect(Signage::query()->firstOrFail()->name)->toBe('Store Front Signage Updated')
        ->and(Signage::query()->firstOrFail()->sync_status)->toBe('pending')
        ->and(SignageAttachment::query()->count())->toBe(1)
        ->and(SignageAttachment::query()->firstOrFail()->id)->toBe($attachment->id);
});

test('removing the only Request attachment blocks the save', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $signage = signageFormPageSubmission($user);

    $component = Livewire::withQueryParams(['submission' => $signage->id])
        ->test(SignageFormPage::class)
        ->assertOk()
        ->call('removeAttachment', SignageAttachmentType::REQUEST->value, 0)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(SignageAttachment::query()->count())->toBe(1);

    $component->assertNoRedirect();
});

test('the edit route aborts 403 for a non-pending submission', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $signage = signageFormPageSubmission($user);

    Signage::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    $this->get(SignageFormPage::getUrl(['submission' => $signage->id]))
        ->assertForbidden();
});

test('opening the Signage form from a sales call preselects and locks that sales call', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    $salescall = signageFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(SignageFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSee('Started from this Sales Call')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the Signage form with an unknown sales call uuid does not preselect or lock', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(SignageFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('a sales call owned by another rep is ignored when opening the Signage form', function () {
    Storage::fake('local');
    $user = signageFormPageUser();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $foreignSalescall = signageFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(SignageFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

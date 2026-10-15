<?php

use App\Enums\TDHStatus;
use App\Enums\TradeRequest\Size;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\TradeRequestFormPage;
use App\Models\Customer;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeRequest;
use App\Models\Forms\TradeRequest\TradeRequestAttachment;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\TradeRequestService;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        '*/api/sync/forms/trade-request/products' => Http::response(['trade_products' => [['id' => 501, 'name' => 'Jacket']]], 200),
        '*/api/sync/forms/trade-request/types' => Http::response(['trade_types' => [['id' => 601, 'name' => 'Apparel']]], 200),
    ]);
});

function tradeRequestFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'trade-request-test-token']);
}

function tradeRequestFormPagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function tradeRequestFormPageStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, tradeRequestFormPagePngDataUri(), $name);
}

function tradeRequestFormPageSubmission(User $user): TradeRequest
{
    $service = app(TradeRequestService::class);
    $service->programs();

    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();
    $staged = tradeRequestFormPageStagedAttachment($user);

    return $service->create($user, [
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'items' => [
            ['trade_type_id' => $type->id, 'quantity' => 3, 'size' => Size::LARGE->value, 'description' => 'Blue'],
        ],
        'staged_attachments' => [
            ['type' => TradeRequestAttachmentType::REQUEST->value, 'staged_id' => $staged->id],
        ],
    ]);
}

test('the Trade Request form opens with the cached masters and one empty item row', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    Livewire::test(TradeRequestFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSet('product_id', null)
        ->assertCount('items', 1)
        ->assertSee('Add Trade Request')
        ->assertSee('Jacket')
        ->assertSee('Apparel');
});

test('saving without items reports an error and creates nothing', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(TradeRequestFormPage::class)->assertOk();
    $product = TradeProduct::query()->firstOrFail();

    $component
        ->set('product_id', $product->id)
        ->set('items', [])
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('items')
        ->and(TradeRequest::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('saving with an item and a Request attachment creates the Trade Request and redirects', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $staged = tradeRequestFormPageStagedAttachment($user);

    $component = Livewire::test(TradeRequestFormPage::class)->assertOk();
    $product = TradeProduct::query()->firstOrFail();
    $type = TradeType::query()->firstOrFail();

    $component
        ->set('product_id', $product->id)
        ->set('items.0.trade_type_id', $type->id)
        ->set('items.0.quantity', '3')
        ->set('items.0.size', Size::LARGE->value)
        ->set('items.0.description', 'Blue')
        ->set('attachments.'.TradeRequestAttachmentType::REQUEST->value, [[
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'request.png',
            'mime_type' => 'image/png',
            'url' => null,
        ]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'trade-request']));

    $tradeRequest = TradeRequest::query()->firstOrFail();
    $item = $tradeRequest->items()->firstOrFail();

    expect($tradeRequest->sync_status)->toBe('pending')
        ->and($tradeRequest->product_id)->toBe($product->id)
        ->and($item->trade_type_id)->toBe($type->id)
        ->and($item->quantity)->toBe(3)
        ->and($item->size)->toBe(Size::LARGE)
        ->and($item->description)->toBe('Blue')
        ->and(TradeRequestAttachment::query()->count())->toBe(1)
        ->and(TradeRequestAttachment::query()->firstOrFail()->type)->toBe(TradeRequestAttachmentType::REQUEST)
        ->and(StagedAttachment::find($staged->id))->toBeNull();
});

test('staging through the Livewire action stores the attachment for the chosen type', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(TradeRequestFormPage::class)->assertOk();

    $component->call('stageTypedAttachment', TradeRequestAttachmentType::REQUEST->value, [
        'data' => tradeRequestFormPagePngDataUri(),
        'original_name' => 'request.png',
    ]);

    $staged = $component->get('attachments')[TradeRequestAttachmentType::REQUEST->value][0] ?? null;

    expect($staged)->not->toBeNull()
        ->and($staged['source'])->toBe('staged')
        ->and(StagedAttachment::find($staged['id']))->not->toBeNull();
});

test('editing a pending Trade Request loads its items and attachments and saves an update', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $tradeRequest = tradeRequestFormPageSubmission($user);
    $attachment = TradeRequestAttachment::query()->firstOrFail();

    Livewire::withQueryParams(['submission' => $tradeRequest->id])
        ->test(TradeRequestFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('product_id', TradeProduct::query()->firstOrFail()->id)
        ->assertSet('items.0.trade_type_id', TradeType::query()->firstOrFail()->id)
        ->assertSet('items.0.quantity', '3')
        ->assertSet('items.0.size', Size::LARGE->value)
        ->assertSet('attachments.'.TradeRequestAttachmentType::REQUEST->value.'.0.source', 'existing')
        ->set('items.0.quantity', '5')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'trade-request']));

    expect(TradeRequest::query()->firstOrFail()->items()->firstOrFail()->quantity)->toBe(5)
        ->and(TradeRequest::query()->firstOrFail()->sync_status)->toBe('pending')
        ->and(TradeRequestAttachment::query()->count())->toBe(1)
        ->and(TradeRequestAttachment::query()->firstOrFail()->id)->toBe($attachment->id);
});

test('removing the only Request attachment blocks the save', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $tradeRequest = tradeRequestFormPageSubmission($user);

    $component = Livewire::withQueryParams(['submission' => $tradeRequest->id])
        ->test(TradeRequestFormPage::class)
        ->assertOk()
        ->call('removeAttachment', TradeRequestAttachmentType::REQUEST->value, 0)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(TradeRequestAttachment::query()->count())->toBe(1);

    $component->assertNoRedirect();
});

test('the edit route aborts 403 for a non-pending submission', function () {
    Storage::fake('local');
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $tradeRequest = tradeRequestFormPageSubmission($user);

    TradeRequest::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    $this->get(TradeRequestFormPage::getUrl(['submission' => $tradeRequest->id]))
        ->assertForbidden();
});

function tradeRequestFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Trade Request Salescall Customer', 'is_active' => true]);
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

test('opening the Trade Request form from a sales call preselects and locks that sales call', function () {
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    $salescall = tradeRequestFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(TradeRequestFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSee('Started from this Sales Call')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the Trade Request form with an unknown sales call uuid does not preselect or lock', function () {
    $user = tradeRequestFormPageUser();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(TradeRequestFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('a sales call owned by another rep is ignored when opening the Trade Request form', function () {
    $user = tradeRequestFormPageUser();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $foreignSalescall = tradeRequestFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(TradeRequestFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

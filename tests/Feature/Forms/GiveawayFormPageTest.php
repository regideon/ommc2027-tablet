<?php

use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\RSMStatus;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\GiveawayFormPage;
use App\Models\Customer;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayAnswer;
use App\Models\Forms\Giveaway\GiveawayAnswerItem;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\GiveawayService;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function giveawayFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'giveaway-test-token']);
}

function giveawayFormPagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function giveawayFormPageStagedSignature(User $user): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, giveawayFormPagePngDataUri(), 'signature.png');
}

function giveawayFormPageSubmission(User $user): Giveaway
{
    $staged = giveawayFormPageStagedSignature($user);

    return app(GiveawayService::class)->create($user, [
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
}

test('the Giveaway form opens with one empty item row', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSet('name', '')
        ->assertCount('items', 1)
        ->assertSee('Add Giveaway');
});

test('saving with a staged signature creates the Giveaway and redirects', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $staged = giveawayFormPageStagedSignature($user);

    Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->set('name', 'Store Giveaway')
        ->set('items.0.category', GiveawayCategory::GC->value)
        ->set('items.0.name', 'Gift Check')
        ->set('items.0.answer', '2')
        ->set('items.0.signature', [
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'signature.png',
            'mime_type' => 'image/png',
            'url' => null,
        ])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'giveaway']));

    expect(Giveaway::query()->count())->toBe(1)
        ->and(GiveawayItem::query()->count())->toBe(1)
        ->and(GiveawaySignature::query()->count())->toBe(1)
        ->and(StagedAttachment::find($staged->id))->toBeNull();
});

test('saving without a signature reports a field error and creates nothing', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->set('name', 'Store Giveaway')
        ->set('items.0.category', GiveawayCategory::GC->value)
        ->set('items.0.name', 'Gift Check')
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('items.0.signature_id')
        ->and(Giveaway::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('Giveaway validation messages use friendly field names instead of row indexes', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->set('name', 'Store Giveaway')
        ->set('items.0.category', null)
        ->set('items.0.name', '')
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors['items.0.category'][0])->toBe('The category field is required.')
        ->and($errors['items.0.name'][0])->toBe('The item name field is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./');

    expect(Giveaway::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('the Giveaway form renders a signature pad per item row', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSeeHtml('x-ref="signatureCanvas"')
        ->assertSee('Clear')
        ->assertSee('have the recipient sign');

    // The pad factory lives in a global script, so it must reach Livewire
    // through this.$wire; bare $wire is only in scope inside inline x-data.
    $html = $component->html();

    expect(str_contains($html, 'this.$wire.stageSignature('))->toBeTrue()
        ->and(str_contains($html, 'this.$wire.removeSignature('))->toBeTrue();
});

test('staging a signature replaces the previous staged signature', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(GiveawayFormPage::class)->assertOk();
    $key = $component->get('items')[0]['key'];

    $component->call('stageSignature', $key, [
        'data' => giveawayFormPagePngDataUri(),
        'original_name' => 'first.png',
    ]);

    $firstStagedId = $component->get('items')[0]['signature']['id'];

    $component->call('stageSignature', $key, [
        'data' => giveawayFormPagePngDataUri(),
        'original_name' => 'second.png',
    ]);

    $secondStagedId = $component->get('items')[0]['signature']['id'];

    expect($firstStagedId)->not->toBe($secondStagedId)
        ->and(StagedAttachment::find($firstStagedId))->toBeNull()
        ->and(StagedAttachment::find($secondStagedId))->not->toBeNull();
});

test('clearing a drawn signature reverts to the saved signature', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $giveaway = giveawayFormPageSubmission($user);
    $signature = GiveawaySignature::query()->firstOrFail();

    $component = Livewire::withQueryParams(['submission' => $giveaway->id])
        ->test(GiveawayFormPage::class)
        ->assertOk();

    $key = $component->get('items')[0]['key'];

    $component->call('stageSignature', $key, [
        'data' => giveawayFormPagePngDataUri(),
        'original_name' => 'redraw.png',
    ])
        ->assertSet('items.0.signature.source', 'staged');

    $stagedId = $component->get('items')[0]['signature']['id'];

    $component->call('removeSignature', $key)
        ->assertSet('items.0.signature.source', 'existing')
        ->assertSet('items.0.signature.id', $signature->id);

    expect(StagedAttachment::find($stagedId))->toBeNull()
        ->and(GiveawaySignature::query()->whereKey($signature->id)->exists())->toBeTrue();

    $component->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'giveaway']));

    expect(GiveawaySignature::query()->count())->toBe(1);
});

test('removing an item row discards its staged signature', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $staged = giveawayFormPageStagedSignature($user);

    $component = Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->call('addItemRow')
        ->assertCount('items', 2);

    $secondKey = $component->get('items')[1]['key'];

    $component->set('items.1.signature', [
        'source' => 'staged',
        'id' => $staged->id,
        'original_name' => 'signature.png',
        'mime_type' => 'image/png',
        'url' => null,
    ])
        ->call('removeItemRow', $secondKey)
        ->assertCount('items', 1);

    expect(StagedAttachment::find($staged->id))->toBeNull();
});

test('editing a pending Giveaway loads its items and saves an update', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $giveaway = giveawayFormPageSubmission($user);
    $item = $giveaway->items()->firstOrFail();
    $signature = GiveawaySignature::query()->firstOrFail();

    Livewire::withQueryParams(['submission' => $giveaway->id])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('name', 'Store Giveaway')
        ->assertSet('items.0.name', 'Gift Check')
        ->assertSet('items.0.answer', '2')
        ->assertSet('items.0.signature.source', 'existing')
        ->assertSet('items.0.signature.id', $signature->id)
        ->assertSet('items.0.existing_signature.id', $signature->id)
        ->set('items.0.answer', '9')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'giveaway']));

    expect(GiveawayAnswerItem::query()->where('item_id', $item->id)->value('answer'))->toBe('9')
        ->and(GiveawayItem::query()->count())->toBe(1)
        ->and(Giveaway::query()->firstOrFail()->sync_status)->toBe('pending');
});

test('the edit route aborts 403 for a non-pending submission', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $giveaway = giveawayFormPageSubmission($user);

    GiveawayAnswer::query()->update(['rsm_status' => RSMStatus::APPROVED->value]);

    $this->get(GiveawayFormPage::getUrl(['submission' => $giveaway->id]))
        ->assertForbidden();
});

function giveawayFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Giveaway Salescall Customer', 'is_active' => true]);
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

test('opening the Giveaway form from a sales call preselects and locks that sales call', function () {
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $salescall = giveawayFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSee('Started from this Sales Call')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the Giveaway form with an unknown sales call uuid does not preselect or lock', function () {
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('a sales call owned by another rep is ignored when opening the Giveaway form', function () {
    $user = giveawayFormPageUser();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $foreignSalescall = giveawayFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

<?php

use App\Enums\Giveaway\GiveawayCategory;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\GiveawayFormPage;
use App\Models\Customer;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawayPhoto;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\GiveawayService;
use App\Services\StagedAttachmentService;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        '*/api/forms/giveaway/master-data' => Http::response([
            'items' => [
                ['id' => 1, 'category' => GiveawayCategory::GC->value, 'category_label' => 'Gift Certificates', 'name' => 'KFC GC', 'order' => 1],
                ['id' => 2, 'category' => GiveawayCategory::GC->value, 'category_label' => 'Gift Certificates', 'name' => 'SM GC', 'order' => 2],
                ['id' => 3, 'category' => GiveawayCategory::SHIRT->value, 'category_label' => 'Shirts', 'name' => 'Motolite Shirt', 'order' => 1],
            ],
        ], 200),
        '*/api/forms/giveaway/pull' => Http::response(['giveaways' => []], 200),
    ]);
});

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

function giveawayFormPageStaged(User $user, string $name): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, giveawayFormPagePngDataUri(), $name);
}

function giveawayFormPageSubmission(User $user): Giveaway
{
    $service = app(GiveawayService::class);
    $items = $service->programs()
        ->take(2)
        ->map(fn (GiveawayItem $item): array => [
            'giveaway_item_id' => $item->id,
            'answer' => '2',
        ])
        ->all();

    $signature = giveawayFormPageStaged($user, 'signature.png');
    $photo = giveawayFormPageStaged($user, 'photo.png');

    return $service->create($user, [
        'salescall_uuid' => null,
        'name' => 'Store Giveaway',
        'items' => $items,
        'staged_signature_id' => $signature->id,
        'staged_photo_id' => $photo->id,
    ]);
}

test('the Giveaway form renders master items grouped by category with number inputs', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertCount('items', 3)
        ->assertSee('Gift Certificates')
        ->assertSee('KFC GC')
        ->assertSee('SM GC')
        ->assertSee('Shirts')
        ->assertSee('Motolite Shirt')
        ->assertSeeHtml('type="number"')
        ->assertDontSee('Add Item');
});

test('saving with a signature and photo creates the Giveaway and redirects', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $signature = giveawayFormPageStaged($user, 'signature.png');
    $photo = giveawayFormPageStaged($user, 'photo.png');

    Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->set('name', 'Store Giveaway')
        ->set('items.0.answer', '2')
        ->set('signature', [
            'source' => 'staged',
            'id' => $signature->id,
            'original_name' => 'signature.png',
            'mime_type' => 'image/png',
            'url' => null,
        ])
        ->set('photo', [
            'source' => 'staged',
            'id' => $photo->id,
            'original_name' => 'photo.png',
            'mime_type' => 'image/png',
            'url' => null,
        ])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'giveaway']));

    expect(Giveaway::query()->count())->toBe(1)
        ->and(GiveawayItem::query()->count())->toBe(3)
        ->and(GiveawaySignature::query()->count())->toBe(1)
        ->and(GiveawayPhoto::query()->count())->toBe(1)
        ->and(StagedAttachment::find($signature->id))->toBeNull()
        ->and(StagedAttachment::find($photo->id))->toBeNull();
});

test('saving without a signature reports a field error and creates nothing', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(GiveawayFormPage::class)
        ->assertOk()
        ->set('name', 'Store Giveaway')
        ->set('items.0.answer', '2')
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('signature_id')
        ->and(Giveaway::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('editing loads the existing signature and photo and can remove the photo', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $giveaway = giveawayFormPageSubmission($user);

    $component = Livewire::withQueryParams(['submission' => $giveaway->id])
        ->test(GiveawayFormPage::class)
        ->assertSet('isEdit', true)
        ->assertSet('signature.source', 'existing')
        ->assertSet('photo.source', 'existing')
        ->call('removePhoto')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'giveaway']));

    expect(GiveawayPhoto::query()->count())->toBe(0)
        ->and(GiveawaySignature::query()->count())->toBe(1);
});

test('the edit route blocks a synced Giveaway', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $giveaway = giveawayFormPageSubmission($user);
    Giveaway::query()->update(['sync_status' => 'synced']);

    $this->withoutMiddleware(Authenticate::class)
        ->get(GiveawayFormPage::getUrl(['submission' => $giveaway->id]))
        ->assertForbidden();
});

test('the form renders exactly one recipient signature pad', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $html = Livewire::test(GiveawayFormPage::class)->html();

    expect(substr_count($html, 'x-ref="signatureCanvas"'))->toBe(1)
        ->and(substr_count($html, 'Signature of recipient'))->toBe(1);
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
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    $salescall = giveawayFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSee('Started from this Sales Call');
});

test('opening the Giveaway form with an unknown sales call uuid does not preselect or lock', function () {
    Storage::fake('local');
    $user = giveawayFormPageUser();
    $this->actingAs($user);

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(GiveawayFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

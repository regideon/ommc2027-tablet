<?php

use App\Filament\Pages\FormsPage;
use App\Filament\Pages\TradeCheckFormPage;
use App\Models\Customer;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAnswer;
use App\Models\Forms\TradeCheck\TradeCheckAnswerItems;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use App\Services\Forms\TradeCheckService;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/forms/trade-check/pull' => Http::response(tradeCheckFormPagePullPayload(), 200)]);
});

function tradeCheckFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'trade-check-test-token']);
}

/**
 * @return array{trade_checks: array<int, array<string, mixed>>}
 */
function tradeCheckFormPagePullPayload(): array
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
                'items' => [
                    [
                        'id' => 101,
                        'name' => 'DF Signage - Available',
                        'description' => null,
                        'type' => 1,
                        'is_required' => true,
                        'is_image_required' => true,
                        'options' => null,
                        'order' => 1,
                        'answer' => null,
                        'attachments' => [],
                    ],
                    [
                        'id' => 102,
                        'name' => 'DF Signage - Condition',
                        'description' => null,
                        'type' => 4,
                        'is_required' => false,
                        'is_image_required' => false,
                        'options' => ['Good', 'Bad'],
                        'order' => 2,
                        'answer' => null,
                        'attachments' => [],
                    ],
                ],
            ]],
            'answers' => [],
        ]],
    ];
}

function tradeCheckFormPageTemplate(): TradeCheck
{
    app(TradeCheckService::class)->programs();

    return TradeCheck::query()->firstOrFail();
}

test('the Trade Check form defaults to the first template and renders its fields', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();

    Livewire::test(TradeCheckFormPage::class)
        ->assertOk()
        ->assertSet('trade_check_id', $template->id)
        ->assertSee('DF Signage - Available')
        ->assertSee('DF Signage - Condition');
});

test('opening the form with a template renders its items', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    Livewire::withQueryParams(['template' => $template->id])
        ->test(TradeCheckFormPage::class)
        ->assertOk()
        ->assertSet('trade_check_id', $template->id)
        ->assertSet('salescall_uuid', null)
        ->assertSee('DF Signage - Available')
        ->assertSee('DF Signage - Condition')
        ->assertSee('External')
        ->assertSee('Good')
        ->assertCount('groups', 1)
        ->assertSet('answers.'.$items[0]->id, '')
        ->assertSet('answers.'.$items[1]->id, '');
});

test('saving required answers creates the submission and redirects', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    Livewire::withQueryParams(['template' => $template->id])
        ->test(TradeCheckFormPage::class)
        ->set('answers.'.$items[0]->id, 'Yes')
        ->set('answers.'.$items[1]->id, 'Good')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'trade-check']));

    expect($template->fresh()->sync_status)->toBe('pending')
        ->and(TradeCheckAnswer::query()->count())->toBe(2)
        ->and(TradeCheckAnswerItems::query()->where('item_id', $items[0]->id)->value('answer'))->toBe('Yes')
        ->and(TradeCheckAnswerItems::query()->where('item_id', $items[1]->id)->value('answer'))->toBe('Good');
});

test('a missing required answer reports a field error and creates nothing', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    $component = Livewire::withQueryParams(['template' => $template->id])
        ->test(TradeCheckFormPage::class)
        ->set('answers.'.$items[1]->id, 'Good')
        ->call('save');

    $errors = $component->get('formErrors');

    expect($errors)
        ->toHaveKey('answers.'.$items[0]->id)
        ->and($errors['answers.'.$items[0]->id][0])->toBe('This Trade Check item is required.')
        ->and(collect($errors)->flatten()->implode(' '))->not->toMatch('/\.\d+\./')
        ->and(TradeCheckAnswer::query()->count())->toBe(0)
        ->and(TradeCheckAnswerItems::query()->count())->toBe(0)
        ->and($template->fresh()->sync_status)->toBe('synced');

    $component->assertNoRedirect();
});

test('editing a pending submission loads its answers and saves an update', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    app(TradeCheckService::class)->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    Livewire::withQueryParams(['submission' => $template->id])
        ->test(TradeCheckFormPage::class)
        ->assertSet('isEdit', true)
        ->assertSet('trade_check_id', $template->id)
        ->assertSet('answers.'.$items[0]->id, 'Yes')
        ->assertSet('answers.'.$items[1]->id, 'Good')
        ->set('answers.'.$items[1]->id, 'Bad')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'trade-check']));

    expect(TradeCheckAnswerItems::query()->where('item_id', $items[1]->id)->value('answer'))->toBe('Bad')
        ->and(TradeCheckAnswerItems::query()->count())->toBe(2)
        ->and($template->fresh()->sync_status)->toBe('pending');
});

test('editing shows existing attachments and removes them on save', function () {
    Storage::fake('local');
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $staged = app(StagedAttachmentService::class)
        ->stageFromDataUri($user, 'data:image/png;base64,'.base64_encode($png), 'photo.png');

    app(TradeCheckService::class)->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
        'attachments' => [$items[0]->id => [$staged->id]],
    ]);

    $attachment = TradeCheckAttachment::query()->first();

    $component = Livewire::withQueryParams(['submission' => $template->id])
        ->test(TradeCheckFormPage::class)
        ->assertSet('attachments.'.$items[0]->id.'.0.source', 'existing')
        ->assertSet('attachments.'.$items[0]->id.'.0.original_name', 'photo.png');

    $component->call('removeItemAttachment', $items[0]->id, 0)
        ->set('answers.'.$items[0]->id, 'Yes')
        ->set('answers.'.$items[1]->id, 'Good')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'trade-check']));

    expect(TradeCheckAttachment::find($attachment->id))->toBeNull();
});

test('the edit route aborts 403 for a non-pending submission', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    $template = tradeCheckFormPageTemplate();
    $items = $template->group->flatMap->items;

    app(TradeCheckService::class)->create($user, [
        'trade_check_id' => $template->id,
        'answers' => [$items[0]->id => 'Yes', $items[1]->id => 'Good'],
    ]);

    TradeCheckAnswer::query()->update(['rsm_status' => 2]);

    $this->get(TradeCheckFormPage::getUrl(['submission' => $template->id]))
        ->assertForbidden();
});

function tradeCheckFormPageSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Trade Check Salescall Customer', 'is_active' => true]);
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

test('opening the Trade Check form from a sales call preselects and locks that sales call', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    tradeCheckFormPageTemplate();
    $salescall = tradeCheckFormPageSalescall($user);

    Livewire::withQueryParams(['salescall' => $salescall->local_uuid])
        ->test(TradeCheckFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', $salescall->local_uuid, true)
        ->assertSet('salescallLocked', true, true)
        ->assertSee('Started from this Sales Call')
        ->assertSeeHtml('wire:model="salescall_uuid" class="field-input bg-white" disabled');
});

test('opening the Trade Check form with an unknown sales call uuid does not preselect or lock', function () {
    $user = tradeCheckFormPageUser();
    $this->actingAs($user);

    tradeCheckFormPageTemplate();

    Livewire::withQueryParams(['salescall' => (string) Str::uuid()])
        ->test(TradeCheckFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

test('a sales call owned by another rep is ignored when opening the Trade Check form', function () {
    $user = tradeCheckFormPageUser();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    tradeCheckFormPageTemplate();
    $foreignSalescall = tradeCheckFormPageSalescall($otherUser);

    Livewire::withQueryParams(['salescall' => $foreignSalescall->local_uuid])
        ->test(TradeCheckFormPage::class)
        ->assertOk()
        ->assertSet('salescall_uuid', null, true)
        ->assertSet('salescallLocked', false, true);
});

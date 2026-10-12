<?php

use App\Enums\TDHStatus;
use App\Enums\Training\TrainingAttachmentType;
use App\Filament\Pages\FormsPage;
use App\Filament\Pages\TrainingFormPage;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingAttachment;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\TrainingService;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        '*/api/sync/forms/training/topics' => Http::response(['training_topics' => [['id' => 801, 'name' => 'Product Knowledge']]], 200),
        '*/api/sync/forms/training/positions' => Http::response(['training_positions' => [['id' => 901, 'name' => 'Driver']]], 200),
    ]);
});

function trainingFormPageUser(): User
{
    return User::factory()->create(['api_token' => 'training-test-token']);
}

function trainingFormPagePngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function trainingFormPageStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)
        ->stageFromDataUri($user, trainingFormPagePngDataUri(), $name);
}

function trainingFormPageSubmission(User $user): Training
{
    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $staged = trainingFormPageStagedAttachment($user);

    return $service->create($user, [
        'salescall_uuid' => null,
        'topic_id' => $topic->id,
        'other_topic' => null,
        'preferred_at' => '2026-11-01 09:00:00',
        'omcc_volume' => 100,
        'competitor_volume' => 50,
        'participants' => [
            ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ],
        'staged_attachments' => [
            ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $staged->id],
        ],
    ]);
}

test('the Training form opens with the cached masters and one empty participant row', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    Livewire::test(TrainingFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', false)
        ->assertSet('topic_choice', '')
        ->assertCount('participants', 1)
        ->assertSee('Add Training')
        ->assertSee('Product Knowledge')
        ->assertSee('Driver');
});

test('saving without a topic reports an error and creates nothing', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(TrainingFormPage::class)->assertOk();
    $position = TrainingPosition::query()->firstOrFail();

    $component
        ->set('preferred_at', '2026-11-01T09:00')
        ->set('participants.0.name', 'Juan Dela Cruz')
        ->set('participants.0.position_id', $position->id)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('topic_id')
        ->and(Training::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('saving without participants reports an error and creates nothing', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(TrainingFormPage::class)->assertOk();
    $topic = TrainingTopic::query()->firstOrFail();

    $component
        ->set('topic_choice', (string) $topic->id)
        ->set('preferred_at', '2026-11-01T09:00')
        ->set('participants', [])
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('participants')
        ->and(Training::query()->count())->toBe(0);

    $component->assertNoRedirect();
});

test('saving with a topic, a participant, and a Request attachment creates the Training', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $staged = trainingFormPageStagedAttachment($user);

    $component = Livewire::test(TrainingFormPage::class)->assertOk();
    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();

    $component
        ->set('topic_choice', (string) $topic->id)
        ->set('preferred_at', '2026-11-01T09:00')
        ->set('omcc_volume', '100')
        ->set('competitor_volume', '50')
        ->set('participants.0.name', 'Juan Dela Cruz')
        ->set('participants.0.position_id', $position->id)
        ->set('attachments.'.TrainingAttachmentType::REQUEST->value, [[
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'request.png',
            'mime_type' => 'image/png',
            'url' => null,
        ]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'training']));

    $training = Training::query()->firstOrFail();

    expect($training->sync_status)->toBe('pending')
        ->and($training->topic_id)->toBe($topic->id)
        ->and($training->preferred_at?->format('Y-m-d H:i'))->toBe('2026-11-01 09:00')
        ->and($training->omcc_volume)->toBe(100)
        ->and($training->participants()->count())->toBe(1)
        ->and($training->participants()->firstOrFail()->name)->toBe('Juan Dela Cruz')
        ->and(TrainingAttachment::query()->count())->toBe(1)
        ->and(StagedAttachment::find($staged->id))->toBeNull();
});

test('saving with an other topic instead of a topic creates the Training', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $staged = trainingFormPageStagedAttachment($user);

    $component = Livewire::test(TrainingFormPage::class)->assertOk();
    $position = TrainingPosition::query()->firstOrFail();

    $component
        ->set('topic_choice', 'other')
        ->set('other_topic', 'Forklift Safety')
        ->set('preferred_at', '2026-11-01T09:00')
        ->set('participants.0.name', 'Juan Dela Cruz')
        ->set('participants.0.position_id', $position->id)
        ->set('attachments.'.TrainingAttachmentType::REQUEST->value, [[
            'source' => 'staged',
            'id' => $staged->id,
            'original_name' => 'request.png',
            'mime_type' => 'image/png',
            'url' => null,
        ]])
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'training']));

    $training = Training::query()->firstOrFail();

    expect($training->topic_id)->toBeNull()
        ->and($training->other_topic)->toBe('Forklift Safety');
});

test('staging through the Livewire action stores the attachment for the chosen type', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $component = Livewire::test(TrainingFormPage::class)->assertOk();

    $component->call('stageTypedAttachment', TrainingAttachmentType::REQUEST->value, [
        'data' => trainingFormPagePngDataUri(),
        'original_name' => 'request.png',
    ]);

    $staged = $component->get('attachments')[TrainingAttachmentType::REQUEST->value][0] ?? null;

    expect($staged)->not->toBeNull()
        ->and($staged['source'])->toBe('staged')
        ->and(StagedAttachment::find($staged['id']))->not->toBeNull();
});

test('editing a pending Training loads its fields, participants, and attachments and saves an update', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $training = trainingFormPageSubmission($user);
    $attachment = TrainingAttachment::query()->firstOrFail();

    Livewire::withQueryParams(['submission' => $training->id])
        ->test(TrainingFormPage::class)
        ->assertOk()
        ->assertSet('isEdit', true)
        ->assertSet('topic_choice', (string) TrainingTopic::query()->firstOrFail()->id)
        ->assertSet('preferred_at', '2026-11-01T09:00')
        ->assertSet('omcc_volume', '100')
        ->assertSet('participants.0.name', 'Juan Dela Cruz')
        ->assertSet('attachments.'.TrainingAttachmentType::REQUEST->value.'.0.source', 'existing')
        ->set('omcc_volume', '250')
        ->call('save')
        ->assertRedirect(FormsPage::getUrl(['form' => 'training']));

    expect(Training::query()->firstOrFail()->omcc_volume)->toBe(250)
        ->and(Training::query()->firstOrFail()->sync_status)->toBe('pending')
        ->and(TrainingAttachment::query()->count())->toBe(1)
        ->and(TrainingAttachment::query()->firstOrFail()->id)->toBe($attachment->id);
});

test('removing the only Request attachment blocks the save', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $training = trainingFormPageSubmission($user);

    $component = Livewire::withQueryParams(['submission' => $training->id])
        ->test(TrainingFormPage::class)
        ->assertOk()
        ->call('removeAttachment', TrainingAttachmentType::REQUEST->value, 0)
        ->call('save');

    expect($component->get('formErrors'))
        ->toHaveKey('attachments')
        ->and(TrainingAttachment::query()->count())->toBe(1);

    $component->assertNoRedirect();
});

test('the edit route aborts 403 for a non-pending submission', function () {
    Storage::fake('local');
    $user = trainingFormPageUser();
    $this->actingAs($user);

    $training = trainingFormPageSubmission($user);

    Training::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    $this->get(TrainingFormPage::getUrl(['submission' => $training->id]))
        ->assertForbidden();
});

<?php

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\Training\TrainingAttachmentType;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingAttachment;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\Forms\TrainingService;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function trainingWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'training-test-token']);
}

/**
 * @return array<int, array<string, mixed>>
 */
function trainingPullRows(): array
{
    return [[
        'id' => 70,
        'topic_id' => 801,
        'other_topic' => null,
        'preferred_at' => '2026-11-01 09:00:00',
        'omcc_volume' => 100,
        'competitor_volume' => 50,
        'rsm_status' => RSMStatus::PENDING->value,
        'tdh_status' => TDHStatus::PENDING->value,
        'tds_status' => TDSStatus::PENDING->value,
        'salescall_id' => null,
        'participants' => [
            ['id' => 1, 'name' => 'Juan Dela Cruz', 'position_id' => 901],
        ],
        'attachments' => [],
    ]];
}

/**
 * @param  array<int, array<string, mixed>>  $trainings
 */
function trainingFakePortal(array $trainings = []): void
{
    Http::fake([
        '*/api/forms/training/topics' => Http::response(['training_topics' => [['id' => 801, 'name' => 'Product Knowledge']]], 200),
        '*/api/forms/training/positions' => Http::response(['training_positions' => [['id' => 901, 'name' => 'Driver']]], 200),
        '*/api/forms/training/pull' => Http::response(['trainings' => $trainings], 200),
    ]);
}

function trainingPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function trainingStagedAttachment(User $user, string $name = 'request.png'): StagedAttachment
{
    return app(StagedAttachmentService::class)->stageFromDataUri($user, trainingPngDataUri(), $name);
}

/**
 * @param  array<int, array{name: string, position_id: int}>  $participants
 * @param  array<int, array{type: int, staged_id: int}>  $attachments
 * @return array<string, mixed>
 */
function trainingCreatePayload(?int $topicId, ?string $otherTopic, array $participants, array $attachments): array
{
    return [
        'salescall_uuid' => null,
        'topic_id' => $topicId,
        'other_topic' => $otherTopic,
        'preferred_at' => '2026-11-01 09:00:00',
        'omcc_volume' => 100,
        'competitor_volume' => 50,
        'participants' => $participants,
        'staged_attachments' => $attachments,
    ];
}

test('training master data is cached from the pull', function () {
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $topics = $service->programs();

    expect($topics)->toHaveCount(1)
        ->and($topics->first()->name)->toBe('Product Knowledge')
        ->and($service->programReadFailed())->toBeFalse();

    expect(TrainingTopic::query()->firstOrFail()->server_id)->toBe(801)
        ->and(TrainingPosition::query()->firstOrFail()->server_id)->toBe(901)
        ->and(TrainingPosition::query()->firstOrFail()->name)->toBe('Driver');
});

test('pulled trainings are cached locally with their participants and statuses', function () {
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal(trainingPullRows());

    $service = app(TrainingService::class);
    $service->programs();

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['topic'])->toBe('Product Knowledge')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['participants'][0]['name'])->toBe('Juan Dela Cruz')
        ->and($rows->first()['participants'][0]['position'])->toBe('Driver');

    $training = Training::query()->firstOrFail();

    expect($training->server_id)->toBe(70)
        ->and($training->sync_status)->toBe('synced')
        ->and($training->submitted_by)->toBe($user->id)
        ->and($training->topic->name)->toBe('Product Knowledge')
        ->and($training->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($training->participants()->count())->toBe(1);
});

test('creating a Training requires at least one participant', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    try {
        $service->create($user, trainingCreatePayload($topic->id, null, [], [
            ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ]));

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('participants');
    }

    expect(Training::query()->count())->toBe(0);
});

test('creating a Training requires a topic or an other topic', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);
    $participants = [['name' => 'Juan Dela Cruz', 'position_id' => $position->id]];
    $attachments = [['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id]];

    try {
        $service->create($user, trainingCreatePayload(null, null, $participants, $attachments));

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('topic_id');
    }

    try {
        $service->create($user, trainingCreatePayload(TrainingTopic::query()->firstOrFail()->id, 'Forklift Safety', $participants, $attachments));

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('other_topic');
    }

    expect(Training::query()->count())->toBe(0);
});

test('creating a Training persists the header, participants, and typed staged attachments', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user, 'request.png');
    $completed = trainingStagedAttachment($user, 'completed.png');

    $training = $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ['name' => 'Maria Santos', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TrainingAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $participants = $training->fresh()->participants()->orderBy('id')->get();

    expect($training->fresh()->sync_status)->toBe('pending')
        ->and($training->fresh()->topic_id)->toBe($topic->id)
        ->and($training->fresh()->preferred_at?->toDateTimeString())->toBe('2026-11-01 09:00:00')
        ->and($training->fresh()->omcc_volume)->toBe(100)
        ->and($training->fresh()->competitor_volume)->toBe(50)
        ->and($participants)->toHaveCount(2)
        ->and($participants->first()->name)->toBe('Juan Dela Cruz')
        ->and($participants->first()->position->name)->toBe('Driver')
        ->and($training->fresh()->attachments()->count())->toBe(2)
        ->and(StagedAttachment::find($request->id))->toBeNull()
        ->and(StagedAttachment::find($completed->id))->toBeNull();

    foreach (TrainingAttachment::query()->get() as $attachment) {
        expect(is_file($attachment->local_path))->toBeTrue();
    }
});

test('a locally created other-topic Training appears in submissions with its participants', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload(null, 'Forklift Safety', [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['topic'])->toBe('Forklift Safety')
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($rows->first()['participants'][0]['name'])->toBe('Juan Dela Cruz')
        ->and($rows->first()['attachments'][0]['type'])->toBe(TrainingAttachmentType::REQUEST->value)
        ->and($rows->first()['attachments'][0]['url'])->toBeString()->not->toBeEmpty();
});

test('updating a Training replaces its participants wholesale', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $training = Training::query()->firstOrFail();
    $originalParticipantId = $training->participants()->firstOrFail()->id;

    $service->update($training->fresh(), [
        'salescall_uuid' => null,
        'topic_id' => null,
        'other_topic' => 'Forklift Safety',
        'preferred_at' => '2026-12-15 13:30:00',
        'omcc_volume' => null,
        'competitor_volume' => null,
        'participants' => [
            ['name' => 'Maria Santos', 'position_id' => $position->id],
            ['name' => 'Pedro Reyes', 'position_id' => $position->id],
        ],
    ]);

    $participants = $training->fresh()->participants()->orderBy('id')->get();

    expect($participants)->toHaveCount(2)
        ->and($participants->pluck('id')->all())->not->toContain($originalParticipantId)
        ->and($participants->first()->name)->toBe('Maria Santos')
        ->and($training->fresh()->other_topic)->toBe('Forklift Safety')
        ->and($training->fresh()->topic_id)->toBeNull()
        ->and($training->fresh()->preferred_at?->toDateTimeString())->toBe('2026-12-15 13:30:00')
        ->and($training->fresh()->sync_status)->toBe('pending');
});

test('updating a Training cannot remove the last Request attachment', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user, 'request.png');
    $completed = trainingStagedAttachment($user, 'completed.png');

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TrainingAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $training = Training::query()->firstOrFail();
    $requestAttachment = TrainingAttachment::query()->where('type', TrainingAttachmentType::REQUEST->value)->firstOrFail();
    $completedAttachment = TrainingAttachment::query()->where('type', TrainingAttachmentType::COMPLETED->value)->firstOrFail();

    $base = [
        'salescall_uuid' => null,
        'topic_id' => $topic->id,
        'other_topic' => null,
        'preferred_at' => '2026-11-01 09:00:00',
        'omcc_volume' => null,
        'competitor_volume' => null,
        'participants' => [
            ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ],
    ];

    $service->update($training->fresh(), [
        ...$base,
        'remove_attachments' => [$completedAttachment->id],
    ]);

    expect(TrainingAttachment::query()->count())->toBe(1)
        ->and(TrainingAttachment::query()->firstOrFail()->type)->toBe(TrainingAttachmentType::REQUEST);

    try {
        $service->update($training->fresh(), [
            ...$base,
            'remove_attachments' => [$requestAttachment->id],
        ]);

        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('attachments');
    }

    expect(TrainingAttachment::query()->count())->toBe(1);
});

test('updating a Training is blocked once an approver has acted', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);
    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    Training::query()->update(['tdh_status' => TDHStatus::APPROVED->value]);

    expect(fn () => $service->update(Training::query()->firstOrFail(), [
        'salescall_uuid' => null,
        'topic_id' => $topic->id,
        'other_topic' => null,
        'preferred_at' => '2026-11-01 09:00:00',
        'participants' => [
            ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ],
    ]))->toThrow(ValidationException::class);
});

test('pulled status changes and participant snapshots are cached for synced trainings', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    $rows = trainingPullRows();
    $rows[0]['rsm_status'] = RSMStatus::APPROVED->value;
    $rows[0]['tdh_status'] = TDHStatus::RETURNED->value;
    $rows[0]['participants'] = [
        ['id' => 9, 'name' => 'Maria Santos', 'position_id' => 901],
    ];

    trainingFakePortal($rows);

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    Training::query()->update(['server_id' => 70, 'sync_status' => 'synced']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['rsm_status'])->toBe(RSMStatus::APPROVED->value)
        ->and($result->first()['tdh_status'])->toBe(TDHStatus::RETURNED->value)
        ->and($result->first()['is_editable'])->toBeFalse()
        ->and($result->first()['participants'])->toHaveCount(1)
        ->and($result->first()['participants'][0]['name'])->toBe('Maria Santos');
});

test('an unsynced local Training is not overwritten by the pull', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal(trainingPullRows());

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload(null, 'Local Topic', [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    Training::query()->update(['server_id' => 70, 'sync_status' => 'pending']);

    $result = $service->submissions();

    expect($result)->toHaveCount(1)
        ->and($result->first()['topic'])->toBe('Local Topic')
        ->and($result->first()['participants'][0]['name'])->toBe('Juan Dela Cruz');
});

test('local trainings deleted on the Portal are removed on pull', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal(trainingPullRows());

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $local = $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $attachmentPath = $local->attachments()->firstOrFail()->local_path;
    $local->update(['server_id' => 99, 'sync_status' => 'synced']);

    $service->submissions();

    expect(Training::query()->find($local->id))->toBeNull()
        ->and(is_file($attachmentPath))->toBeFalse();
});

test('adopting a server row returns the cached local Training', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $local = $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    $local->update(['server_id' => 70, 'sync_status' => 'synced']);

    expect($service->adopt($user, ['server_id' => 70])->is($local))->toBeTrue();

    expect(fn () => $service->adopt($user, ['server_id' => 404]))
        ->toThrow(ValidationException::class);
});

test('pushing a Training posts the header and participant snapshot with master server ids', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $training = $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    Http::fake([
        '*/api/forms/training/push' => Http::response(['server_id' => 1101], 200),
        '*/api/forms/training/attach' => Http::response(['server_id' => 1150, 'uploaded_file_id' => 1150], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/forms/training/push')
            && $request['topic_id'] === 801
            && $request['other_topic'] === null
            && $request['preferred_at'] === '2026-11-01 09:00:00'
            && $request['omcc_volume'] === 100
            && $request['competitor_volume'] === 50
            && $request['participants'][0]['name'] === 'Juan Dela Cruz'
            && $request['participants'][0]['position_id'] === 901;
    });

    expect($training->fresh()->sync_status)->toBe('synced')
        ->and($training->fresh()->server_id)->toBe(1101);
});

test('Training attachments push after the parent with their type', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user, 'request.png');
    $completed = trainingStagedAttachment($user, 'completed.png');

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
        ['type' => TrainingAttachmentType::COMPLETED->value, 'staged_id' => $completed->id],
    ]));

    $attachment = TrainingAttachment::query()
        ->where('type', TrainingAttachmentType::COMPLETED->value)
        ->firstOrFail();

    Http::fake([
        '*/api/forms/training/push' => Http::response(['server_id' => 1101], 200),
        '*/api/forms/training/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 77], 200),
        '*' => Http::response([], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/forms/training/attach')) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return ($parts['server_id']['contents'] ?? null) == 1101
            && ($parts['type']['contents'] ?? null) == TrainingAttachmentType::COMPLETED->value;
    });

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88)
        ->and(Training::query()->firstOrFail()->server_id)->toBe(1101);
});

test('pull caching batches lookups and replaces participant snapshots with one delete and insert', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    $rows = trainingPullRows();
    $second = $rows[0];
    $second['id'] = 71;
    $rows[] = $second;

    trainingFakePortal($rows);

    $service = app(TrainingService::class);
    $service->programs();

    DB::enableQueryLog();

    $result = $service->submissions();

    $queries = collect(DB::getQueryLog());

    DB::disableQueryLog();

    $singleRowLookups = fn (string $table): int => $queries
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"')
            && str_contains(strtolower((string) $query['query']), 'limit 1'))
        ->count();

    $participantDeletes = $queries
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'delete from "training_participants"'))
        ->count();

    $participantInserts = $queries
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'insert into "training_participants"'))
        ->count();

    expect($result)->toHaveCount(2)
        ->and($singleRowLookups('training_topics'))->toBe(0)
        ->and($singleRowLookups('training_positions'))->toBe(0)
        ->and($singleRowLookups('trainings'))->toBe(0)
        ->and($participantDeletes)->toBe(1)
        ->and($participantInserts)->toBe(1);
});

test('has pending changes includes Trainings', function () {
    Storage::fake('local');
    $user = trainingWorkflowUser();
    $this->actingAs($user);

    trainingFakePortal();

    $service = app(TrainingService::class);
    $service->programs();

    $topic = TrainingTopic::query()->firstOrFail();
    $position = TrainingPosition::query()->firstOrFail();
    $request = trainingStagedAttachment($user);

    $service->create($user, trainingCreatePayload($topic->id, null, [
        ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
    ], [
        ['type' => TrainingAttachmentType::REQUEST->value, 'staged_id' => $request->id],
    ]));

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

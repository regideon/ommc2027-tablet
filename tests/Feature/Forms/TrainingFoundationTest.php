<?php

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\Training\TrainingAttachmentType;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingAttachment;
use App\Models\Forms\Training\TrainingParticipant;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\User;
use App\Support\Forms\TrainingRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('training form tables and key columns exist', function () {
    expect(Schema::hasTable('training_topics'))->toBeTrue()
        ->and(Schema::hasTable('training_positions'))->toBeTrue()
        ->and(Schema::hasTable('trainings'))->toBeTrue()
        ->and(Schema::hasTable('training_participants'))->toBeTrue()
        ->and(Schema::hasTable('training_attachments'))->toBeTrue();

    expect(Schema::hasColumns('training_topics', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('training_positions', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('trainings', [
            'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
            'topic_id', 'other_topic', 'preferred_at', 'omcc_volume', 'competitor_volume',
            'rsm_status', 'tdh_status', 'tds_status',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('training_participants', [
            'id', 'training_id', 'name', 'position_id', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('training_attachments', [
            'id', 'local_uuid', 'server_id', 'training_id', 'type',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

/**
 * @return array{user: User, topic: TrainingTopic, position: TrainingPosition, training: Training}
 */
function trainingFoundationRecords(): array
{
    $user = User::factory()->create();
    $topic = TrainingTopic::create(['name' => 'Product Knowledge']);
    $position = TrainingPosition::create(['name' => 'Promodiser']);

    $training = Training::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'topic_id' => $topic->id,
        'preferred_at' => '2026-10-20 09:00:00',
        'omcc_volume' => 10,
        'competitor_volume' => 5,
    ]);

    return ['user' => $user, 'topic' => $topic, 'position' => $position, 'training' => $training];
}

test('training models cast enums and resolve their relationships', function () {
    ['user' => $user, 'topic' => $topic, 'position' => $position, 'training' => $training] = trainingFoundationRecords();

    $training = $training->fresh();

    expect($training->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($training->tdh_status)->toBe(TDHStatus::PENDING)
        ->and($training->tds_status)->toBe(TDSStatus::PENDING)
        ->and($training->preferred_at->toDateTimeString())->toBe('2026-10-20 09:00:00')
        ->and($training->submittedBy->is($user))->toBeTrue()
        ->and($training->topic->is($topic))->toBeTrue()
        ->and($topic->trainings()->where('id', $training->id)->exists())->toBeTrue();

    $participant = TrainingParticipant::create([
        'training_id' => $training->id,
        'name' => 'Juan Dela Cruz',
        'position_id' => $position->id,
    ]);

    expect($participant->training->is($training))->toBeTrue()
        ->and($participant->position->is($position))->toBeTrue()
        ->and($position->participants()->where('id', $participant->id)->exists())->toBeTrue();

    $attachment = TrainingAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'training_id' => $training->id,
        'type' => TrainingAttachmentType::REQUEST->value,
        'local_path' => 'training_attachments/'.$training->local_uuid.'.png',
    ]);

    expect($attachment->type)->toBe(TrainingAttachmentType::REQUEST)
        ->and($attachment->training->is($training))->toBeTrue()
        ->and($training->attachments()->where('id', $attachment->id)->exists())->toBeTrue();
});

test('training rules accept a valid payload and reject an invalid one', function () {
    $topic = TrainingTopic::create(['name' => 'Product Knowledge']);
    $position = TrainingPosition::create(['name' => 'Promodiser']);

    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'topic_id' => $topic->id,
        'other_topic' => null,
        'preferred_at' => '2026-10-20 09:00:00',
        'omcc_volume' => 10,
        'competitor_volume' => 5,
        'participants' => [
            ['name' => 'Juan Dela Cruz', 'position_id' => $position->id],
        ],
    ];

    expect(Validator::make($valid, TrainingRules::rules())->passes())->toBeTrue();

    $invalid = [
        'topic_id' => 999999,
        'preferred_at' => 'not-a-date',
        'participants' => [
            ['name' => '', 'position_id' => 999999],
        ],
    ];

    $validator = Validator::make($invalid, TrainingRules::rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('preferred_at'))->toBeTrue()
        ->and($validator->errors()->has('topic_id'))->toBeTrue()
        ->and($validator->errors()->has('participants.0.name'))->toBeTrue()
        ->and($validator->errors()->has('participants.0.position_id'))->toBeTrue();
});

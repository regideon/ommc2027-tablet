<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\Training\TrainingAttachmentType;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingAttachment;
use App\Models\Forms\Training\TrainingParticipant;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use App\Support\Forms\TrainingRules;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Training form's single service: reads the current user's submissions
 * (local rows cached from a live, scoped Portal pull) and creates/edits them
 * offline-first. Topics and positions are pull-only masters, participants are
 * a wholesale snapshot, and attachments are typed (request, completed) with
 * at least one request required.
 */
class TrainingService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{trainings: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Refresh both master picklists (topics and positions) from the Portal;
     * the contract returns the topic cache, and the form page reads both.
     *
     * @return Collection<int, TrainingTopic>
     */
    public function programs(): Collection
    {
        $topics = $this->syncService->readTrainingTopicsWithStatus();
        $this->programReadFailed = $topics['failed'];

        if (! $topics['failed']) {
            $this->reconcileLookup(
                TrainingTopic::class,
                $topics['topics'],
                fn (array $topic): array => [
                    'server_id' => $topic['id'],
                    'name' => $topic['name'],
                ],
                'trainings',
            );
        }

        $positions = $this->syncService->readTrainingPositionsWithStatus();
        $this->programReadFailed = $this->programReadFailed || $positions['failed'];

        if (! $positions['failed']) {
            $this->reconcileLookup(
                TrainingPosition::class,
                $positions['positions'],
                fn (array $position): array => [
                    'server_id' => $position['id'],
                    'name' => $position['name'],
                ],
                'participants',
            );
        }

        return TrainingTopic::query()->orderBy('name')->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * The current user's Training submissions, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function submissions(): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $result = $this->pull();
        $this->globalReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheRows($result['trainings']);
            $this->reconcileDeleted($result['trainings']);
        }

        $local = Training::query()
            ->with(['participants.position', 'attachments', 'topic', 'salescall.customer'])
            ->where('submitted_by', $viewer->id)
            ->get();

        return $local
            ->map(fn (Training $training): array => $this->mapLocal($training))
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->values();
    }

    public function globalReadFailed(): bool
    {
        return $this->globalReadFailed;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE / EDIT
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Training
    {
        $validated = TrainingRules::validate($input);

        $topic = $this->normalizeTopic($validated['topic_id'] ?? null, $validated['other_topic'] ?? null);
        $participants = $this->normalizeParticipants($validated['participants'] ?? []);
        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);

        $this->assertParticipants($participants);
        $this->assertRequestAttachmentForCreate($staged);

        $training = null;
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($user, $validated, $topic, $participants, $staged, &$training, &$storedPaths): void {
                $training = Training::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'topic_id' => $topic['topic_id'],
                    'other_topic' => $topic['other_topic'],
                    'preferred_at' => $validated['preferred_at'],
                    'omcc_volume' => $validated['omcc_volume'] ?? null,
                    'competitor_volume' => $validated['competitor_volume'] ?? null,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'tdh_status' => TDHStatus::PENDING->value,
                    'tds_status' => TDSStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->replaceParticipantSnapshot([$training->id => $participants]);
                $this->persistStagedAttachments($training, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $training->fresh(['participants.position', 'attachments']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): Training
    {
        if (! $submission instanceof Training) {
            throw new InvalidArgumentException('Expected a Training submission.');
        }

        $training = $submission;

        if (! $training->is_editable) {
            throw ValidationException::withMessages([
                'training' => ['Only Trainings that have not synced or have been returned to the DRM can be edited.'],
            ]);
        }

        $validated = TrainingRules::validate($input);

        $topic = $this->normalizeTopic($validated['topic_id'] ?? null, $validated['other_topic'] ?? null);
        $participants = $this->normalizeParticipants($validated['participants'] ?? []);
        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);
        $removed = $this->normalizeIds($validated['remove_attachments'] ?? []);

        $this->assertParticipants($participants);
        $this->assertRequestAttachmentForUpdate($training, $staged, $removed);

        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($training, $validated, $topic, $participants, $staged, $removed, &$storedPaths): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $training->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'topic_id' => $topic['topic_id'],
                    'other_topic' => $topic['other_topic'],
                    'preferred_at' => $validated['preferred_at'],
                    'omcc_volume' => $validated['omcc_volume'] ?? null,
                    'competitor_volume' => $validated['competitor_volume'] ?? null,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->replaceParticipantSnapshot([$training->id => $participants]);
                $this->removeAttachments($training, $removed);
                $this->persistStagedAttachments($training, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $training->fresh(['participants.position', 'attachments']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Training
    {
        $training = isset($row['server_id'])
            ? Training::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($training) {
            return $training;
        }

        throw ValidationException::withMessages([
            'training' => ['This Training is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    public function attachmentUrl(TrainingAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "training_attachments/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($attachment->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{trainings: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readTrainingsWithStatus();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheRows(array $rows): void
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return;
        }

        $rows = array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && isset($row['id']),
        ));

        if ($rows === []) {
            return;
        }

        $topicServerIds = collect($rows)
            ->pluck('topic_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $positionServerIds = collect($rows)
            ->flatMap(fn (array $row): array => collect($row['participants'] ?? [])
                ->filter(fn (mixed $participant): bool => is_array($participant))
                ->pluck('position_id')
                ->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $salescallServerIds = collect($rows)
            ->pluck('salescall_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $rowServerIds = collect($rows)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // One lookup per master + one fetch of existing rows for the whole pull.
        $topicsByServerId = TrainingTopic::query()->whereIn('server_id', $topicServerIds)->pluck('id', 'server_id');
        $positionsByServerId = TrainingPosition::query()->whereIn('server_id', $positionServerIds)->pluck('id', 'server_id');
        $salescallsByServerId = Salescall::query()->whereIn('server_id', $salescallServerIds)->pluck('id', 'server_id');
        $existingByServerId = Training::query()->whereIn('server_id', $rowServerIds)->get()->keyBy('server_id');

        $participantsByTrainingId = [];

        foreach ($rows as $row) {
            $topicId = isset($row['topic_id']) && $row['topic_id'] !== null
                ? $topicsByServerId->get((int) $row['topic_id'])
                : null;

            // Wait for the master picklists to be cached before mirroring rows.
            if (isset($row['topic_id']) && $row['topic_id'] !== null && ! $topicId) {
                continue;
            }

            $participantRows = [];
            $positionsResolved = true;

            foreach ($row['participants'] ?? [] as $participantRow) {
                if (! is_array($participantRow)) {
                    continue;
                }

                $positionId = $positionsByServerId->get((int) ($participantRow['position_id'] ?? 0));

                if (! $positionId) {
                    $positionsResolved = false;

                    break;
                }

                $participantRows[] = [
                    'name' => (string) ($participantRow['name'] ?? ''),
                    'position_id' => $positionId,
                ];
            }

            if (! $positionsResolved) {
                continue;
            }

            $clientUuid = is_string($row['client_uuid'] ?? null) && $row['client_uuid'] !== '' ? $row['client_uuid'] : null;
            $training = $clientUuid !== null
                ? Training::query()->where('local_uuid', $clientUuid)->first()
                : null;
            $training ??= $existingByServerId->get((int) $row['id'])
                ?? new Training(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($training->exists && $training->sync_status !== 'synced') {
                continue;
            }

            if (! $training->exists) {
                $training->local_uuid = (string) Str::uuid();
                $training->submitted_by = $viewer->id;
                $training->sync_status = 'synced';
                $training->sync_attempts = 0;
            }

            $salescallId = isset($row['salescall_id']) && $row['salescall_id'] !== null
                ? $salescallsByServerId->get((int) $row['salescall_id'])
                : null;

            $training->fill([
                'salescall_id' => $salescallId,
                'topic_id' => $topicId,
                'other_topic' => $row['other_topic'] ?? null,
                'preferred_at' => $row['preferred_at'] ?? null,
                'omcc_volume' => $row['omcc_volume'] ?? null,
                'competitor_volume' => $row['competitor_volume'] ?? null,
                'rsm_status' => (int) ($row['rsm_status'] ?? RSMStatus::PENDING->value),
                'tdh_status' => (int) ($row['tdh_status'] ?? TDHStatus::PENDING->value),
                'tds_status' => (int) ($row['tds_status'] ?? TDSStatus::PENDING->value),
                'synced_at' => now(),
            ]);

            $training->save();

            $existingByServerId->put((int) $row['id'], $training);

            // The Portal rebuilds participants on every push, so they are a snapshot.
            $participantsByTrainingId[$training->id] = $participantRows;
        }

        $this->replaceParticipantSnapshot($participantsByTrainingId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function reconcileDeleted(array $rows): void
    {
        $serverIds = collect($rows)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        Training::query()
            ->with('attachments')
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (Training $training): void {
                foreach ($training->attachments ?? collect() as $attachment) {
                    $this->deleteAttachmentFile($attachment);
                }

                $training->delete();
            });
    }

    /**
     * Replace the participant snapshots for many trainings with one delete and
     * one insert so a pull never issues a relation write per row.
     *
     * @param  array<int, array<int, array{name: string, position_id: int}>>  $participantsByTrainingId
     */
    private function replaceParticipantSnapshot(array $participantsByTrainingId): void
    {
        if ($participantsByTrainingId === []) {
            return;
        }

        TrainingParticipant::query()
            ->whereIn('training_id', array_keys($participantsByTrainingId))
            ->delete();

        $now = now();
        $inserts = [];

        foreach ($participantsByTrainingId as $trainingId => $rows) {
            foreach ($rows as $row) {
                $inserts[] = [
                    'training_id' => $trainingId,
                    'name' => $row['name'],
                    'position_id' => $row['position_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($inserts !== []) {
            TrainingParticipant::query()->insert($inserts);
        }
    }

    /**
     * @return array{topic_id: ?int, other_topic: ?string}
     */
    private function normalizeTopic(?int $topicId, mixed $otherTopic): array
    {
        $otherTopic = is_string($otherTopic) ? trim($otherTopic) : '';
        $otherTopic = $otherTopic === '' ? null : $otherTopic;

        if ($topicId === null && $otherTopic === null) {
            throw ValidationException::withMessages([
                'topic_id' => ['Choose a topic or enter an other topic.'],
            ]);
        }

        if ($topicId !== null && $otherTopic !== null) {
            throw ValidationException::withMessages([
                'other_topic' => ['Choose either a topic or an other topic, not both.'],
            ]);
        }

        return [
            'topic_id' => $topicId,
            'other_topic' => $otherTopic,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $participants
     * @return array<int, array{name: string, position_id: int}>
     */
    private function normalizeParticipants(array $participants): array
    {
        $normalized = [];

        foreach (array_values($participants) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'position_id' => (int) ($row['position_id'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{name: string, position_id: int}>  $participants
     */
    private function assertParticipants(array $participants): void
    {
        if ($participants === []) {
            throw ValidationException::withMessages([
                'participants' => ['Add at least one participant.'],
            ]);
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function assertRequestAttachmentForCreate(array $staged): void
    {
        if (! $this->hasStagedType($staged, TrainingAttachmentType::REQUEST)) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  array<int, int>  $removed
     */
    private function assertRequestAttachmentForUpdate(Training $training, array $staged, array $removed): void
    {
        $remaining = TrainingAttachment::query()
            ->where('training_id', $training->id)
            ->where('type', TrainingAttachmentType::REQUEST->value)
            ->whereNotIn('id', $removed)
            ->count();

        if ($remaining + ($this->hasStagedType($staged, TrainingAttachmentType::REQUEST) ? 1 : 0) === 0) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function hasStagedType(array $staged, TrainingAttachmentType $type): bool
    {
        return collect($staged)->contains(
            fn (array $row): bool => $row['type'] === $type->value
        );
    }

    private function missingRequestAttachment(): ValidationException
    {
        return ValidationException::withMessages([
            'attachments' => ['At least one Request attachment is required.'],
        ]);
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  list<string>  $storedPaths
     */
    private function persistStagedAttachments(Training $training, User $user, array $staged, array &$storedPaths): void
    {
        foreach ($staged as $index => $row) {
            $stagedService = app(StagedAttachmentService::class);
            $stagedModel = $stagedService->findForUser($user, $row['staged_id']);

            if (! $stagedModel instanceof StagedAttachment) {
                throw ValidationException::withMessages([
                    "staged_attachments.$index.staged_id" => ['A staged attachment is no longer available. Re-add the file and try again.'],
                ]);
            }

            $metadata = $stagedService->consume($user, $stagedModel, 'training_attachments');
            $storedPaths[] = $metadata['local_path'];

            TrainingAttachment::create([
                'local_uuid' => (string) Str::uuid(),
                'training_id' => $training->id,
                'type' => $row['type'],
                'local_path' => $metadata['local_path'],
                'original_name' => $metadata['original_name'],
                'mime_type' => $metadata['mime_type'],
                'extension' => $metadata['extension'],
                'byte_size' => $metadata['byte_size'],
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function removeAttachments(Training $training, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        TrainingAttachment::query()
            ->where('training_id', $training->id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (TrainingAttachment $attachment): void {
                $this->deleteAttachmentFile($attachment);
                $attachment->delete();
            });
    }

    /**
     * @param  array<int|string, mixed>  $staged
     * @return array<int, array{type: int, staged_id: int}>
     */
    private function normalizeStagedAttachments(array $staged): array
    {
        $normalized = [];

        foreach (array_values($staged) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = isset($row['type']) ? (int) $row['type'] : 0;
            $stagedId = isset($row['staged_id']) ? (int) $row['staged_id'] : 0;

            if ($stagedId <= 0 || ! in_array($type, TrainingAttachmentType::values(), true)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'staged_id' => $stagedId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param  list<string>  $storedPaths
     */
    private function cleanupStoredPaths(array $storedPaths): void
    {
        foreach ($storedPaths as $storedPath) {
            if (is_string($storedPath) && is_file($storedPath)) {
                @unlink($storedPath);
            }
        }
    }

    /**
     * Retry a local write on transient SQLite open/lock errors (CANTOPEN, busy,
     * disk I/O) before surfacing the failure.
     */
    private function attemptTransaction(Closure $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! $this->isTransientDatabaseError($exception)) {
                    throw $exception;
                }

                usleep(150_000 * $attempt);
            }
        }
    }

    private function isTransientDatabaseError(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'unable to open database file')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'disk I/O error');
    }

    private function deleteAttachmentFile(TrainingAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("training_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(Training $training): array
    {
        $otherTopic = $training->other_topic;

        return [
            'id' => $training->id,
            'server_id' => $training->server_id,
            'local_uuid' => $training->local_uuid,
            'topic' => $training->topic?->name ?? ($otherTopic !== null && $otherTopic !== '' ? $otherTopic : '—'),
            'topic_id' => $training->topic_id,
            'other_topic' => $otherTopic,
            'preferred_at' => $training->preferred_at?->toDateTimeString(),
            'omcc_volume' => $training->omcc_volume,
            'competitor_volume' => $training->competitor_volume,
            'sales_call' => $this->salesCallLabel($training->salescall),
            'salescall_uuid' => $training->salescall?->local_uuid,
            'rsm_status' => $training->rsm_status->value,
            'tdh_status' => $training->tdh_status->value,
            'tds_status' => $training->tds_status->value,
            'is_editable' => $training->is_editable,
            'sync_status' => $training->sync_status,
            'participants' => $training->participants
                ->sortBy('id')
                ->values()
                ->map(fn (TrainingParticipant $participant): array => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'position' => $participant->position?->name ?? '—',
                ])
                ->all(),
            'attachments' => $training->attachments
                ->sortBy('id')
                ->values()
                ->map(fn (TrainingAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'type' => $attachment->type->value,
                    'type_label' => $attachment->type->meta('title'),
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $this->attachmentUrl($attachment),
                ])
                ->all(),
            'created_at' => $training->created_at?->toISOString(),
        ];
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }
}

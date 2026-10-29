<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Enums\Training\TrainingAttachmentType;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingPosition;
use App\Models\Forms\Training\TrainingTopic;
use App\Models\Salescall;
use App\Services\Forms\TrainingService;
use App\Support\NativeMediaPath;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Throwable;

class TrainingFormPage extends Page
{
    use RedirectsWithFormSync;
    use StagesAttachments;

    protected string $view = 'filament.pages.training-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/training';

    protected static ?string $title = '';

    public ?Training $training = null;

    public bool $isEdit = false;

    public string $topic_choice = '';

    public string $other_topic = '';

    public string $preferred_at = '';

    public ?string $omcc_volume = null;

    public ?string $competitor_volume = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{name: string, position_id: ?int}> */
    public array $participants = [];

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $topics = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $positions = [];

    /** @var array<int|string, array<int, array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}>> */
    public array $attachments = [];

    /** @var array<int, int> */
    public array $removedAttachmentIds = [];

    /** @var array<string, int> */
    public array $pendingAttachment = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public string $masterLoadError = '';

    public function mount(TrainingService $service): void
    {
        $service->programs();

        $this->masterLoadError = $service->programReadFailed()
            ? 'Training topics and positions could not be refreshed. Reconnect and reopen this form.'
            : '';

        $this->topics = TrainingTopic::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TrainingTopic $topic): array => [
                'id' => $topic->id,
                'name' => $topic->name,
            ])
            ->values()
            ->all();

        $this->positions = TrainingPosition::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TrainingPosition $position): array => [
                'id' => $position->id,
                'name' => $position->name,
            ])
            ->values()
            ->all();

        $this->salescalls = Salescall::query()
            ->with('customer')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->get()
            ->map(fn (Salescall $salescall): array => [
                'uuid' => $salescall->local_uuid,
                'label' => $salescall->customer?->name
                    ?? $salescall->ref_number
                    ?? ('Salescall #'.$salescall->id),
            ])
            ->values()
            ->all();

        $submissionId = filter_var(request()->query('submission'), FILTER_VALIDATE_INT);

        if ($submissionId !== false && $submissionId !== null) {
            $training = Training::query()
                ->with(['participants', 'attachments', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($training instanceof Training) {
                abort_unless($training->is_editable, 403);

                $this->training = $training;
                $this->isEdit = true;
                $this->topic_choice = $training->topic_id !== null
                    ? (string) $training->topic_id
                    : ($training->other_topic !== null && $training->other_topic !== '' ? 'other' : '');
                $this->other_topic = (string) ($training->other_topic ?? '');
                $this->preferred_at = $training->preferred_at?->format('Y-m-d\TH:i') ?? '';
                $this->omcc_volume = $training->omcc_volume !== null ? (string) $training->omcc_volume : null;
                $this->competitor_volume = $training->competitor_volume !== null ? (string) $training->competitor_volume : null;
                $this->salescall_uuid = $training->salescall?->local_uuid;

                foreach ($training->participants->sortBy('id') as $participant) {
                    $this->participants[] = [
                        'name' => $participant->name,
                        'position_id' => $participant->position_id,
                    ];
                }

                foreach ($training->attachments->sortBy('id') as $attachment) {
                    $this->attachments[$attachment->type->value][] = [
                        'source' => 'existing',
                        'id' => $attachment->id,
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'url' => $service->attachmentUrl($attachment),
                    ];
                }
            }
        }

        if ($this->participants === []) {
            $this->participants = [$this->blankParticipantRow()];
        }
    }

    public function save(TrainingService $service): void
    {
        $this->formErrors = [];

        $participants = [];

        foreach (array_values($this->participants) as $row) {
            $participants[] = [
                'name' => $row['name'] ?? '',
                'position_id' => $row['position_id'] ?? null,
            ];
        }

        $input = [
            'salescall_uuid' => $this->salescall_uuid,
            'topic_id' => ctype_digit($this->topic_choice) ? (int) $this->topic_choice : null,
            'other_topic' => $this->topic_choice === 'other' ? $this->other_topic : null,
            'preferred_at' => $this->preferred_at,
            'omcc_volume' => $this->omcc_volume === '' ? null : $this->omcc_volume,
            'competitor_volume' => $this->competitor_volume === '' ? null : $this->competitor_volume,
            'participants' => $participants,
            'staged_attachments' => $this->stagedAttachments(),
            'remove_attachments' => $this->removedAttachmentIds,
        ];

        try {
            if ($this->isEdit && $this->training instanceof Training) {
                $service->update($this->training, $input);
                $title = 'Training updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'Training saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local Training save failed.', [
                'training_id' => $this->training?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The Training could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::TRAINING->value);
    }

    public function cancel(): void
    {
        $this->discardAllStagedAttachments();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::TRAINING->value]));
    }

    public function addParticipantRow(): void
    {
        $this->participants[] = $this->blankParticipantRow();
    }

    public function removeParticipantRow(int $index): void
    {
        unset($this->participants[$index]);
        $this->participants = array_values($this->participants);

        $this->formErrors = [];
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageTypedAttachment(int $type, array $payload): void
    {
        unset($this->formErrors['attachments']);

        if (! in_array($type, TrainingAttachmentType::values(), true)) {
            return;
        }

        $data = $payload['data'] ?? null;

        if (! is_string($data) || $data === '') {
            return;
        }

        $staged = $this->stageAttachmentFromDataUri($data, (string) ($payload['original_name'] ?? 'attachment'));

        if ($staged !== null) {
            $this->attachments[$type][] = ['source' => 'staged', ...$staged];
        }
    }

    public function removeAttachment(int $type, int $index): void
    {
        $attachment = $this->attachments[$type][$index] ?? null;

        if (is_array($attachment) && isset($attachment['id'])) {
            if (($attachment['source'] ?? null) === 'staged') {
                $this->discardStagedAttachment((int) $attachment['id']);
            } else {
                $this->removedAttachmentIds[] = (int) $attachment['id'];
            }
        }

        unset($this->attachments[$type][$index]);
        $this->attachments[$type] = array_values($this->attachments[$type] ?? []);

        unset($this->formErrors['attachments']);
    }

    public function takeAttachmentPhoto(int $type): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $capture = Camera::getPhoto();
        $this->pendingAttachment[$capture->getId()] = $type;
        $capture->start();
    }

    public function pickAttachmentFromGallery(int $type): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $picker = Camera::pickImages('image')->single();
        $this->pendingAttachment[$picker->getId()] = $type;
        $picker->start();
    }

    #[On('native:'.PhotoTaken::class)]
    public function onAttachmentPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        $type = $this->pendingAttachment[$id];
        unset($this->pendingAttachment[$id]);

        $this->stageCapturedAttachment($path, $type);
    }

    /** @param array<int, mixed> $files */
    #[On('native:'.MediaSelected::class)]
    public function onAttachmentMediaSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        $type = $this->pendingAttachment[$id];
        unset($this->pendingAttachment[$id]);

        if ($cancelled) {
            return;
        }

        if (! $success || empty($files)) {
            Notification::make()
                ->title($error ?: 'Could not import the selected photo.')
                ->danger()
                ->send();

            return;
        }

        $path = NativeMediaPath::resolve($files[0]);

        if ($path === null) {
            Notification::make()->title('Selected attachment path is invalid.')->danger()->send();

            return;
        }

        $this->stageCapturedAttachment($path, $type);
    }

    #[On('native:'.PhotoCancelled::class)]
    public function onAttachmentCancelled(bool $cancelled = true, ?string $id = null): void
    {
        if ($id !== null) {
            unset($this->pendingAttachment[$id]);
        }
    }

    #[On('native:'.PermissionDenied::class)]
    public function onAttachmentPermissionDenied(string $action = 'photo', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        unset($this->pendingAttachment[$id]);
        Notification::make()->title('Camera permission is required to add an attachment.')->danger()->send();
    }

    /**
     * @return array{name: string, position_id: null}
     */
    private function blankParticipantRow(): array
    {
        return [
            'name' => '',
            'position_id' => null,
        ];
    }

    private function stageCapturedAttachment(string $sourcePath, int $type): void
    {
        $path = NativeMediaPath::resolve($sourcePath) ?? $sourcePath;
        $staged = $this->stageAttachmentFromPath($path);

        if ($staged !== null) {
            $this->attachments[$type][] = ['source' => 'staged', ...$staged];
        }
    }

    /**
     * @return array<int, array{type: int, staged_id: int}>
     */
    private function stagedAttachments(): array
    {
        $staged = [];

        foreach ($this->attachments as $type => $list) {
            foreach (is_array($list) ? $list : [] as $attachment) {
                if (! is_array($attachment) || ($attachment['source'] ?? null) !== 'staged' || ! isset($attachment['id'])) {
                    continue;
                }

                $staged[] = [
                    'type' => (int) $type,
                    'staged_id' => (int) $attachment['id'],
                ];
            }
        }

        return $staged;
    }

    private function discardAllStagedAttachments(): void
    {
        foreach ($this->attachments as $list) {
            foreach (is_array($list) ? $list : [] as $attachment) {
                if (is_array($attachment) && ($attachment['source'] ?? null) === 'staged' && isset($attachment['id'])) {
                    $this->discardStagedAttachment((int) $attachment['id']);
                }
            }
        }

        $this->attachments = [];
        $this->removedAttachmentIds = [];
    }
}

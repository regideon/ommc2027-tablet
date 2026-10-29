<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Enums\Repair\RepairAttachmentType;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\Repair\Repair;
use App\Models\Salescall;
use App\Services\Forms\RepairService;
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

class RepairFormPage extends Page
{
    use RedirectsWithFormSync;
    use StagesAttachments;

    protected string $view = 'filament.pages.repair-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/repair';

    protected static ?string $title = '';

    public ?Repair $repair = null;

    public bool $isEdit = false;

    public string $plate_number = '';

    public ?string $invoice_number = null;

    public string $details = '';

    public string $amount = '';

    public ?string $remarks = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<int|string, array<int, array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}>> */
    public array $attachments = [];

    /** @var array<int, int> */
    public array $removedAttachmentIds = [];

    /** @var array<string, int> */
    public array $pendingAttachment = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public function mount(RepairService $service): void
    {
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
            $repair = Repair::query()
                ->with(['attachments', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($repair instanceof Repair) {
                abort_unless($repair->is_editable, 403);

                $this->repair = $repair;
                $this->isEdit = true;
                $this->plate_number = $repair->plate_number;
                $this->invoice_number = $repair->invoice_number;
                $this->details = $repair->details;
                $this->amount = (string) $repair->amount;
                $this->remarks = $repair->remarks;
                $this->salescall_uuid = $repair->salescall?->local_uuid;

                foreach ($repair->attachments->sortBy('id') as $attachment) {
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
    }

    public function save(RepairService $service): void
    {
        $this->formErrors = [];

        $input = [
            'salescall_uuid' => $this->salescall_uuid,
            'plate_number' => $this->plate_number,
            'invoice_number' => $this->invoice_number,
            'details' => $this->details,
            'amount' => $this->amount,
            'remarks' => $this->remarks,
            'staged_attachments' => $this->stagedAttachments(),
            'remove_attachments' => $this->removedAttachmentIds,
        ];

        try {
            if ($this->isEdit && $this->repair instanceof Repair) {
                $service->update($this->repair, $input);
                $title = 'Repair updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'Repair saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local Repair save failed.', [
                'repair_id' => $this->repair?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The Repair could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::REPAIR->value);
    }

    public function cancel(): void
    {
        $this->discardAllStagedAttachments();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::REPAIR->value]));
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageTypedAttachment(int $type, array $payload): void
    {
        unset($this->formErrors['attachments']);

        if (! in_array($type, RepairAttachmentType::values(), true)) {
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

<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Salescall;
use App\Services\Forms\CrrService;
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

class CrrFormPage extends Page
{
    use LocksRequestedSalescall;

    protected string $view = 'filament.pages.crr-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/crr';

    protected static ?string $title = '';

    public ?Crr $crr = null;

    public bool $isEdit = false;

    /** @var array<int, array{id: int, name: string}> */
    public array $programs = [];

    public string $programLoadError = '';

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    public ?int $crr_program_id = null;

    public ?string $date_implemented = null;

    public ?string $amount = null;

    public ?string $details = null;

    public ?string $remarks = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{data: string, original_name: string, mime_type: string, byte_size: int}> */
    public array $attachments = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    /** @var array<string, bool> */
    public array $pendingAttachment = [];

    public function mount(CrrService $service): void
    {
        $this->programs = $service->programs()
            ->map(fn (CrrProgram $program): array => [
                'id' => $program->id,
                'name' => $program->name,
            ])
            ->values()
            ->all();

        $this->programLoadError = $service->programReadFailed()
            ? 'CRR programs could not be loaded. Reconnect and reopen this form.'
            : '';

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

        $crrId = filter_var(request()->query('crr'), FILTER_VALIDATE_INT);

        if ($crrId !== false && $crrId !== null) {
            $crr = Crr::query()
                ->with('salescall')
                ->where('submitted_by', auth()->id())
                ->find($crrId);

            if ($crr instanceof Crr) {
                abort_unless($crr->isEditable(), 403);

                $this->crr = $crr;
                $this->isEdit = true;
                $this->crr_program_id = $crr->crr_program_id;
                $this->date_implemented = $crr->date_implemented;
                $this->amount = $crr->amount !== null ? (string) $crr->amount : null;
                $this->details = $crr->details;
                $this->remarks = $crr->remarks;
                $this->salescall_uuid = $crr->salescall?->local_uuid;

                return;
            }
        }

        $this->applyRequestedSalescallLock();

        $this->date_implemented = now()->toDateString();
    }

    public function save(CrrService $service): void
    {
        $this->formErrors = [];

        $input = [
            'crr_program_id' => $this->crr_program_id,
            'date_implemented' => $this->date_implemented,
            'amount' => $this->amount,
            'details' => $this->details,
            'remarks' => $this->remarks,
            'salescall_uuid' => $this->salescall_uuid,
            'attachments' => $this->attachments,
        ];

        try {
            if ($this->isEdit && $this->crr instanceof Crr) {
                $service->update($this->crr, $input);
                $title = 'CRR updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'CRR saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (\Throwable $exception) {
            Log::error('Local CRR save failed.', [
                'crr_id' => $this->crr?->id,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The CRR could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::CRR->value]));
    }

    public function cancel(): void
    {
        $this->redirect(FormsPage::getUrl(['form' => FormKey::CRR->value]));
    }

    public function takeAttachmentPhoto(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $capture = Camera::getPhoto();
        $this->pendingAttachment[$capture->getId()] = true;
        $capture->start();
    }

    public function pickAttachmentFromGallery(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $picker = Camera::pickImages('image')->single();
        $this->pendingAttachment[$picker->getId()] = true;
        $picker->start();
    }

    #[On('native:'.PhotoTaken::class)]
    public function onAttachmentPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        unset($this->pendingAttachment[$id]);
        $this->dispatchAttachmentFromPath($path);
    }

    /** @param array<int, mixed> $files */
    #[On('native:'.MediaSelected::class)]
    public function onAttachmentMediaSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

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

        $this->dispatchAttachmentFromPath($path);
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

    public function addAttachment(array $payload): void
    {
        unset($this->formErrors['attachments']);

        $data = $payload['data'] ?? null;
        if (! is_string($data) || $data === '') {
            return;
        }

        if (count($this->attachments) >= 10) {
            $this->formErrors['attachments'] = ['A CRR may have at most 10 attachments.'];

            return;
        }

        $mimeType = (string) ($payload['mime_type'] ?? '');
        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            $this->formErrors['attachments'] = ['Only JPEG, PNG, and PDF attachments are supported.'];

            return;
        }

        $this->attachments[] = [
            'data' => $data,
            'original_name' => (string) ($payload['original_name'] ?? 'attachment'),
            'mime_type' => $mimeType,
            'byte_size' => (int) ($payload['byte_size'] ?? 0),
        ];
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
        unset($this->formErrors['attachments']);
    }

    private function dispatchAttachmentFromPath(string $sourcePath): void
    {
        $resolvedPath = NativeMediaPath::resolve($sourcePath) ?? $sourcePath;
        if (! is_file($resolvedPath)) {
            Notification::make()->title('The selected attachment was not available.')->danger()->send();

            return;
        }

        $bytes = @file_get_contents($resolvedPath);
        if (! is_string($bytes) || $bytes === '') {
            Notification::make()->title('The selected attachment could not be read.')->danger()->send();

            return;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            Notification::make()->title('Only JPEG, PNG, and PDF attachments are supported.')->danger()->send();

            return;
        }

        $this->dispatch('crr-attachment-ready', attachment: [
            'data' => 'data:'.$mimeType.';base64,'.base64_encode($bytes),
            'original_name' => basename($resolvedPath),
            'mime_type' => $mimeType,
            'byte_size' => strlen($bytes),
        ]);
    }
}

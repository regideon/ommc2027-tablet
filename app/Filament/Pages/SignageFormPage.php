<?php

namespace App\Filament\Pages;

use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\FormKey;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Salescall;
use App\Services\Forms\SignageService;
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

class SignageFormPage extends Page
{
    use LocksRequestedSalescall;
    use StagesAttachments;

    protected string $view = 'filament.pages.signage-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/crr-signage';

    protected static ?string $title = '';

    public ?Signage $signage = null;

    public bool $isEdit = false;

    public string $name = '';

    public ?int $product_id = null;

    public ?int $type_id = null;

    public ?int $category = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $products = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $types = [];

    /** @var array<int|string, array<int, array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}>> */
    public array $attachments = [];

    /** @var array<int, int> */
    public array $removedAttachmentIds = [];

    /** @var array<string, int> */
    public array $pendingAttachment = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public string $masterLoadError = '';

    public function mount(SignageService $service): void
    {
        $service->programs();

        $this->masterLoadError = $service->programReadFailed()
            ? 'Signage products and types could not be refreshed. Reconnect and reopen this form.'
            : '';

        $this->products = SignageProduct::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SignageProduct $product): array => [
                'id' => $product->id,
                'name' => $product->name,
            ])
            ->values()
            ->all();

        $this->types = SignageType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SignageType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
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
            $signage = Signage::query()
                ->with(['attachments', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($signage instanceof Signage) {
                abort_unless($signage->isEditable(), 403);

                $this->signage = $signage;
                $this->isEdit = true;
                $this->name = $signage->name;
                $this->product_id = $signage->product_id;
                $this->type_id = $signage->type_id;
                $this->category = $signage->category->value;
                $this->salescall_uuid = $signage->salescall?->local_uuid;

                foreach ($signage->attachments->sortBy('id') as $attachment) {
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

        $this->applyRequestedSalescallLock();

        $this->category ??= SignageCategory::NEW->value;
    }

    public function save(SignageService $service): void
    {
        $this->formErrors = [];

        $input = [
            'salescall_uuid' => $this->salescall_uuid,
            'product_id' => $this->product_id,
            'type_id' => $this->type_id,
            'category' => $this->category,
            'name' => $this->name,
            'staged_attachments' => $this->stagedAttachments(),
            'remove_attachments' => $this->removedAttachmentIds,
        ];

        try {
            if ($this->isEdit && $this->signage instanceof Signage) {
                $service->update($this->signage, $input);
                $title = 'CRR Signage updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'CRR Signage saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local CRR Signage save failed.', [
                'signage_id' => $this->signage?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The CRR Signage could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::SIGNAGE->value]));
    }

    public function cancel(): void
    {
        $this->discardAllStagedAttachments();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::SIGNAGE->value]));
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageTypedAttachment(int $type, array $payload): void
    {
        unset($this->formErrors['attachments']);

        if (! in_array($type, SignageAttachmentType::values(), true)) {
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

<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawayPhoto;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Forms\Giveaway\GiveawaySubmissionItem;
use App\Models\Salescall;
use App\Services\Forms\GiveawayService;
use App\Support\NativeMediaPath;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Throwable;

class GiveawayFormPage extends Page
{
    use LocksRequestedSalescall;
    use RedirectsWithFormSync;
    use StagesAttachments;

    protected string $view = 'filament.pages.giveaway-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/giveaway';

    protected static ?string $title = '';

    public ?Giveaway $giveaway = null;

    public bool $isEdit = false;

    public string $name = '';

    public ?string $salescall_uuid = null;

    /** @var array<int, array{key: string, id: ?int, giveaway_item_id: int, category: int, category_label: string, name: string, answer: string}> */
    public array $items = [];

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    /** @var array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null */
    public ?array $signature = null;

    /** @var array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null */
    public ?array $existingSignature = null;

    /** @var array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null */
    public ?array $photo = null;

    /** @var array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null */
    public ?array $existingPhoto = null;

    public bool $removePhoto = false;

    public string $templateLoadError = '';

    public ?string $pendingPhotoCapture = null;

    public function mount(GiveawayService $service): void
    {
        $masterItems = $service->programs();
        $this->templateLoadError = $service->programReadFailed()
            ? 'Giveaway items could not be loaded. Reconnect and reopen this form.'
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

        $submissionId = filter_var(request()->query('submission'), FILTER_VALIDATE_INT);

        if ($submissionId !== false && $submissionId !== null) {
            $giveaway = Giveaway::query()
                ->with(['items.item', 'items.answerItem', 'signature', 'photo', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($giveaway instanceof Giveaway) {
                abort_unless($giveaway->is_editable, 403);

                $this->giveaway = $giveaway;
                $this->isEdit = true;
                $this->name = $giveaway->name;
                $this->salescall_uuid = $giveaway->salescall?->local_uuid;
                $this->items = $this->buildRows($masterItems, $giveaway);
                $this->signature = $this->signaturePayload($giveaway->signature);
                $this->existingSignature = $this->signature;
                $this->photo = $this->photoPayload($giveaway->photo);
                $this->existingPhoto = $this->photo;

                return;
            }
        }

        $this->applyRequestedSalescallLock();
        $this->items = $this->buildRows($masterItems, null);
    }

    public function save(GiveawayService $service): void
    {
        $this->formErrors = [];

        $input = [
            'name' => $this->name,
            'salescall_uuid' => $this->salescall_uuid,
            'items' => collect($this->items)->map(fn (array $row): array => [
                'id' => $row['id'],
                'giveaway_item_id' => $row['giveaway_item_id'],
                'answer' => $row['answer'],
            ])->all(),
            'signature_id' => ($this->signature['source'] ?? null) === 'existing' ? ($this->signature['id'] ?? null) : null,
            'staged_signature_id' => ($this->signature['source'] ?? null) === 'staged' ? ($this->signature['id'] ?? null) : null,
            'photo_id' => ($this->photo['source'] ?? null) === 'existing' ? ($this->photo['id'] ?? null) : null,
            'staged_photo_id' => ($this->photo['source'] ?? null) === 'staged' ? ($this->photo['id'] ?? null) : null,
            'remove_photo' => $this->removePhoto,
        ];

        try {
            if ($this->isEdit && $this->giveaway instanceof Giveaway) {
                $service->update($this->giveaway, $input);
                $title = 'Giveaway updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'Giveaway saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local Giveaway save failed.', [
                'giveaway_id' => $this->giveaway?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The Giveaway could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::GIVEAWAY->value);
    }

    public function cancel(): void
    {
        $this->discardStagedPhoto();
        $this->discardStagedSignature();
        $this->redirect(FormsPage::getUrl(['form' => FormKey::GIVEAWAY->value]));
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageSignature(array $payload): void
    {
        unset($this->formErrors['signature_id']);

        $data = $payload['data'] ?? null;

        if (! is_string($data) || $data === '') {
            return;
        }

        $staged = $this->stageAttachmentFromDataUri($data, (string) ($payload['original_name'] ?? 'signature.png'));

        if ($staged === null) {
            return;
        }

        $this->discardStagedSignature();
        $this->signature = ['source' => 'staged', ...$staged];
    }

    public function removeSignature(): void
    {
        $this->discardStagedSignature();
        $this->signature = $this->existingSignature;
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stagePhoto(array $payload): void
    {
        unset($this->formErrors['photo_id']);

        $data = $payload['data'] ?? null;

        if (! is_string($data) || $data === '') {
            return;
        }

        $staged = $this->stageAttachmentFromDataUri($data, (string) ($payload['original_name'] ?? 'photo.png'));

        if ($staged === null) {
            return;
        }

        $this->discardStagedPhoto();
        $this->photo = ['source' => 'staged', ...$staged];
        $this->removePhoto = false;
    }

    public function removePhoto(): void
    {
        $this->discardStagedPhoto();
        $this->photo = null;
        $this->removePhoto = true;
        unset($this->formErrors['photo_id']);
    }

    public function takePhoto(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $capture = Camera::getPhoto();
        $this->pendingPhotoCapture = $capture->getId();
        $capture->start();
    }

    public function pickPhotoFromGallery(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $picker = Camera::pickImages('image')->single();
        $this->pendingPhotoCapture = $picker->getId();
        $picker->start();
    }

    #[On('native:'.PhotoTaken::class)]
    public function onPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id === null || $id !== $this->pendingPhotoCapture) {
            return;
        }

        $this->pendingPhotoCapture = null;
        $this->stagePhotoFromPath($path);
    }

    /** @param array<int, mixed> $files */
    #[On('native:'.MediaSelected::class)]
    public function onPhotoSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id === null || $id !== $this->pendingPhotoCapture) {
            return;
        }

        $this->pendingPhotoCapture = null;

        if ($cancelled) {
            return;
        }

        if (! $success || empty($files)) {
            Notification::make()->title($error ?: 'Could not import the selected photo.')->danger()->send();

            return;
        }

        $path = NativeMediaPath::resolve($files[0]);

        if ($path === null) {
            Notification::make()->title('Selected photo path is invalid.')->danger()->send();

            return;
        }

        $this->stagePhotoFromPath($path);
    }

    #[On('native:'.PhotoCancelled::class)]
    public function onPhotoCancelled(?string $id = null): void
    {
        if ($id === $this->pendingPhotoCapture) {
            $this->pendingPhotoCapture = null;
        }
    }

    #[On('native:'.PermissionDenied::class)]
    public function onPhotoPermissionDenied(?string $id = null): void
    {
        if ($id === $this->pendingPhotoCapture) {
            $this->pendingPhotoCapture = null;
        }

        Notification::make()->title('Camera permission is required to add a photo.')->danger()->send();
    }

    /**
     * @param  Collection<int, GiveawayItem>  $masterItems
     * @return array<int, array{key: string, id: ?int, giveaway_item_id: int, category: int, category_label: string, name: string, answer: string}>
     */
    private function buildRows(Collection $masterItems, ?Giveaway $giveaway): array
    {
        $submissionItems = $giveaway?->items->keyBy('giveaway_item_id') ?? collect();

        return $masterItems
            ->sortBy(fn (GiveawayItem $item): string => sprintf('%02d-%05d', $item->category->value, $item->order))
            ->values()
            ->map(function (GiveawayItem $master, int $index) use ($submissionItems): array {
                $submission = $submissionItems->get($master->id);

                return [
                    'key' => 'master-'.$master->id,
                    'id' => $submission instanceof GiveawaySubmissionItem ? $submission->id : null,
                    'giveaway_item_id' => $master->id,
                    'category' => $master->category->value,
                    'category_label' => (string) $master->category->meta('title'),
                    'name' => $master->name,
                    'answer' => (string) ($submission?->answerItem?->answer ?? ''),
                ];
            })
            ->all();
    }

    private function signaturePayload(?GiveawaySignature $signature): ?array
    {
        if (! $signature instanceof GiveawaySignature) {
            return null;
        }

        return [
            'source' => 'existing',
            'id' => $signature->id,
            'original_name' => $signature->original_name,
            'mime_type' => $signature->mime_type,
            'url' => app(GiveawayService::class)->signatureUrl($signature),
        ];
    }

    private function photoPayload(?GiveawayPhoto $photo): ?array
    {
        if (! $photo instanceof GiveawayPhoto) {
            return null;
        }

        return [
            'source' => 'existing',
            'id' => $photo->id,
            'original_name' => $photo->original_name,
            'mime_type' => $photo->mime_type,
            'url' => app(GiveawayService::class)->photoUrl($photo),
        ];
    }

    private function stagePhotoFromPath(string $sourcePath): void
    {
        $path = NativeMediaPath::resolve($sourcePath) ?? $sourcePath;
        $staged = $this->stageAttachmentFromPath($path);

        if ($staged !== null) {
            $this->discardStagedPhoto();
            $this->photo = ['source' => 'staged', ...$staged];
            $this->removePhoto = false;
        }
    }

    private function discardStagedSignature(): void
    {
        if (($this->signature['source'] ?? null) === 'staged' && isset($this->signature['id'])) {
            $this->discardStagedAttachment((int) $this->signature['id']);
        }
    }

    private function discardStagedPhoto(): void
    {
        if (($this->photo['source'] ?? null) === 'staged' && isset($this->photo['id'])) {
            $this->discardStagedAttachment((int) $this->photo['id']);
        }
    }
}

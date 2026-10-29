<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeRequest;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\Salescall;
use App\Services\Forms\TradeRequestService;
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

class TradeRequestFormPage extends Page
{
    use LocksRequestedSalescall;
    use RedirectsWithFormSync;
    use StagesAttachments;

    protected string $view = 'filament.pages.trade-request-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/trade-request';

    protected static ?string $title = '';

    public ?TradeRequest $tradeRequest = null;

    public bool $isEdit = false;

    public ?int $product_id = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{trade_type_id: ?int, quantity: string, size: ?int, description: string}> */
    public array $items = [];

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

    public function mount(TradeRequestService $service): void
    {
        $service->programs();

        $this->masterLoadError = $service->programReadFailed()
            ? 'Trade products and types could not be refreshed. Reconnect and reopen this form.'
            : '';

        $this->products = TradeProduct::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TradeProduct $product): array => [
                'id' => $product->id,
                'name' => $product->name,
            ])
            ->values()
            ->all();

        $this->types = TradeType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TradeType $type): array => [
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
            $tradeRequest = TradeRequest::query()
                ->with(['items', 'attachments', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($tradeRequest instanceof TradeRequest) {
                abort_unless($tradeRequest->is_editable, 403);

                $this->tradeRequest = $tradeRequest;
                $this->isEdit = true;
                $this->product_id = $tradeRequest->product_id;
                $this->salescall_uuid = $tradeRequest->salescall?->local_uuid;

                foreach ($tradeRequest->items->sortBy('id') as $item) {
                    $this->items[] = [
                        'trade_type_id' => $item->trade_type_id,
                        'quantity' => (string) $item->quantity,
                        'size' => $item->size?->value,
                        'description' => (string) ($item->description ?? ''),
                    ];
                }

                foreach ($tradeRequest->attachments->sortBy('id') as $attachment) {
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

        if ($this->items === []) {
            $this->items = [$this->blankItemRow()];
        }

        $this->applyRequestedSalescallLock();
    }

    public function save(TradeRequestService $service): void
    {
        $this->formErrors = [];

        $items = [];

        foreach (array_values($this->items) as $row) {
            $items[] = [
                'trade_type_id' => $row['trade_type_id'] ?? null,
                'quantity' => $row['quantity'] ?? null,
                'size' => $row['size'] ?? null,
                'description' => $row['description'] ?? null,
            ];
        }

        $input = [
            'salescall_uuid' => $this->salescall_uuid,
            'product_id' => $this->product_id,
            'items' => $items,
            'staged_attachments' => $this->stagedAttachments(),
            'remove_attachments' => $this->removedAttachmentIds,
        ];

        try {
            if ($this->isEdit && $this->tradeRequest instanceof TradeRequest) {
                $service->update($this->tradeRequest, $input);
                $title = 'CRR - LSM updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'CRR - LSM saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local CRR - LSM save failed.', [
                'trade_request_id' => $this->tradeRequest?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The CRR - LSM could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::TRADE_REQUEST->value);
    }

    public function cancel(): void
    {
        $this->discardAllStagedAttachments();

        $this->redirect(FormsPage::getUrl(['form' => FormKey::TRADE_REQUEST->value]));
    }

    public function addItemRow(): void
    {
        $this->items[] = $this->blankItemRow();
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        $this->formErrors = [];
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageTypedAttachment(int $type, array $payload): void
    {
        unset($this->formErrors['attachments']);

        if (! in_array($type, TradeRequestAttachmentType::values(), true)) {
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
     * @return array{trade_type_id: null, quantity: string, size: null, description: string}
     */
    private function blankItemRow(): array
    {
        return [
            'trade_type_id' => null,
            'quantity' => '',
            'size' => null,
            'description' => '',
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

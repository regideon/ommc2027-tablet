<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Enums\TradeCheck\FieldType;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\Forms\TradeCheck\TradeCheckGroup;
use App\Models\Forms\TradeCheck\TradeCheckItem;
use App\Models\Forms\TradeCheck\TradeCheckSubgroup;
use App\Models\Salescall;
use App\Services\Forms\TradeCheckService;
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

class TradeCheckFormPage extends Page
{
    use LocksRequestedSalescall;
    use RedirectsWithFormSync;
    use StagesAttachments;

    protected string $view = 'filament.pages.trade-check-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/trade-check';

    protected static ?string $title = '';

    public ?TradeCheck $tradeCheck = null;

    public bool $isEdit = false;

    /** @var array<int, array{id: int, name: string}> */
    public array $templates = [];

    public ?int $trade_check_id = null;

    public ?string $salescall_uuid = null;

    /** @var array<int|string, mixed> */
    public array $answers = [];

    /** @var array<int, array<int, array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}>> */
    public array $attachments = [];

    /** @var array<int, int> */
    public array $removedAttachmentIds = [];

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public string $templateLoadError = '';

    /** @var array<string, int> */
    public array $pendingAttachment = [];

    /** @var array<int, array{id: int, name: string, category_label: string, description: ?string, subgroups: array<int, array{id: int, name: string, description: ?string, is_image_required: bool, items: array<int, array{id: int, name: string, description: ?string, type: int, type_label: string, is_required: bool, options: array<int, mixed>}>}>}> */
    public array $groups = [];

    public function mount(TradeCheckService $service): void
    {
        $this->templates = $service->programs()
            ->map(fn (TradeCheck $template): array => [
                'id' => $template->id,
                'name' => $template->name,
            ])
            ->values()
            ->all();

        $this->templateLoadError = $service->programReadFailed()
            ? 'Trade Check templates could not be loaded. Reconnect and reopen this form.'
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
            $tradeCheck = TradeCheck::query()
                ->with(['group.subgroups.items.answerItem', 'answers', 'attachments', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($tradeCheck instanceof TradeCheck) {
                abort_unless($tradeCheck->isEditableBy((int) auth()->id()), 403);

                $this->tradeCheck = $tradeCheck;
                $this->isEdit = true;
                $this->trade_check_id = $tradeCheck->id;
                $this->salescall_uuid = $tradeCheck->salescall?->local_uuid;

                $attachmentsBySubgroup = $tradeCheck->attachments->groupBy('subgroup_id');
                $service = app(TradeCheckService::class);

                foreach ($tradeCheck->group->flatMap->subgroups->flatMap->items as $item) {
                    $this->answers[$item->id] = $this->normalizeAnswer($item, $item->answerItem?->answer);
                }

                foreach ($tradeCheck->group->flatMap->subgroups as $subgroup) {
                    $this->attachments[$subgroup->id] = ($attachmentsBySubgroup->get($subgroup->id) ?? collect())
                        ->map(fn (TradeCheckAttachment $attachment): array => [
                            'source' => 'existing',
                            'id' => $attachment->id,
                            'original_name' => $attachment->original_name,
                            'mime_type' => $attachment->mime_type,
                            'url' => $service->attachmentUrl($attachment),
                        ])
                        ->values()
                        ->all();
                }

                $this->groups = $this->mapGroups($tradeCheck->group);

                return;
            }
        }

        $this->applyRequestedSalescallLock();

        $templateId = filter_var(request()->query('template'), FILTER_VALIDATE_INT);

        $template = $templateId !== false && $templateId !== null
            ? TradeCheck::query()->with('group.subgroups.items')->find($templateId)
            : TradeCheck::query()->with('group.subgroups.items')->orderBy('id')->first();

        if ($template instanceof TradeCheck) {
            $this->trade_check_id = $template->id;

            foreach ($template->group->flatMap->subgroups->flatMap->items as $item) {
                $this->answers[$item->id] = '';
            }

            foreach ($template->group->flatMap->subgroups as $subgroup) {
                $this->attachments[$subgroup->id] = [];
            }

            $this->groups = $this->mapGroups($template->group);
        } else {
            $this->templateLoadError = 'No Trade Check template is available locally yet.';
        }
    }

    public function save(TradeCheckService $service): void
    {
        $this->formErrors = [];

        $input = [
            'trade_check_id' => $this->trade_check_id,
            'salescall_uuid' => $this->salescall_uuid,
            'answers' => $this->answers,
            'attachments' => $this->stagedAttachmentIds(),
            'remove_attachments' => $this->removedAttachmentIds,
        ];

        try {
            if ($this->isEdit && $this->tradeCheck instanceof TradeCheck) {
                $service->update($this->tradeCheck, $input);
                $title = 'Trade Check updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'Trade Check saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (Throwable $exception) {
            Log::error('Local Trade Check save failed.', [
                'trade_check_id' => $this->tradeCheck?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The Trade Check could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::TRADE_CHECK->value);
    }

    public function cancel(): void
    {
        $this->discardAllStagedAttachments();
        $this->redirect(FormsPage::getUrl(['form' => FormKey::TRADE_CHECK->value]));
    }

    public function stageSubgroupAttachment(int $subgroupId, array $payload): void
    {
        unset($this->formErrors['attachments.'.$subgroupId]);

        $data = $payload['data'] ?? null;
        if (! is_string($data) || $data === '') {
            return;
        }

        if (! $this->canStageMoreForSubgroup($subgroupId)) {
            return;
        }

        $staged = $this->stageAttachmentFromDataUri($data, (string) ($payload['original_name'] ?? 'attachment'));

        if ($staged !== null) {
            $this->attachments[$subgroupId][] = ['source' => 'staged', ...$staged];
        }
    }

    public function removeSubgroupAttachment(int $subgroupId, int $index): void
    {
        $attachment = $this->attachments[$subgroupId][$index] ?? null;

        if (is_array($attachment) && isset($attachment['id'])) {
            if (($attachment['source'] ?? null) === 'staged') {
                $this->discardStagedAttachment((int) $attachment['id']);
            } else {
                $this->removedAttachmentIds[] = (int) $attachment['id'];
            }
        }

        unset($this->attachments[$subgroupId][$index]);
        $this->attachments[$subgroupId] = array_values($this->attachments[$subgroupId] ?? []);
        unset($this->formErrors['attachments.'.$subgroupId]);
    }

    public function takeAttachmentPhoto(int $subgroupId): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $capture = Camera::getPhoto();
        $this->pendingAttachment[$capture->getId()] = $subgroupId;
        $capture->start();
    }

    public function pickAttachmentFromGallery(int $subgroupId): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $picker = Camera::pickImages('image')->single();
        $this->pendingAttachment[$picker->getId()] = $subgroupId;
        $picker->start();
    }

    #[On('native:'.PhotoTaken::class)]
    public function onAttachmentPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        $subgroupId = $this->pendingAttachment[$id];
        unset($this->pendingAttachment[$id]);

        $this->stageCapturedAttachment($path, $subgroupId);
    }

    /** @param array<int, mixed> $files */
    #[On('native:'.MediaSelected::class)]
    public function onAttachmentMediaSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingAttachment[$id])) {
            return;
        }

        $subgroupId = $this->pendingAttachment[$id];
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

        $this->stageCapturedAttachment($path, $subgroupId);
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
     * @param  Collection<int, TradeCheckGroup>  $groups
     * @return array<int, array{id: int, name: string, category_label: string, description: ?string, subgroups: array<int, array{id: int, name: string, description: ?string, is_image_required: bool, items: array<int, array{id: int, name: string, description: ?string, type: int, type_label: string, is_required: bool, options: array<int, mixed>}>}>}>
     */
    private function mapGroups(Collection $groups): array
    {
        return $groups
            ->sortBy('order')
            ->values()
            ->map(fn (TradeCheckGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'category_label' => (string) $group->category->meta('title'),
                'description' => $group->description,
                'subgroups' => $group->subgroups
                    ->sortBy('order')
                    ->values()
                    ->map(fn (TradeCheckSubgroup $subgroup): array => [
                        'id' => $subgroup->id,
                        'name' => $subgroup->name,
                        'description' => $subgroup->description,
                        'is_image_required' => $subgroup->is_image_required,
                        'items' => $subgroup->items
                            ->sortBy('order')
                            ->values()
                            ->map(fn (TradeCheckItem $item): array => [
                                'id' => $item->id,
                                'name' => $item->name,
                                'description' => $item->description,
                                'type' => $item->type->value,
                                'type_label' => (string) $item->type->meta('title'),
                                'is_required' => $item->is_required,
                                'options' => $this->itemOptions($item),
                            ])
                            ->all(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Boolean items without explicit options still render Yes/No, and the
     * answer must always be one of the item's options.
     *
     * @return array<int, mixed>
     */
    private function itemOptions(TradeCheckItem $item): array
    {
        $options = is_array($item->options)
            ? array_values(array_filter($item->options, static fn (mixed $option): bool => $option !== null && $option !== ''))
            : [];

        if ($item->type === FieldType::BOOLEAN && $options === []) {
            return ['Yes', 'No'];
        }

        return $options;
    }

    /**
     * Legacy boolean answers were stored as 1/0; render them as Yes/No so they
     * match the options and pass validation when re-saved.
     */
    private function normalizeAnswer(TradeCheckItem $item, mixed $value): mixed
    {
        if ($value === null || $item->type !== FieldType::BOOLEAN) {
            return $value;
        }

        return match ((string) $value) {
            '1' => 'Yes',
            '0' => 'No',
            default => $value,
        };
    }

    private function stageCapturedAttachment(string $sourcePath, int $subgroupId): void
    {
        if (! $this->canStageMoreForSubgroup($subgroupId)) {
            return;
        }

        $path = NativeMediaPath::resolve($sourcePath) ?? $sourcePath;
        $staged = $this->stageAttachmentFromPath($path);

        if ($staged !== null) {
            $this->attachments[$subgroupId][] = ['source' => 'staged', ...$staged];
        }
    }

    private function canStageMoreForSubgroup(int $subgroupId): bool
    {
        if (count($this->attachments[$subgroupId] ?? []) >= 10) {
            $this->formErrors['attachments.'.$subgroupId] = ['A Trade Check subgroup may have at most 10 attachments.'];

            return false;
        }

        return true;
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function stagedAttachmentIds(): array
    {
        $ids = [];

        foreach ($this->attachments as $subgroupId => $list) {
            $stagedIds = array_values(array_filter(array_map(
                static fn (mixed $attachment): int => ($attachment['source'] ?? null) === 'staged'
                    ? (int) ($attachment['id'] ?? 0)
                    : 0,
                is_array($list) ? $list : [],
            )));

            if ($stagedIds !== []) {
                $ids[$subgroupId] = $stagedIds;
            }
        }

        return $ids;
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

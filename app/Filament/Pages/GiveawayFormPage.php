<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Enums\Giveaway\GiveawayCategory;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\StagesAttachments;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawayItem;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Salescall;
use App\Services\Forms\GiveawayService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GiveawayFormPage extends Page
{
    use LocksRequestedSalescall;
    use StagesAttachments;

    protected string $view = 'filament.pages.giveaway-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/giveaway';

    protected static ?string $title = '';

    public ?Giveaway $giveaway = null;

    public bool $isEdit = false;

    public string $name = '';

    public ?string $salescall_uuid = null;

    /** @var array<int, array{key: string, id: ?int, category: ?int, name: string, answer: string, signature: array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null, existing_signature: array{source: string, id: int, original_name: ?string, mime_type: ?string, url: ?string}|null}> */
    public array $items = [];

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    /** @var array<int, string> */
    public array $categories = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public function mount(): void
    {
        $this->categories = collect(GiveawayCategory::cases())
            ->mapWithKeys(fn (GiveawayCategory $category): array => [$category->value => (string) $category->meta('title')])
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
            $giveaway = Giveaway::query()
                ->with(['items.answerItem', 'answers.signature', 'salescall'])
                ->where('submitted_by', auth()->id())
                ->find($submissionId);

            if ($giveaway instanceof Giveaway) {
                abort_unless($giveaway->isEditableBy((int) auth()->id()), 403);

                $this->giveaway = $giveaway;
                $this->isEdit = true;
                $this->name = $giveaway->name;
                $this->salescall_uuid = $giveaway->salescall?->local_uuid;

                $answers = $giveaway->answers->keyBy('item_id');
                $service = app(GiveawayService::class);

                $this->items = $giveaway->items
                    ->sortBy('id')
                    ->values()
                    ->map(function (GiveawayItem $item) use ($answers, $service): array {
                        $signature = $answers->get($item->id)?->signature;
                        $existing = $signature instanceof GiveawaySignature ? [
                            'source' => 'existing',
                            'id' => $signature->id,
                            'original_name' => $signature->original_name,
                            'mime_type' => $signature->mime_type,
                            'url' => $service->signatureUrl($signature),
                        ] : null;

                        return [
                            'key' => 'item-'.$item->id,
                            'id' => $item->id,
                            'category' => $item->category->value,
                            'name' => $item->name,
                            'answer' => (string) ($item->answerItem?->answer ?? ''),
                            'signature' => $existing,
                            'existing_signature' => $existing,
                        ];
                    })
                    ->all();

                if ($this->items === []) {
                    $this->items = [$this->blankItemRow()];
                }

                return;
            }
        }

        $this->applyRequestedSalescallLock();

        $this->items = [$this->blankItemRow()];
    }

    public function save(GiveawayService $service): void
    {
        $this->formErrors = [];

        $items = [];

        foreach (array_values($this->items) as $row) {
            $signature = is_array($row['signature'] ?? null) ? $row['signature'] : null;

            $items[] = [
                'id' => $row['id'] ?? null,
                'category' => $row['category'] ?? null,
                'name' => $row['name'] ?? '',
                'answer' => $row['answer'] ?? null,
                'signature_id' => ($signature['source'] ?? null) === 'existing' ? ($signature['id'] ?? null) : null,
                'staged_signature_id' => ($signature['source'] ?? null) === 'staged' ? ($signature['id'] ?? null) : null,
            ];
        }

        $input = [
            'name' => $this->name,
            'salescall_uuid' => $this->salescall_uuid,
            'items' => $items,
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

        $this->redirect(FormsPage::getUrl(['form' => FormKey::GIVEAWAY->value]));
    }

    public function cancel(): void
    {
        foreach ($this->items as $row) {
            if (is_array($row)) {
                $this->discardRowSignature($row);
            }
        }

        $this->redirect(FormsPage::getUrl(['form' => FormKey::GIVEAWAY->value]));
    }

    public function addItemRow(): void
    {
        $this->items[] = $this->blankItemRow();
    }

    public function removeItemRow(string $key): void
    {
        $index = $this->rowIndex($key);

        if ($index === null) {
            return;
        }

        $this->discardRowStagedSignature($index);

        unset($this->items[$index]);
        $this->items = array_values($this->items);

        $this->formErrors = [];
    }

    public function removeSignature(string $key): void
    {
        $index = $this->rowIndex($key);

        if ($index === null) {
            return;
        }

        $this->discardRowStagedSignature($index);
        $this->items[$index]['signature'] = $this->items[$index]['existing_signature'] ?? null;

        unset($this->formErrors['items.'.$index.'.signature_id']);
    }

    /**
     * @param  array{data?: string, original_name?: string}  $payload
     */
    public function stageSignature(string $key, array $payload): void
    {
        $index = $this->rowIndex($key);

        if ($index === null) {
            return;
        }

        unset($this->formErrors['items.'.$index.'.signature_id']);

        $data = $payload['data'] ?? null;

        if (! is_string($data) || $data === '') {
            return;
        }

        $staged = $this->stageAttachmentFromDataUri($data, (string) ($payload['original_name'] ?? 'signature.png'));

        if ($staged === null) {
            return;
        }

        $this->discardRowStagedSignature($index);
        $this->items[$index]['signature'] = ['source' => 'staged', ...$staged];
    }

    private function rowIndex(string $key): ?int
    {
        foreach ($this->items as $index => $row) {
            if (($row['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    private function discardRowStagedSignature(int $index): void
    {
        $row = $this->items[$index] ?? null;

        if (is_array($row)) {
            $this->discardRowSignature($row);
        }
    }

    /**
     * @return array{key: string, id: ?int, category: int, name: string, answer: string, signature: null, existing_signature: null}
     */
    private function blankItemRow(): array
    {
        return [
            'key' => 'new-'.Str::uuid(),
            'id' => null,
            'category' => GiveawayCategory::GC->value,
            'name' => '',
            'answer' => '',
            'signature' => null,
            'existing_signature' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function discardRowSignature(array $row): void
    {
        $signature = $row['signature'] ?? null;

        if (is_array($signature) && ($signature['source'] ?? null) === 'staged' && isset($signature['id'])) {
            $this->discardStagedAttachment((int) $signature['id']);
        }
    }
}

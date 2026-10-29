<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Filament\Concerns\LocksRequestedSalescall;
use App\Filament\Concerns\RedirectsWithFormSync;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingMechanic;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\Marketing\MarketingRealization;
use App\Models\Forms\Marketing\MarketingVolume;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Services\Forms\MarketingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MarketingFormPage extends Page
{
    use LocksRequestedSalescall;
    use RedirectsWithFormSync;

    protected string $view = 'filament.pages.marketing-form-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'forms/marketing';

    protected static ?string $title = '';

    public ?Marketing $marketing = null;

    public bool $isEdit = false;

    /** @var array<int, array{id: int, name: string}> */
    public array $programs = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $regionTypes = [];

    /** @var array<int, array{uuid: ?string, label: string}> */
    public array $salescalls = [];

    public string $programLoadError = '';

    public ?int $program_id = null;

    public ?int $region_type_id = null;

    public ?string $duration_start_at = null;

    public ?string $duration_end_at = null;

    public ?string $objective = null;

    public ?string $name = null;

    public ?string $mechanics_description = null;

    public ?string $salescall_uuid = null;

    /** @var array<int, array{before_program: ?string, during_program: ?string, volume: ?string}> */
    public array $volumes = [];

    /** @var array<int, array{stage: ?int, start_at: ?string, end_at: ?string, volume: ?string}> */
    public array $realizations = [];

    /** @var array<int, array{period: ?string, unit_price: ?string, sub_total: ?string}> */
    public array $mechanics = [];

    /** @var array<string, array<int, string>> */
    public array $formErrors = [];

    public function mount(MarketingService $service): void
    {
        $this->programs = $service->programs()
            ->map(fn (MarketingProgram $program): array => [
                'id' => $program->id,
                'name' => $program->name,
            ])
            ->values()
            ->all();

        $this->programLoadError = $service->programReadFailed()
            ? 'Marketing programs could not be loaded. Reconnect and reopen this form.'
            : '';

        $this->regionTypes = RegionType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (RegionType $regionType): array => [
                'id' => $regionType->id,
                'name' => $regionType->name,
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

        $marketingId = filter_var(request()->query('marketing'), FILTER_VALIDATE_INT);

        if ($marketingId !== false && $marketingId !== null) {
            $marketing = Marketing::query()
                ->with(['salescall', 'volumes', 'realizations', 'mechanics'])
                ->where('submitted_by', auth()->id())
                ->find($marketingId);

            if ($marketing instanceof Marketing) {
                abort_unless($marketing->is_editable, 403);

                $this->marketing = $marketing;
                $this->isEdit = true;
                $this->program_id = $marketing->program_id;
                $this->region_type_id = $marketing->region_type_id;
                $this->duration_start_at = $marketing->duration_start_at?->toDateString();
                $this->duration_end_at = $marketing->duration_end_at?->toDateString();
                $this->objective = $marketing->objective;
                $this->name = $marketing->name;
                $this->mechanics_description = $marketing->mechanics_description;
                $this->salescall_uuid = $marketing->salescall?->local_uuid;

                $this->volumes = $marketing->volumes
                    ->map(fn (MarketingVolume $volume): array => [
                        'before_program' => (string) $volume->before_program,
                        'during_program' => (string) $volume->during_program,
                        'volume' => (string) $volume->volume,
                    ])
                    ->values()
                    ->all();

                $this->realizations = $marketing->realizations
                    ->map(fn (MarketingRealization $realization): array => [
                        'stage' => $realization->stage->value,
                        'start_at' => $realization->start_at?->toDateString(),
                        'end_at' => $realization->end_at?->toDateString(),
                        'volume' => (string) $realization->volume,
                    ])
                    ->values()
                    ->all();

                $this->mechanics = $marketing->mechanics
                    ->map(fn (MarketingMechanic $mechanic): array => [
                        'period' => $mechanic->period?->toDateString(),
                        'unit_price' => (string) $mechanic->unit_price,
                        'sub_total' => (string) $mechanic->sub_total,
                    ])
                    ->values()
                    ->all();

                return;
            }
        }

        $this->applyRequestedSalescallLock();

        $this->duration_start_at = now()->toDateString();
        $this->duration_end_at = now()->addMonth()->toDateString();

        $this->volumes = [$this->emptyVolume()];
        $this->realizations = [$this->emptyRealization()];
        $this->mechanics = [$this->emptyMechanic()];
    }

    public function addVolume(): void
    {
        $this->volumes[] = $this->emptyVolume();
    }

    public function removeVolume(int $index): void
    {
        unset($this->volumes[$index]);
        $this->volumes = array_values($this->volumes);
    }

    public function addRealization(): void
    {
        $this->realizations[] = $this->emptyRealization();
    }

    public function removeRealization(int $index): void
    {
        unset($this->realizations[$index]);
        $this->realizations = array_values($this->realizations);
    }

    public function addMechanic(): void
    {
        $this->mechanics[] = $this->emptyMechanic();
    }

    public function removeMechanic(int $index): void
    {
        unset($this->mechanics[$index]);
        $this->mechanics = array_values($this->mechanics);
    }

    public function save(MarketingService $service): void
    {
        $this->formErrors = [];

        $input = [
            'program_id' => $this->program_id,
            'region_type_id' => $this->region_type_id,
            'duration_start_at' => $this->duration_start_at,
            'duration_end_at' => $this->duration_end_at,
            'objective' => $this->objective,
            'name' => $this->name,
            'mechanics_description' => $this->mechanics_description,
            'salescall_uuid' => $this->salescall_uuid,
            'volumes' => $this->volumes,
            'realizations' => $this->realizations,
            'mechanics' => $this->mechanics,
        ];

        try {
            if ($this->isEdit && $this->marketing instanceof Marketing) {
                $service->update($this->marketing, $input);
                $title = 'Marketing Recommendation updated locally.';
            } else {
                $service->create(auth()->user(), $input);
                $title = 'Marketing Recommendation saved locally.';
            }
        } catch (ValidationException $exception) {
            $this->formErrors = $exception->errors();

            return;
        } catch (\Throwable $exception) {
            Log::error('Local Marketing Recommendation save failed.', [
                'marketing_id' => $this->marketing?->id,
                'message' => $exception->getMessage(),
            ]);

            $this->formErrors = [
                'form' => ['The Marketing Recommendation could not be saved. Please try again.'],
            ];

            return;
        }

        Notification::make()->title($title)->success()->send();

        $this->redirectAfterFormSave(FormKey::MARKETING->value);
    }

    public function cancel(): void
    {
        $this->redirect(FormsPage::getUrl(['form' => FormKey::MARKETING->value]));
    }

    /**
     * @return array{before_program: ?string, during_program: ?string, volume: ?string}
     */
    private function emptyVolume(): array
    {
        return ['before_program' => null, 'during_program' => null, 'volume' => null];
    }

    /**
     * @return array{stage: ?int, start_at: ?string, end_at: ?string, volume: ?string}
     */
    private function emptyRealization(): array
    {
        return ['stage' => null, 'start_at' => null, 'end_at' => null, 'volume' => null];
    }

    /**
     * @return array{period: ?string, unit_price: ?string, sub_total: ?string}
     */
    private function emptyMechanic(): array
    {
        return ['period' => null, 'unit_price' => null, 'sub_total' => null];
    }
}

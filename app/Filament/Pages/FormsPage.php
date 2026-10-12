<?php

namespace App\Filament\Pages;

use App\Enums\FormKey;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\User;
use App\Services\Forms\CrrReadService;
use App\Services\Forms\LocalCrrCreationService;
use App\Services\SyncService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

class FormsPage extends Page
{
    protected string $view = 'filament.pages.forms-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 250;

    public string $selectedForm = 'crr';

    /** @var array<int, array<string, mixed>> */
    public array $crrRows = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $crrPrograms = [];

    public string $crrLoadError = '';

    public ?string $selectedCrrKey = null;

    public string $actionError = '';

    public bool $syncing = false;

    public string $syncMessage = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(CrrReadService $service): void
    {
        $this->loadCrrs($service);
    }

    public function loadCrrs(CrrReadService $service): void
    {
        try {
            $this->crrPrograms = $service->crrPrograms()
                ->map(fn (CrrProgram $program): array => [
                    'id' => $program->id,
                    'name' => $program->name,
                ])
                ->values()
                ->all();

            $this->crrRows = $service->crrForCurrentUser()->all();
            $this->crrLoadError = $service->globalReadFailed()
                ? 'Some CRR submissions could not be loaded. Refresh to try again.'
                : '';
        } catch (Throwable $exception) {
            report($exception);
            $this->crrPrograms = [];
            $this->crrRows = [];
            $this->crrLoadError = 'CRRs could not be loaded. Refresh to try again.';
        }
    }

    public function selectForm(string $key): void
    {
        $form = FormKey::tryFrom($key);

        if (! $form || ! $form->available()) {
            return;
        }

        if ($this->selectedForm === $form->value) {
            return;
        }

        $this->selectedForm = $form->value;
        $this->clearCrrSelection();
    }

    public function selectCrr(?string $key): void
    {
        if ($key === null || $key === '') {
            $this->clearCrrSelection();

            return;
        }

        if (! collect($this->crrRows)->contains(fn (array $row): bool => $this->rowKey($row) === $key)) {
            return;
        }

        $this->selectedCrrKey = $key;
        $this->actionError = '';
    }

    public function clearCrrSelection(): void
    {
        $this->selectedCrrKey = null;
        $this->actionError = '';
    }

    public function syncNow(SyncService $sync, CrrReadService $read): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;
        $this->syncMessage = '';
        $this->actionError = '';

        try {
            if (! $sync->isReachable()) {
                $this->syncMessage = 'You are offline. Pending CRRs will sync when reconnected.';
            } else {
                $this->syncMessage = $sync->push()->message;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->syncMessage = 'Sync could not be completed. Try again later.';
        } finally {
            $this->syncing = false;
            $this->loadCrrs($read);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectedCrr(): ?array
    {
        if ($this->selectedCrrKey === null) {
            return null;
        }

        foreach ($this->crrRows as $row) {
            if ($this->rowKey($row) === $this->selectedCrrKey) {
                return $row;
            }
        }

        return null;
    }

    public function editSelectedCrr(LocalCrrCreationService $service): void
    {
        $row = $this->selectedCrr();

        if (! $row || ! ($row['is_editable'] ?? false)) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $crr = $this->resolveLocalCrr($service, $row, $user);
        } catch (ValidationException $exception) {
            $this->actionError = (string) (collect($exception->errors())->flatten()->first()
                ?? 'This CRR could not be opened for editing.');

            return;
        }

        $this->redirect(CrrFormPage::getUrl(['crr' => $crr->id]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLocalCrr(LocalCrrCreationService $service, array $row, User $user): Crr
    {
        if (! empty($row['id'])) {
            return Crr::query()->findOrFail($row['id']);
        }

        if (! empty($row['server_id'])) {
            $existing = Crr::query()->where('server_id', $row['server_id'])->first();

            if ($existing) {
                return $existing;
            }
        }

        return $service->adopt($user, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowKey(array $row): string
    {
        return ! empty($row['server_id'])
            ? 'server:'.$row['server_id']
            : 'local:'.$row['local_uuid'];
    }
}

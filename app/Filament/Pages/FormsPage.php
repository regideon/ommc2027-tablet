<?php

namespace App\Filament\Pages;

use App\Contracts\FormSubmissionService;
use App\Enums\FormKey;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\Marketing\Marketing;
use App\Models\User;
use App\Services\Forms\CrrService;
use App\Services\Forms\MarketingService;
use App\Services\SyncService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
    public array $rows = [];

    public string $formLoadError = '';

    public ?string $selectedRowKey = null;

    public string $actionError = '';

    public bool $syncing = false;

    public string $syncMessage = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $this->formLoadError = '';
        $this->actionError = '';

        try {
            $service = $this->formService();
            $service->programs();
            $this->rows = $service->submissions()->all();
            $this->formLoadError = $service->globalReadFailed()
                ? 'Some submissions could not be loaded. Refresh to try again.'
                : '';
        } catch (Throwable $exception) {
            report($exception);
            $this->rows = [];
            $this->formLoadError = 'Submissions could not be loaded. Refresh to try again.';
        }
    }

    public function selectForm(string $key): void
    {
        $form = FormKey::tryFrom($key);

        if (! $form || ! $form->available() || $this->selectedForm === $form->value) {
            return;
        }

        $this->selectedForm = $form->value;
        $this->clearRowSelection();
        $this->loadRows();
    }

    public function selectRow(?string $key): void
    {
        if ($key === null || $key === '') {
            $this->clearRowSelection();

            return;
        }

        if (! collect($this->rows)->contains(fn (array $row): bool => $this->rowKey($row) === $key)) {
            return;
        }

        $this->selectedRowKey = $key;
        $this->actionError = '';
    }

    public function clearRowSelection(): void
    {
        $this->selectedRowKey = null;
        $this->actionError = '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectedRow(): ?array
    {
        if ($this->selectedRowKey === null) {
            return null;
        }

        foreach ($this->rows as $row) {
            if ($this->rowKey($row) === $this->selectedRowKey) {
                return $row;
            }
        }

        return null;
    }

    public function syncNow(SyncService $sync): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;
        $this->syncMessage = '';
        $this->actionError = '';

        try {
            if (! $sync->isReachable()) {
                $this->syncMessage = 'You are offline. Pending submissions will sync when reconnected.';
            } else {
                $this->syncMessage = $sync->push()->message;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->syncMessage = 'Sync could not be completed. Try again later.';
        } finally {
            $this->syncing = false;
            $this->loadRows();
        }
    }

    public function editSelectedRow(): void
    {
        $row = $this->selectedRow();

        if (! $row || ! ($row['is_editable'] ?? false)) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $submission = $this->resolveLocalSubmission($row, $user);
        } catch (ValidationException $exception) {
            $this->actionError = (string) (collect($exception->errors())->flatten()->first()
                ?? 'This submission could not be opened for editing.');

            return;
        }

        $this->redirect(match ($this->selectedForm) {
            FormKey::CRR->value => CrrFormPage::getUrl(['crr' => $submission->getKey()]),
            FormKey::MARKETING->value => MarketingFormPage::getUrl(['marketing' => $submission->getKey()]),
            default => FormsPage::getUrl(),
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLocalSubmission(array $row, User $user): Model
    {
        $modelClass = $this->modelClass();

        if (! empty($row['id'])) {
            return $modelClass::query()->findOrFail($row['id']);
        }

        if (! empty($row['server_id'])) {
            $existing = $modelClass::query()->where('server_id', $row['server_id'])->first();

            if ($existing) {
                return $existing;
            }
        }

        return $this->formService()->adopt($user, $row);
    }

    private function formService(): FormSubmissionService
    {
        return match ($this->selectedForm) {
            FormKey::CRR->value => app(CrrService::class),
            FormKey::MARKETING->value => app(MarketingService::class),
            default => throw new RuntimeException('This form is not available yet.'),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(): string
    {
        return match ($this->selectedForm) {
            FormKey::CRR->value => Crr::class,
            FormKey::MARKETING->value => Marketing::class,
            default => throw new RuntimeException('This form is not available yet.'),
        };
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

<?php

namespace App\Filament\Pages;

use App\Models\ExpenseType;
use App\Models\Salescall;
use App\Services\LocalExpenseCreationService;
use App\Support\NativeMediaPath;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;

class ExpenseCreatePage extends Page
{
    protected string $view = 'filament.pages.expense-create-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'expenses/create';

    protected static ?string $title = 'Add Expense';

    public Salescall $salescall;

    public ExpenseType $expenseType;

    /** @var array<string, bool> */
    public array $pendingExpenseAttachment = [];

    public function mount(): void
    {
        $salescallId = filter_var(request()->query('salescall'), FILTER_VALIDATE_INT);
        $typeCode = trim((string) request()->query('type'));

        abort_unless($salescallId && $typeCode !== '', 404);

        $this->salescall = Salescall::query()
            ->with('customer')
            ->where('created_by', auth()->id())
            ->findOrFail($salescallId);

        $this->expenseType = ExpenseType::query()
            ->where('code', $typeCode)
            ->where('is_enabled', true)
            ->firstOrFail();
    }

    protected function getViewData(): array
    {
        return [
            'salescallContext' => [
                'id' => $this->salescall->id,
                'name' => $this->salescall->customer?->name ?? '—',
                'location' => $this->salescall->customer?->address ?? '',
                'lat' => $this->salescall->customer?->latitude,
                'lng' => $this->salescall->customer?->longitude,
                'ref_number' => $this->salescall->ref_number,
            ],
            'selectedExpenseType' => [
                'code' => $this->expenseType->code,
                'label' => $this->expenseType->label,
            ],
        ];
    }

    /**
     * Persist a supported Expense locally from the authorized originating Sales Call.
     *
     * @param array<string, mixed> $form
     * @return array{ok: bool, errors?: array<string, array<int, string>>}
     */
    public function saveExpense(array $form, array $attachments = []): array
    {
        try {
            $expense = app(LocalExpenseCreationService::class)->createFromSalescall(
                $this->salescall,
                auth()->user(),
                array_merge($form, [
                    'expense_type_code' => $this->expenseType->code,
                    'attachments' => $attachments,
                ]),
            );

            Notification::make()->title('Expense saved locally.')->success()->send();

            $this->redirectToSalescall();

            return ['ok' => true, 'expense_id' => $expense->id];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => $exception->errors()];
        } catch (\Throwable $exception) {
            Log::error('Local Expense creation failed.', [
                'salescall_id' => $this->salescall->id,
                'expense_type_code' => $this->expenseType->code,
                'message' => $exception->getMessage(),
            ]);

            Notification::make()->title('Could not save the Expense locally.')->danger()->send();

            return ['ok' => false, 'errors' => [
                'form' => ['The Expense could not be saved. Please try again.'],
            ]];
        }
    }

    public function cancelExpense(): void
    {
        $this->redirectToSalescall();
    }

    private function redirectToSalescall(): void
    {
        $this->redirect(SalescallPage::getUrl(['call' => $this->salescall->id]));
    }

    public function takeExpenseAttachmentPhoto(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $capture = Camera::getPhoto();
        $this->pendingExpenseAttachment[$capture->getId()] = true;
        $capture->start();
    }

    public function pickExpenseAttachmentFromGallery(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $picker = Camera::pickImages('image')->single();
        $this->pendingExpenseAttachment[$picker->getId()] = true;
        $picker->start();
    }

    #[On('native:'.PhotoTaken::class)]
    public function onExpenseAttachmentPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingExpenseAttachment[$id])) {
            return;
        }

        unset($this->pendingExpenseAttachment[$id]);
        $this->dispatchExpenseAttachmentFromPath($path);
    }

    #[On('native:'.MediaSelected::class)]
    public function onExpenseAttachmentMediaSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingExpenseAttachment[$id])) {
            return;
        }

        unset($this->pendingExpenseAttachment[$id]);

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

        $this->dispatchExpenseAttachmentFromPath($path);
    }

    #[On('native:'.PhotoCancelled::class)]
    public function onExpenseAttachmentCancelled(bool $cancelled = true, ?string $id = null): void
    {
        if ($id !== null) {
            unset($this->pendingExpenseAttachment[$id]);
        }
    }

    #[On('native:'.PermissionDenied::class)]
    public function onExpenseAttachmentPermissionDenied(string $action = 'photo', ?string $id = null): void
    {
        if ($id === null || ! isset($this->pendingExpenseAttachment[$id])) {
            return;
        }

        unset($this->pendingExpenseAttachment[$id]);
        Notification::make()->title('Camera permission is required to add an attachment.')->danger()->send();
    }

    private function dispatchExpenseAttachmentFromPath(string $sourcePath): void
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

        $this->dispatch('expense-attachment-ready', attachment: [
            'data' => 'data:'.$mimeType.';base64,'.base64_encode($bytes),
            'original_name' => basename($resolvedPath),
            'mime_type' => $mimeType,
            'byte_size' => strlen($bytes),
        ]);
    }
}

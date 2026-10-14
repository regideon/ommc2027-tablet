<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Salescall;
use App\Models\User;
use App\Support\ExpenseAmountLimit;
use App\Support\ExpensePaymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpenseReadService
{
    private const GLOBAL_LIST_LIMIT = 200;

    private bool $globalReadFailed = false;

    public function __construct(private readonly SyncService $syncService) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forSalescall(int $salescallId): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $salescall = Salescall::query()->findOrFail($salescallId);

        abort_unless($this->visibleSalescallsQuery($viewer)->whereKey($salescall->id)->exists(), 403);

        $localExpenses = Expense::query()
            ->with(['expenseType', 'customer', 'creator', 'attachments'])
            ->where('salescall_id', $salescall->id)
            ->get();

        $serverExpenses = $salescall->server_id
            ? $this->syncService->readExpensesForSalescall((int) $salescall->server_id)
            : [];

        return $this->merge($localExpenses, $serverExpenses);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forCurrentUser(): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $this->globalReadFailed = false;

        try {
            $localExpenses = Expense::query()
                ->with(['expenseType', 'customer', 'creator', 'attachments'])
                ->whereHas('salescall', fn (Builder $query) => $this->constrainVisibleSalescalls($query, $viewer))
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->globalReadFailed = true;
            $localExpenses = collect();
        }

        $serverRead = $this->syncService->readExpensesWithStatus();
        $this->globalReadFailed = $this->globalReadFailed || $serverRead['failed'];

        return $this->merge($localExpenses, $serverRead['expenses'])
            ->take(self::GLOBAL_LIST_LIMIT)
            ->values();
    }

    public function globalReadFailed(): bool
    {
        return $this->globalReadFailed;
    }

    public function retryAttachmentForVisibleExpense(User $viewer, int $attachmentId): SyncResult
    {
        $attachment = ExpenseAttachment::with('expense.salescall')->findOrFail($attachmentId);
        $expense = $attachment->expense;

        abort_unless($expense && $this->visibleSalescallsQuery($viewer)->whereKey($expense->salescall_id)->exists(), 403);

        if ($attachment->sync_status !== 'failed') {
            throw ValidationException::withMessages(['attachment' => 'Only failed Expense attachments can be retried.']);
        }

        return $this->syncService->retryFailedExpenseAttachment($attachment->id);
    }

    /**
     * @param  Collection<int, Expense>  $localExpenses
     * @param  array<int, array<string, mixed>>  $serverExpenses
     * @return Collection<int, array<string, mixed>>
     */
    private function merge(Collection $localExpenses, array $serverExpenses): Collection
    {
        $expenses = $localExpenses->mapWithKeys(function (Expense $expense): array {
            $key = $expense->server_id ? 'server:'.$expense->server_id : 'local:'.$expense->local_uuid;

            return [$key => $this->mapLocalExpense($expense)];
        });

        foreach ($serverExpenses as $expense) {
            $key = isset($expense['server_id'])
                ? 'server:'.$expense['server_id']
                : 'local:'.($expense['local_uuid'] ?? uniqid('expense-', true));
            $expenses[$key] = $expense;
        }

        return $expenses
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    private function mapLocalExpense(Expense $expense): array
    {
        $attachments = $expense->attachments->map(fn (ExpenseAttachment $attachment): array => [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'url' => $this->expenseAttachmentUrlFor($attachment),
            'sync_status' => $attachment->sync_status,
            'sync_error' => $attachment->sync_error,
        ])->values()->all();

        return [
            'id' => $expense->id,
            'server_id' => $expense->server_id,
            'local_uuid' => $expense->local_uuid,
            'salescall_id' => $expense->salescall_id,
            'customer_id' => $expense->customer_id,
            'customer_name' => $expense->customer?->name,
            'customer_unique_id' => $expense->customer?->unique_id,
            'expense_type' => $expense->expenseType?->label ?? '—',
            'amount' => (string) $expense->amount,
            'date_filed' => $expense->date_filed?->toDateString(),
            'payment_type' => ExpensePaymentType::forDisplay($expense->payment_type),
            'payment_remarks' => $expense->payment_remarks,
            'invoice_number' => $expense->invoice_number,
            'with_invoice' => $expense->with_invoice,
            'establishment' => $expense->establishment,
            'location' => $expense->location,
            'purpose' => $expense->purpose,
            'tin' => $expense->tin,
            'latitude' => $expense->latitude,
            'longitude' => $expense->longitude,
            'form_data' => $expense->form_data ?? [],
            'creator' => $expense->creator?->name,
            'created_at' => $expense->created_at?->toISOString(),
            'updated_at' => $expense->updated_at?->toISOString(),
            'approved' => $expense->approved,
            'approver_remarks' => $expense->approver_remarks,
            'sync_status' => $expense->sync_status,
            'attachments_required' => $this->attachmentsRequired($expense),
            'attachment_sync_status' => collect($attachments)->contains(fn (array $attachment): bool => $attachment['sync_status'] !== 'synced')
                ? (collect($attachments)->contains(fn (array $attachment): bool => $attachment['sync_status'] === 'failed') ? 'failed' : 'pending')
                : 'synced',
            'attachments' => $attachments,
        ];
    }

    private function attachmentsRequired(Expense $expense): bool
    {
        if ($expense->payment_type === 'SBC Credit Card') {
            return true;
        }

        $code = $expense->expenseType?->code;
        $amount = (string) $expense->amount;

        if ($code === 'lodging') {
            return ExpenseAmountLimit::exceeds($amount, '500.00');
        }

        if ($code === 'per_diem') {
            $creator = $expense->creator;
            $limit = $creator?->hasRole('rsm') ? '450.00' : ($creator?->hasRole('drm') ? '320.00' : null);

            return $limit === null || ExpenseAmountLimit::exceeds($amount, $limit);
        }

        return true;
    }

    private function expenseAttachmentUrlFor(ExpenseAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "expense_attachments/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($attachment->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    private function visibleSalescallsQuery(User $viewer): Builder
    {
        $query = Salescall::query();

        if ($viewer->hasAnyRole(['superadmin', 'admin', 'vp'])) {
            return $query;
        }

        if ($viewer->hasRole('drm_approver') || $viewer->hasRole('rsm')) {
            $ownerIds = User::query()
                ->where('rsm_id', $viewer->id)
                ->pluck('id')
                ->push($viewer->id);

            return $query->whereIn('created_by', $ownerIds);
        }

        if ($viewer->hasRole('rsm_approver')) {
            $rsmIds = User::role('rsm')->pluck('id');

            return $query->whereIn('created_by', $rsmIds);
        }

        return $query->where('created_by', $viewer->id);
    }

    private function constrainVisibleSalescalls(Builder $query, User $viewer): Builder
    {
        $visibleIds = $this->visibleSalescallsQuery($viewer)->select('id');

        return $query->whereIn('id', $visibleIds);
    }
}

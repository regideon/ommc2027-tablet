<?php

namespace App\Filament\Concerns;

use App\Services\SyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Livewire side of the paginated customer pull. The browser calls
 * pullCustomersStep() repeatedly until it reports `done`, so each request
 * does at most one portal round trip (see SyncService::pullCustomersStep()).
 */
trait PullsCustomers
{
    /**
     * @return array{success: bool, done: bool, customer_pull_succeeded: bool, location_pull_succeeded: ?bool, location_refresh_failed: bool, full_sync_succeeded: bool, pulled: int, returned: int, inserted: int, updated: int, unchanged: int, rejected: int, total: ?int, message: string}
     */
    public function pullCustomersStep(): array
    {
        try {
            return app(SyncService::class)->pullCustomersStep();
        } catch (Throwable $e) {
            $reference = (string) Str::uuid();
            Log::error('sync:pull:customers:failed', [
                'reference' => $reference,
                'stage' => 'livewire_action',
                'exception_class' => $e::class,
                'exception' => $e,
                'page_transaction_committed' => false,
            ]);

            return [
                'success' => false,
                'done' => false,
                'pulled' => 0,
                'total' => null,
                'message' => "Customer Pull failed before a page step could complete. Reference: {$reference}.",
                'failure_stage' => 'livewire_action',
                'diagnostic_reference' => $reference,
            ];
        }
    }

    public function customerPullPending(): bool
    {
        return app(SyncService::class)->customerPullPending();
    }
}

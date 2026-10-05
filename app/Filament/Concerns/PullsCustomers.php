<?php

namespace App\Filament\Concerns;

use App\Services\SyncService;
use Throwable;

/**
 * Livewire side of the paginated customer pull. The browser calls
 * pullCustomersStep() repeatedly until it reports `done`, so each request
 * does at most one portal round trip (see SyncService::pullCustomersStep()).
 */
trait PullsCustomers
{
    /**
     * @return array{success: bool, done: bool, pulled: int, total: ?int, message: string}
     */
    public function pullCustomersStep(): array
    {
        try {
            return app(SyncService::class)->pullCustomersStep();
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'done' => false, 'pulled' => 0, 'total' => null, 'message' => 'Customer pull could not be completed. Try again.'];
        }
    }

    public function customerPullPending(): bool
    {
        return app(SyncService::class)->customerPullPending();
    }
}

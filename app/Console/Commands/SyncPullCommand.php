<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncPullCommand extends Command
{
    protected $signature = 'sync:pull';

    protected $description = 'Pull the schedule, then customers page by page, from the server.';

    public function handle(SyncService $sync): int
    {
        $result = $sync->pull();

        if (! $result->success) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message);

        do {
            $step = $sync->pullCustomersStep();

            if (! $step['success']) {
                $this->error($step['message']);

                return self::FAILURE;
            }
        } while (! $step['done']);

        $this->info($step['message']);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncPullCommand extends Command
{
    protected $signature   = 'sync:pull';
    protected $description = 'Pull itineraries and salescalls from the server.';

    public function handle(SyncService $sync): int
    {
        $result = $sync->pull();

        if ($result->success) {
            $this->info($result->message);
            return self::SUCCESS;
        }

        $this->error($result->message);
        return self::FAILURE;
    }
}

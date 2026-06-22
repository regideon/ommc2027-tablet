<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncPushCommand extends Command
{
    protected $signature   = 'sync:push';
    protected $description = 'Push pending itineraries and salescalls to the server.';

    public function handle(SyncService $sync): int
    {
        $result = $sync->push();

        if ($result->success) {
            $this->info($result->message);
            return self::SUCCESS;
        }

        $this->error($result->message);
        return self::FAILURE;
    }
}

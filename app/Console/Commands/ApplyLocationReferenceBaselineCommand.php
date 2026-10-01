<?php

namespace App\Console\Commands;

use App\Services\LocationReferenceBaselineService;
use Illuminate\Console\Command;

class ApplyLocationReferenceBaselineCommand extends Command
{
    protected $signature = 'reference:apply-location-baseline';

    protected $description = 'Apply the bundled canonical Tablet location reference baseline.';

    public function handle(LocationReferenceBaselineService $baseline): int
    {
        $result = $baseline->apply();
        $this->info('LOCATION_BASELINE_STATUS='.strtoupper($result['status']));
        $this->info('LOCATION_BASELINE_VERSION='.$result['version']);
        foreach ($result['counts'] as $key => $count) {
            $this->line('LOCATION_BASELINE_'.strtoupper($key).'='.$count);
        }

        return self::SUCCESS;
    }
}

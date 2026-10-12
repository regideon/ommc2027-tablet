<?php

namespace App\Console\Commands;

use App\Services\StagedAttachmentService;
use Illuminate\Console\Command;

class PruneStagedAttachmentsCommand extends Command
{
    protected $signature = 'staged-attachments:prune {--hours=24 : Delete staging rows older than this many hours}';

    protected $description = 'Delete staged attachments that were never saved into a form.';

    public function handle(StagedAttachmentService $service): int
    {
        $count = $service->prune((int) $this->option('hours'));

        $this->info("Pruned {$count} staged attachment(s).");

        return self::SUCCESS;
    }
}

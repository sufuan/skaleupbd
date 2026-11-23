<?php

// Goes in: app/Console/Commands/SweepWebhookEvents.php (new file). Scheduled every minute (see routes/console.snippet.php).

namespace App\Console\Commands;

use App\Services\Integrations\OutboundWebhookService;
use Illuminate\Console\Command;

class SweepWebhookEvents extends Command
{
    protected $signature = 'integrations:sweep-webhooks';

    protected $description = 'Wake parked outbound events when the shop has points again and re-queue orphaned ones';

    public function handle(OutboundWebhookService $service): int
    {
        $service->sweep();

        return self::SUCCESS;
    }
}

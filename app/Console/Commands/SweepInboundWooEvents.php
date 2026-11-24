<?php

// Goes in: app/Console/Commands/SweepInboundWooEvents.php
// Scheduled every minute. Re-queues unprocessed inbound WooCommerce events
// for shops whose points balance has been restored after a suspension.

namespace App\Console\Commands;

use App\Jobs\Integrations\ProcessWooWebhookJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Services\Integrations\IntegrationHost;
use Illuminate\Console\Command;

class SweepInboundWooEvents extends Command
{
    protected $signature = 'integrations:sweep-woo-inbound';

    protected $description = 'Re-queue unprocessed inbound WooCommerce webhook events (zero-points recovery)';

    public function handle(IntegrationHost $host): int
    {
        // 'failed' is included on purpose: a connection can land in failed
        // (e.g. a partial webhook registration) while its held orders are
        // perfectly processable, and ProcessWooWebhookJob only refuses
        // 'disabled'. Held events must resume once points return, whatever
        // the connection's failure state.
        $connIds = IntegrationConnection::query()
            ->where('platform', 'woo')
            ->whereIn('status', ['active', 'degraded', 'pending', 'failed'])
            ->pluck('id');

        if ($connIds->isEmpty()) {
            return self::SUCCESS;
        }

        $businessBalances = [];

        $events = IntegrationEvent::query()
            ->whereIn('connection_id', $connIds)
            ->where('direction', 'in')
            ->whereIn('status', ['received', 'failed'])
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->whereNull('locked_until')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'connection_id']);

        foreach ($events as $event) {
            $conn = IntegrationConnection::find($event->connection_id);
            if (! $conn) {
                continue;
            }

            $biz = (int) $conn->business_id;

            if (! array_key_exists($biz, $businessBalances)) {
                $businessBalances[$biz] = $host->hasPoints($biz);
            }

            if (! $businessBalances[$biz]) {
                continue;
            }

            dispatch(new ProcessWooWebhookJob($event->id));
        }

        return self::SUCCESS;
    }
}

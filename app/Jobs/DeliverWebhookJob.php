<?php

// Goes in: app/Jobs/DeliverWebhookJob.php (new file). Runs on the database queue drained by the every-minute cron.

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Services\Integrations\OutboundWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    // Retries are scheduled by the service (1m/5m/30m/2h/12h), not by the queue, so the queue never retries on its own.
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $eventId)
    {
    }

    public function handle(OutboundWebhookService $service): void
    {
        $event = $service->claim($this->eventId);
        if (! $event) {
            return; // not due yet, already taken, or already finished: a duplicate job is harmless
        }

        $service->attempt($event);
    }

    /** An unexpected crash must not leave the event locked for good; the sweeper will pick it up again. */
    public function failed(\Throwable $e): void
    {
        IntegrationEvent::query()->whereKey($this->eventId)->update(['locked_until' => null]);
    }
}

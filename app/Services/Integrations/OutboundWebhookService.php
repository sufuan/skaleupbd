<?php

// Goes in: app/Services/Integrations/OutboundWebhookService.php (new file). The dispatcher.

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhookJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutboundWebhookService
{
    public const TOPICS_BY_STATUS = [
        'Processing' => 'order.confirmed',
        'Shipped' => 'order.shipped',
        'Delivered' => 'order.delivered',
        'Returned' => 'order.returned',
        'Cancelled' => 'order.cancelled',
    ];

    /** Stored in integration_events.error while an event waits because the shop is at zero points. */
    public const PAUSED = 'paused_no_points';

    public function __construct(
        private readonly SafeHttp $http,
        private readonly IntegrationHost $host,
        private readonly OrderEventPayloadBuilder $builder,
    ) {
    }

    // ------------------------------------------------------------------ producing events

    /**
     * Called by the order observer after a status change. Never sends anything itself: it only stores an
     * event and queues a job, so it is cheap and cannot slow the seller's order update. Does not check the
     * balance: at zero points the event is still stored and simply waits.
     */
    public function orderStatusChanged(Order $order): ?IntegrationEvent
    {
        $topic = self::TOPICS_BY_STATUS[self::normalizeStatus((string) $order->status)] ?? null;
        // SkaleUp marks orders from the seller's own website with source
        // 'WEB' (the kit was written against 'website'); an external_ref
        // also proves the order came from an external system. Hosted
        // storefront orders use source 'STOREFRONT' and never fire here.
        $src = strtolower((string) data_get($order, 'source'));
        $isWebsite = in_array($src, ['website', 'web'], true) || ! empty($order->external_ref);
        if ($topic === null || ! $isWebsite) {
            return null;
        }

        $conn = $this->activeCustomConnection((int) $order->business_id);
        if (! $conn) {
            return null;
        }

        // Orders that came from a WooCommerce connection are updated by write-back (stage 5), not by webhook.
        $isWoo = DB::table('external_orders')
            ->join('integration_connections', 'integration_connections.id', '=', 'external_orders.connection_id')
            ->where('external_orders.order_id', $order->getKey())
            ->where('integration_connections.platform', 'woo')
            ->exists();
        if ($isWoo) {
            return null;
        }

        $stamp = $order->updated_at?->format('U.u') ?? (string) microtime(true);
        $key = 'out:'.sha1($conn->id.'|order|'.$order->getKey().'|'.$topic.'|'.$stamp);

        return $this->enqueue($conn, $topic, (int) $order->getKey(), $key);
    }

    /** Called whenever available stock for a product changes. Only products matched to the seller's site. */
    public function stockChanged(int $businessId, int $productId): ?IntegrationEvent
    {
        $conn = $this->activeCustomConnection($businessId);
        if (! $conn) {
            return null;
        }

        $matched = DB::table('external_products')
            ->where('connection_id', $conn->id)->where('product_id', $productId)->where('state', 'matched')
            ->exists();
        if (! $matched) {
            return null;
        }

        // Coalesce: an event that has not built its payload yet will read the latest stock when it does.
        $pending = IntegrationEvent::query()
            ->where('connection_id', $conn->id)->where('direction', 'out')
            ->where('topic', 'stock.updated')->where('subject_id', $productId)
            ->where('status', 'received')->whereNull('payload')
            ->exists();
        if ($pending) {
            return null;
        }

        return $this->enqueue($conn, 'stock.updated', $productId, 'out:'.sha1($conn->id.'|stock|'.$productId.'|'.Str::uuid()));
    }

    public function sendTest(IntegrationConnection $conn): ?IntegrationEvent
    {
        return $this->enqueue($conn, 'webhook.test', null, 'out:'.sha1($conn->id.'|test|'.Str::uuid()));
    }

    /** Low-level: store one outbound event and queue its first delivery. A repeated dedupe key stores nothing. */
    public function enqueue(IntegrationConnection $conn, string $topic, ?int $subjectId, string $dedupeKey): ?IntegrationEvent
    {
        $waiting = IntegrationEvent::query()
            ->where('connection_id', $conn->id)->where('direction', 'out')
            ->whereIn('status', ['received', 'failed'])->count();
        if ($waiting >= (int) config('integrations.webhook.max_waiting_events', 2000)) {
            Log::warning('integration.outbound.queue_full', ['connection_id' => $conn->id]);

            return null;
        }

        try {
            $event = IntegrationEvent::create([
                'connection_id' => $conn->id,
                'direction' => 'out',
                'topic' => $topic,
                'event_uuid' => (string) Str::uuid(),
                'subject_id' => $subjectId,
                'dedupe_key' => $dedupeKey,
                'status' => 'received',
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        DeliverWebhookJob::dispatch($event->id)->afterCommit();

        return $event;
    }

    // ------------------------------------------------------------------ delivering events

    /** Atomically take the event for this worker. Returns null if it is not due, not ours, or already taken. */
    public function claim(int $eventId): ?IntegrationEvent
    {
        $taken = IntegrationEvent::query()
            ->whereKey($eventId)
            ->where('direction', 'out')
            ->whereIn('status', ['received', 'failed'])
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['locked_until' => now()->addMinutes(2)]);

        return $taken === 1 ? IntegrationEvent::find($eventId) : null;
    }

    /** One delivery attempt. Call only with an event returned by claim(). */
    public function attempt(IntegrationEvent $event): void
    {
        $conn = IntegrationConnection::find($event->connection_id);

        if (! $conn || $conn->platform !== 'custom' || $conn->status === 'disabled' || ! $conn->webhook_url) {
            $this->skip($event, 'Updates are turned off for this connection.');

            return;
        }

        // Zero points: nothing leaves our side. The balance is checked when the job runs, not when it was queued.
        if (! $this->host->hasPoints((int) $conn->business_id)) {
            $this->park($event);

            return;
        }

        if (($event->error === self::PAUSED || $event->topic === 'stock.updated') && ! $this->isStillRelevant($event, $conn)) {
            $this->skip($event, 'Skipped because a newer change replaced it.');

            return;
        }

        if ($event->payload === null) {
            if ($event->topic === 'order.shipped' && $this->shouldWaitForTracking($event, $conn)) {
                $this->reschedule($event, 60);

                return;
            }
            if (! $this->buildPayload($event, $conn)) {
                $this->skip($event, 'Skipped because the order or product no longer exists.');

                return;
            }
        }

        $secret = (string) $conn->webhook_secret_enc;
        if ($secret === '') {
            $this->fail($event, $conn, null, 0, 'No signing secret is set. Make a new secret and save it in your website.', false, null);

            return;
        }

        $timestamp = time(); // fresh on every attempt so a retry after hours is never "stale" to the receiver
        $headers = [
            'User-Agent' => 'SkaleUp-Webhooks/1.0',
            'X-Skale-Event-Id' => (string) $event->event_uuid,
            'X-Skale-Event' => $event->topic,
            'X-Skale-Timestamp' => (string) $timestamp,
            'X-Skale-Signature' => WebhookSigner::sign($secret, $timestamp, $event->payload),
            'X-Skale-Delivery-Attempt' => (string) ($event->attempts + 1),
        ];

        $started = microtime(true);
        $httpStatus = null;
        $error = null;
        $excerpt = null;
        $outcome = 'failed';

        try {
            $response = $this->http->post($conn->webhook_url, $event->payload, $headers);
            $httpStatus = $response->status();
            $excerpt = $this->excerpt($response->body());
            if ($response->successful()) {
                $outcome = 'delivered';
            } else {
                $error = $this->describeHttpStatus($httpStatus);
            }
        } catch (UnsafeUrlException $e) {
            $outcome = 'blocked';
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $this->describeTransportFailure($e);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($outcome === 'delivered') {
            $this->delivered($event, $conn, $httpStatus, $ms, $excerpt);
        } else {
            $this->fail($event, $conn, $httpStatus, $ms, (string) $error, $httpStatus === 410, $excerpt, $outcome);
        }
    }

    // ------------------------------------------------------------------ outcomes

    private function delivered(IntegrationEvent $event, IntegrationConnection $conn, ?int $http, int $ms, ?string $excerpt): void
    {
        $attempt = $event->attempts + 1;

        WebhookDelivery::create([
            'connection_id' => $conn->id, 'event_id' => $event->id, 'topic' => $event->topic, 'attempt' => $attempt,
            'outcome' => 'delivered', 'http_status' => $http, 'duration_ms' => $ms, 'response_excerpt' => $excerpt,
        ]);

        $event->update(['status' => 'processed', 'attempts' => $attempt, 'error' => null, 'locked_until' => null, 'next_attempt_at' => null]);

        $conn->update([
            'last_event_at' => now(), 'last_success_at' => now(), 'failure_count' => 0, 'last_error' => null,
            'status' => $conn->status === 'degraded' ? 'active' : $conn->status,
        ]);
    }

    private function fail(
        IntegrationEvent $event,
        IntegrationConnection $conn,
        ?int $http,
        int $ms,
        string $error,
        bool $gone,
        ?string $excerpt,
        string $outcome = 'failed',
    ): void {
        $attempt = $event->attempts + 1;
        $backoff = array_values((array) config('integrations.webhook.backoff_seconds', [60, 300, 1800, 7200, 43200]));
        $dead = $gone || $attempt > count($backoff);
        $next = $dead ? null : now()->addSeconds($backoff[$attempt - 1]);
        $error = Str::limit($error, 290, '');

        WebhookDelivery::create([
            'connection_id' => $conn->id, 'event_id' => $event->id, 'topic' => $event->topic, 'attempt' => $attempt,
            'outcome' => $outcome, 'http_status' => $http, 'duration_ms' => $ms, 'error' => $error,
            'response_excerpt' => $excerpt, 'next_retry_at' => $next,
        ]);

        $event->update([
            'status' => $dead ? 'dead' : 'failed', 'attempts' => $attempt, 'error' => $error,
            'locked_until' => null, 'next_attempt_at' => $next,
        ]);

        $conn->increment('failure_count');
        $conn->refresh();
        $conn->update([
            'last_event_at' => now(), 'last_error' => $error,
            'status' => ($conn->status === 'active' && $conn->failure_count >= (int) config('integrations.webhook.degraded_after_failures', 3))
                ? 'degraded' : $conn->status,
        ]);

        if (! $dead) {
            DeliverWebhookJob::dispatch($event->id)->delay($next);

            return;
        }

        if ($gone || $conn->failure_count >= (int) config('integrations.webhook.disable_after_failures', 15)) {
            $this->autoDisable($conn, $gone
                ? 'Your website said this address no longer exists. Updates were turned off. Save the new address to turn them back on.'
                : 'Your website did not accept our updates after many tries, so updates were turned off. Fix the problem, then turn updates back on. Last problem: '.$error);
        }
    }

    private function autoDisable(IntegrationConnection $conn, string $reason): void
    {
        $conn->update(['status' => 'disabled', 'last_error' => Str::limit($reason, 490, '')]);

        IntegrationEvent::query()
            ->where('connection_id', $conn->id)->where('direction', 'out')->whereIn('status', ['received', 'failed'])
            ->update(['status' => 'skipped', 'error' => 'Updates are turned off for this connection.', 'locked_until' => null]);

        $this->host->audit((int) $conn->business_id, null, 'integration.webhook.auto_disabled', ['connection_id' => $conn->id]);
        $this->host->workInboxTask((int) $conn->business_id, 'Order updates to your website were turned off', $reason);
    }

    private function skip(IntegrationEvent $event, string $why): void
    {
        $event->update(['status' => 'skipped', 'error' => $why, 'locked_until' => null, 'next_attempt_at' => null]);
    }

    /** Suspended shop: keep the event, send nothing, look again later. Does not count as an attempt. */
    private function park(IntegrationEvent $event): void
    {
        $maxDays = (int) config('integrations.webhook.max_wait_days', 7);
        if ($event->created_at && $event->created_at->lt(now()->subDays($maxDays))) {
            $this->skip($event, 'Skipped because points ran out and the update became too old.');

            return;
        }

        $event->update([
            'error' => self::PAUSED, 'locked_until' => null,
            'next_attempt_at' => now()->addSeconds((int) config('integrations.webhook.paused_recheck_seconds', 600)),
        ]);
    }

    private function reschedule(IntegrationEvent $event, int $seconds): void
    {
        $at = now()->addSeconds($seconds);
        $event->update(['locked_until' => null, 'next_attempt_at' => $at]);
        DeliverWebhookJob::dispatch($event->id)->delay($at);
    }

    // ------------------------------------------------------------------ payload and relevance

    private function buildPayload(IntegrationEvent $event, IntegrationConnection $conn): bool
    {
        $biz = (int) $conn->business_id;

        if (str_starts_with($event->topic, 'order.')) {
            $order = Order::query()->where('business_id', $biz)->find($event->subject_id);
            if (! $order) {
                return false;
            }
            $data = $this->builder->orderData($order);
        } elseif ($event->topic === 'stock.updated') {
            $data = $this->builder->stockData($biz, (int) $conn->id, (int) $event->subject_id);
            if ($data === null) {
                return false;
            }
        } else {
            $data = ['message' => 'This is a test update from SkaleUp. Your website received it and the signature was sent with it.'];
        }

        $envelope = [
            'id' => $event->event_uuid,
            'event' => $event->topic,
            'occurredAt' => ($event->created_at ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'businessId' => $this->host->businessPublicId($biz),
            'data' => $data,
        ];

        $event->payload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $event->save();

        return true;
    }

    private function shouldWaitForTracking(IntegrationEvent $event, IntegrationConnection $conn): bool
    {
        $order = Order::query()->where('business_id', (int) $conn->business_id)->find($event->subject_id);
        if (! $order) {
            return false;
        }
        $ship = $this->builder->shipment($order);
        $grace = (int) config('integrations.webhook.shipped_tracking_grace_seconds', 300);

        return $ship['trackingNumber'] === null && $event->created_at && $event->created_at->gt(now()->subSeconds($grace));
    }

    public function isStillRelevant(IntegrationEvent $event, IntegrationConnection $conn): bool
    {
        if ($event->topic === 'webhook.test') {
            return true;
        }

        if (str_starts_with($event->topic, 'order.')) {
            $order = Order::query()->where('business_id', (int) $conn->business_id)->find($event->subject_id);

            return $order !== null
                && (self::TOPICS_BY_STATUS[self::normalizeStatus((string) $order->status)] ?? null) === $event->topic;
        }

        if ($event->topic === 'stock.updated') {
            return ! IntegrationEvent::query()
                ->where('connection_id', $conn->id)->where('direction', 'out')->where('topic', 'stock.updated')
                ->where('subject_id', $event->subject_id)->where('id', '>', $event->id)
                ->where('status', '!=', 'skipped')->exists();
        }

        return true;
    }

    // ------------------------------------------------------------------ resuming, sweeping, pruning

    /** Call right after points are credited so waiting events go out at once instead of at the next sweep. */
    public function resumeForBusiness(int $businessId): int
    {
        $conn = IntegrationConnection::query()->forBusiness($businessId)->where('platform', 'custom')->first();
        if (! $conn || $conn->status === 'disabled') {
            return 0;
        }

        return $this->resumeConnection($conn->id);
    }

    private function resumeConnection(int $connectionId): int
    {
        $ids = IntegrationEvent::query()
            ->where('connection_id', $connectionId)->where('direction', 'out')
            ->whereIn('status', ['received', 'failed'])->where('error', self::PAUSED)
            ->orderBy('id')->limit(500)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        IntegrationEvent::query()->whereIn('id', $ids)->update(['next_attempt_at' => now(), 'locked_until' => null]);
        foreach ($ids as $id) {
            DeliverWebhookJob::dispatch($id);
        }

        return $ids->count();
    }

    /** Runs every minute. Wakes parked events once the shop has points again, and re-queues orphaned ones. */
    public function sweep(): void
    {
        $recheck = (int) config('integrations.webhook.paused_recheck_seconds', 600);

        $dueConnections = IntegrationEvent::query()
            ->where('direction', 'out')->whereIn('status', ['received', 'failed'])->where('error', self::PAUSED)
            ->where('next_attempt_at', '<=', now())
            ->distinct()->pluck('connection_id');

        foreach ($dueConnections as $connectionId) {
            $conn = IntegrationConnection::find($connectionId);
            if (! $conn || $conn->status === 'disabled') {
                continue;
            }
            if ($this->host->hasPoints((int) $conn->business_id)) {
                $this->resumeConnection((int) $connectionId);
            } else {
                IntegrationEvent::query()
                    ->where('connection_id', $connectionId)->where('error', self::PAUSED)
                    ->where('next_attempt_at', '<=', now())
                    ->update(['next_attempt_at' => now()->addSeconds($recheck)]);
            }
        }

        // Orphans: due long ago, not parked, not locked, untouched for a while (a lost queue job).
        $cutoff = now()->subMinutes((int) config('integrations.webhook.sweep_overdue_minutes', 15));
        IntegrationEvent::query()
            ->where('direction', 'out')->whereIn('status', ['received', 'failed'])
            ->where(fn ($q) => $q->whereNull('error')->orWhere('error', '!=', self::PAUSED))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $cutoff))
            ->where('updated_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderBy('id')->limit(200)->get(['id'])
            ->each(function ($row) {
                IntegrationEvent::query()->whereKey($row->id)->update(['next_attempt_at' => now()]);
                DeliverWebhookJob::dispatch($row->id);
            });
    }

    public function prune(): void
    {
        $cutoff = now()->subDays((int) config('integrations.webhook.retention_days', 30));

        WebhookDelivery::query()->where('created_at', '<', $cutoff)->delete();
        IntegrationEvent::query()
            ->where('direction', 'out')->whereIn('status', ['processed', 'skipped', 'dead'])
            ->where('updated_at', '<', $cutoff)->delete();
    }

    // ------------------------------------------------------------------ helpers

    public function activeCustomConnection(int $businessId): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->forBusiness($businessId)->where('platform', 'custom')
            ->whereIn('status', ['active', 'degraded'])->whereNotNull('webhook_url')
            ->first();
    }

    public static function normalizeStatus(string $status): string
    {
        return ucfirst(strtolower(trim($status)));
    }

    private function excerpt(string $body): ?string
    {
        $clean = trim((string) preg_replace('/[^\P{C}\n\t]+/u', '', $body));

        return $clean === '' ? null : Str::limit($clean, 280, '');
    }

    private function describeHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => "Your website refused the update (code {$status}). Check that it accepts our signature and the signing secret matches.",
            $status === 404 => 'Your website said this address does not exist (code 404). Check the address.',
            $status >= 300 && $status < 400 => "Your website tried to send us somewhere else (code {$status}). Use the final address directly.",
            $status >= 500 => "Your website had an error while receiving the update (code {$status}).",
            default => "Your website answered with code {$status}. It must answer with a success code.",
        };
    }

    private function describeTransportFailure(\Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'cURL error 6') => 'We could not find your website address. Check the domain name.',
            str_contains($m, 'cURL error 7') => 'We could not connect to your website. Check that it is online.',
            str_contains($m, 'cURL error 28') => 'Your website took too long to answer (more than 10 seconds).',
            preg_match('/cURL error (35|51|58|60|77|83)/', $m) === 1 => 'The security certificate on your website could not be verified. Check that it is valid and not expired.',
            str_contains($m, 'Response too large') => 'Your website sent back a response that was too large.',
            default => 'We could not reach your website.',
        };
    }
}

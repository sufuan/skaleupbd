<?php

// Goes in: app/Http/Controllers/Integrations/WebhookSettingsController.php (new file).
// Backs the webhook section of the Connect page. The business always comes from the logged-in seller
// (IntegrationHost::currentBusinessId); no connection id or business id is ever read from the request,
// so one shop cannot reach another shop's connection.

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\WebhookDelivery;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\OutboundWebhookService;
use App\Services\Integrations\SafeHttp;
use App\Services\Integrations\UnsafeUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookSettingsController extends Controller
{
    public function __construct(
        private readonly OutboundWebhookService $service,
        private readonly IntegrationHost $host,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->json($this->state($this->host->currentBusinessId($request)));
    }

    public function save(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $data = $request->validate(['url' => ['required', 'string', 'max:500']]);
        $url = trim($data['url']);

        try {
            $host = SafeHttp::validateShape($url); // shape only; DNS and address checks happen in the queued job
        } catch (UnsafeUrlException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $conn = $this->connection($biz) ?? new IntegrationConnection(['business_id' => $biz, 'platform' => 'custom', 'status' => 'pending']);

        $newSecret = null;
        if (! $conn->webhook_secret_enc) {
            $newSecret = $this->makeSecret();
            $conn->webhook_secret_enc = $newSecret;
            $conn->webhook_secret_last4 = substr($newSecret, -4);
        }

        $changed = $conn->webhook_url !== $url;
        $conn->webhook_url = $url;
        $conn->site_url = $conn->site_url ?: 'https://'.$host;
        if ($changed || $conn->status === 'pending') {
            $conn->status = 'active';
            $conn->failure_count = 0;
            $conn->last_error = null;
        }
        $conn->save();

        // Keep the existing api_keys.webhook_url column in step so older screens still agree.
        DB::table('api_keys')->where('business_id', $biz)->update(['webhook_url' => $url]);

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.webhook.url_saved', ['connection_id' => $conn->id]);

        return $this->json(['ok' => true, 'secret' => $newSecret, 'webhook' => $this->state($biz)]);
    }

    public function rotateSecret(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);
        if (! $conn || ! $conn->webhook_url) {
            return $this->json(['ok' => false, 'message' => 'Save the address on your website first.'], 422);
        }

        $secret = $this->makeSecret();
        $conn->webhook_secret_enc = $secret;
        $conn->webhook_secret_last4 = substr($secret, -4);
        $conn->save();

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.webhook.secret_rotated', ['connection_id' => $conn->id]);

        return $this->json(['ok' => true, 'secret' => $secret, 'webhook' => $this->state($biz)]);
    }

    public function test(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);

        if (! $conn || ! $conn->webhook_url || $conn->status === 'disabled') {
            return $this->json(['ok' => false, 'message' => 'Updates are not turned on yet.'], 422);
        }
        if (! $this->host->hasPoints($biz)) {
            return $this->json(['ok' => false, 'message' => 'Updates are paused because your points have run out. Top up to send a test.'], 422);
        }

        $this->service->sendTest($conn);

        return $this->json(['ok' => true, 'webhook' => $this->state($biz)]);
    }

    public function enable(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);
        if (! $conn || ! $conn->webhook_url) {
            return $this->json(['ok' => false, 'message' => 'Save the address on your website first.'], 422);
        }

        $conn->update(['status' => 'active', 'failure_count' => 0, 'last_error' => null]);
        $this->host->audit($biz, $request->user()?->getKey(), 'integration.webhook.enabled', ['connection_id' => $conn->id]);

        return $this->json(['ok' => true, 'webhook' => $this->state($biz)]);
    }

    public function disable(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);
        if ($conn) {
            $conn->update(['status' => 'disabled', 'last_error' => 'You turned updates off.']);
            IntegrationEvent::query()
                ->where('connection_id', $conn->id)->where('direction', 'out')->whereIn('status', ['received', 'failed'])
                ->update(['status' => 'skipped', 'error' => 'Updates are turned off for this connection.', 'locked_until' => null]);
            $this->host->audit($biz, $request->user()?->getKey(), 'integration.webhook.disabled', ['connection_id' => $conn->id]);
        }

        return $this->json(['ok' => true, 'webhook' => $this->state($biz)]);
    }

    // ------------------------------------------------------------------

    private function connection(int $businessId): ?IntegrationConnection
    {
        return IntegrationConnection::query()->forBusiness($businessId)->where('platform', 'custom')->first();
    }

    private function makeSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** Explicit whitelist. The secret and credentials are never part of this. */
    private function state(int $businessId): array
    {
        $topUp = (string) config('integrations.top_up_url');
        $c = $this->connection($businessId);

        if (! $c || ! $c->webhook_url) {
            return ['configured' => false, 'top_up_url' => $topUp];
        }

        $paused = $c->status !== 'disabled' && ! $this->host->hasPoints($businessId);

        $waiting = IntegrationEvent::query()
            ->where('connection_id', $c->id)->where('direction', 'out')->whereIn('status', ['received', 'failed'])->count();

        $deliveries = WebhookDelivery::query()
            ->where('connection_id', $c->id)->orderByDesc('id')->limit(20)->get()
            ->map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'topic' => $d->topic,
                'attempt' => $d->attempt,
                'outcome' => $d->outcome,
                'http_status' => $d->http_status,
                'error' => $d->error,
                'at' => $d->created_at?->toIso8601String(),
                'next_retry_at' => $d->next_retry_at?->toIso8601String(),
            ])->all();

        return [
            'configured' => true,
            'url' => $c->webhook_url,
            'status' => $paused ? 'paused' : $c->status,
            'secret_last4' => $c->webhook_secret_last4,
            'last_success_at' => $c->last_success_at?->toIso8601String(),
            'last_error' => $c->last_error,
            'failure_count' => $c->failure_count,
            'waiting' => $waiting,
            'top_up_url' => $topUp,
            'deliveries' => $deliveries,
        ];
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}

<?php

// Goes in: tests/Feature/OutboundWebhookTest.php (new file).
// Uses only the integration tables plus a minimal orders table that follows the ADAPT assumptions
// in OrderEventPayloadBuilder. Adjust the setUp() table if the real orders schema differs.

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\OutboundWebhookService;
use App\Services\Integrations\SafeHttp;
use App\Services\Integrations\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeIntegrationHost;
use Tests\TestCase;

class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeIntegrationHost $host;

    private string $resolvedIp = '93.184.216.34';

    private const SECRET = 'whsec_abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = new FakeIntegrationHost();
        $this->app->instance(IntegrationHost::class, $this->host);
        $this->app->instance(SafeHttp::class, new SafeHttp(fn (string $h) => [$this->resolvedIp]));
        $this->app->forgetInstance(OutboundWebhookService::class);

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function ($t) {
                $t->id();
                $t->unsignedBigInteger('business_id');
                $t->string('status')->default('Pending');
                $t->string('source')->nullable();
                $t->string('invoice_no')->nullable();
                $t->string('external_ref')->nullable();
                $t->string('courier_name')->nullable();
                $t->string('tracking_number')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function ($t) {
                $t->id();
                $t->unsignedBigInteger('business_id');
                $t->string('webhook_url')->nullable();
            });
        }
        if (! Schema::hasTable('businesses')) {
            Schema::create('businesses', function ($t) {
                $t->id();
                $t->string('public_id')->nullable();
            });
        }

        config()->set('integrations.webhook.backoff_seconds', [60, 300, 1800, 7200, 43200]);

        // Our schema has real foreign keys to businesses (the kit's
        // fixtures assumed the rows exist): create shops 1 and 2.
        \DB::table('businesses')->insertOrIgnore([
            ['id' => 1, 'name' => 'Shop One', 'public_id' => 'biz_public_1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Shop Two', 'public_id' => 'biz_public_2', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function service(): OutboundWebhookService
    {
        return app(OutboundWebhookService::class);
    }

    private function connection(int $biz = 1, array $over = []): IntegrationConnection
    {
        return IntegrationConnection::create($over + [
            'business_id' => $biz, 'platform' => 'custom', 'status' => 'active',
            'webhook_url' => 'https://shop'.$biz.'.example.com/hook',
            'webhook_secret_enc' => self::SECRET, 'webhook_secret_last4' => substr(self::SECRET, -4),
        ]);
    }

    private function deliverNow(IntegrationEvent $event): void
    {
        $event->refresh();
        $event->update(['next_attempt_at' => null, 'locked_until' => null]);
        $claimed = $this->service()->claim($event->id);
        if ($claimed) {
            $this->service()->attempt($claimed);
        }
    }

    public function test_signed_request_is_sent_and_verifiable(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 5;
        $conn = $this->connection();

        $event = $this->service()->sendTest($conn);
        $this->deliverNow($event);

        Http::assertSent(function (HttpRequest $r) use ($event) {
            $body = $r->body();
            return $r->url() === 'https://shop1.example.com/hook'
                && $r->hasHeader('X-Skale-Event-Id', $event->event_uuid)
                && WebhookSigner::verify(self::SECRET, $r->header('X-Skale-Timestamp')[0], $r->header('X-Skale-Signature')[0], $body)
                && json_decode($body, true)['event'] === 'webhook.test'
                && json_decode($body, true)['businessId'] === 'biz_public_1';
        });

        $this->assertSame('processed', $event->fresh()->status);
        $this->assertDatabaseHas('webhook_deliveries', ['event_id' => $event->id, 'outcome' => 'delivered', 'http_status' => 200]);
        $this->assertNotNull($conn->fresh()->last_success_at);
    }

    public function test_zero_points_sends_nothing_then_resumes_after_topup(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 0;
        $conn = $this->connection();

        $event = $this->service()->sendTest($conn);
        $this->deliverNow($event);

        Http::assertNothingSent();
        $event->refresh();
        $this->assertSame('received', $event->status);
        $this->assertSame(OutboundWebhookService::PAUSED, $event->error);
        $this->assertSame(0, $event->attempts);
        $this->assertDatabaseCount('webhook_deliveries', 0);

        // Sweeper at zero points: still nothing, still parked.
        $event->update(['next_attempt_at' => now()->subMinute()]);
        $this->service()->sweep();
        Http::assertNothingSent();
        $this->assertTrue($event->fresh()->next_attempt_at->isFuture());

        // Top up, then the sweep (or resumeForBusiness) wakes it.
        $this->host->points[1] = 10;
        $event->update(['next_attempt_at' => now()->subMinute()]);
        $this->service()->sweep();
        Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->eventId === $event->id);

        $this->deliverNow($event);
        Http::assertSentCount(1);
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_resume_for_business_wakes_parked_events_immediately(): void
    {
        Queue::fake();
        $this->host->points[1] = 0;
        $conn = $this->connection();
        $event = $this->service()->sendTest($conn);
        $this->deliverNow($event);

        $this->host->points[1] = 3;
        $this->assertSame(1, $this->service()->resumeForBusiness(1));
        $this->assertTrue($event->fresh()->next_attempt_at->lte(now()));
    }

    public function test_retry_schedule_fresh_timestamp_same_event_id_and_dies_after_sixth_failure(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->host->points[1] = 5;
        $conn = $this->connection();
        $event = $this->service()->sendTest($conn);

        $expected = [60, 300, 1800, 7200, 43200];
        foreach ($expected as $i => $seconds) {
            $this->deliverNow($event);
            $event->refresh();
            $this->assertSame('failed', $event->status);
            $this->assertSame($i + 1, $event->attempts);
            $this->assertEqualsWithDelta($seconds, now()->diffInSeconds($event->next_attempt_at, false), 3);
        }

        $this->deliverNow($event);
        $this->assertSame('dead', $event->fresh()->status);
        $this->assertSame(6, $event->fresh()->attempts);

        $ids = [];
        $bodies = [];
        Http::assertSentCount(6);
        Http::recorded(function (HttpRequest $r) use (&$ids, &$bodies) {
            $ids[] = $r->header('X-Skale-Event-Id')[0];
            $bodies[] = $r->body();
        });
        $this->assertCount(1, array_unique($ids));
        $this->assertCount(1, array_unique($bodies));
    }

    public function test_repeated_failures_degrade_then_auto_disable_with_warning(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->host->points[1] = 5;
        $conn = $this->connection();

        for ($e = 0; $e < 3; $e++) {
            $event = $this->service()->sendTest($conn);
            for ($a = 0; $a < 6; $a++) {
                $this->deliverNow($event);
            }
            if ($e === 0) {
                $this->assertSame('degraded', $conn->fresh()->status);
            }
        }

        $conn->refresh();
        $this->assertSame('disabled', $conn->status);
        $this->assertNotEmpty($conn->last_error);
        $this->assertCount(1, $this->host->tasks);
        $this->assertSame('integration.webhook.auto_disabled', $this->host->audits[0][1]);
    }

    public function test_http_410_disables_immediately(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('gone', 410)]);
        $this->host->points[1] = 5;
        $conn = $this->connection();
        $this->deliverNow($this->service()->sendTest($conn));

        $this->assertSame('disabled', $conn->fresh()->status);
    }

    public function test_redirects_are_not_followed_and_count_as_failure(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);
        $this->host->points[1] = 5;
        $conn = $this->connection();
        $event = $this->service()->sendTest($conn);
        $this->deliverNow($event);

        Http::assertSentCount(1);
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertStringContainsString('send us somewhere else', $event->fresh()->error);
    }

    public function test_ssrf_resolution_to_private_address_is_blocked_and_nothing_is_sent(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 5;

        foreach (['127.0.0.1', '169.254.169.254', '10.0.0.5', '192.168.1.10', '::1'] as $ip) {
            $this->resolvedIp = $ip;
            $conn = IntegrationConnection::query()->forBusiness(1)->first() ?? $this->connection();
            $event = $this->service()->sendTest($conn);
            $this->deliverNow($event);

            $this->assertDatabaseHas('webhook_deliveries', ['event_id' => $event->id, 'outcome' => 'blocked']);
        }
        Http::assertNothingSent();
    }

    public function test_unsafe_urls_are_rejected_by_the_save_endpoint(): void
    {
        // Real owner users: the app's PermissionGate requires a genuine
        // shop owner record (business_id + is_owner).
        $user = User::factory()->create(['business_id' => 1, 'is_owner' => true]);
        $this->host->userBusiness[$user->id] = 1;
        $this->host->points[1] = 5;

        foreach (['http://example.com/h', 'https://127.0.0.1/h', 'https://localhost/h', 'https://[::1]/h', 'https://user:pw@example.com/h', 'https://example.com:8443/h', 'https://intranet.local/h', 'ftp://example.com'] as $bad) {
            $this->actingAs($user)->putJson('/storefront/integrations/webhook', ['url' => $bad])->assertStatus(422);
        }
        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_duplicate_dedupe_key_stores_one_event(): void
    {
        Queue::fake();
        $conn = $this->connection();
        $a = $this->service()->enqueue($conn, 'order.shipped', 7, 'out:same');
        $b = $this->service()->enqueue($conn, 'order.shipped', 7, 'out:same');

        $this->assertNotNull($a);
        $this->assertNull($b);
        $this->assertSame(1, IntegrationEvent::count());
        Queue::assertPushedTimes(DeliverWebhookJob::class, 1);
    }

    public function test_two_workers_cannot_send_the_same_event_twice(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 5;
        $event = $this->service()->sendTest($this->connection());

        $first = $this->service()->claim($event->id);
        $second = $this->service()->claim($event->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_order_status_change_creates_event_only_for_website_orders_with_active_connection(): void
    {
        Queue::fake();
        $this->connection(1);

        $mine = Order::create(['business_id' => 1, 'status' => 'Pending', 'source' => 'website', 'external_ref' => 'E-1', 'invoice_no' => 'INV-E1', 'customer_name' => 'Test', 'customer_phone' => '01700000000']);
        $mine->status = 'Shipped';
        $mine->delivery_method = 'Pathao';
        $mine->tracking_id = 'PT123';
        $mine->save();
        $this->service()->orderStatusChanged($mine);
        $this->service()->orderStatusChanged($mine); // same change replayed: same dedupe key
        $this->assertSame(1, IntegrationEvent::where('topic', 'order.shipped')->count());

        $dash = Order::create(['business_id' => 1, 'status' => 'Pending', 'source' => 'dashboard', 'invoice_no' => 'INV-D1', 'customer_name' => 'Test', 'customer_phone' => '01700000000']);
        $dash->update(['status' => 'Shipped']);
        $this->assertNull($this->service()->orderStatusChanged($dash));

        $other = Order::create(['business_id' => 2, 'status' => 'Processing', 'source' => 'website', 'invoice_no' => 'INV-O2', 'customer_name' => 'Test', 'customer_phone' => '01700000000']);
        $this->assertNull($this->service()->orderStatusChanged($other)); // shop 2 has no connection
        $this->assertSame(1, IntegrationEvent::count());
    }

    public function test_shipped_payload_carries_courier_and_tracking_and_is_never_sent_for_another_shop(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points = [1 => 5, 2 => 5];
        $this->connection(1);
        $this->connection(2);

        $order = Order::create(['business_id' => 1, 'status' => 'Shipped', 'source' => 'website', 'delivery_method' => 'Steadfast', 'tracking_id' => 'SF9', 'invoice_no' => 'INV-S1', 'customer_name' => 'Test', 'customer_phone' => '01700000000']);
        $event = $this->service()->orderStatusChanged($order);
        $this->deliverNow($event);

        Http::assertSent(function (HttpRequest $r) {
            $d = json_decode($r->body(), true);
            return $r->url() === 'https://shop1.example.com/hook'
                && $d['event'] === 'order.shipped'
                && $d['data']['courier'] === 'Steadfast'
                && $d['data']['trackingNumber'] === 'SF9';
        });
        Http::assertSentCount(1);
    }

    public function test_paused_order_event_is_skipped_when_status_moved_on(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 0;
        $this->connection(1);

        $order = Order::create(['business_id' => 1, 'status' => 'Shipped', 'source' => 'website', 'tracking_id' => 'T1', 'invoice_no' => 'INV-P1', 'customer_name' => 'Test', 'customer_phone' => '01700000000']);
        $shipped = $this->service()->orderStatusChanged($order);
        $this->deliverNow($shipped);               // parked
        $order->update(['status' => 'Delivered']);
        // In the wired app the OrderWebhookObserver fires on the update
        // and stores the delivered event itself, so the manual call
        // dedupes to null; fall back to the stored event.
        $delivered = $this->service()->orderStatusChanged($order)
            ?? IntegrationEvent::where('topic', 'order.delivered')->latest('id')->first();
        $this->deliverNow($delivered);             // parked

        $this->host->points[1] = 5;
        $this->deliverNow($shipped);
        $this->deliverNow($delivered);

        $this->assertSame('skipped', $shipped->fresh()->status);
        $this->assertSame('processed', $delivered->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_old_paused_events_are_skipped_not_sent(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 0;
        $event = $this->service()->sendTest($this->connection());
        $event->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->deliverNow($event);

        $this->assertSame('skipped', $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disabled_connection_sends_nothing_and_skips(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->host->points[1] = 5;
        $conn = $this->connection();
        $event = $this->service()->sendTest($conn);
        $conn->update(['status' => 'disabled']);
        $this->deliverNow($event);

        $this->assertSame('skipped', $event->fresh()->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- controller, isolation, secrets

    public function test_shop_a_cannot_see_or_change_shop_b_and_secret_is_shown_once_only(): void
    {
        Queue::fake();
        Log::spy();
        $a = User::factory()->create(['business_id' => 1, 'is_owner' => true]);
        $b = User::factory()->create(['business_id' => 2, 'is_owner' => true]);
        $this->host->userBusiness = [$a->id => 1, $b->id => 2];
        $this->host->points = [1 => 5, 2 => 5];

        $created = $this->actingAs($a)->putJson('/storefront/integrations/webhook', ['url' => 'https://shop-a.example.com/hook'])->assertOk();
        $secret = $created->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);

        // Shop B sees nothing of A.
        $this->actingAs($b)->getJson('/storefront/integrations/webhook')->assertOk()->assertJson(['configured' => false]);
        $this->actingAs($b)->postJson('/storefront/integrations/webhook/test')->assertStatus(422);
        $this->actingAs($b)->postJson('/storefront/integrations/webhook/secret')->assertStatus(422);
        $this->actingAs($b)->postJson('/storefront/integrations/webhook/disable')->assertOk();
        $this->assertSame('active', IntegrationConnection::query()->forBusiness(1)->first()->status);

        // The secret is never returned again and never stored in the clear.
        $again = $this->actingAs($a)->getJson('/storefront/integrations/webhook')->assertOk();
        $this->assertStringNotContainsString($secret, $again->getContent());
        $this->assertSame(substr($secret, -4), $again->json('secret_last4'));
        $stored = \DB::table('integration_connections')->where('business_id', 1)->value('webhook_secret_enc');
        $this->assertStringNotContainsString($secret, (string) $stored);

        // Saving again with the same address does not issue a second secret.
        $second = $this->actingAs($a)->putJson('/storefront/integrations/webhook', ['url' => 'https://shop-a.example.com/hook'])->assertOk();
        $this->assertNull($second->json('secret'));

        Log::shouldNotHaveReceived('info', fn ($m, $ctx = []) => str_contains(json_encode($ctx), $secret));
        $this->assertSame(1, IntegrationConnection::count());
    }

    public function test_state_reports_paused_when_points_run_out(): void
    {
        $user = User::factory()->create(['business_id' => 1, 'is_owner' => true]);
        $this->host->userBusiness[$user->id] = 1;
        $this->host->points[1] = 5;
        $this->actingAs($user)->putJson('/storefront/integrations/webhook', ['url' => 'https://shop-a.example.com/hook'])->assertOk();

        $this->actingAs($user)->getJson('/storefront/integrations/webhook')->assertJson(['status' => 'active']);
        $this->host->points[1] = 0;
        $this->actingAs($user)->getJson('/storefront/integrations/webhook')->assertJson(['status' => 'paused']);
        $this->actingAs($user)->postJson('/storefront/integrations/webhook/test')->assertStatus(422);
    }

    public function test_api_keys_webhook_url_is_mirrored(): void
    {
        $user = User::factory()->create(['business_id' => 1, 'is_owner' => true]);
        $this->host->userBusiness[$user->id] = 1;
        \DB::table('api_keys')->insert(['business_id' => 1, 'key_hash' => hash('sha256', 'sk_mirror1'), 'last4' => 'ror1']);
        \DB::table('api_keys')->insert(['business_id' => 2, 'key_hash' => hash('sha256', 'sk_mirror2'), 'last4' => 'ror2']);

        $this->actingAs($user)->putJson('/storefront/integrations/webhook', ['url' => 'https://shop-a.example.com/hook'])->assertOk();

        $this->assertSame('https://shop-a.example.com/hook', \DB::table('api_keys')->where('business_id', 1)->value('webhook_url'));
        $this->assertNull(\DB::table('api_keys')->where('business_id', 2)->value('webhook_url'));
    }
}

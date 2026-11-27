<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderStatusHistory;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\RuleBrainService;
use App\Services\SidebarCountsService;
use App\Services\SuccessRateService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 2 UI/UX fix batch (2026-11-27) — backend regression tests.
 *
 *  (5)  The AiActivity headline names the outgoing kind, not only
 *       the matched rule ("Product clarification", never a
 *       clarification logged as "Price answer").
 *  (8)  One action, one Processing History entry: approve() and
 *       changeStatus() write the history row only — the duplicate
 *       activity row is gone, and AutoPick's approval count reads
 *       the history.
 *  (9)  Order::changeStatusOptions() is a pure function of status.
 *  (12) Order::deliverableByHand() — the guard shared by Scan To
 *       Delivery and the order page's Mark as Delivered.
 *  (13) OrderService::composeAddress() never repeats a trailing
 *       district/area the address already ends with.
 *  (21) SuccessRateService: a rate-based "risky" verdict needs at
 *       least 3 completed outcomes; a block stays risky at any size.
 *  (3)  The sidebar Inbox badge counts pending AI drafts only.
 *  (6)  The inbox list shows one entry per channel thread.
 */
class Round2UiUxFixTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Business, User} */
    private function shopWithPoints(): array
    {
        $biz = Business::create(['name' => 'Round2 Shop', 'public_id' => 'round2-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Round2 Owner', 'email' => 'round2-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'round2 test', $owner->id);

        return [$biz, $owner];
    }

    private function makeOrder(Business $biz, User $owner): Order
    {
        $product = Product::create(['business_id' => $biz->id, 'name' => 'R2 Panjabi', 'sku' => 'R2-' . uniqid(), 'price' => 1300, 'cost' => 900, 'stock_qty' => 10]);

        return app(OrderService::class)->create($biz->id, [
            'customer_name' => 'R2 Customer', 'customer_phone' => '01712345678',
            'customer_address' => 'House 1, Dhanmondi, Dhaka', 'district' => 'Dhaka',
            'source' => 'WEB', 'status' => 'Pending', 'delivery_charge' => 60,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 1300]],
        ], $owner, 'round2 test');
    }

    // ------------------------------------------------------------- (5)

    public function test_outgoing_label_names_the_kind_not_the_rule(): void
    {
        // The live failure: a clarification drafted while the Price
        // rule matched was logged as "Price answer".
        $this->assertSame('Product clarification', RuleBrainService::outgoingLabel('product_clarify', 'Price answer'));
        $this->assertSame('Order summary', RuleBrainService::outgoingLabel('order_summary', 'Order taking'));
        $this->assertSame('Order status answer', RuleBrainService::outgoingLabel('order_status', null));
        $this->assertSame('Acknowledgement', RuleBrainService::outgoingLabel('acknowledgement', null));
        // No kind: the rule name is the honest label (a real price
        // answer IS the Price rule's answer).
        $this->assertSame('Price answer', RuleBrainService::outgoingLabel(null, 'Price answer'));
        $this->assertSame('No rule matched', RuleBrainService::outgoingLabel(null, null));
        $this->assertSame('No rule matched', RuleBrainService::outgoingLabel('unknown_kind', null));
    }

    // ------------------------------------------------------------- (8)

    public function test_approve_and_status_changes_write_one_history_entry_each(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $order = $this->makeOrder($biz, $owner);
        $svc = app(OrderService::class);

        // Creation is one entry too, carrying the via in its note.
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->whereNull('from_status')->count(), 'one creation history row');
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('text', 'like', 'Order created via%')->count(), 'no duplicate creation activity');

        $svc->approve($order, $owner);
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->where('note', 'Approved')->count(), 'one approval history row');
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('text', 'like', 'Approved by%')->count(), 'no duplicate approval activity');

        $svc->changeStatus($order->fresh(), 'Shipped', $owner, 'Sent out');
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'Shipped')->count(), 'one shipping history row');
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('text', 'like', 'Status changed by%')->count(), 'no duplicate status activity');

        // The timeline the order page renders (history + activities)
        // mentions the approval exactly once.
        $rendered = OrderStatusHistory::where('order_id', $order->id)->count()
            + OrderActivity::where('order_id', $order->id)->where('text', 'like', '%Approved%')->count();
        $approvalRows = OrderStatusHistory::where('order_id', $order->id)->where('note', 'Approved')->count();
        $this->assertSame(1, $approvalRows);
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('text', 'like', '%Approved%')->count());
        $this->assertGreaterThanOrEqual(2, $rendered); // creation + approval + shipping rows exist, unduplicated
    }

    // ------------------------------------------------------------- (9)

    public function test_change_status_options_are_a_pure_function_of_status(): void
    {
        $order = new Order;
        foreach (['Pending', 'Processing'] as $status) {
            $order->status = $status;
            $this->assertSame(Order::PROCESSING_STATUSES, $order->changeStatusOptions(), "in-flow set for {$status}");
            $this->assertSame($order->changeStatusOptions(), $order->changeStatusOptions(), 'deterministic across calls');
        }
        foreach (['Shipped', 'Delivered', 'Returned', 'Cancelled', 'Incomplete', 'Lost'] as $status) {
            $order->status = $status;
            $opts = $order->changeStatusOptions();
            $this->assertSame($status, $opts[0], 'current status leads');
            $this->assertNotContains('Cancel', $opts, 'no Cancel outside the flow');
            $this->assertSame($opts, array_values(array_unique($opts)), 'no duplicates');
            $this->assertCount(7, $opts, 'stable size outside the flow');
        }
    }

    // ------------------------------------------------------------ (12)

    public function test_deliverable_by_hand_guard(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $order = $this->makeOrder($biz, $owner);

        // No courier method at all: hand delivery.
        $this->assertTrue($order->deliverableByHand());

        $manual = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Own Delivery', 'courier_type' => 'MANUAL',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
        ]);
        $order->update(['courier_method_id' => $manual->id]);
        $this->assertTrue($order->fresh()->deliverableByHand(), 'a manual courier is completed by hand');

        // A real API courier (mock off, so the factory returns the
        // real driver) reports delivery itself: never by hand.
        config(['couriers.mock' => false]);
        $api = CourierMethod::create([
            'business_id' => $biz->id, 'name' => 'Pathao', 'courier_type' => 'PATHAO',
            'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120, 'is_active' => true,
            'credentials' => ['client_id' => 'x', 'client_secret' => 'y', 'client_email' => 'a@b.c', 'password' => 'z', 'store_id' => '1'],
        ]);
        $order->update(['courier_method_id' => $api->id]);
        $this->assertFalse($order->fresh()->deliverableByHand(), 'an API courier must not be completed by hand');
    }

    // ------------------------------------------------------------ (13)

    public function test_compose_address_never_repeats_the_district(): void
    {
        $this->assertSame(
            'House 5, Road 2, Dhanmondi, Dhaka',
            OrderService::composeAddress('House 5, Road 2, Dhanmondi, Dhaka', null, 'Dhaka'),
            'district already at the end is not appended again'
        );
        $this->assertSame(
            'Agrabad, Chattogram',
            OrderService::composeAddress('Agrabad, Chattogram', null, 'Chattogram')
        );
        $this->assertSame(
            'House 5, Road 2, Dhanmondi, Dhaka',
            OrderService::composeAddress('House 5, Road 2, Dhanmondi', null, 'Dhaka'),
            'a missing district is appended once'
        );
        $this->assertSame(
            'Mirpur 10, Dhaka',
            OrderService::composeAddress('Mirpur 10', 'Dhaka', 'Dhaka'),
            'area and district do not double up either'
        );
        $this->assertSame('Dhaka', OrderService::composeAddress('', null, 'Dhaka'));
        $this->assertSame('', OrderService::composeAddress(null, null, null));
        // Case and trailing punctuation do not defeat the check.
        $this->assertSame(
            'House 5, dhaka.',
            OrderService::composeAddress('House 5, dhaka.', null, 'Dhaka')
        );
    }

    // ------------------------------------------------------------ (21)

    private function seedOutcomes(Business $biz, string $phone, int $delivered, int $returned): void
    {
        $n = 0;
        foreach (['Delivered' => $delivered, 'Returned' => $returned] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                Order::create([
                    'business_id' => $biz->id, 'invoice_no' => 'R2F' . (++$n) . uniqid(),
                    'customer_name' => 'Fraud Case', 'customer_phone' => $phone,
                    'status' => $status, 'grand_total' => 500,
                ]);
            }
        }
    }

    public function test_risk_flag_needs_a_minimum_sample(): void
    {
        [$biz] = $this->shopWithPoints();
        $svc = app(SuccessRateService::class);

        // One return, nothing else: rate shows, no red alert.
        $this->seedOutcomes($biz, '01711111111', 0, 1);
        $one = $svc->forPhone($biz->id, '01711111111');
        $this->assertSame(0, $one['rate']);
        $this->assertSame('low_sample', $one['verdict'], 'one return must not red-flag a customer');
        $this->assertSame(1, $one['sample']);

        // Two returns: still below the minimum sample.
        $this->seedOutcomes($biz, '01722222222', 0, 2);
        $this->assertSame('low_sample', $svc->forPhone($biz->id, '01722222222')['verdict']);

        // Three returns: the flag is earned.
        $this->seedOutcomes($biz, '01733333333', 0, 3);
        $three = $svc->forPhone($biz->id, '01733333333');
        $this->assertSame('risky', $three['verdict']);
        $this->assertSame(3, $three['sample']);

        // A good rate at a full sample stays safe.
        $this->seedOutcomes($biz, '01744444444', 3, 1);
        $this->assertSame('safe', $svc->forPhone($biz->id, '01744444444')['verdict']);
    }

    public function test_blocked_phone_stays_risky_at_any_sample(): void
    {
        [$biz] = $this->shopWithPoints();
        $this->seedOutcomes($biz, '01755555555', 0, 1);
        \App\Models\IpPhoneBlock::create([
            'business_id' => $biz->id, 'type' => 'phone', 'value' => '01755555555',
        ]);
        $check = app(SuccessRateService::class)->forPhone($biz->id, '01755555555');
        $this->assertSame('risky', $check['verdict'], 'a deliberate block is not a statistic');
    }

    // ------------------------------------------------------------- (3)

    public function test_inbox_badge_counts_pending_drafts_only(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Badge Customer', 'unread_count' => 5,
        ]);
        $counts = app(SidebarCountsService::class)->countsFor($owner);
        $this->assertSame(0, $counts['messages_inbox'], 'unread customer messages alone do not move the badge');

        ChatMessage::create([
            'business_id' => $biz->id, 'conversation_id' => $conv->id, 'direction' => 'out',
            'sender' => 'ai', 'body' => 'A draft', 'status' => 'draft',
        ]);
        ChatMessage::create([
            'business_id' => $biz->id, 'conversation_id' => $conv->id, 'direction' => 'out',
            'sender' => 'ai', 'body' => 'A flagged draft', 'status' => 'flagged',
        ]);
        $counts = app(SidebarCountsService::class)->countsFor($owner);
        $this->assertSame(2, $counts['messages_inbox'], 'the badge is exactly the drafts waiting');
    }

    // ------------------------------------------------------------ (19)

    public function test_dashboard_payload_carries_all_time_delivered_revenue(): void
    {
        [$biz] = $this->shopWithPoints();
        foreach ([['Delivered', 1000], ['Delivered', 2500], ['Shipped', 9999], ['Cancelled', 8888]] as $i => [$status, $total]) {
            Order::create([
                'business_id' => $biz->id, 'invoice_no' => 'R2D' . $i . uniqid(),
                'customer_name' => 'Dash Case', 'customer_phone' => '01766666666',
                'status' => $status, 'grand_total' => $total,
            ]);
        }
        $stats = app(\App\Services\DashboardStatsService::class)->myDashboard($biz->id, now('Asia/Dhaka')->toDateString(), 'approved');
        $this->assertSame(3500.0, $stats['revenue_all_time'], 'all-time revenue is Delivered orders only');
    }

    // ------------------------------------------------------------- (6)

    public function test_inbox_list_shows_one_entry_per_external_thread(): void
    {
        [$biz, $owner] = $this->shopWithPoints();
        Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Round Two Test', 'external_id' => 'thread-1',
            'last_message_at' => now()->subHour(),
        ]);
        Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Round Two Recheck', 'external_id' => 'thread-1',
            'last_message_at' => now(),
        ]);
        Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Someone Else', 'external_id' => 'thread-2',
            'last_message_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($owner)->get('/messages/inbox');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('conversations', 2)
            ->where('conversations.0.customer_name', 'Round Two Recheck'));
    }
}

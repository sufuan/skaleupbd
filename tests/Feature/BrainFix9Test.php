<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\AiClassifierService;
use App\Services\OrderService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 9 (2026-11-25, from the batch-6+7 LIVE re-check on
 * the CRUD Test Shop's real Messenger thread, 18:53-18:56, head
 * c141126c). Two defects:
 *
 *  A. The batch-3 already-confirmed guard outlives a cancelled
 *     order. The seller cancelled the chat order OB300 in the app;
 *     when the customer then wrote "order korte chai", the bot
 *     answered "আপনার অর্ডারটি ইতিমধ্যে কনফার্ম হয়েছে। অর্ডার নম্বর:
 *     OB300।" and refused to start a new flow — the state_data
 *     .last_order snapshot was never checked against the order's
 *     actual status.
 *
 *  B. A details message during bargaining is treated as another
 *     bargain round. The bot had offered 1,235 and asked "অর্ডার
 *     করবেন?" (bargain mode, NOT collecting). The customer answered
 *     with his full details in one message; detectIntent's
 *     mid-bargain digit branch read the phone digits as a ladder
 *     push, NegotiationService::quote stepped the ladder down to
 *     1,170, and no summary was produced. Batch 1 fixed the sibling
 *     for confirmations and batch 5 fixed it inside collect mode;
 *     bargain mode outside collect was never covered.
 */
class BrainFix9Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent, as in the
        // earlier brain suites: the deterministic layer is under test.
        $stub = new class extends AiClassifierService
        {
            public function __construct()
            {
            }

            public function classify(int $businessId, string $text): ?array
            {
                return null;
            }
        };
        app()->instance(AiClassifierService::class, $stub);
    }

    private function setMode(string $mode): void
    {
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => $mode, 'whatsapp' => $mode,
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    /**
     * The live thread's shop: one product (CRUD Test Panjabi, 1,300 —
     * ladder 1,235 / 1,170 / floor 1,105), a points wallet that can
     * pay for orders, and a fresh conversation focused on it.
     *
     * @return array{Business, User, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Batch9 Shop', 'public_id' => 'batch9-shop-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Batch9 Owner', 'email' => 'batch9-owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix9 test', $owner->id);

        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $owner, $p, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function balance(int $bizId): int
    {
        return (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
    }

    private function orderCount(int $bizId): int
    {
        return Order::where('business_id', $bizId)->count();
    }

    /** Bargain the focused Panjabi down to the first rung, 1,235. */
    private function bargainToFirstRung(RuleBrainService $brain, Conversation $conv): void
    {
        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(1235.0, (float) ($offer->meta['offer'] ?? 0), 'the first ladder rung');
        $fresh = $conv->fresh();
        $this->assertSame(1, (int) $fresh->neg_step);
        $this->assertSame(1235.0, (float) $fresh->neg_offer);
        $this->assertNull($fresh->state, 'bargain mode — NOT collecting: the state the live defect lived in');
    }

    /**
     * THE live replay of defect B: the standing offer is 1,235, the
     * bot asked "অর্ডার করবেন?", and the customer answers with his
     * full details in one message. That message is an order with
     * details attached — never a counter-offer: the summary must
     * come out at the STANDING offer and the ladder must not move.
     */
    public function test_details_message_during_bargain_summarises_at_the_standing_offer(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);
        $this->bargainToFirstRung($brain, $conv);

        $r = $this->send($brain, $conv, 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null, 'the details complete the order — a summary, not another ladder round');
        $this->assertSame(1235.0, (float) ($r->meta['unit_price'] ?? 0), 'the summary charges the standing offer');
        $this->assertSame(1295.0, (float) ($r->meta['total'] ?? 0), '1,235 + 60 delivery');
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r->body);

        $fresh = $conv->fresh();
        $this->assertSame(1235.0, (float) $fresh->neg_offer, 'the ladder did not move');
        $this->assertSame(1, (int) $fresh->neg_step, 'no extra ladder round was consumed');
        $this->assertNotEmpty(($fresh->state_data ?? [])['pending_summary'] ?? null, 'the summary awaits confirmation');
        $this->assertSame(0, $this->orderCount($biz->id), 'no order before the customer confirms');
        $this->assertSame($pointsBefore, $this->balance($biz->id), 'no point before confirmation');
    }

    /**
     * Defect B, incomplete details: only name + phone arrive during
     * the bargain. The standing offer is kept, the flow asks only
     * for the piece still missing (the address), and once it arrives
     * the summary still charges the standing offer.
     */
    public function test_partial_details_during_bargain_keep_the_offer_and_ask_for_the_missing_piece(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->bargainToFirstRung($brain, $conv);

        $r = $this->send($brain, $conv, 'Amar nam Phase2 Customer, phone 01612345678');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'the missing piece is asked for — the message was not a counter-offer');
        $this->assertSame('collecting', $conv->fresh()->state);

        $fresh = $conv->fresh();
        $this->assertSame(1235.0, (float) $fresh->neg_offer, 'the standing offer survives');
        $this->assertSame(1, (int) $fresh->neg_step, 'the ladder did not move');

        $r = $this->send($brain, $conv, 'House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertSame(1295.0, (float) ($r->meta['total'] ?? 0), 'the summary charges the standing offer, not a lower rung');
        $this->assertSame(0, $this->orderCount($biz->id));
    }

    /**
     * The guard against over-blocking: a genuine counter-offer — a
     * figure with bargaining words and NO contact details — still
     * moves the ladder exactly as before.
     */
    public function test_a_genuine_counter_offer_still_moves_the_ladder(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->bargainToFirstRung($brain, $conv);

        $r = $this->send($brain, $conv, '1150 taka hole nibo');
        $this->assertNotNull($r);
        $this->assertSame(1170.0, (float) ($r->meta['offer'] ?? 0), 'the ladder steps down to the next rung');
        $fresh = $conv->fresh();
        $this->assertSame(1170.0, (float) $fresh->neg_offer);
        $this->assertSame(2, (int) $fresh->neg_step);
        $this->assertNull($fresh->customer_phone, 'no details were captured from a counter-offer');
        $this->assertSame(0, $this->orderCount($biz->id));
    }

    /** Place a chat order end-to-end and return it. */
    private function confirmedOrder(RuleBrainService $brain, Conversation $conv, int $bizId): Order
    {
        $pointsBefore = $this->balance($bizId);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame(1, $this->orderCount($bizId));
        $this->assertSame($pointsBefore - 1, $this->balance($bizId), 'exactly one point for the order');

        return Order::where('business_id', $bizId)->firstOrFail();
    }

    /**
     * THE live replay of defect A: the chat order exists, the SELLER
     * cancels it in the app, and the customer's next "order korte
     * chai" must start a fresh flow — the dead order's
     * already-confirmed memory must not answer for it.
     */
    public function test_a_cancelled_chat_order_does_not_block_a_fresh_order(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $order = $this->confirmedOrder($brain, $conv, $biz->id);

        app(OrderService::class)->changeStatus($order->fresh(), 'Cancelled', $owner, 'seller cancelled the test order');
        $this->assertSame('Cancelled', $order->fresh()->status);

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow starts — not the already-confirmed answer');
        $this->assertStringNotContainsString('ইতিমধ্যে কনফার্ম হয়েছে', $r->body);
        $this->assertSame('collecting', $conv->fresh()->state);
        $this->assertSame(1, $this->orderCount($biz->id), 'no second order was created');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['last_order'] ?? null, 'the dead order memory is cleared');
    }

    /**
     * Defect A, the Returned branch: a returned order is just as
     * dead for ordering again — it must not block a fresh flow.
     */
    public function test_a_returned_chat_order_does_not_block_a_fresh_order(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $order = $this->confirmedOrder($brain, $conv, $biz->id);

        $order->update(['status' => 'Returned']);
        $this->assertSame('Returned', $order->fresh()->status);

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow starts');
        $this->assertStringNotContainsString('ইতিমধ্যে কনফার্ম হয়েছে', $r->body);
        $this->assertSame(1, $this->orderCount($biz->id));
    }

    /**
     * Defect A, the alive branch: while the placed order is still
     * alive, a bare repeat confirmation keeps the batch-3 answer —
     * the placed order's number, no second order, no second point.
     */
    public function test_a_live_chat_order_still_answers_already_confirmed(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $order = $this->confirmedOrder($brain, $conv, $biz->id);
        $pointsAfterOrder = $this->balance($biz->id);

        $r = $this->send($brain, $conv, 'confirm');
        $this->assertNotNull($r);
        $this->assertSame('order_already_confirmed', $r->meta['kind'] ?? null);
        $this->assertStringContainsString((string) $order->invoice_no, $r->body);
        $this->assertSame(1, $this->orderCount($biz->id), 'no duplicate order');
        $this->assertSame($pointsAfterOrder, $this->balance($biz->id), 'no second point');
    }
}

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
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 7 (2026-11-25, from the batch-6 LIVE re-check on
 * the CRUD Test Shop's real Messenger thread, 18:31-18:32): the
 * batch-5 cancel vocabulary lived only inside handleCollect, so it
 * was honoured only in the collecting state. With a summary on the
 * table (collection finished, state null, pending_summary set):
 *
 *  1. "বাদ দিন" matched no rule and no intent, fell through the
 *     whole chain to the no-rule fallback, and was answered with an
 *     unrelated PRICE line ("CRUD Test Panjabi-এর দাম 1,300 টাকা।
 *     আর কিছু জানতে চাইলে বলুন।"). The summary stayed alive.
 *  2. "order korte chai" — a fresh order intent in any other
 *     context — was read by summaryConfirmation as confirmation of
 *     that still-live summary and CREATED THE ORDER (live: OB300,
 *     total 1,295, 1 point charged) for the very purchase the
 *     customer had just tried to cancel.
 *
 * The fix: one shared flowCancellation() runs in handleIncoming
 * before the collecting dispatch and before the confirmation hook,
 * with batch 5's guards and a full flow close-out (pending summary,
 * collection, ladder, standing offer cleared; the flow's details
 * marked consumed; a still-draft summary rejected so the seller
 * approval route cannot resurrect it). And a message that matches
 * nothing while a summary awaits confirmation now gets a pointer
 * at that summary — never the bare price fallback.
 */
class BrainFix7Test extends TestCase
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
     * The live thread's shape at 18:31: one product (CRUD Test
     * Panjabi, 1,300 — ladder 1,235 / 1,170 / floor 1,105), a points
     * wallet that can pay for orders, and a fresh conversation with
     * no details on record.
     *
     * @return array{Business, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Cancel Shop', 'public_id' => 'cancel-shop-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Cancel Owner', 'email' => 'cancel-owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix7 test', $owner->id);

        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Cancel Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $p, $conv];
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

    /**
     * Drive the live sequence up to the unconfirmed summary: order
     * intent -> collection -> a mid-collect bargain to 1,235 -> the
     * details in one message -> the summary at 1,235 + 60 = 1,295,
     * sent (Full auto), state null, pending_summary set. Returns the
     * summary message.
     */
    private function summaryOnTheTable(RuleBrainService $brain, Conversation $conv): ChatMessage
    {
        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertSame('collecting', $conv->fresh()->state);

        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(1235.0, (float) ($offer->meta['offer'] ?? 0), 'the ladder rung the summary will charge');

        $summary = $this->send($brain, $conv, 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('sent', $summary->status, 'Full auto sent the summary — it awaits confirmation');
        $this->assertSame(1295.0, (float) ($summary->meta['total'] ?? 0), 'bargained 1,235 + 60 delivery');

        $fresh = $conv->fresh();
        $this->assertNull($fresh->state, 'collection is over — the state the live bug lived in');
        $this->assertNotEmpty(($fresh->state_data ?? [])['pending_summary'] ?? null, 'the summary is pending confirmation');

        return $summary;
    }

    /**
     * THE live replay: a summary is on the table, the customer
     * cancels, then says "order korte chai" — the cancelled summary
     * must stay dead: cancel acknowledgement, no order, no point,
     * and the order intent starts a FRESH collection.
     */
    public function test_cancel_with_a_summary_pending_kills_it_and_the_next_order_intent_starts_fresh(): void
    {
        $this->setMode('full_auto');
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);
        $this->summaryOnTheTable($brain, $conv);
        $this->assertSame(0, $this->orderCount($biz->id));
        $this->assertSame($pointsBefore, $this->balance($biz->id));

        // 1. The cancel. Live, this got a price answer and the
        // summary stayed confirmable.
        $r = $this->send($brain, $conv, 'বাদ দিন');
        $this->assertNotNull($r);
        $this->assertSame('collect_cancel', $r->meta['kind'] ?? null, 'the cancellation acknowledgement, not a price answer');
        $this->assertStringContainsString('অর্ডারটা রাখা হলো না', $r->body);
        $this->assertStringNotContainsString('আর কিছু জানতে চাইলে বলুন', $r->body, 'not the no-rule price fallback');

        $fresh = $conv->fresh();
        $this->assertNull($fresh->state);
        $this->assertEmpty(($fresh->state_data ?? [])['pending_summary'] ?? null, 'the summary is no longer confirmable');
        $this->assertSame(0, (int) $fresh->neg_step, 'the ladder is cleared');
        $this->assertNull($fresh->neg_offer, 'the standing offer is cleared');
        $this->assertSame(0, $this->orderCount($biz->id), 'no order from a cancel');
        $this->assertSame($pointsBefore, $this->balance($biz->id), 'no point from a cancel');

        // 2. The same phrase that created OB300 live. With the
        // summary dead and the flow's details consumed, it can only
        // start over: a collect ask, still no order, still no point.
        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow asks for the details again');
        $this->assertSame('collecting', $conv->fresh()->state);
        $this->assertSame(0, $this->orderCount($biz->id), 'the cancelled summary was NOT confirmed');
        $this->assertSame($pointsBefore, $this->balance($biz->id), 'no point charged');
    }

    /**
     * A message that matches nothing while a summary awaits
     * confirmation points at that summary (with its total and the
     * confirm/cancel choice) — never the bare price fallback — and
     * the summary stays confirmable: a genuine acceptance right
     * after still creates the order exactly once (batch 3 intact).
     */
    public function test_unrecognised_message_with_a_summary_pending_points_at_the_summary(): void
    {
        $this->setMode('full_auto');
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(1360.0, (float) ($summary->meta['total'] ?? 0), 'list price 1,300 + 60 delivery');

        $r = $this->send($brain, $conv, 'একটু ভেবে জানাচ্ছি');
        $this->assertNotNull($r);
        $this->assertSame('summary_reminder', $r->meta['kind'] ?? null, 'a pointer at the pending summary');
        $this->assertStringContainsString('1,360', $r->body, 'the reminder carries the pending total');
        $this->assertStringNotContainsString('আর কিছু জানতে চাইলে বলুন', $r->body, 'not the bare price fallback');
        $this->assertNotEmpty(($conv->fresh()->state_data ?? [])['pending_summary'] ?? null, 'the summary is still on the table');
        $this->assertSame(0, $this->orderCount($biz->id));

        // The reminder changed nothing about confirmation: a real
        // acceptance still creates the order, exactly once.
        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame(1, $this->orderCount($biz->id), 'exactly one order');
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point');
    }

    /**
     * Mid-bargain (no collection, no summary — just a standing
     * offer): a cancel clears the ladder and the offer, and the
     * next order intent starts from the collect ask.
     */
    public function test_mid_bargain_cancel_clears_the_ladder(): void
    {
        $this->setMode('full_auto');
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(1235.0, (float) ($offer->meta['offer'] ?? 0));
        $this->assertSame(1235.0, (float) $conv->fresh()->neg_offer);

        $r = $this->send($brain, $conv, 'লাগবে না');
        $this->assertNotNull($r);
        $this->assertSame('collect_cancel', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('অর্ডারটা রাখা হলো না', $r->body);

        $fresh = $conv->fresh();
        $this->assertSame(0, (int) $fresh->neg_step, 'the ladder is cleared');
        $this->assertNull($fresh->neg_offer, 'the standing offer is cleared');
        $this->assertSame(0, $this->orderCount($biz->id));
        $this->assertSame($pointsBefore, $this->balance($biz->id));

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow, not the cancelled bargain');
        $this->assertSame(0, $this->orderCount($biz->id));
    }

    /**
     * Draft mode: the summary sits as a seller draft. The chat
     * cancel must kill it there too — the draft is rejected, so the
     * seller approval route cannot place the cancelled order later.
     */
    public function test_cancel_in_draft_mode_rejects_the_summary_draft(): void
    {
        $this->setMode('draft');
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, 'CRUD-101 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('draft', $summary->status);

        $r = $this->send($brain, $conv, 'বাদ দিন');
        $this->assertNotNull($r);
        $this->assertSame('collect_cancel', $r->meta['kind'] ?? null);
        $this->assertSame('rejected', $summary->fresh()->status, 'the killed summary draft cannot be approved into an order');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['pending_summary'] ?? null);
        $this->assertSame(0, $this->orderCount($biz->id));
        $this->assertSame($pointsBefore, $this->balance($biz->id));

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow asks again');
        $this->assertSame(0, $this->orderCount($biz->id));
    }
}

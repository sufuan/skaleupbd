<?php

namespace Tests\Feature;

use App\Models\AiActivity;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\AiClassifierService;
use App\Services\AiGuardService;
use App\Services\AiTalkerService;
use App\Services\OrderService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 3 (2026-11-25, from the batch-2 LIVE re-test on the
 * CRUD Test Shop's real Messenger thread, 15:47-16:03): batches 1-2
 * fixed the conversation around the order, but the order-taking core
 * itself had three failures left.
 *
 *  FAIL 1 — a CONFIRMED summary never created an order. The corrected
 *  summary (808 + 60 = 868) was shown, the customer confirmed twice,
 *  and the AI re-sent the same summary four times: orderTaking()
 *  could only re-render, and the one order-creation path lived in
 *  the seller inbox approval route, which Full-auto chat never
 *  reaches. (Test gap, stated plainly: earlier suites asserted the
 *  summary and exercised creation only through approveDraft.)
 *  FAIL 2 — the FIRST summary after an acceptance still used the
 *  days-old stored details of a shipped order (OB294): batch 2's
 *  consumption marking only fired when an order was created through
 *  the new path, and a product switch did not close the old flow's
 *  details, so they counted as collected for the new product too.
 *  FAIL 3 — the talker paraphrased the product noun ("এই প্যান্টটি"
 *  for a Panjabi): the product name reached it as literal prose in
 *  the draft, not as a protected slot like the money figures.
 *
 * These tests replay the full live sequence end to end in Full-auto
 * mode on the same pre-seeded stale thread shape as BrainFix2Test.
 */
class BrainFix3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent, as in the
        // earlier brain suites: the failures lived in the
        // deterministic layer, and that is the layer under test.
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
     * The live thread's shop, seeded like BrainFix2Test's stale shop —
     * two products, the conversation focused on the FIRST at its
     * floor, the contact details of an old shipped order still on the
     * record — plus the two things the live shop also had: a points
     * wallet that can pay for orders, and the shipped order itself
     * (OB294's shape: the old product at 1,105 + 60 delivery = 1,165).
     *
     * @return array{Business, Product, Product, Conversation}
     */
    private function liveShop(): array
    {
        $biz = Business::create(['name' => 'Live Thread Shop', 'public_id' => 'live-shop-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Live Owner', 'email' => 'live-owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix3 test', $owner->id);

        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);

        // The old shipped order whose details still sit on the thread.
        app(OrderService::class)->create($biz->id, [
            'customer_name' => 'পাঞ্জাবি পাঞ্জ',
            'customer_phone' => '01700000001',
            'customer_address' => 'মিরপুর 10, ঢাকা',
            'source' => 'FB',
            'status' => 'Shipped',
            'delivery_charge' => 60,
            'items' => [['product_id' => $p1->id, 'qty' => 1, 'price' => 1105]],
        ], $owner, 'Chat order intake');

        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'পাঞ্জাবি পাঞ্জ',
            'customer_phone' => '01700000001',
            'customer_address' => 'মিরপুর 10, ঢাকা',
            'product_id' => $p1->id,
            'neg_step' => 3, 'neg_offer' => 1105,
            'needs_human' => true,
            'state_data' => ['floor_repeats' => 1],
        ]);

        return [$biz, $p1, $p2, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function outCount(Conversation $conv): int
    {
        return ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->count();
    }

    private function balance(int $bizId): int
    {
        return (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
    }

    private function summaryCount(Conversation $conv): int
    {
        return ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->get()
            ->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'order_summary')->count();
    }

    /**
     * The full live sequence, Full-auto: switch -> price -> bargain ->
     * acceptance (must ASK for details, never summarise stale ones) ->
     * details -> correct summary -> confirm -> THE ORDER EXISTS ->
     * repeat confirm (no second order) -> a different product's order
     * intent asks for details again.
     */
    public function test_confirmed_summary_creates_the_order_exactly_once_in_full_auto(): void
    {
        $this->setMode('full_auto');
        [$biz, $p1, $p2, $conv] = $this->liveShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        // 1. Price question naming the second product: switch + 850.
        $out = $this->outCount($conv);
        $r = $this->send($brain, $conv, 'CRUD-102 er dam koto?');
        $this->assertNotNull($r);
        $this->assertSame($out + 1, $this->outCount($conv), 'exactly one reply');
        $this->assertStringContainsString('850', $r->body);
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id);

        // 2. Bargain: CRUD-102's own ladder, product price only.
        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r);
        $this->assertSame(808.0, (float) ($r->meta['offer'] ?? 0));
        $this->assertStringContainsString('808', $r->body);

        // 3. Acceptance at the agreed figure. FAIL 2's live moment:
        // this produced an instant summary built from the shipped
        // order's stored details. The switch closed that flow, so the
        // AI must ASK for the details instead.
        $r = $this->send($brain, $conv, 'thik ache, CRUD-102 ta 808 takay nibo');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'acceptance asks for details — no stale summary');
        $this->assertStringContainsString('808 টাকায় ফাইনাল', $r->body);
        $this->assertStringNotContainsString('01700000001', $r->body, 'the stale phone must never reach a reply');
        $this->assertStringNotContainsString('মিরপুর', $r->body, 'the stale address must never reach a reply');
        $this->assertSame(0, $this->summaryCount($conv), 'no summary was built from stale details');
        $this->assertSame(0, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count());

        // 4. The customer states the details: the summary is built
        // from THESE, at the agreed price.
        $r = $this->send($brain, $conv, 'confirm korchi. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status, 'Full auto sends the summary');
        $this->assertStringContainsString('Phase2 Customer', $r->body);
        $this->assertStringContainsString('01612345678', $r->body);
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r->body);
        $this->assertStringContainsString('মোট: 868', $r->body, '808 agreed + 60 delivery');
        $this->assertSame(0, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count(), 'the summary alone creates nothing');
        $this->assertSame($pointsBefore, $this->balance($biz->id), 'no point before confirmation');

        // 5. THE confirmation. FAIL 1's live moment: this re-sent the
        // summary (four times in total) and no order ever existed.
        $out = $this->outCount($conv);
        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame($out + 1, $this->outCount($conv), 'exactly one reply');
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame(1, $this->summaryCount($conv), 'the summary was NOT re-sent');

        $order = Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->sole();
        $this->assertSame('Phase2 Customer', $order->customer_name);
        $this->assertSame('House 10, Road 5, Uttara, Dhaka', $order->customer_address);
        $this->assertSame('FB', $order->source);
        $this->assertSame('Pending', $order->status);
        $this->assertEqualsWithDelta(60.0, (float) $order->delivery_charge, 0.01);
        $this->assertEqualsWithDelta(868.0, (float) $order->grand_total, 0.01, 'the summarised total, exactly');
        $item = $order->items()->sole();
        $this->assertSame($p2->id, (int) $item->product_id);
        $this->assertEqualsWithDelta(808.0, (float) $item->price, 0.01, 'the agreed price, not the list price');
        $this->assertSame(1, (int) $item->qty);
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point deducted');
        $this->assertStringContainsString((string) $order->invoice_no, $r->body, 'the confirmed reply carries the order number');
        $this->assertStringContainsString('868', $r->body);
        $fresh = $conv->fresh();
        $this->assertSame(0, (int) $fresh->neg_step, 'the flow is reset after creation');
        $this->assertNull($fresh->neg_offer);

        // 6. A second confirmation: no second order, no second point,
        // no new summary — the placed order's status instead.
        $r = $this->send($brain, $conv, 'confirm');
        $this->assertNotNull($r);
        $this->assertSame('order_already_confirmed', $r->meta['kind'] ?? null);
        $this->assertStringContainsString((string) $order->invoice_no, $r->body);
        $this->assertSame(1, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count(), 'still exactly one order');
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'still exactly one point');
        $this->assertSame(1, $this->summaryCount($conv));

        // 7. A NEW product's order intent in the same thread asks for
        // the details again — the placed order's data is not reused.
        $r = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertStringNotContainsString('01612345678', $r->body);
        $this->assertSame(1, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count());
    }

    /**
     * Draft-only mode: the same confirmation must produce the order
     * draft for seller approval (the summary draft, marked confirmed)
     * — not a direct order, and not a loop of summary drafts.
     */
    public function test_draft_mode_confirmation_marks_the_order_draft_instead_of_looping_summaries(): void
    {
        $this->setMode('draft');
        [$biz, $p1, $p2, $conv] = $this->liveShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        // Details stated with the order intent: the summary is a draft.
        $summary = $this->send($brain, $conv, 'CRUD-102 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('draft', $summary->status);
        $this->assertStringContainsString('মোট: 910', $summary->body, '850 + 60 delivery');

        // The customer confirms: no direct order, no second summary —
        // the confirmation lands on the summary draft and a draft
        // acknowledgement answers.
        $ack = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($ack);
        $this->assertSame('order_confirm_ack', $ack->meta['kind'] ?? null);
        $this->assertSame('draft', $ack->status);
        $this->assertTrue((bool) ($summary->fresh()->meta['customer_confirmed'] ?? false), 'the seller sees the customer confirmed this draft');
        $this->assertSame(1, $this->summaryCount($conv), 'no loop of summaries');
        $this->assertSame(0, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count(), 'no direct order in Draft mode');
        $this->assertSame($pointsBefore, $this->balance($biz->id), 'no point in Draft mode');

        // A repeat confirmation piles up nothing: it is logged, and
        // the draft stays the single order draft.
        $repeat = $this->send($brain, $conv, 'confirm korchi');
        $this->assertNull($repeat);
        $this->assertTrue(AiActivity::where('conversation_id', $conv->id)->where('action', 'order_confirm_repeat')->exists());
        $this->assertSame(1, $this->summaryCount($conv));
        $this->assertSame(0, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count());
    }

    /**
     * Stock guard at confirmation time: the product sold out after
     * the summary was shown — no order, the out-of-stock reply.
     */
    public function test_confirmation_after_the_product_sells_out_creates_no_order(): void
    {
        $this->setMode('full_auto');
        [$biz, $p1, $p2, $conv] = $this->liveShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, 'CRUD-102 order korte chai. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('sent', $summary->status);

        $p2->stock_qty = 0;
        $p2->save();

        $r = $this->send($brain, $conv, 'confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('out_of_stock', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('স্টকে নেই', $r->body);
        $this->assertSame(0, Order::where('business_id', $biz->id)->where('customer_phone', '01612345678')->count(), 'stock 0 = NO order, even after a summary');
        $this->assertSame($pointsBefore, $this->balance($biz->id));
        $state = (array) ($conv->fresh()->state_data ?? []);
        $this->assertEmpty($state['pending_summary'] ?? [], 'the dead summary is no longer confirmable');
    }

    /** A talker stub that records what it was given and answers via a callback. */
    private function talkerStub(callable $responder): AiTalkerService
    {
        $stub = new class(app(SettingsService::class), app(AiGuardService::class)) extends AiTalkerService
        {
            /** @var array<int, array> */
            public array $calls = [];

            /** @var null|callable */
            public $responder = null;

            public function phrase(array $ctx): ?array
            {
                $this->calls[] = $ctx;

                return ($this->responder)($ctx);
            }
        };
        $stub->responder = $responder;
        app()->instance(AiTalkerService::class, $stub);

        return $stub;
    }

    private function talkerShop(): array
    {
        $biz = Business::create(['name' => 'Talker Shop', 'public_id' => 'talker-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $conv = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Talker Customer', 'status' => 'open', 'product_id' => $p->id]);

        return [$biz, $p, $conv];
    }

    /**
     * FAIL 3, the slot: the product name reaches the talker as the
     * protected ⟦product⟧ token (never as literal prose), and the
     * customer's reply carries the real name verbatim because CODE
     * renders the slot after phrasing.
     */
    public function test_the_product_name_reaches_the_talker_as_a_protected_slot(): void
    {
        [$biz, $p, $conv] = $this->talkerShop();
        $talker = $this->talkerStub(fn (array $ctx) => [
            'text' => '⟦product⟧-এর দাম মাত্র ⟦price⟧ টাকা।',
            'provider' => 'gemini', 'model' => 'stub', 'tier' => 'normal',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $brain->handleIncoming($conv->fresh(), 'dam koto?');

        $this->assertNotEmpty($talker->calls);
        $draft = (string) ($talker->calls[array_key_last($talker->calls)]['draft'] ?? '');
        $this->assertStringContainsString('⟦product⟧', $draft, 'the name travels as a slot');
        $this->assertStringNotContainsString('CRUD Test Panjabi 2', $draft, 'the literal name never reaches the model as prose');
        $this->assertNotNull($r);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body, 'code renders the real name verbatim');
        $this->assertStringContainsString('850', $r->body);
    }

    /**
     * FAIL 3, the guard: a phrasing that renames the product (the
     * live "এই প্যান্টটি" for a Panjabi) is discarded — the
     * code-written draft goes out instead, and the failure is logged.
     */
    public function test_a_phrasing_that_renames_the_product_is_discarded_for_the_template(): void
    {
        [$biz, $p, $conv] = $this->talkerShop();
        $this->talkerStub(fn (array $ctx) => [
            'text' => 'এই প্যান্টটির দাম ⟦price⟧ টাকা।',
            'provider' => 'gemini', 'model' => 'stub', 'tier' => 'normal',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $brain->handleIncoming($conv->fresh(), 'dam koto?');

        $this->assertNotNull($r);
        $this->assertStringNotContainsString('প্যান্ট', $r->body, 'the invented noun never reaches the customer');
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body, 'the template with the real name goes out');
        $this->assertStringContainsString('850', $r->body);
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'talker_failed')->exists(),
            'the rename is visible in AI Activity like every talker failure'
        );
    }

    /**
     * FAIL 3, both directions on the bargain path: the talker
     * inventing a noun for a draft that never named the product is
     * discarded, while the product's OWN noun in the customer's
     * script (পাঞ্জাবি for "Panjabi") is a legitimate phrasing and
     * is kept.
     */
    public function test_bargain_noun_invention_is_discarded_but_the_products_own_noun_is_kept(): void
    {
        [$biz, $p, $conv] = $this->talkerShop();
        $this->talkerStub(function (array $ctx) {
            if (str_contains((string) ($ctx['draft'] ?? ''), '⟦offer⟧')) {
                // The live failure's shape: the bargain draft names no
                // product, the talker invents one — the wrong one.
                return ['text' => 'এই প্যান্টটি আপনার জন্য ⟦offer⟧ টাকায় দিতে পারব। অর্ডার করবেন?', 'provider' => 'gemini', 'model' => 'stub', 'tier' => 'normal'];
            }

            return ['text' => '⟦product⟧ — এই পাঞ্জাবিটার দাম ⟦price⟧ টাকা।', 'provider' => 'gemini', 'model' => 'stub', 'tier' => 'normal'];
        });
        $brain = app(RuleBrainService::class);

        $price = $brain->handleIncoming($conv->fresh(), 'dam koto?');
        $this->assertNotNull($price);
        $this->assertStringContainsString('পাঞ্জাবিটার', $price->body, 'the product\u2019s own noun in Bangla script is a legitimate phrasing');
        $this->assertStringContainsString('CRUD Test Panjabi 2', $price->body);
        $this->assertSame(0, AiActivity::where('conversation_id', $conv->id)->where('action', 'talker_failed')->count(), 'no false positive on the correct noun');

        $offer = $brain->handleIncoming($conv->fresh(), 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(808.0, (float) ($offer->meta['offer'] ?? 0));
        $this->assertStringNotContainsString('প্যান্ট', $offer->body, 'the invented noun is discarded with the phrasing');
        $this->assertStringContainsString('808', $offer->body, 'the template offer goes out');
        $this->assertTrue(AiActivity::where('conversation_id', $conv->id)->where('action', 'talker_failed')->exists());
    }
}

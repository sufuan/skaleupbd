<?php

namespace Tests\Feature;

use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 15 (2026-11-25) — the FINAL batch of the external
 * expert review: Step 7 (multi-intent, finding #17), the remaining
 * new catalog cases, and the P3 findings #23 (off-hours silence),
 * #24 (language matching) and #25 (returning customers). Every item
 * was verified in the full code + the 47-rule catalog first; cases
 * that turned out already handled are PINNED, not re-built:
 *
 *  PINNED (already handled, tests pass before and after):
 *   - Quantity discount ("বেশি নিলে ছাড় আছে?") — the catalog's
 *     Offer rule answers it honestly (checks, invents nothing).
 *   - "কেউ আছেন?" — the wants_human intent already owns it.
 *   - Payment NUMBER asks — the Payment number request rule.
 *   - "ওকে" against a pending summary — batch 3's confirmation.
 *   - Romanised Bangla ("dam koto?") stays on the Bangla path.
 *
 *  FIXED here:
 *   A. Multi-intent: 2-3 independent informational questions in one
 *      message are answered together; anything more, or any part
 *      that is a flow/handover/unrecognised outcome, keeps today's
 *      single-intent behaviour.
 *   B1. Bare "আছেন?" — a presence ping, answered briefly.
 *   B2. Bare acknowledgement ("ওকে", "আচ্ছা") with no question
 *      pending — a polite close, NOT batch 10's no-rule draft+flag
 *      (the deliberate carve-out: a RECOGNISED acknowledgement is
 *      not an unmatched message).
 *   B3. Polite decline outside a flow — a catalog rule; inside a
 *      flow batch 7's cancellation still owns the words.
 *   B4. "অনেক দাম" / "দাম বেশি" — an opening bargain push, not a
 *      price re-quote.
 *   B6. "কি কি কালার আছে?" — the product's real variant names.
 *   B7. "কিভাবে পেমেন্ট করব?" — an honest deferral rule; no bKash
 *      number is ever invented.
 *   B8. Redelivery — its own fine intent + handover rule (it used
 *      to get the delivery-CHARGE answer).
 *   B9. "কনফার্মেশন মেসেজ পাইনি" — order status by phone, not the
 *      angry handover the word 'পাইনি' used to trigger.
 *   B10. "কুরিয়ার ফোন করেনি" — handover with an apology ack.
 *   B11. Invoice/memo — a handover rule; "মেমো লাগবে" no longer
 *      reads as an order intent (guard phrase).
 *   B12. Gift wrap — an honest draft rule, no promised service.
 *   B13. Reserve/hold — a handover rule, no promised reservation.
 *   B14. Profanity — a separate 'abuse' intent: fully silent +
 *      needs_human (anger keeps its holding-line behaviour).
 *   B15. Usage/ingredients — a catalog rule in the Product details
 *      family: honest deferral, no invented facts.
 *   B16. Corrections: (a) AFTER a summary was shown, a correction
 *      re-renders the summary at once (the qty/variant rerenders'
 *      twin); (b) MID-COLLECTION, a correction message ("নম্বরটা
 *      ভুল, সঠিকটা …") was swallowed as the ADDRESS by both
 *      blob-address rules — the collection "completed" with ঠিকানা:
 *      "নম্বরটা ভুল, সঠিকটা" — so correction text now disarms the
 *      blob rules (labelled parts still land as before).
 *   C23. Off-hours: one customer-facing line per conversation per
 *      off-hours stretch; the reply itself still only drafts.
 *   C24. English-dominant messages get English templates for the
 *      four core informational intents (price, delivery, stock,
 *      greeting); romanised Bangla is not English.
 *   C25. A returning customer whose previous DELIVERED order's
 *      address is on the record is ASKED "আগের ঠিকানায় পাঠাব?" —
 *      never silently reused; "হ্যাঁ" confirms it, "না" asks fresh.
 */
class BrainFix15Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function setMode(string $mode): void
    {
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => $mode, 'whatsapp' => $mode,
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    /** A shop with a points wallet. @return array{Business, User} */
    private function shopBase(string $tag): array
    {
        $biz = Business::create(['name' => 'Batch15 Shop', 'public_id' => 'batch15-' . $tag . '-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Batch15 Owner', 'email' => 'batch15-' . $tag . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix15 test', $owner->id);

        return [$biz, $owner];
    }

    /** One Panjabi (1,300) in focus. @return array{Business, User, Product, Conversation} */
    private function plainShop(int $stock = 10): array
    {
        [$biz, $owner] = $this->shopBase('plain');
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => $stock]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $owner, $p, $conv];
    }

    /**
     * A variant product in focus. $variants: list of [name, price, stock].
     *
     * @return array{Business, User, Product, Conversation, array<int, ProductVariant>}
     */
    private function variantShop(string $tag, string $productName, float $basePrice, array $variants): array
    {
        [$biz, $owner] = $this->shopBase($tag);
        $p = Product::create(['business_id' => $biz->id, 'name' => $productName, 'sku' => 'BF15-' . strtoupper($tag), 'price' => $basePrice, 'stock_qty' => 50]);
        $made = [];
        foreach ($variants as [$name, $price, $stock]) {
            $made[] = ProductVariant::create([
                'business_id' => $biz->id, 'product_id' => $p->id,
                'name' => $name, 'price' => $price, 'stock_qty' => $stock,
            ]);
        }
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $owner, $p, $conv, $made];
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

    private function outCount(Conversation $conv): int
    {
        return ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->count();
    }

    private const DETAILS = 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.';

    // ------------------------------------------------- A. multi-intent

    /** Two independent questions, one joined answer. */
    public function test_two_independent_questions_are_answered_together(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম কত? আর ঢাকার বাইরে ডেলিভারি কতদিন লাগবে?');
        $this->assertNotNull($r);
        $this->assertSame('multi_intent', $r->meta['kind'] ?? null, 'the two questions are answered in one joined reply');
        $this->assertStringContainsString('1,300', $r->body, 'the price part is answered');
        $this->assertStringContainsString('সময় লাগবে', $r->body, 'the delivery-time part is answered');
        $this->assertSame('sent', $r->status);
        $this->assertSame(1, $this->outCount($conv), 'exactly one outgoing reply for the one message');
    }

    /** A greeting may ride along: greeting + price + stock = three parts. */
    public function test_a_greeting_plus_two_questions_joins(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আসসালামু আলাইকুম। দাম কত? স্টকে আছে?');
        $this->assertNotNull($r);
        $this->assertSame('multi_intent', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('স্বাগতম', $r->body);
        $this->assertStringContainsString('1,300', $r->body);
        $this->assertStringContainsString('আছে: 10', $r->body);
    }

    /** Four questions are too many: today's single-intent behaviour. */
    public function test_four_questions_fall_back_to_a_single_answer(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম কত? স্টকে আছে? ডেলিভারি চার্জ কত? কতদিনে পাব?');
        $this->assertNotNull($r);
        $this->assertNotSame('multi_intent', $r->meta['kind'] ?? null, 'more than three parts are never joined');
        $this->assertStringNotContainsString('1,300', $r->body, 'the single-intent winner (delivery time) answers alone');
    }

    /** A bargain part is a flow intent: it owns the message alone. */
    public function test_a_bargain_part_keeps_the_single_flow(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম কত? একটু কমানো যাবে?');
        $this->assertNotNull($r);
        $this->assertNotSame('multi_intent', $r->meta['kind'] ?? null);
        $this->assertEqualsWithDelta(1235.0, (float) ($r->meta['offer'] ?? 0), 0.01, 'the ladder answered, as today');
        $this->assertSame(1, (int) $conv->fresh()->neg_step);
    }

    /** A part nothing recognises aborts the join. */
    public function test_an_unrecognised_part_falls_back_to_single_behaviour(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম কত? রিফান্ড চাই');
        $this->assertNotNull($r);
        $this->assertNotSame('multi_intent', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('1,300', $r->body, 'the price question keeps its normal single answer');
    }

    // ------------------------------------------------------- B1. presence

    /** A bare "আছেন?" is a presence ping: a short reply, no flag. */
    public function test_a_bare_presence_ping_is_answered(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('presence');
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আছেন?');
        $this->assertNotNull($r);
        $this->assertSame('presence', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status);
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'a ping is not a human case');
    }

    /** PIN: "কেউ আছেন?" already belongs to wants_human. */
    public function test_keu_achen_stays_with_wants_human(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('keuachen');
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কেউ আছেন?');
        $this->assertNotNull($r);
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'wants_human still hands over');
        $this->assertStringContainsString('প্রতিনিধি', $r->body, 'the wants_human acknowledgement answers');
    }

    // ------------------------------------------------- B2. acknowledgement

    /** A bare acknowledgement with nothing pending: a polite close,
     *  sent, and NO human flag — the deliberate carve-out from batch
     *  10's no-rule treatment (a recognised ack is not unmatched). */
    public function test_a_bare_acknowledgement_closes_politely(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'ওকে');
        $this->assertNotNull($r);
        $this->assertSame('acknowledgement', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status);
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'a recognised acknowledgement raises no flag');
        $this->assertEmpty($r->meta['no_rule'] ?? null, 'and it is not the no-rule fallback');

        $r = $this->send($brain, $conv, 'আচ্ছা');
        $this->assertNotNull($r);
        $this->assertSame('acknowledgement', $r->meta['kind'] ?? null);
    }

    /** PIN: against a pending summary, "ওকে" is still batch 3's
     *  confirmation — the order is created, exactly one point. */
    public function test_an_acknowledgement_against_a_summary_still_confirms_it(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, self::DETAILS);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);

        $r = $this->send($brain, $conv, 'ওকে');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null, 'summary confirmation runs before the acknowledgement close');
        $this->assertSame(1, $this->orderCount($biz->id));
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id));
    }

    // ------------------------------------------------------- B3. decline

    /** A polite decline outside any flow: a calm close, no order,
     *  no ladder, no flag. */
    public function test_a_polite_decline_outside_a_flow_closes_calmly(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'লাগবে না');
        $this->assertNotNull($r);
        $this->assertSame('Polite decline', $r->rule?->name);
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('কোনো সমস্যা নেই', $r->body);
        $this->assertFalse((bool) $conv->fresh()->needs_human);
        $this->assertSame(0, $this->orderCount($biz->id));
        $this->assertSame(0, (int) $conv->fresh()->neg_step, 'no bargain ladder opens for a decline');
    }

    /** PIN: inside a collection, the same words are batch 7's
     *  cancellation, not the decline rule. */
    public function test_a_decline_inside_collection_stays_a_cancellation(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);

        $r = $this->send($brain, $conv, 'লাগবে না');
        $this->assertNotNull($r);
        $this->assertSame('collect_cancel', $r->meta['kind'] ?? null, 'flow cancellation owns the words inside a flow');
        $this->assertNull($conv->fresh()->state, 'the collection is closed');
    }

    // ---------------------------------------------------- B4. price-too-high

    /** "অনেক দাম" opens the ladder — it is a push, not a question. */
    public function test_price_too_high_opens_the_bargain_ladder(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'অনেক দাম');
        $this->assertNotNull($r);
        $this->assertSame(1, (int) $conv->fresh()->neg_step, 'the ladder stepped');
        $this->assertEqualsWithDelta(1235.0, (float) ($r->meta['offer'] ?? 0), 0.01, 'the first rung is offered');
        $this->assertStringContainsString('1,235', $r->body);
    }

    /** "দাম বেশি" is the same push; a plain price question is not. */
    public function test_dam_beshi_is_a_push_but_dam_koto_is_a_question(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম বেশি');
        $this->assertNotNull($r);
        $this->assertSame(1, (int) $conv->fresh()->neg_step);
        $this->assertEqualsWithDelta(1235.0, (float) ($r->meta['offer'] ?? 0), 0.01);

        [$biz2, $owner2, $p2, $conv2] = $this->plainShop();
        $r = $this->send($brain, $conv2, 'দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame(0, (int) $conv2->fresh()->neg_step, 'a price question never opens the ladder');
        $this->assertStringContainsString('1,300', $r->body);
    }

    // ------------------------------------------- B5. PIN: quantity discount

    /** PIN: a quantity-discount question is the Offer rule's case —
     *  it checks and invents nothing (no bulk mechanism exists). */
    public function test_a_quantity_discount_question_is_the_offer_rules_case(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'বেশি নিলে ছাড় আছে?');
        $this->assertNotNull($r);
        $this->assertSame('Offer or coupon enquiry', $r->rule?->name);
        $this->assertSame(0, (int) $conv->fresh()->neg_step, 'no ladder opens and no discount is invented');
    }

    // ---------------------------------------------------- B6. variant list

    /** "কি কি কালার আছে?" lists the product's real variant names. */
    public function test_a_colour_list_question_lists_the_real_variants(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, $vars] = $this->variantShop('colors', 'Rongin Panjabi', 1000, [
            ['Color: লাল', 800, 5],
            ['Color: কালো', 1200, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কি কি কালার আছে?');
        $this->assertNotNull($r);
        $this->assertSame('variant_list', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('লাল', $r->body);
        $this->assertStringContainsString('কালো', $r->body);
    }

    /** The size form lists the size variants. */
    public function test_a_size_list_question_lists_the_size_variants(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, $vars] = $this->variantShop('sizes', 'Size Shirt', 1000, [
            ['Size: L', 900, 5],
            ['Size: M', 1100, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'সাইজ কি কি আছে?');
        $this->assertNotNull($r);
        $this->assertSame('variant_list', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('Size: L', $r->body);
        $this->assertStringContainsString('Size: M', $r->body);
    }

    /** A product with no variants has no list to give: the branch
     *  stays out of the way. */
    public function test_a_list_question_without_variants_is_not_a_variant_list(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কি কি কালার আছে?');
        $this->assertTrue($r === null || ($r->meta['kind'] ?? null) !== 'variant_list');
    }

    // --------------------------------------------------------- B7. payment

    /** "কিভাবে পেমেন্ট করব?" — an honest deferral; no number, no
     *  invented method. */
    public function test_a_payment_methods_question_defers_honestly(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কিভাবে পেমেন্ট করব?');
        $this->assertNotNull($r);
        $this->assertSame('Payment methods question', $r->rule?->name);
        $this->assertStringContainsString('নিশ্চিত তথ্য', $r->body);
        $this->assertDoesNotMatchRegularExpression('/01[3-9]\d{8}/', $r->body, 'no payment number is ever invented');
    }

    /** PIN: asking FOR the number is the Payment number rule's case. */
    public function test_a_payment_number_ask_stays_with_the_number_rule(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'বিকাশ নম্বরটা দেন');
        $this->assertNotNull($r);
        $this->assertSame('Payment number request', $r->rule?->name);
        $this->assertStringContainsString('অন্য কোনো নম্বরে টাকা পাঠাবেন না', $r->body);
    }

    // ------------------------------------------------------ B8. redelivery

    /** "আবার ডেলিভারি করেন" is a redelivery request for a human —
     *  not the delivery-charge answer it used to get. */
    public function test_a_redelivery_request_hands_over(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আবার ডেলিভারি করেন');
        $this->assertNotNull($r);
        $this->assertSame('Redelivery request', $r->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertStringContainsString('আবার পাঠানোর', $r->body);
        $this->assertStringNotContainsString('ডেলিভারি চার্জ ঢাকার ভেতরে', $r->body, 'not the charge template');
    }

    // ---------------------------------------------- B9. no confirmation msg

    /** Ordered but no confirmation message: the order-status family
     *  answers from the record — the word 'পাইনি' must not turn it
     *  into the angry handover. */
    public function test_a_missing_confirmation_message_gets_the_order_status(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, self::DETAILS);
        $confirmed = $this->send($brain, $conv, 'confirm korchi');
        $this->assertSame('order_confirmed', $confirmed->meta['kind'] ?? null);
        $order = Order::where('business_id', $biz->id)->firstOrFail();

        $r = $this->send($brain, $conv, 'আমি অর্ডার করেছি কিন্তু কোনো কনফার্মেশন মেসেজ পাইনি');
        $this->assertNotNull($r);
        $this->assertSame('order_status', $r->meta['kind'] ?? null);
        $this->assertStringContainsString((string) $order->invoice_no, $r->body, 'the real order answers');
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'no angry handover for a status question');
    }

    /** The same words with no order behind them: an honest
     *  not-found, still not a handover. */
    public function test_a_missing_confirmation_without_an_order_is_an_honest_not_found(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('noconf');
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer', 'customer_phone' => '01612345678',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কোনো মেসেজ পাইনি');
        $this->assertNotNull($r);
        $this->assertSame('order_status', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('কোনো অর্ডার খুঁজে পাইনি', $r->body);
        $this->assertFalse((bool) $conv->fresh()->needs_human);
    }

    // ------------------------------------------------- B10. courier no call

    /** "কুরিয়ার ফোন করেনি" — a handover with an apology, never an
     *  argument. */
    public function test_courier_did_not_call_hands_over_with_an_ack(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'কুরিয়ার ফোন করেনি');
        $this->assertNotNull($r);
        $this->assertSame('Courier did not call', $r->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertStringContainsString('কুরিয়ারের সাথে কথা বলে', $r->body);
    }

    // --------------------------------------------------------- B11. invoice

    /** "মেমো লাগবে" is an invoice request for a human — the weak
     *  order word inside it must not start an order collection. */
    public function test_a_memo_request_is_not_an_order(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'মেমো লাগবে');
        $this->assertNotNull($r);
        $this->assertSame('Invoice or memo request', $r->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertNotSame('collect_question', $r->meta['kind'] ?? null, 'no order collection starts');
        $this->assertSame(0, $this->orderCount($biz->id));
    }

    /** "ইনভয়েস দেন" reaches the same rule. */
    public function test_an_invoice_request_hands_over(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'ইনভয়েস দেন');
        $this->assertNotNull($r);
        $this->assertSame('Invoice or memo request', $r->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    // ------------------------------------------------------- B12. gift wrap

    /** Gift wrap: an honest "let me check" — the service is never
     *  promised. */
    public function test_a_gift_wrap_question_defers_without_promising(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'গিফট র‍্যাপ হবে?');
        $this->assertNotNull($r);
        $this->assertSame('Gift wrap or note', $r->rule?->name);
        $this->assertStringContainsString('নিশ্চিত করে জানাচ্ছি', $r->body);
        $this->assertEmpty($r->meta['no_rule'] ?? null);
    }

    // --------------------------------------------------------- B13. reserve

    /** "২ দিন রেখে দেন" — a human confirms from stock; the AI
     *  promises no reservation. */
    public function test_a_reserve_request_hands_over(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'এটা ২ দিন রেখে দেন');
        $this->assertNotNull($r);
        $this->assertSame('Reserve or hold request', $r->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertStringContainsString('রেখে দেওয়ার বিষয়টি', $r->body);
    }

    // ----------------------------------------------------------- B14. abuse

    /** Profanity: fully silent — no reply at all — with the human
     *  flag up. */
    public function test_abusive_language_is_silent_and_flagged(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'তোরা সব হারামজাদা, টাকা ফেরত দে');
        $this->assertNull($r, 'abuse gets no reply text at all');
        $this->assertSame(0, $this->outCount($conv), 'nothing was sent or drafted');
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertTrue(
            \App\Models\AiActivity::where('conversation_id', $conv->id)->where('detail', 'like', '%Abusive language%')->exists(),
            'the handover is logged under the abuse rule'
        );
    }

    /** PIN: an angry complaint without profanity keeps the angry
     *  path (a complaint is not abuse). */
    public function test_an_angry_complaint_without_profanity_stays_angry(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'অভিযোগ করছি, আপনাদের সার্ভিস নিয়ে আমি খুবই অসন্তুষ্ট');
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertTrue(
            \App\Models\AiActivity::where('conversation_id', $conv->id)->where('detail', 'like', '%Angry customer%')->exists(),
            'the angry rule owns a complaint'
        );
        $this->assertFalse(
            \App\Models\AiActivity::where('conversation_id', $conv->id)->where('detail', 'like', '%Abusive language%')->exists()
        );
    }

    // ----------------------------------------------------------- B15. usage

    /** Ingredient and usage questions defer honestly — no product
     *  facts are invented. */
    public function test_usage_and_ingredient_questions_defer_honestly(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'উপাদান কি?');
        $this->assertNotNull($r);
        $this->assertSame('Usage or ingredients question', $r->rule?->name);
        $this->assertStringContainsString('উপাদান', $r->body);
        $this->assertStringContainsString('নিশ্চিত করে জানাচ্ছি', $r->body);

        $r = $this->send($brain, $conv, 'কিভাবে ব্যবহার করব?');
        $this->assertNotNull($r);
        $this->assertSame('Usage or ingredients question', $r->rule?->name);
    }

    // ------------------------------------------------------- B16. correction

    /** A correction after the summary re-renders it at once with
     *  the corrected details; the order then carries the NEW phone. */
    public function test_a_correction_after_a_summary_rerenders_it(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, self::DETAILS);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('01612345678', $summary->body);

        $r = $this->send($brain, $conv, 'নম্বরটা ভুল, সঠিক নম্বর 01798765432');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null, 'the correction re-renders the summary, it does not just point at the stale one');
        $this->assertStringContainsString('01798765432', $r->body, 'the new summary carries the corrected number');
        $this->assertSame(0, $this->orderCount($biz->id), 'the correction itself creates no order');

        $r = $this->send($brain, $conv, 'confirm korchi');
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame('01798765432', $order->customer_phone, 'the order is placed with the corrected number');
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point');
    }

    /** A correction mid-collection: the corrected number wins
     *  (batch 2's precedence) AND the correction text itself must
     *  not be swallowed as the address — the summary is built with
     *  the corrected number and the real address. (Pre-fix this
     *  FAILED: both blob-address rules stored "নম্বরটা ভুল,
     *  সঠিকটা" as the address and the collection completed on it.) */
    public function test_a_correction_mid_collection_wins(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'order korte chai');
        $this->send($brain, $conv, '01612345678');
        $this->assertSame('01612345678', (string) $conv->fresh()->customer_phone);

        $this->send($brain, $conv, 'নম্বরটা ভুল, সঠিকটা 01798765432');
        $this->assertSame('01798765432', (string) $conv->fresh()->customer_phone, 'the corrected number replaces the first one');

        $summary = $this->send($brain, $conv, 'বাসা ৫, রোড ২, মিরপুর, ঢাকা');
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('01798765432', $summary->body);
    }

    // ---------------------------------------------------------- C23. hours

    /** Off-hours: the customer hears ONE line per off-hours stretch
     *  (not silence), while the real reply still only drafts. */
    public function test_off_hours_sends_one_line_per_stretch(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        app(SettingsService::class)->set("ai.{$biz->id}.working_hours", '9 AM - 11 PM');
        $brain = app(RuleBrainService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-26 03:00', 'Asia/Dhaka'));
        $r = $this->send($brain, $conv, 'দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('draft', $r->status, 'off-hours the reply itself still only drafts');
        $lines = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours');
        $this->assertCount(1, $lines, 'one customer-facing line goes out');
        $this->assertSame('sent', $lines->first()->status, 'the line itself is sent, not drafted');
        $this->assertStringContainsString('বন্ধ আছে', $lines->first()->body);

        $this->send($brain, $conv, 'স্টকে আছে?');
        $lines = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours');
        $this->assertCount(1, $lines, 'never twice in the same stretch');

        Carbon::setTestNow(Carbon::parse('2026-11-27 03:00', 'Asia/Dhaka'));
        $this->send($brain, $conv, 'দাম কত?');
        $lines = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours');
        $this->assertCount(2, $lines, 'the next night is a new stretch: one more line');
    }

    /** Inside working hours there is no line and replies send. */
    public function test_inside_working_hours_there_is_no_off_hours_line(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        app(SettingsService::class)->set("ai.{$biz->id}.working_hours", '9 AM - 11 PM');
        $brain = app(RuleBrainService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00', 'Asia/Dhaka'));
        $r = $this->send($brain, $conv, 'দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('sent', $r->status);
        $lines = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours');
        $this->assertCount(0, $lines);
    }

    /** A shop that chose Draft itself gets no line either — the
     *  line belongs to the hours step-down only. */
    public function test_a_draft_mode_shop_gets_no_off_hours_line(): void
    {
        $this->setMode('draft');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        app(SettingsService::class)->set("ai.{$biz->id}.working_hours", '9 AM - 11 PM');
        $brain = app(RuleBrainService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-26 03:00', 'Asia/Dhaka'));
        $r = $this->send($brain, $conv, 'দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('draft', $r->status);
        $lines = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours');
        $this->assertCount(0, $lines, 'no step-down happened, so no line');
    }

    // -------------------------------------------------------- C24. English

    /** An English price question gets an English price answer. */
    public function test_an_english_price_question_gets_an_english_answer(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'What is the price of this panjabi?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('The price of', $r->body);
        $this->assertStringContainsString('1,300', $r->body);
        $this->assertStringNotContainsString('দাম', $r->body, 'no Bangla template for an English question');
    }

    /** English delivery and stock answers. */
    public function test_english_delivery_and_stock_answers(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'How much is the delivery charge?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Delivery charge is', $r->body);
        $this->assertStringContainsString('120', $r->body, 'the outside-Dhaka figure is in the English template too');

        $r = $this->send($brain, $conv, 'Is this in stock?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('in stock: 10', $r->body);
    }

    /** An English greeting gets the English greeting. */
    public function test_an_english_greeting_gets_an_english_greeting(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'Hello there');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Welcome', $r->body);
        $this->assertStringNotContainsString('স্বাগতম', $r->body);
    }

    /** PIN: romanised Bangla is not English — the Bangla template
     *  answers it, as today. */
    public function test_romanised_bangla_stays_on_the_bangla_path(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('দাম', $r->body);
        $this->assertStringNotContainsString('The price of', $r->body);
    }

    // --------------------------------------------- C25. returning customer

    /**
     * A shop whose customer already has a DELIVERED order, with the
     * details consumed by that order (the record still carries
     * them). @return array{Business, User, Product, Conversation, Order}
     */
    private function returningShop(): array
    {
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);
        $this->send($brain, $conv, self::DETAILS);
        $confirmed = $this->send($brain, $conv, 'confirm korchi');
        $this->assertSame('order_confirmed', $confirmed->meta['kind'] ?? null);
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $order->status = 'Delivered';
        $order->save();

        return [$biz, $owner, $p, $conv->fresh(), $order];
    }

    /** The returning customer is ASKED about the previous address —
     *  and a "হ্যাঁ" puts exactly that address on the new summary. */
    public function test_a_returning_customer_is_asked_about_the_previous_address(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, $order] = $this->returningShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'order korte chai');
        $r = $this->send($brain, $conv, '01612345678');
        $this->assertNotNull($r);
        $this->assertStringContainsString('আগের ঠিকানায় পাঠাব?', $r->body, 'the previous address is offered as a question, never silently reused');
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r->body);

        $summary = $this->send($brain, $conv, 'হ্যাঁ');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $summary->body, 'the confirmed previous address is what the summary uses');
    }

    /** A "না" asks for the new address instead. */
    public function test_a_no_to_the_previous_address_asks_for_the_new_one(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, $order] = $this->returningShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'order korte chai');
        $this->send($brain, $conv, '01612345678');
        $r = $this->send($brain, $conv, 'না');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('ডেলিভারির ঠিকানাটা দিন', $r->body);

        $summary = $this->send($brain, $conv, 'বাসা ৯, রোড ৩, ধানমন্ডি, ঢাকা');
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('ধানমন্ডি', $summary->body);
    }

    /** PIN: with no delivered order behind the record, the address
     *  question is the plain one — the confirm question never fires. */
    public function test_no_previous_order_means_the_plain_address_question(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'order korte chai');
        $r = $this->send($brain, $conv, '01612345678');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('আগের ঠিকানায় পাঠাব?', $r->body);
    }

    // ------------------------------------------------------------ catalog

    /** The library grew by the nine genuinely missing cases. */
    public function test_the_catalog_carries_the_new_cases(): void
    {
        $this->assertCount(56, AiRule::defaults(), '47 + 9 new defs');
        [$biz, $owner] = $this->shopBase('catalog');
        AiRule::where('business_id', $biz->id)->delete();
        AiRule::ensureDefaults($biz->id);
        $this->assertSame(56, AiRule::where('business_id', $biz->id)->count(), 'ensureDefaults installs the grown library');
    }
}

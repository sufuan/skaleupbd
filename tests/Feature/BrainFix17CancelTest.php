<?php

namespace Tests\Feature;

use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Batch-17 cancel fix (2026-11-26, from the Stage 1 LIVE re-check on
 * the CRUD Test Shop's real Messenger thread, ~09:13): a customer
 * sent "cancel kore den" with NO order in flight and heard NOTHING
 * back. The platform logged two "draft_created — No rule matched"
 * events and created two wrong drafts: an order-intake prompt asking
 * for name/mobile/address, and a Price answer quoting the wrong
 * product (CRUD Test Panjabi 1 at 1,300). The Cancel order rule
 * showed 0 runs / Never.
 *
 * Root cause: the Cancel order rule is an intent rule whose only
 * trigger is the model-classified intent "cancel_order", and the
 * deterministic keyword list behind it (INTENTS['cancel_order'])
 * never contained the phrase the customer actually used. "cancel
 * kore den" matches none of "cancel koren", "cancel korun",
 * "ক্যান্সেল করে দিন" or "order cancel", and the bare words
 * "cancel", "বাতিল", "বাদ দিন" were absent too — so detectIntent
 * returned null, matchRule's intent-word fallback missed for the
 * same reason, the stage-2 classifier was rate-limited and
 * returned nothing, and the message fell into the no-rule fallback
 * which invented a price/order draft from the focus product. The
 * batch-7 flowCancellation path only fires while a flow is live,
 * so with no order in flight there was no working path at all.
 *
 * The fix: (1) the cancel keyword list gains the bare words and
 * the dismissal family, so detectIntent + matchRule's intent-word
 * fallback fire without any model call; (2) a cancel-signal guard
 * in handleIncoming, before stage 2 and the no-rule fallback,
 * routes any residual cancel-like message to the shop's Cancel
 * order rule; (3) the handover flag for the Cancel rule is
 * deferred until flowCancellation has had its say, so an in-flow
 * cancel still closes with the plain collect_cancel and no human
 * flag (batch 16's pin), while a no-flow cancel keeps the flag.
 */
class BrainFix17CancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent — the live
        // failure happened exactly while the talker was rate-limited,
        // so the deterministic layer must carry the cancel alone.
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
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => 'full_auto', 'whatsapp' => 'full_auto',
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Business, Product, Product, Product, Conversation} */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Cancel Livecheck Shop', 'public_id' => 'cancel-livecheck-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $p3 = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'CAP-1', 'price' => 250, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Livecheck Customer',
            'product_id' => $p1->id,
        ]);

        return [$biz, $p1, $p2, $p3, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function cancelRule(int $bizId): ?AiRule
    {
        return AiRule::where('business_id', $bizId)->where('trigger_type', 'intent')
            ->where('match_text', 'cancel_order')->first();
    }

    /** @return \Illuminate\Support\Collection<int, ChatMessage> */
    private function outgoing(Conversation $conv)
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')->where('sender', 'ai')->get()->values();
    }

    // ------------------------------------- the live failure, replayed

    /**
     * THE live replay: no order in flight, no flow, focus product
     * standing — "cancel kore den" must get the Cancel order rule's
     * acknowledgement, SENT in Full auto, flagged for a human, and
     * nothing else. No price draft, no order-intake draft, no order.
     */
    public function test_cancel_kore_den_with_no_order_in_flight_gets_the_cancel_ack(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'cancel kore den');

        $this->assertNotNull($r, 'the cancel is answered, not dropped');
        $this->assertSame('sent', $r->status, 'Full auto sends the acknowledgement');
        $this->assertStringContainsString('আপনার বাতিলের অনুরোধ পেয়েছি', (string) $r->body, 'the Cancel order rule template');
        $this->assertSame($this->cancelRule($biz->id)?->id, $r->ai_rule_id, 'answered by the Cancel order rule');
        $this->assertArrayNotHasKey('no_rule', (array) ($r->meta ?? []), 'never the no-rule fallback');
        $this->assertNotSame('collect_question', $r->meta['kind'] ?? null, 'never an order-intake prompt');
        $this->assertStringNotContainsString('1,300', (string) $r->body, 'no price quoted at a cancelling customer');
        $this->assertStringNotContainsString('1300', (string) $r->body, 'no price quoted at a cancelling customer');
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the thread stays flagged for the human confirmation');
        $this->assertSame(0, Order::where('business_id', $biz->id)->count(), 'no order from a cancel');
        $this->assertCount(1, $this->outgoing($conv), 'exactly one reply, not a price draft plus an order draft');
    }

    /**
     * The cancel family, Bangla + romanised, each through the
     * deterministic layer only (classifier stubbed silent): every
     * phrase must classify cancel_order and match the Cancel order
     * rule, with no model call involved.
     */
    #[DataProvider('cancelPhrases')]
    public function test_the_cancel_family_classifies_without_any_model_call(string $text): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $this->assertSame('cancel_order', $brain->detectIntent($text, $conv->fresh()), "detectIntent('{$text}')");
        $rule = $brain->matchRule($biz->id, $text, 'facebook', $conv->fresh());
        $this->assertNotNull($rule, "matchRule('{$text}')");
        $this->assertSame('Cancel order', $rule->name);
    }

    /** @return array<string, array{string}> */
    public static function cancelPhrases(): array
    {
        return [
            'the live phrase' => ['cancel kore den'],
            'romanised din' => ['cancel kore din'],
            'romanised future' => ['cancel korbo'],
            'bare cancel' => ['cancel'],
            'please cancel' => ['please cancel it'],
            'bangla kore din' => ['বাতিল করে দিন'],
            'bare batil' => ['বাতিল'],
            'romanised batil' => ['batil kore den'],
            'bangla dismissal' => ['বাদ দিন'],
            'bangla dismissal den' => ['বাদ দেন'],
            'romanised dismissal' => ['bad din'],
            'order cancel' => ['অর্ডার ক্যান্সেল'],
            'cancel my order' => ['cancel my order'],
            'cancel korun' => ['cancel korun'],
            'cancel koren' => ['cancel koren'],
            'bangla cancel kore din' => ['ক্যান্সেল করে দিন'],
        ];
    }

    /**
     * Residual cancel phrasings the keyword list does not name —
     * "do not send", "আর পাঠাবেন না" — must still reach the Cancel
     * order rule through the guard, never the no-rule price draft
     * and never order taking, even with stage 2 silent.
     */
    #[DataProvider('residualCancelPhrases')]
    public function test_residual_cancel_phrasings_never_reach_the_no_rule_fallback(string $text): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, $text);

        $this->assertNotNull($r, "a reply for '{$text}'");
        $this->assertSame('sent', $r->status);
        $this->assertSame($this->cancelRule($biz->id)?->id, $r->ai_rule_id, "the Cancel order rule answers '{$text}'");
        $this->assertStringContainsString('আপনার বাতিলের অনুরোধ পেয়েছি', (string) $r->body);
        $this->assertArrayNotHasKey('no_rule', (array) ($r->meta ?? []));
        $this->assertNotSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertSame(0, Order::where('business_id', $biz->id)->count(), 'no order from a cancel');
    }

    /** @return array<string, array{string}> */
    public static function residualCancelPhrases(): array
    {
        return [
            'do not send' => ['please do not send the order'],
            'bangla do not send' => ['আর পাঠাবেন না'],
            'stop my order' => ['stop my order'],
        ];
    }

    /**
     * No focus product at all (the second half of the batch-16
     * pin, which returned null): the cancel still gets its
     * acknowledgement — the rule needs no product to answer.
     */
    public function test_a_cancel_with_no_focus_product_still_gets_the_ack(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $bare = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Bare Customer',
        ]);

        $r = $this->send($brain, $bare, 'cancel kore den');

        $this->assertNotNull($r, 'a cancel with no product in focus is answered, not dropped');
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('আপনার বাতিলের অনুরোধ পেয়েছি', (string) $r->body);
        $this->assertSame($this->cancelRule($biz->id)?->id, $r->ai_rule_id);
        $this->assertTrue((bool) $bare->fresh()->needs_human);
    }

    // ------------------------------------- the in-flow path, intact

    /**
     * Mid-bargain cancel (batch 7 intact): the ladder stands, the
     * customer cancels — the flow closes with the plain
     * collect_cancel, the ladder and offer are cleared, and NO
     * human flag is raised (batch 16's pin: a clean cancel raises
     * no flag). The Cancel rule's handover flag must not leak into
     * this path.
     */
    public function test_mid_bargain_cancel_still_closes_the_flow_with_no_human_flag(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(1235.0, (float) ($offer->meta['offer'] ?? 0), 'the ladder stands at 1,235');
        $this->assertSame(1235.0, (float) $conv->fresh()->neg_offer);

        $r = $this->send($brain, $conv, 'cancel kore den');
        $this->assertNotNull($r);
        $this->assertSame('collect_cancel', $r->meta['kind'] ?? null, 'the flow-close acknowledgement, not the rule ack');
        $this->assertStringContainsString('অর্ডারটা রাখা হলো না', (string) $r->body);

        $fresh = $conv->fresh();
        $this->assertSame(0, (int) $fresh->neg_step, 'the ladder is cleared');
        $this->assertNull($fresh->neg_offer, 'the standing offer is cleared');
        $this->assertFalse((bool) $fresh->needs_human, 'a clean in-flow cancel raises no human flag');
        $this->assertSame(0, Order::where('business_id', $biz->id)->count());

        // And the next order intent starts fresh, as before.
        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'a fresh flow, not the cancelled bargain');
    }

    /**
     * The dismissal family must not swallow the polite decline:
     * "লাগবে না" with no flow is still the decline rule's calm
     * close, not a cancel handover.
     */
    public function test_a_polite_decline_is_not_hijacked_by_the_cancel_words(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'লাগবে না');

        $this->assertNotNull($r);
        $this->assertStringContainsString('কোনো সমস্যা নেই', (string) $r->body, 'the Polite decline close');
        $this->assertStringNotContainsString('বাতিলের অনুরোধ', (string) $r->body, 'not the cancel acknowledgement');
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'a decline raises no human flag');
    }
}

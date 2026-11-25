<?php

namespace Tests\Feature;

use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\User;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brain fix batch 10 (2026-11-25, from an external expert review of
 * RuleBrainService.php + AiRule.php): fallback safety.
 *
 *  1. A no-rule fallback reply (the focus product's description or
 *     price line, meta no_rule) AUTO-SENT in Full auto — and in plain
 *     Auto too, since it commits nothing — for messages that matched
 *     nothing at all ("ওকে", "ঠিক আছে"). The code comment claimed a
 *     human always checks a no-rule draft; the mode decided otherwise.
 *     Now: a no-rule reply is ALWAYS a draft and the conversation is
 *     flagged needs_human.
 *  2. Every path that logs 'unanswered' and returns null left the
 *     conversation unflagged — the customer waited and no staff member
 *     could see the thread needed a human. Now each one raises
 *     needs_human, including the catch-all net in processPendingLocked.
 *  3. Three handover dead-ends told the customer NOTHING: the bargain
 *     floor, collection giving up on incomplete order data, and a
 *     handover rule with no acknowledgement template (legal_threat).
 *     Each now sends ONE holding line first — at most one per
 *     conversation per 24 hours, the throttle holdingLine() already
 *     used for angry customers (same meta kind, so the budget is
 *     shared across every holding line).
 */
class BrainFix10Test extends TestCase
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
     * One shop, one product (CRUD Test Panjabi, 1,300 — ladder
     * 1,235 / 1,170 / floor 1,105).
     *
     * @return array{Business, Product}
     */
    private function shop(?string $description = null): array
    {
        $biz = Business::create(['name' => 'Fallback Shop', 'public_id' => 'fallback-shop-' . uniqid(), 'status' => 'approved']);
        User::create([
            'name' => 'Fallback Owner', 'email' => 'fallback-owner-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $attrs = ['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10];
        if ($description !== null) {
            $attrs['short_description'] = $description;
        }
        $p = Product::create($attrs);

        return [$biz, $p];
    }

    private function conversation(Business $biz, ?Product $focus = null): Conversation
    {
        return Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Fallback Customer',
            'product_id' => $focus?->id,
        ]);
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function holdingCount(Conversation $conv): int
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')
            ->get()
            ->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'holding')
            ->count();
    }

    // ---- Change 1: a no-rule fallback never auto-sends -------------

    public function test_unmatched_message_with_a_focus_product_becomes_a_draft_and_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop('খুব ভালো মানের পাঞ্জাবি, আরামদায়ক কাপড়।');
        $conv = $this->conversation($biz, $p);
        $brain = app(RuleBrainService::class);

        // "ওকে" matches no rule, no intent, no knowledge entry — the
        // chain falls to the no-rule fallback over the focus product.
        $r = $this->send($brain, $conv, 'ওকে');
        $this->assertNotNull($r);
        $this->assertTrue((bool) ($r->meta['no_rule'] ?? false), 'the reply really is the no-rule fallback');
        $this->assertSame('draft', $r->status, 'a no-rule reply must never auto-send, whatever the mode');
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the thread is flagged for a human');
    }

    public function test_unmatched_message_without_a_description_also_stays_a_draft(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop();
        $conv = $this->conversation($biz, $p);
        $brain = app(RuleBrainService::class);

        // No description on the product: the fallback is the price line.
        $r = $this->send($brain, $conv, 'আচ্ছা তাহলে');
        $this->assertNotNull($r);
        $this->assertTrue((bool) ($r->meta['no_rule'] ?? false));
        $this->assertStringContainsString('দাম', $r->body);
        $this->assertSame('draft', $r->status);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    /**
     * The scoping that keeps a proven flow intact: the media job's
     * high-confidence pass-through (a photo whose caption IS the
     * product SKU) reaches the same no-rule product answer — but
     * there it is the designed reply for a matched photo, so it
     * still sends and does NOT flag (the photo suite's pinned
     * behaviour: "no human flag for a matched photo").
     */
    public function test_media_match_pass_through_keeps_its_designed_answer(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop('খুব ভালো মানের পাঞ্জাবি, আরামদায়ক কাপড়।');
        $conv = $this->conversation($biz, $p);
        $in = ChatMessage::create([
            'business_id' => $biz->id, 'conversation_id' => $conv->id,
            'direction' => 'in', 'sender' => 'customer', 'body' => 'CRUD-101',
            'status' => 'received', 'attachment_type' => 'image',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $brain->handleIncoming($conv->fresh(), 'CRUD-101', ['prestored_ids' => [$in->id], 'media_match' => true]);
        $this->assertNotNull($r);
        $this->assertTrue((bool) ($r->meta['no_rule'] ?? false), 'the same no-rule product answer');
        $this->assertSame('sent', $r->status, 'a matched photo is still answered directly');
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'no human flag for a matched photo');
    }

    // ---- Change 2: every 'unanswered' path raises needs_human ------

    public function test_bargain_with_no_product_in_focus_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz] = $this->shop();
        $conv = $this->conversation($biz);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNull($r, 'still no reply — there is nothing to bargain over');
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists(),
            'the miss is on the record'
        );
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'and the thread is flagged for a human');
    }

    public function test_order_intent_with_no_product_in_focus_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz] = $this->shop();
        $conv = $this->conversation($biz);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'order korte chai');
        $this->assertNull($r);
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists()
        );
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_matched_rule_with_an_empty_template_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop();
        $conv = $this->conversation($biz, $p);
        AiRule::create([
            'business_id' => $biz->id, 'name' => 'Empty template rule',
            'trigger_type' => 'keyword', 'match_text' => 'framistan',
            'action' => 'answer', 'reply_template' => '', 'active' => true,
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'framistan');
        $this->assertNull($r, 'an empty template still sends nothing');
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists()
        );
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_no_rule_and_no_product_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz] = $this->shop();
        $conv = $this->conversation($biz);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'ওকে');
        $this->assertNull($r);
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists()
        );
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_the_process_pending_net_flags_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz] = $this->shop();
        $conv = $this->conversation($biz);
        $conv->ai_paused = true;
        $conv->save();

        // A claimed burst the brain stays silent on (AI paused): the
        // catch-all net in processPendingLocked logs 'unanswered' —
        // and now flags the thread too.
        $in = ChatMessage::create([
            'business_id' => $biz->id, 'conversation_id' => $conv->id,
            'direction' => 'in', 'sender' => 'customer', 'body' => 'dam koto?',
            'status' => 'received',
        ]);
        $in->created_at = now()->subMinutes(5);
        $in->save();

        $result = app(RuleBrainService::class)->processPending($conv->fresh());
        $this->assertNull($result);
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists(),
            'the net still logs the miss'
        );
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'and the thread is flagged for a human');
    }

    // ---- Change 3: holding lines instead of silence ----------------

    public function test_bargain_floor_sends_exactly_one_holding_line(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop();
        $conv = $this->conversation($biz, $p);
        $brain = app(RuleBrainService::class);

        // Walk the ladder down: 1,235 -> 1,170 -> 1,105 (floor) ->
        // the floor repeated once. Every push is answered.
        foreach ([1235.0, 1170.0, 1105.0, 1105.0] as $expected) {
            $r = $this->send($brain, $conv, 'ektu kom hobe na?');
            $this->assertNotNull($r);
            $this->assertSame($expected, (float) ($r->meta['offer'] ?? 0));
        }

        // The next push reaches the floor dead-end: previously the
        // customer heard nothing at all. Now one holding line goes
        // out and the thread is flagged.
        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r, 'the floor dead-end answers with a holding line, not silence');
        $this->assertSame('holding', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('একটু অপেক্ষা করুন, আমাদের প্রতিনিধি দেখে জানাচ্ছেন।', $r->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human);

        // A second floor event inside 24 hours: no second line.
        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNull($r);
        $this->assertSame(1, $this->holdingCount($conv), 'at most one holding line per conversation per 24 hours');
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_templateless_handover_sends_a_holding_line(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop();
        $conv = $this->conversation($biz, $p);
        $brain = app(RuleBrainService::class);

        // legal_threat is a handover rule with NO acknowledgement
        // template: the handover was logged and flagged, but the
        // customer heard nothing.
        $r = $this->send($brain, $conv, 'আমি মামলা করব');
        $this->assertNotNull($r, 'a template-less handover answers with a holding line, not silence');
        $this->assertSame('holding', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('একটু অপেক্ষা করুন, আমাদের প্রতিনিধি দেখে জানাচ্ছেন।', $r->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the handover flag stays up');

        // A second template-less handover inside 24 hours shares the
        // same one-line budget.
        $r = $this->send($brain, $conv, 'আমি মামলা করব');
        $this->assertNull($r);
        $this->assertSame(1, $this->holdingCount($conv));
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_collection_giving_up_sends_a_holding_line(): void
    {
        $this->setMode('full_auto');
        [$biz, $p] = $this->shop();
        $conv = $this->conversation($biz, $p);
        $brain = app(RuleBrainService::class);

        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collecting', $conv->fresh()->state);

        // Three answers that supply nothing exhaust the retries: the
        // flow gives up and hands over. Previously the customer heard
        // nothing at that point; now a holding line goes out.
        $r = null;
        foreach (['xyz qqq', 'xyz qqq', 'xyz qqq'] as $junk) {
            $r = $this->send($brain, $conv, $junk);
        }
        $this->assertNotNull($r, 'the give-up point answers with a holding line, not silence');
        $this->assertSame('holding', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('একটু অপেক্ষা করুন, আমাদের প্রতিনিধি দেখে জানাচ্ছেন।', $r->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertNull($conv->fresh()->state, 'the collection is closed, as before');
    }
}

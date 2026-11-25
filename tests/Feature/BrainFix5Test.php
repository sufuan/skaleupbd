<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brain fix batch 5 (2026-11-25, from the batch-4 LIVE spot-check):
 * the thread was already mid-collection when the spot-check began,
 * and in that state the customer's "CRUD-101 er dam koto?" (price
 * question) and "ektu kom hobe na?" (bargain push) were BOTH answered
 * with the identical collect prompt — handleCollect never classified
 * the message at all, so the questions were swallowed. Real customers
 * ask side questions mid-checkout; the brain must answer them without
 * losing the order flow:
 *
 *  1. A detail-free price/bargain message during collection is
 *     answered through the normal machinery (the price template; the
 *     negotiation ladder — a rung agreed mid-collection is the rung
 *     the eventual summary charges), with the collect reminder for
 *     the still-missing pieces appended and the collection state
 *     left untouched.
 *  2. A cancellation ("বাদ দিন", "লাগবে না", "নিব না") during
 *     collection exits the flow cleanly. Before, the informal words
 *     were not in the cancel list at all and were re-asked until the
 *     retries handed the thread to a human. A message carrying
 *     contact details never triggers the exit.
 */
class BrainFix5Test extends TestCase
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
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => 'full_auto', 'whatsapp' => 'full_auto',
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    /**
     * One product (CRUD Test Panjabi, 1,300 — ladder 1,235 / 1,170 /
     * floor 1,105) and a fresh conversation with no details on record.
     *
     * @return array{Business, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Collect Shop', 'public_id' => 'collect-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Side Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $p, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /** Drive the conversation into guided collection and assert it got there. */
    private function startCollecting(RuleBrainService $brain, Conversation $conv): void
    {
        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertSame('collecting', $conv->fresh()->state);
    }

    /**
     * (a) Mid-collect price question: the reply answers the price AND
     * appends the collect reminder for the missing pieces, and the
     * conversation is still collecting afterwards.
     */
    public function test_mid_collect_price_question_is_answered_with_the_reminder_appended(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        $r = $this->send($brain, $conv, 'CRUD-101 er dam koto?');
        $this->assertNotNull($r);
        $this->assertSame('collect_side_answer', $r->meta['kind'] ?? null);
        $this->assertSame('price', $r->meta['side'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body, 'the answer names the product');
        $this->assertStringContainsString('1,300', $r->body, 'the answer states the correct price');
        $this->assertStringContainsString('ঠিকানা', $r->body, 'the reminder still asks for the address');
        $this->assertStringContainsString('নম্বর', $r->body, 'the reminder still asks for the number');

        $fresh = $conv->fresh();
        $this->assertSame('collecting', $fresh->state, 'still in collect state');
        $this->assertNotEmpty(($fresh->state_data ?? [])['collect'] ?? null, 'the collect step survives');
    }

    /**
     * (b) Mid-collect bargain push: a ladder offer below list, the
     * product named, the reminder appended — and the negotiation is
     * REMEMBERED: when the details then arrive, the summary charges
     * the bargained price, not the list price.
     */
    public function test_mid_collect_bargain_moves_the_ladder_and_the_summary_uses_it(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r);
        $this->assertSame('collect_side_answer', $r->meta['kind'] ?? null);
        $this->assertSame('bargain', $r->meta['side'] ?? null);
        $this->assertSame(1235.0, (float) ($r->meta['offer'] ?? 0), 'the first ladder rung below 1,300');
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body, 'the offer names the product');
        $this->assertStringContainsString('1,235', $r->body);
        $this->assertStringNotContainsString('ডেলিভারি চার্জ', $r->body, 'bargain purity: no delivery charge in the offer');
        $this->assertStringContainsString('ঠিকানা', $r->body, 'the reminder is appended');

        $fresh = $conv->fresh();
        $this->assertSame('collecting', $fresh->state, 'still in collect state');
        $this->assertSame(1235.0, (float) $fresh->neg_offer, 'the ladder move is remembered on the conversation');

        $summary = $this->send($brain, $conv, 'Amar nam Side Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(1235.0, (float) ($summary->meta['unit_price'] ?? 0), 'the summary charges the bargained price');
        $this->assertSame(1295.0, (float) ($summary->meta['total'] ?? 0), 'bargained price + the 60 delivery charge');
        $this->assertStringContainsString('মোট: 1,295', $summary->body);
    }

    /**
     * (c) Mid-collect cancel: the flow exits with an acknowledgement
     * (both the long-standing word and the newly covered informal
     * one), and a price question afterwards gets the plain price
     * answer — no collect prompt.
     */
    public function test_mid_collect_cancel_exits_and_price_answers_normally_again(): void
    {
        foreach (['বাদ দিন', 'লাগবে না'] as $word) {
            [$biz, $p, $conv] = $this->shop();
            $brain = app(RuleBrainService::class);
            $this->startCollecting($brain, $conv);

            $r = $this->send($brain, $conv, $word);
            $this->assertNotNull($r, "cancel word: {$word}");
            $this->assertSame('collect_cancel', $r->meta['kind'] ?? null, "cancel word: {$word}");
            $this->assertStringContainsString('অর্ডারটা রাখা হলো না', $r->body);

            $fresh = $conv->fresh();
            $this->assertNull($fresh->state, 'the flow is exited');
            $this->assertEmpty(($fresh->state_data ?? [])['collect'] ?? null, 'the collect state is cleared');

            $price = $this->send($brain, $conv, 'CRUD-101 er dam koto?');
            $this->assertNotNull($price);
            $this->assertStringContainsString('1,300', $price->body, 'the plain price answer is back');
            $this->assertStringNotContainsString('অর্ডারের জন্য আপনার', $price->body, 'no collect prompt after the exit');
            $this->assertNull($conv->fresh()->state, 'asking a price does not restart collection');
        }
    }

    /**
     * (d) A details-bearing message mid-collect never triggers the
     * side-question path: it is collection input, answered with the
     * collect flow's own next step.
     */
    public function test_details_bearing_message_mid_collect_never_takes_the_side_path(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        // The phone number alone: captured, and the flow asks for what
        // is still missing — no price answer, no side path.
        $r = $this->send($brain, $conv, '01612345678');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertSame('01612345678', $conv->fresh()->customer_phone);
        $this->assertSame('collecting', $conv->fresh()->state);

        // A stated name riding with a price question: the name makes
        // it collection input — the question is not answered sideways
        // and no price is quoted.
        [$biz2, $p2, $conv2] = $this->shop();
        $this->startCollecting($brain, $conv2);
        $r2 = $this->send($brain, $conv2, 'Amar nam Rafik, dam koto?');
        $this->assertNotNull($r2);
        $this->assertSame('collect_question', $r2->meta['kind'] ?? null);
        $this->assertStringNotContainsString('1,300', $r2->body, 'no price answer for a details-bearing message');
        $this->assertSame('collecting', $conv2->fresh()->state);
    }
}

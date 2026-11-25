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
 * Brain fix batch 11b (2026-11-25): product resolution INSIDE
 * collect mode — the gap batch 11 left behind, proven live the
 * same evening (20:26, CRUD Test Shop thread, deployed batch 11
 * verified as head).
 *
 * The conversation was mid-collection for CRUD-101 when the
 * customer asked "Panjabi tar dam koto?". Batch 5's
 * collectSideAnswer answered it about the collect product (1,300,
 * reminder appended): handleCollect never runs batch 11's
 * resolveProduct(), so CATEGORY_MULTI could not fire. The
 * follow-up "2" was then read as a collect non-answer and the
 * collect question was simply re-asked. On a non-collecting
 * conversation the identical sequence clarifies correctly
 * (BrainFix11Test test 1) — the gap was only inside collect mode.
 *
 * Now a price/bargain side question is resolved BEFORE the side
 * answer: a category tie sends the clarification (names only, no
 * prices, no collect reminder) and the existing pick resolution
 * takes over. Picking the collect product answers the pending
 * question and collection continues with its state intact;
 * picking a different product is a product switch — the old
 * collection ends, its details are marked consumed, the focus
 * moves, and the pending question is answered for the new
 * product. A side question about the collect product itself
 * keeps batch 5's behaviour byte-for-byte.
 */
class BrainFix11bTest extends TestCase
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
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => 'full_auto', 'whatsapp' => 'full_auto',
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

    /**
     * The live shop's shape: two Panjabis at different prices + a
     * cap; the conversation's focus is the first Panjabi.
     *
     * @return array{Business, Product, Product, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Collect Resolution Shop', 'public_id' => 'collect-res-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $p3 = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'CAP-1', 'price' => 250, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Collect Customer',
            'product_id' => $p1->id,
        ]);

        return [$biz, $p1, $p2, $p3, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /**
     * Drive the conversation into collection for CRUD-101 with the
     * phone already given (the live thread's shape: some details in,
     * the address still outstanding).
     */
    private function startCollectingWithPhone(RuleBrainService $brain, Conversation $conv): void
    {
        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertSame('collecting', $conv->fresh()->state);

        $phone = $this->send($brain, $conv, '01612345678');
        $this->assertNotNull($phone);
        $this->assertSame('collect_question', $phone->meta['kind'] ?? null);
        $this->assertSame('01612345678', $conv->fresh()->customer_phone);
        $this->assertSame('collecting', $conv->fresh()->state);
    }

    /**
     * (1) THE live replay: mid-collect for CRUD-101, the ambiguous
     * category question clarifies instead of quoting the collect
     * product; picking the OTHER product switches the flow — the
     * price is answered for it, the old collection is over, and
     * the details it gathered no longer count.
     */
    public function test_ambiguous_side_question_mid_collect_clarifies_and_pick_of_other_product_switches(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollectingWithPhone($brain, $conv);

        $r = $this->send($brain, $conv, 'Panjabi tar dam koto?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('Plain Cap', $r->body, 'only the matched products are listed');
        $this->assertStringNotContainsString('1,300', $r->body, 'the collect product\'s price is not quoted');
        $this->assertStringNotContainsString('850', $r->body, 'no candidate price is quoted');
        $this->assertStringNotContainsString('ঠিকানা', $r->body, 'the collect reminder is NOT appended to a clarification');

        $mid = $conv->fresh();
        $this->assertSame('collecting', $mid->state, 'the clarification itself does not end the collection');
        $clarify = (array) (($mid->state_data ?? [])['product_clarify'] ?? []);
        $this->assertSame([(int) $p1->id, (int) $p2->id], array_map('intval', (array) ($clarify['product_ids'] ?? [])));

        $pick = $this->send($brain, $conv, '2');

        $this->assertNotNull($pick);
        $this->assertStringContainsString('850', $pick->body, 'the pending price question is answered for the picked product');
        $this->assertStringContainsString('CRUD Test Panjabi 2', $pick->body);

        $fresh = $conv->fresh();
        $this->assertSame($p2->id, (int) $fresh->product_id, 'the focus moved to the picked product');
        $this->assertNull($fresh->state, 'the old collection is over');
        $this->assertEmpty(($fresh->state_data ?? [])['collect'] ?? null, 'the collect state is cleared');
        $this->assertTrue((bool) (($fresh->state_data ?? [])['details_consumed'] ?? false), 'the old flow\'s details are marked consumed');

        // The phone the old flow collected does not populate the new
        // product's flow: ordering it asks for the details again.
        $ask = $this->send($brain, $conv, 'CRUD-102 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertStringContainsString('নম্বর', $ask->body, 'the new flow asks for the phone number again');
    }

    /**
     * (2) Picking the collect product itself: its price is answered
     * and collection continues exactly where it left off — the
     * details already given stay valid and the flow completes into
     * a summary for the same product.
     */
    public function test_pick_of_the_collect_product_answers_and_collection_continues(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollectingWithPhone($brain, $conv);

        $r = $this->send($brain, $conv, 'Panjabi tar dam koto?');
        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);

        $pick = $this->send($brain, $conv, '1');

        $this->assertNotNull($pick);
        $this->assertSame('collect_side_answer', $pick->meta['kind'] ?? null, 'the pending question is answered the batch-5 way');
        $this->assertSame('price', $pick->meta['side'] ?? null);
        $this->assertStringContainsString('1,300', $pick->body, 'the collect product\'s price is answered once it is picked');
        $this->assertStringContainsString('ঠিকানা', $pick->body, 'the reminder for the still-missing address is appended');

        $fresh = $conv->fresh();
        $this->assertSame('collecting', $fresh->state, 'collection continues');
        $this->assertNotEmpty(($fresh->state_data ?? [])['collect'] ?? null, 'the collect state is intact');
        $this->assertSame($p1->id, (int) $fresh->product_id);

        $summary = $this->send($brain, $conv, 'Amar nam Collect Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(1300.0, (float) ($summary->meta['unit_price'] ?? 0), 'the summary is for the collect product at its list price');
        $this->assertSame(1360.0, (float) ($summary->meta['total'] ?? 0), 'list price + the 60 delivery charge');
    }

    /**
     * (3) A side question naming the collect product exactly keeps
     * batch 5's behaviour byte-for-byte: the side answer with the
     * reminder appended, the collection untouched.
     */
    public function test_side_question_naming_the_collect_product_keeps_batch5_behaviour(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollectingWithPhone($brain, $conv);

        $r = $this->send($brain, $conv, 'CRUD-101 er dam koto?');

        $this->assertNotNull($r);
        $this->assertSame('collect_side_answer', $r->meta['kind'] ?? null);
        $this->assertSame('price', $r->meta['side'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('1,300', $r->body);
        $this->assertStringContainsString('ঠিকানা', $r->body, 'the reminder is appended');
        $this->assertSame('collecting', $conv->fresh()->state, 'still collecting');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['product_clarify'] ?? null, 'no clarification was raised');
    }

    /**
     * (3b) A side question naming a DIFFERENT product explicitly
     * already switches before collection is dispatched
     * (switchProductIfNamed, batch 2) — pinned here so the collect
     * resolution change cannot disturb it: the new product's price
     * is answered by the main chain and the collection is over.
     */
    public function test_explicit_different_product_mid_collect_switches_via_the_existing_path(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollectingWithPhone($brain, $conv);

        $r = $this->send($brain, $conv, 'CRUD-102 er dam koto?');

        $this->assertNotNull($r);
        $this->assertStringContainsString('850', $r->body);
        $this->assertNotSame('collect_side_answer', $r->meta['kind'] ?? null, 'the main chain answered, not the collect side path');

        $fresh = $conv->fresh();
        $this->assertSame($p2->id, (int) $fresh->product_id);
        $this->assertNull($fresh->state, 'the collection ended with the switch');
        $this->assertTrue((bool) (($fresh->state_data ?? [])['details_consumed'] ?? false), 'the old flow\'s details are marked consumed');
    }

    /**
     * (4) A mid-collect bargain push on the collect product still
     * persists its rung (batch 5): the resolution check must not
     * clarify or swallow a question that names no category at all,
     * and the eventual summary charges the bargained price.
     */
    public function test_mid_collect_bargain_still_persists_its_rung(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collecting', $conv->fresh()->state);

        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r);
        $this->assertSame('collect_side_answer', $r->meta['kind'] ?? null);
        $this->assertSame('bargain', $r->meta['side'] ?? null);
        $this->assertSame(1235.0, (float) ($r->meta['offer'] ?? 0), 'the first ladder rung below 1,300');
        $this->assertSame(1235.0, (float) $conv->fresh()->neg_offer, 'the ladder move is remembered');
        $this->assertSame('collecting', $conv->fresh()->state, 'still collecting');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['product_clarify'] ?? null, 'no clarification was raised');

        $summary = $this->send($brain, $conv, 'Amar nam Collect Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame(1235.0, (float) ($summary->meta['unit_price'] ?? 0), 'the summary charges the bargained price');
        $this->assertSame(1295.0, (float) ($summary->meta['total'] ?? 0));
    }
}

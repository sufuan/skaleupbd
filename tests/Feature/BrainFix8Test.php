<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brain fix batch 8 (2026-11-25, owner-raised from the WhatsApp leg):
 * a customer on a fresh channel asks a bare price question — "দাম
 * কত?" or "Panjabi tar dam koto?" — when the shop sells SEVERAL
 * products matching that word. The word scoring tied the two
 * Panjabis (same coverage, same distinctiveness) and productFor()
 * broke the tie by product id, so the AI quoted the FIRST Panjabi's
 * price (1,300) and even started a bargain ladder on it — a silent
 * guess. A bare question with no product named got a generic
 * "কোন পণ্যটার কথা বলছেন?" that never listed what the shop sells,
 * and a bare bargain got silence.
 *
 * The rule: never guess a product. Several tied candidates (or no
 * product named at all in a multi-product shop) → a clarification
 * listing the candidate names, no price quoted, no focus set, no
 * ladder started. The customer's pick (name, SKU, ordinal, or the
 * one separating word) resumes the normal flow for that product.
 * Exactly one match and explicit mentions are unchanged, and an
 * item the shop does not sell keeps the existing path.
 */
class BrainFix8Test extends TestCase
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
     * Two same-category products at different prices + one unrelated.
     *
     * @return array{Business, Product, Product, Product}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Ambiguity Shop', 'public_id' => 'amb-shop-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $p3 = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'CAP-1', 'price' => 250, 'stock_qty' => 10]);

        return [$biz, $p1, $p2, $p3];
    }

    private function conv(Business $biz, string $channel = 'whatsapp'): Conversation
    {
        return Conversation::create([
            'business_id' => $biz->id, 'channel' => $channel, 'status' => 'open',
            'customer_name' => 'Ambiguity Customer',
        ]);
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /**
     * (a) Bare price question on a fresh conversation: the reply
     * lists the shop's products and asks which one — it quotes NO
     * product's price, sets NO focus and creates NO order state.
     */
    public function test_bare_price_question_lists_products_and_quotes_nothing(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'দাম কত?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringContainsString('Plain Cap', $r->body);
        $this->assertStringNotContainsString('1,300', $r->body, 'no price is quoted for an unchosen product');
        $this->assertStringNotContainsString('850', $r->body);
        $this->assertStringNotContainsString('250', $r->body);

        $fresh = $conv->fresh();
        $this->assertNull($fresh->product_id, 'no product is put in focus');
        $this->assertSame(0, (int) $fresh->neg_step);
        $this->assertNull($fresh->state, 'no collection or order state is created');
        $this->assertSame(0, Order::where('business_id', $biz->id)->count());
        $this->assertNotEmpty(($fresh->state_data ?? [])['product_clarify']['product_ids'] ?? null, 'the candidates are remembered for the pick');
    }

    /**
     * (b) A category word matching two products: the clarification
     * lists exactly those two — not the unrelated product, and no
     * price from either.
     */
    public function test_category_word_matching_two_products_clarifies_with_both_names(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        // The owner's own WhatsApp wording.
        $r = $this->send($brain, $conv, 'Panjabi tar dam koto?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('Plain Cap', $r->body, 'only the matching candidates are listed');
        $this->assertStringNotContainsString('1,300', $r->body);
        $this->assertStringNotContainsString('850', $r->body);
        $this->assertNull($conv->fresh()->product_id);
    }

    /**
     * (c) The pick resumes the flow: an ordinal pick ("2") and a
     * separating-word pick ("panjabi 2") each land on the SECOND
     * product and answer its exact price.
     */
    public function test_pick_by_ordinal_and_by_separating_word_answers_that_products_price(): void
    {
        [$biz, $p1, $p2] = $this->shop();
        $brain = app(RuleBrainService::class);

        $conv = $this->conv($biz);
        $this->send($brain, $conv, 'Panjabi tar dam koto?');
        $r = $this->send($brain, $conv, '2');
        $this->assertNotNull($r);
        $this->assertStringContainsString('850', $r->body, 'the second product\'s exact price');
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('1,300', $r->body);
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id, 'the pick becomes the focus');
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['product_clarify'] ?? null, 'the pending question is consumed');

        $conv2 = $this->conv($biz);
        $this->send($brain, $conv2, 'Panjabi tar dam koto?');
        $r2 = $this->send($brain, $conv2, 'panjabi 2 tar dam koto?');
        $this->assertNotNull($r2);
        $this->assertStringContainsString('850', $r2->body);
        $this->assertSame($p2->id, (int) $conv2->fresh()->product_id);
    }

    /**
     * (d) Regression: an exact full name and a SKU still answer
     * directly — no clarification detour for unambiguous mentions.
     */
    public function test_full_name_and_sku_still_answer_directly(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);

        $conv = $this->conv($biz);
        $r = $this->send($brain, $conv, 'CRUD Test Panjabi 2 er dam koto?');
        $this->assertNotNull($r);
        $this->assertNotSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('850', $r->body);

        $conv2 = $this->conv($biz);
        $r2 = $this->send($brain, $conv2, 'CRUD-101 er dam koto?');
        $this->assertNotNull($r2);
        $this->assertNotSame('product_clarify', $r2->meta['kind'] ?? null);
        $this->assertStringContainsString('1,300', $r2->body);
    }

    /**
     * (e) A word matching exactly ONE product keeps the current
     * behaviour: that product's price, directly.
     */
    public function test_word_matching_exactly_one_product_answers_directly(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'plain er dam koto?');

        $this->assertNotNull($r);
        $this->assertNotSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('250', $r->body);
        $this->assertStringContainsString('Plain Cap', $r->body);
    }

    /**
     * (f) Bargain on the ambiguous word: clarification, and NO
     * ladder is started on any product. Picking one afterwards
     * starts that product's ladder from its own price.
     */
    public function test_bargain_on_ambiguous_word_clarifies_and_starts_no_ladder(): void
    {
        [$biz, $p1, $p2] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'panjabi ektu kom hobe na?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $fresh = $conv->fresh();
        $this->assertSame(0, (int) $fresh->neg_step, 'no ladder step was taken');
        $this->assertNull($fresh->neg_offer, 'no offer stands on a guessed product');
        $this->assertNull($fresh->product_id);

        // Pick the first candidate — its ladder starts from 1,300.
        $offer = $this->send($brain, $conv, 'prothom ta');
        $this->assertNotNull($offer);
        $this->assertSame($p1->id, (int) $conv->fresh()->product_id);
        $this->assertSame(1, (int) $conv->fresh()->neg_step, 'the ladder now runs on the picked product');
        $this->assertStringContainsString('1,235', $offer->body, 'the first rung under 1,300');
    }

    /**
     * (h) A tie among products that all share ONE price is not a
     * guess that can misquote — the figure is the same whichever the
     * customer meant — so the legacy scored answer stands. This is
     * the Demo Shop's colour pair ("Cotton Panjabi - White" /
     * "- Blue", both 1,250) that the golden suite pins.
     */
    public function test_tie_among_same_price_products_still_answers_directly(): void
    {
        $biz = Business::create(['name' => 'Same Price Shop', 'public_id' => 'same-price-' . uniqid(), 'status' => 'approved']);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi - White', 'sku' => 'CP-101', 'price' => 1250, 'stock_qty' => 10]);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi - Blue', 'sku' => 'CP-102', 'price' => 1250, 'stock_qty' => 10]);
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'Cotton Panjabi-র দাম কত?');

        $this->assertNotNull($r);
        $this->assertNotSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('1,250', $r->body);
    }

    /**
     * (g) Zero matches stay on the existing path: an item noun the
     * shop does not sell gets no product listing and no price — the
     * generic clarify, exactly as before this batch.
     */
    public function test_item_the_shop_does_not_sell_keeps_the_existing_path(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'শাড়ির দাম কত?');

        $this->assertNotNull($r);
        $this->assertNotSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringNotContainsString('আমাদের পণ্য:', $r->body);
        $this->assertStringNotContainsString('1,300', $r->body);
        $this->assertNull($conv->fresh()->product_id);
    }
}

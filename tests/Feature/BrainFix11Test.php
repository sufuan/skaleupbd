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
 * Brain fix batch 11 (2026-11-25, Step 1 of the external review —
 * findings #1, #2, #10, #11, #12): product resolution.
 *
 * Batch 8 taught the brain to ask "which one?" instead of guessing,
 * but only when NO product was in focus. Live on the CRUD Test Shop
 * thread (2026-11-25 19:20) that was not enough: CRUD-101 sat in
 * focus from earlier turns, so "Panjabi tar dam koto?" quoted its
 * 1,300 directly while CRUD-102 matched the word just as well. And
 * focus_at was written on every focus change but read nowhere —
 * the focus never expired, so a product named days ago answered
 * today's bare questions.
 *
 * Now handleIncoming resolves every product-dependent message
 * through one step (resolveProduct): EXPLICIT (a product is named
 * outright), CATEGORY_MULTI (a category word ties 2+ products —
 * clarify, focus or no focus, listing only the matched products),
 * FOCUS_REF (the message names no product and the focus is fresh —
 * under six hours), NONE. Category words also match across scripts
 * ("পাঞ্জাবি" matches products named "Panjabi", #10), and a tie
 * clarifies even when the tied products share one price (#11).
 */
class BrainFix11Test extends TestCase
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
     * The live shop's shape: two Panjabis at different prices + a cap.
     *
     * @return array{Business, Product, Product, Product}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Resolution Shop', 'public_id' => 'res-shop-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $p3 = Product::create(['business_id' => $biz->id, 'name' => 'Plain Cap', 'sku' => 'CAP-1', 'price' => 250, 'stock_qty' => 10]);

        return [$biz, $p1, $p2, $p3];
    }

    private function conv(Business $biz, array $extra = []): Conversation
    {
        return Conversation::create(array_merge([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Resolution Customer',
        ], $extra));
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /**
     * (1) THE live failure, replayed: CRUD-101 is put in focus by an
     * earlier turn, then the category question must STILL clarify —
     * the stale focus no longer wins over the category word. The
     * pick then answers the picked product's exact price.
     */
    public function test_category_question_clarifies_even_with_a_focus_product(): void
    {
        [$biz, $p1, $p2] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $first = $this->send($brain, $conv, 'CRUD-101 er dam koto?');
        $this->assertNotNull($first);
        $this->assertStringContainsString('1,300', $first->body);
        $this->assertSame($p1->id, (int) $conv->fresh()->product_id, 'the earlier turn put CRUD-101 in focus');

        $r = $this->send($brain, $conv, 'Panjabi tar dam koto?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('Plain Cap', $r->body, 'only the matched products are listed');
        $this->assertStringNotContainsString('1,300', $r->body, 'the focus product\'s price is not quoted');
        $this->assertStringNotContainsString('850', $r->body);

        $pick = $this->send($brain, $conv, '2');
        $this->assertNotNull($pick);
        $this->assertStringContainsString('850', $pick->body);
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id);
    }

    /**
     * (2) A focus older than six hours is no focus: a bare price
     * question gets the product listing, not the stale product's
     * price.
     */
    public function test_stale_focus_does_not_answer_a_bare_price_question(): void
    {
        [$biz, $p1] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz, ['product_id' => $p1->id, 'focus_at' => now()->subHours(7)]);

        $r = $this->send($brain, $conv, 'দাম কত?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringContainsString('Plain Cap', $r->body);
        $this->assertStringNotContainsString('1,300', $r->body, 'the stale focus price is never quoted');
    }

    /**
     * (3) A demonstrative with a FRESH focus still means the focus
     * product: "etar dam koto?" answers its price directly.
     */
    public function test_demonstrative_with_fresh_focus_answers_the_focus_price(): void
    {
        [$biz, $p1] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz, ['product_id' => $p1->id, 'focus_at' => now()->subHour()]);

        $r = $this->send($brain, $conv, 'etar dam koto?');

        $this->assertNotNull($r);
        $this->assertNotSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('1,300', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertSame($p1->id, (int) $conv->fresh()->product_id);
    }

    /**
     * (4) The clarification list is filtered to the category the
     * customer named: "panjabi" lists the Panjabis only, never the
     * shop's other products (finding #12).
     */
    public function test_category_clarification_lists_only_the_matched_products(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'panjabi gular dam koto?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('Plain Cap', $r->body);
        $this->assertStringNotContainsString('CAP-1', $r->body);
    }

    /**
     * (5) A tie clarifies even when every tied product shares one
     * price (finding #11): the products differ (colour), so which
     * one is meant is still a real question. The pick resolves it.
     */
    public function test_same_price_tie_clarifies_and_the_pick_resolves(): void
    {
        $biz = Business::create(['name' => 'Colour Shop', 'public_id' => 'colour-shop-' . uniqid(), 'status' => 'approved']);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi - White', 'sku' => 'CP-101', 'price' => 1250, 'stock_qty' => 10]);
        $blue = Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi - Blue', 'sku' => 'CP-102', 'price' => 1250, 'stock_qty' => 10]);
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'Cotton Panjabi-র দাম কত?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('Cotton Panjabi - White', $r->body);
        $this->assertStringContainsString('Cotton Panjabi - Blue', $r->body);
        $this->assertStringNotContainsString('1,250', $r->body, 'no price is quoted before the pick');

        $pick = $this->send($brain, $conv, '2');
        $this->assertNotNull($pick);
        $this->assertStringContainsString('1,250', $pick->body);
        $this->assertStringContainsString('Blue', $pick->body);
        $this->assertSame($blue->id, (int) $conv->fresh()->product_id);
    }

    /**
     * (6) A Bangla category word matches English-named products
     * (finding #10): "পাঞ্জাবি" is the word "Panjabi" in the
     * products' names, so the two Panjabis tie and clarify — the
     * word no longer matches nothing.
     */
    public function test_bangla_category_word_matches_english_named_products(): void
    {
        [$biz, $p1] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $r = $this->send($brain, $conv, 'পাঞ্জাবির দাম কত?');

        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringNotContainsString('Plain Cap', $r->body);

        $pick = $this->send($brain, $conv, 'prothom ta');
        $this->assertNotNull($pick);
        $this->assertStringContainsString('1,300', $pick->body);
        $this->assertSame($p1->id, (int) $conv->fresh()->product_id);
    }

    /**
     * (7a) Regression: an explicit name still switches the product
     * and resets the old product's negotiation (batch 2 semantics,
     * untouched by the resolution step).
     */
    public function test_explicit_switch_still_resets_the_negotiation(): void
    {
        [$biz, $p1, $p2] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        $this->send($brain, $conv, 'CRUD-101 er dam koto?');
        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($offer);
        $this->assertSame(1, (int) $conv->fresh()->neg_step, 'the ladder is running on CRUD-101');
        $this->assertNotNull($conv->fresh()->neg_offer);

        $r = $this->send($brain, $conv, 'CRUD-102 er dam koto?');

        $this->assertNotNull($r);
        $this->assertStringContainsString('850', $r->body);
        $fresh = $conv->fresh();
        $this->assertSame($p2->id, (int) $fresh->product_id);
        $this->assertSame(0, (int) $fresh->neg_step, 'the switch reset the ladder');
        $this->assertNull($fresh->neg_offer, 'the switch cleared the standing offer');
    }

    /**
     * (7b) Regression: a word matching exactly ONE product still
     * answers directly — no clarification detour.
     */
    public function test_single_match_still_answers_directly(): void
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
}

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brain fix batch 18 (2026-11-27): five root causes behind the
 * Round 2 Leg C LIVE wrong-product drafts on the CRUD Test Shop
 * (off-hours, draft mode). The shop sells "CRUD Test Panjabi"
 * (৳1,300) and "CRUD Test Panjabi 2" (৳850); the customer named
 * the first product three times and was clarified at, then quoted
 * the second product's ৳850 twice.
 *
 * (RC1) The word machinery dropped every name word under 4 chars,
 *     so the digit "2" — the ONLY token separating the pair — was
 *     invisible and both products reduced to {crud, test, panjabi}.
 *     Distinctiveness now decides which tokens count, and a digit
 *     appearing in exactly one product's name is separator evidence
 *     (score-level in wordMatchRows, a separator check in
 *     explicitProductFor) — never coverage, and never when the
 *     digit is quantity-bound.
 * (RC2) A quoted code ("CRUD-101") was honoured only as a verbatim
 *     substring of sku/aliases. Codes now compare as separator-free
 *     skeletons against the sku, the aliases and code-like tokens
 *     inside the product's own name, with digits normalised.
 * (RC3) A leftover `collecting` state pre-empted the clarification
 *     branch, and a comma in a greeting tripped handleCollect's
 *     addressShaped test, closing the side-question escape hatch.
 *     A category tie now outranks the collecting dispatch (the
 *     collection is remembered on the clarification and resumes
 *     through the pick machinery), a comma alone is no longer
 *     address evidence, and a collection untouched for six hours
 *     expires like the focus does.
 * (RC5) resolveClarifiedPick scanned bare ordinals before the
 *     distinguishing tokens, so a quantity digit ("১টা") hijacked
 *     the pick. Tokens are judged first; ordinal digits are read
 *     on quantity-stripped text.
 * (RC4) A details-bearing message that matched no rule and held
 *     no flow state fell into the no-rule fallback — a price line
 *     about the focus product. It now routes to order-taking for
 *     the resolved product, like every other details-bearing
 *     message (batch 9's doctrine).
 */
class BrainFix18Test extends TestCase
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
        $settings = app(SettingsService::class);
        $settings->set('ai.autonomy', [
            'facebook' => 'full_auto', 'whatsapp' => 'full_auto',
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
        $talker = $settings->get('ai.talker', []);
        $talker['enabled'] = false;
        $settings->set('ai.talker', $talker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Business, Product, Product, Conversation} */
    private function shop(string $sku1 = 'SKU-001', string $sku2 = 'SKU-002'): array
    {
        $biz = Business::create(['name' => 'Batch18 Shop', 'public_id' => 'b18-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => $sku1, 'price' => 1300, 'stock_qty' => 9]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => $sku2, 'price' => 850, 'stock_qty' => 9]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Batch18 Customer',
        ]);

        return [$biz, $p1, $p2, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    // ---------------------------------------------------------- replay

    /**
     * The exact live conversation, with the sku stored in a variant
     * form ("CRUD101") the customer's typed code ("CRUD-101") is
     * not a substring of: clarify, resolve the ৳1,300 product, never
     * quote the wrong product, complete at a ৳1,360 summary.
     */
    public function test_the_live_replay_resolves_the_code_and_completes_at_1360(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop('CRUD101', 'CRUD102');
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আসসালামু আলাইকুম, পাঞ্জাবিটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'a bare category question clarifies');
        $this->assertStringNotContainsString('850', (string) $r->body);
        $this->assertStringNotContainsString('1,300', (string) $r->body);

        $r = $this->send($brain, $conv, 'আমি Cotton Panjabi (CRUD-101) টা নিতে চাই, এটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('850', (string) $r->body, 'the wrong product is never quoted');
        $after = $conv->fresh();
        $this->assertSame($p1->id, (int) $after->product_id, 'the code resolved the 1,300 product');
        $this->assertSame('collecting', $after->state);
        $this->assertSame($p1->id, (int) (($after->state_data ?? [])['collect']['product_id'] ?? 0), 'collection is for the named product');

        $r = $this->send($brain, $conv, 'CRUD Test Panjabi (CRUD-101) টাই চাই, ১টা অর্ডার করতে চাই।');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('850', (string) $r->body, 'no wrong-product price mid-flow either');
        $this->assertSame($p1->id, (int) $conv->fresh()->product_id);

        $r = $this->send($brain, $conv, 'নাম: Round Two Test, ফোন: 01712345678, ঠিকানা: House 5, Road 2, Dhanmondi, Dhaka');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi', (string) $r->body);
        $this->assertStringNotContainsString('Panjabi 2', (string) $r->body);
        $this->assertStringContainsString('1,360', (string) $r->body, 'the summary totals 1,300 + 60 inside Dhaka');
    }

    // ------------------------------------------------------------- RC2

    public function test_a_code_token_matches_however_the_code_is_stored_or_typed(): void
    {
        [$biz, $p1, $p2] = $this->shop('CRUD101', 'CRUD 102');
        $brain = app(RuleBrainService::class);

        $this->assertSame($p1->id, (int) $brain->explicitProductFor($biz->id, 'CRUD-101 er dam koto?')?->id, 'separator-less sku, hyphenated mention');
        $this->assertSame($p2->id, (int) $brain->explicitProductFor($biz->id, 'crud-102 ta chai')?->id, 'spaced sku, hyphenated mention');
        $this->assertSame($p1->id, (int) $brain->explicitProductFor($biz->id, 'CRUD-১০১ er dam koto?')?->id, 'the code typed with Bengali digits');
        $this->assertSame($p1->id, (int) $brain->explicitProductFor($biz->id, 'crud 101 ta chai')?->id, 'the code typed with a space');

        // A code embedded in the product's own name is honoured too.
        $biz2 = Business::create(['name' => 'Batch18 Named Codes', 'public_id' => 'b18n-' . uniqid(), 'status' => 'approved']);
        $n1 = Product::create(['business_id' => $biz2->id, 'name' => 'Cotton Panjabi CRUD101', 'sku' => 'X-1', 'price' => 1300, 'stock_qty' => 9]);
        $n2 = Product::create(['business_id' => $biz2->id, 'name' => 'Cotton Panjabi CRUD102', 'sku' => 'X-2', 'price' => 850, 'stock_qty' => 9]);
        $this->assertSame($n2->id, (int) $brain->explicitProductFor($biz2->id, 'CRUD-102 ta chai')?->id, 'the code inside the name separates the pair');
        $this->assertSame($n1->id, (int) $brain->explicitProductFor($biz2->id, 'CRUD101 ta chai')?->id);
    }

    // ------------------------------------------------------------- RC1

    public function test_the_digit_in_a_product_name_separates_the_pair(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'panjabi 2 tar dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('850', (string) $r->body, 'the digit names the second product and its price answers');
        $this->assertStringContainsString('CRUD Test Panjabi 2', (string) $r->body);
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id);

        // The bare category word still ties and still clarifies.
        $conv2 = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open', 'customer_name' => 'Batch18 Customer']);
        $r = $this->send($brain, $conv2, 'panjabi tar dam koto?');
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'no separator said — no guess made');

        // A quantity digit is not a separator: "2 ta" is a count.
        $conv3 = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open', 'customer_name' => 'Batch18 Customer']);
        $r = $this->send($brain, $conv3, '2 ta panjabi order korte chai');
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'a quantity does not silently pick the digit product');
        $this->assertNull($conv3->fresh()->product_id);

        // The separator check inside explicitProductFor itself: the
        // distinctive words in scrambled order (a contiguous
        // "crud test panjabi" would be the full-name pass's own
        // explicit mention of the first product).
        $this->assertSame($p2->id, (int) $brain->explicitProductFor($biz->id, 'panjabi 2 crud test chai')?->id);
        $this->assertNull($brain->explicitProductFor($biz->id, 'panjabi crud test chai'), 'every word said, separator not said — still ambiguous');
    }

    // ------------------------------------------------------------- RC3

    public function test_a_category_question_during_collection_clarifies_and_the_pick_resumes(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->state = 'collecting';
        $conv->state_data = ['collect' => ['product_id' => $p2->id, 'step' => 'phone', 'retries' => 0, 'touched_at' => now()->timestamp]];
        $conv->save();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আসসালামু আলাইকুম, পাঞ্জাবিটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'the greeting + price question clarifies — the collect prompt does not swallow it');
        $after = $conv->fresh();
        $this->assertSame('collecting', $after->state, 'the collection stands');
        $this->assertSame($p2->id, (int) (($after->state_data ?? [])['product_clarify']['collect_product_id'] ?? 0), 'the clarification remembers the collection');

        // The pick of the collect product itself: answered alongside
        // the collection, which continues untouched.
        $r = $this->send($brain, $conv, '2');
        $this->assertNotNull($r);
        $this->assertSame('collect_side_answer', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('850', (string) $r->body);
        $this->assertSame('collecting', $conv->fresh()->state);
    }

    public function test_a_pick_of_a_different_product_during_collection_switches_out(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->state = 'collecting';
        $conv->state_data = ['collect' => ['product_id' => $p2->id, 'step' => 'phone', 'retries' => 0, 'touched_at' => now()->timestamp]];
        $conv->save();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'আসসালামু আলাইকুম, পাঞ্জাবিটার দাম কত?');
        $r = $this->send($brain, $conv, '1');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,300', (string) $r->body, 'the resumed price question answers for the picked product');
        $after = $conv->fresh();
        $this->assertNull($after->state, 'the old collection is closed by the switch');
        $this->assertSame($p1->id, (int) $after->product_id);
    }

    public function test_an_abandoned_collection_expires_but_a_live_or_legacy_one_does_not(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->state = 'collecting';
        $conv->state_data = ['collect' => ['product_id' => $p2->id, 'step' => 'phone', 'retries' => 0, 'touched_at' => now()->subHours(7)->timestamp]];
        $conv->save();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আসসালামু আলাইকুম, পাঞ্জাবিটার দাম কত?');
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'the stale collection no longer intercepts the new question');
        $after = $conv->fresh();
        $this->assertNull($after->state, 'the abandoned collection was dropped');
        $this->assertArrayNotHasKey('collect_product_id', (array) (($after->state_data ?? [])['product_clarify'] ?? []), 'the clarification is a fresh one, not a collection resume');

        // A collection with no stamp cannot be proven stale.
        $legacy = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open', 'customer_name' => 'Batch18 Customer',
            'product_id' => $p2->id, 'focus_at' => now(), 'state' => 'collecting',
            'state_data' => ['collect' => ['product_id' => $p2->id, 'step' => 'phone', 'retries' => 0]]]);
        $r = $this->send($brain, $legacy, 'hello');
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'an unstamped collection stays in force');
        $this->assertSame('collecting', $legacy->fresh()->state);
    }

    // ------------------------------------------------------------- RC5

    public function test_a_pick_is_never_bound_by_a_quantity_digit(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'panjabi dam koto');
        $r = $this->send($brain, $conv, 'Panjabi 2 টাই চাই, ১টা অর্ডার করতে চাই।');
        $this->assertNotNull($r);
        $after = $conv->fresh();
        $this->assertSame($p2->id, (int) $after->product_id, 'the distinguishing token picks Panjabi 2 — the quantity digit does not hijack it to #1');
        $this->assertSame($p2->id, (int) (($after->state_data ?? [])['collect']['product_id'] ?? 0), 'the order flow starts for Panjabi 2');
        $this->assertStringNotContainsString('1,300', (string) $r->body);

        // A bare quantity after a clarification binds nothing at all.
        $conv2 = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open', 'customer_name' => 'Batch18 Customer']);
        $this->send($brain, $conv2, 'panjabi dam koto');
        $this->send($brain, $conv2, '১টা');
        $this->assertNull($conv2->fresh()->product_id, '১টা is a count, not an ordinal pick of candidate #1');
    }

    // ------------------------------------------------------------- RC4

    public function test_details_with_no_rule_and_no_flow_take_the_order_instead_of_a_price_line(): void
    {
        [$biz, $p1, $p2, $conv] = $this->shop();
        // The live thread's shape at message 4: the OTHER product in
        // focus (timestamp-less, so in force), a standing ladder at
        // 808, no collection, no pending summary. The details blob
        // matched no rule; the no-rule fallback answered it with
        // Panjabi 2's bare price line.
        $conv->product_id = $p2->id;
        $conv->focus_at = null;
        $conv->neg_step = 1;
        $conv->neg_offer = 808;
        $conv->save();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'আমার নাম Round Two Test, ফোন: 01712345678, ঠিকানা: House 5, Road 2, Dhanmondi, Dhaka');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null, 'the details are order input — a summary, not a price line');
        $this->assertEmpty($r->meta['no_rule'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi 2', (string) $r->body);
        $this->assertStringContainsString('868', (string) $r->body, 'the summary stands at the agreed 808 + 60 delivery');
        $this->assertStringNotContainsString('আর কিছু জানতে চাইলে বলুন', (string) $r->body, 'the no-rule fallback line is gone');
    }
}

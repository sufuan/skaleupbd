<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 14 (2026-11-25, Step 6 + the Step 2 remainder of
 * the external expert review; findings #13, #14, #15, #16, #18).
 * The reviewer read only RuleBrainService.php + AiRule.php; every
 * finding was verified against the whole codebase before fixing:
 *
 *  #13 VARIANT STICKS — variantFor() returned the remembered
 *     state['variant_id'] BEFORE reading the message, so once a
 *     variant was chosen, naming a different one never switched:
 *     the price slot, the collect flow's frozen variant and any
 *     summary all kept the first choice forever. (Collect mode and
 *     a pending summary had no switch path at all — batch 13 built
 *     qtyChangeRerender for quantities; variants had no twin.)
 *
 *  #14 BENGALI SUFFIXES — variantFor's boundary pattern treats any
 *     Bengali letter after the token as "still inside a word", and
 *     the definiteness suffixes টা/টি/টার are Bengali letters, so
 *     "লালটা" / "লালটার" never matched the variant "লাল".
 *
 *  #15 PRICE NEVER ASKS THE VARIANT — the variant question fired
 *     only for stock and order_intent. A price question about a
 *     product whose variants cost DIFFERENT amounts quoted the
 *     product's base price as if final; and when every variant
 *     shared one price different from the base, it quoted the base.
 *
 *  STEP 2 SLOT-BINDING — the four product intents resolve through
 *     batch 11's resolveProduct(), but a keyword rule (or a fine
 *     intent like competitor_price) whose template carries
 *     {product} happily bound a merely SCORED product (a partial
 *     word overlap) or the stage-2 classifier's guess, and named
 *     it to the customer as if they had established it.
 *
 *  #16 DETAILS WITHOUT A BARGAIN — batch 9 routed details-bearing
 *     messages into order taking from the bargain branch (and
 *     detectIntent mid-bargain). A details message with NO bargain
 *     started and NO order words — the customer simply answering
 *     the price reply's "নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন"
 *     — matched no rule and no intent, and fell into the no-rule
 *     fallback: a draft and a human flag, the order never started,
 *     even though the details were captured and a product was in
 *     focus.
 *
 *  #18 YES/NO — the brain's genuine yes/no questions are the photo
 *     confirmation (yesNo, handled) and the summary confirmation
 *     (batch 3, protected). Its choice questions (which variant,
 *     which product) are answered by naming/picking, not yes/no —
 *     pinned below. ONE real gap: batch 13's reduced-quantity
 *     invitation ("৩টা নিলে বলুন, অর্ডার করে দিচ্ছি") left no
 *     pending state, so the customer's bare "হ্যাঁ" fell into the
 *     no-rule fallback and the offered order never started.
 */
class BrainFix14Test extends TestCase
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

    /** A shop with a points wallet. @return array{Business, User} */
    private function shopBase(string $tag): array
    {
        $biz = Business::create(['name' => 'Batch14 Shop', 'public_id' => 'batch14-' . $tag . '-' . uniqid(), 'status' => 'approved']);
        $owner = User::create([
            'name' => 'Batch14 Owner', 'email' => 'batch14-' . $tag . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $biz->id, 'is_owner' => true,
        ]);
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($biz->id, $pack->id, null, 'test', 'brainfix14 test', $owner->id);

        return [$biz, $owner];
    }

    /**
     * A variant product in focus. $variants: list of [name, price, stock].
     *
     * @return array{Business, User, Product, Conversation, array<int, ProductVariant>}
     */
    private function variantShop(string $tag, string $productName, float $basePrice, array $variants, bool $focus = true): array
    {
        [$biz, $owner] = $this->shopBase($tag);
        $p = Product::create(['business_id' => $biz->id, 'name' => $productName, 'sku' => 'BF14-' . strtoupper($tag), 'price' => $basePrice, 'stock_qty' => 50]);
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
            'product_id' => $focus ? $p->id : null,
        ]);

        return [$biz, $owner, $p, $conv, $made];
    }

    /** The batch-13 shop: one Panjabi (1,300) in focus. @return array{Business, User, Product, Conversation} */
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

    private const DETAILS = 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.';

    // ------------------------------------------------------------ #13 switch

    /** A variant named in a later message replaces the remembered
     *  choice — the price that follows is the NEW variant's. */
    public function test_a_named_variant_switches_the_remembered_choice(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$l, $m]] = $this->variantShop('switch', 'Size Shirt', 1000, [
            ['Size: L', 900, 5],
            ['Size: M', 1100, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'L size er dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('900', $r->body);
        $this->assertSame((int) $l->id, (int) (($conv->fresh()->state_data ?? [])['variant_id'] ?? 0));

        $r = $this->send($brain, $conv, 'M er dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,100', $r->body, 'the newly named variant is priced, not the remembered one');
        $this->assertStringNotContainsString('900', $r->body);
        $this->assertSame((int) $m->id, (int) (($conv->fresh()->state_data ?? [])['variant_id'] ?? 0), 'the flow now holds M');
    }

    /** The switch reaches the summary: a variant named while a
     *  summary stands retires that summary and re-renders it at the
     *  new variant; the order is created for the NEW variant. */
    public function test_a_variant_named_against_a_pending_summary_rerenders_it(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$l, $m]] = $this->variantShop('resummary', 'Size Shirt', 1000, [
            ['Size: L', 900, 5],
            ['Size: M', 1100, 5],
        ]);
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $summary = $this->send($brain, $conv, 'L ta order korte chai. ' . self::DETAILS);
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('(Size: L)', $summary->body);
        $this->assertSame(960.0, (float) ($summary->meta['total'] ?? 0), '900 + 60 inside Dhaka');

        $r = $this->send($brain, $conv, 'M ta diben');
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null, 'the change re-renders the summary, it does not just point at the old one');
        $this->assertStringContainsString('(Size: M)', $r->body);
        $this->assertSame(1160.0, (float) ($r->meta['total'] ?? 0), '1,100 + 60');
        $this->assertSame(0, $this->orderCount($biz->id), 'the change itself creates no order');

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point');
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame('Size: M', $order->items()->first()->variant_name, 'the order is for the switched variant');
        $this->assertEqualsWithDelta(1160.0, (float) $order->grand_total, 0.01);
    }

    /** The switch also lands mid-collection: the variant the
     *  collection eventually summarises is the one named last. */
    public function test_a_variant_named_mid_collection_reaches_the_summary(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$l, $m]] = $this->variantShop('collect', 'Size Shirt', 1000, [
            ['Size: L', 900, 5],
            ['Size: M', 1100, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'L order korte chai');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertSame((int) $l->id, (int) (($conv->fresh()->state_data ?? [])['collect']['variant_id'] ?? 0));

        $r = $this->send($brain, $conv, 'M ta diben');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'the collection itself is undisturbed');
        $this->assertSame((int) $m->id, (int) (($conv->fresh()->state_data ?? [])['collect']['variant_id'] ?? 0), 'the collection now holds M');

        $summary = $this->send($brain, $conv, self::DETAILS);
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertStringContainsString('(Size: M)', $summary->body);
        $this->assertSame(1160.0, (float) ($summary->meta['total'] ?? 0));
    }

    // ------------------------------------------------------------ #14 suffix

    /** "লালটার" is the variant লাল with its suffix on: it matches,
     *  and its own price is the answer. */
    public function test_a_bangla_suffixed_variant_name_matches(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$lal, $kalo]] = $this->variantShop('suffix', 'Rongin Panjabi', 1000, [
            ['Color: লাল', 800, 5],
            ['Color: কালো', 1200, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'লালটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('800', $r->body, 'the suffixed mention resolves to the লাল variant and its price');
        $this->assertSame((int) $lal->id, (int) (($conv->fresh()->state_data ?? [])['variant_id'] ?? 0));

        $r = $this->send($brain, $conv, 'কালোটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,200', $r->body);
        $this->assertSame((int) $kalo->id, (int) (($conv->fresh()->state_data ?? [])['variant_id'] ?? 0));
    }

    // ------------------------------------------------- #15 price asks variant

    /** Variants at different prices: a bare price question asks
     *  WHICH variant instead of quoting the base price as final —
     *  and the pick then resumes the price question (the variant
     *  question is a choice, answered by naming: #18a). */
    public function test_a_price_question_asks_the_variant_when_prices_differ(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$lal, $kalo]] = $this->variantShop('askvar', 'Rongin Panjabi', 1000, [
            ['Color: লাল', 800, 5],
            ['Color: কালো', 1200, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'এটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('variant_question', $r->meta['kind'] ?? null, 'no single price exists to quote — the question is asked');
        $this->assertStringContainsString('লাল', $r->body);
        $this->assertStringContainsString('কালো', $r->body);
        $this->assertStringNotContainsString('1,000', $r->body, 'the base price is not quoted as the answer');

        $r = $this->send($brain, $conv, 'কালো');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,200', $r->body, 'the pick resumes the price question at that variant');
    }

    /** Variants at ONE shared price: that figure is quoted directly
     *  — it, not the product's different base price. */
    public function test_a_price_question_quotes_the_shared_variant_price(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->variantShop('shared', 'Rongin Panjabi', 1000, [
            ['Color: লাল', 800, 5],
            ['Color: কালো', 800, 5],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'এটার দাম কত?');
        $this->assertNotNull($r);
        $this->assertNotSame('variant_question', $r->meta['kind'] ?? null, 'one price covers every variant — no question needed');
        $this->assertStringContainsString('800', $r->body);
        $this->assertStringNotContainsString('1,000', $r->body, 'the unsellable base figure is not quoted');
    }

    // ------------------------------------------- Step 2 slot-binding ({product})

    /** A keyword rule carrying {product} must not name a product
     *  the customer never established: a partial-word score is not
     *  an establishment. The reply goes out product-free. */
    public function test_a_keyword_rule_does_not_name_a_merely_scored_product(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('scored');
        $wallet = Product::create(['business_id' => $biz->id, 'name' => 'Original Leather Wallet', 'sku' => 'BF14-W', 'price' => 1500, 'stock_qty' => 5]);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'BF14-P', 'price' => 1300, 'stock_qty' => 5]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'original wallet ta ki genuine?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('Original Leather Wallet', $r->body, 'a scored overlap is not the customer naming the product');
        $this->assertStringContainsString('পণ্যটা', $r->body, 'the answer stands, product-free');
    }

    /** The same rule WITH the product in fresh focus names it —
     *  focus is an establishment, and stays one. */
    public function test_a_keyword_rule_names_the_focus_product(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('focusbind');
        $wallet = Product::create(['business_id' => $biz->id, 'name' => 'Original Leather Wallet', 'sku' => 'BF14-W', 'price' => 1500, 'stock_qty' => 5]);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'BF14-P', 'price' => 1300, 'stock_qty' => 5]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
            'product_id' => $wallet->id,
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'eta ki original?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Original Leather Wallet', $r->body);
    }

    /** A {price}-carrying fine intent (competitor price) with only
     *  a scored product behind it cannot quote that product's price
     *  either: the outcome is the clarification question. */
    public function test_a_competitor_claim_does_not_quote_a_scored_product(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('competitor');
        Product::create(['business_id' => $biz->id, 'name' => 'Original Leather Wallet', 'sku' => 'BF14-W', 'price' => 1500, 'stock_qty' => 5]);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'BF14-P', 'price' => 1300, 'stock_qty' => 5]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'competitor sells the original wallet cheaper');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('Original Leather Wallet', $r->body);
        $this->assertStringNotContainsString('1,500', $r->body, 'the guessed product\'s price is not stated as the current price');
        $this->assertStringContainsString('কোন পণ্যটার কথা বলছেন', $r->body, 'the outcome is the clarification question');
    }

    // ------------------------------------------------------------ #16 details

    /** The live gap: the price reply invites the details, the
     *  customer sends exactly those (no order words, no bargain) —
     *  the order flow must start: summary, then a created order. */
    public function test_details_after_a_price_answer_start_the_order(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $r = $this->send($brain, $conv, 'dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,300', $r->body);

        $summary = $this->send($brain, $conv, self::DETAILS);
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null, 'details alone are order input — the summary is built');
        $this->assertSame(1360.0, (float) ($summary->meta['total'] ?? 0), '1,300 + 60 inside Dhaka');
        $this->assertSame('sent', $summary->status);

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point');
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertEqualsWithDelta(1360.0, (float) $order->grand_total, 0.01);
    }

    /** Partial details with no order words start the collection
     *  for the missing pieces — they do not fall into the fallback. */
    public function test_partial_details_without_order_words_start_collection(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'phone 01612345678');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertSame('collecting', $conv->fresh()->state);
    }

    /** PIN (unchanged): details arriving cold — no product in
     *  focus, nothing to order — still go to a human, never invent
     *  an order. */
    public function test_details_with_no_product_in_focus_stay_with_a_human(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner] = $this->shopBase('cold');
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'BF14-P', 'price' => 1300, 'stock_qty' => 5]);
        Product::create(['business_id' => $biz->id, 'name' => 'Leather Wallet', 'sku' => 'BF14-W', 'price' => 1500, 'stock_qty' => 5]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Phase2 Customer',
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, self::DETAILS);
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'a human takes a details message with no product behind it');
        $this->assertSame(0, $this->orderCount($biz->id));
        if ($r !== null) {
            $this->assertNotSame('order_summary', $r->meta['kind'] ?? null);
        }
    }

    // ------------------------------------------------------------ #18 yes/no

    /** Batch 13's reduced-quantity invitation is a yes/no question
     *  the brain asks: "হ্যাঁ" takes the offered quantity into a
     *  summary and then a real order. */
    public function test_yes_takes_a_reduced_quantity_offer(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop(stock: 3);
        $brain = app(RuleBrainService::class);
        $pointsBefore = $this->balance($biz->id);

        $r = $this->send($brain, $conv, 'CRUD-101 order korte chai, ৫টা নিব. ' . self::DETAILS);
        $this->assertNotNull($r);
        $this->assertSame('insufficient_stock', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('3টা আছে', $r->body);
        $this->assertSame(3, (int) (($conv->fresh()->state_data ?? [])['qty_offer']['qty'] ?? 0), 'the offer the brain just made is on the record');

        $summary = $this->send($brain, $conv, 'হ্যাঁ');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null, 'the bare yes answers the offer — the order starts');
        $this->assertSame(3, (int) ($summary->meta['qty'] ?? 0));
        $this->assertSame(3960.0, (float) ($summary->meta['total'] ?? 0), '1,300 x 3 + 60');

        $r = $this->send($brain, $conv, 'ha, confirm korchi');
        $this->assertNotNull($r);
        $this->assertSame('order_confirmed', $r->meta['kind'] ?? null);
        $this->assertSame($pointsBefore - 1, $this->balance($biz->id), 'exactly one point');
        $order = Order::where('business_id', $biz->id)->firstOrFail();
        $this->assertSame(3, (int) $order->items()->first()->qty);
    }

    /** "না" closes the offer politely: no summary, no order, and
     *  the offer does not linger to swallow a later "হ্যাঁ". */
    public function test_no_closes_a_reduced_quantity_offer(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv] = $this->plainShop(stock: 3);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'CRUD-101 order korte chai, ৫টা নিব. ' . self::DETAILS);
        $this->assertNotNull($r);
        $this->assertSame('insufficient_stock', $r->meta['kind'] ?? null);

        $r = $this->send($brain, $conv, 'না');
        $this->assertNotNull($r);
        $this->assertSame('qty_offer_declined', $r->meta['kind'] ?? null);
        $this->assertSame(0, $this->orderCount($biz->id));
        $this->assertEmpty(($conv->fresh()->state_data ?? [])['qty_offer'] ?? null, 'the offer is closed');

        $r = $this->send($brain, $conv, 'হ্যাঁ');
        if ($r !== null) {
            $this->assertNotSame('order_summary', $r->meta['kind'] ?? null, 'a later bare yes has no offer left to accept');
        }
        $this->assertSame(0, $this->orderCount($biz->id));
    }

    /** PIN (unchanged, #18a): the variant question is a CHOICE —
     *  a bare "হ্যাঁ" cannot answer it and resolves nothing; the
     *  question stays open and naming the variant answers it. */
    public function test_a_bare_yes_does_not_answer_a_variant_question(): void
    {
        $this->setMode('full_auto');
        [$biz, $owner, $p, $conv, [$lal, $kalo]] = $this->variantShop('yespin', 'Rongin Panjabi', 1000, [
            ['Color: লাল', 800, 5],
            ['Color: কালো', 1200, 7],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'স্টকে আছে?');
        $this->assertNotNull($r);
        $this->assertSame('variant_question', $r->meta['kind'] ?? null);

        $r = $this->send($brain, $conv, 'হ্যাঁ');
        $this->assertNotEmpty(($conv->fresh()->state_data ?? [])['variant_pending'] ?? null, 'the choice is still open — a bare yes names nothing');
        if ($r !== null) {
            $this->assertStringNotContainsString('আছে: 5', $r->body);
            $this->assertStringNotContainsString('আছে: 7', $r->body);
        }

        $r = $this->send($brain, $conv, 'কালো');
        $this->assertNotNull($r);
        $this->assertStringContainsString('আছে: 7', $r->body, 'naming the variant answers the stock question from its own pool');
    }
}

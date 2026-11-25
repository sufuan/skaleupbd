<?php

namespace Tests\Feature;

use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\RuleBrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch 2 (2026-11-25, from the FRESH LIVE TEST on the CRUD
 * Test Shop's real Messenger thread, 14:59-15:12): the batch-1 fixes
 * passed on fresh conversations, but the live thread is DAYS OLD — it
 * had bargained CRUD-101 to its floor (1,105) and still carried the
 * name, phone and address of an order from 22 Nov. On that thread the
 * brain (a) answered a CRUD-102 price question with CRUD-101's floor
 * line, then handed the re-ask to a human ("দাম floor-এ পৌঁছেছে"),
 * (b) produced no reply and no log for two bargain pushes, (c) sent a
 * COD holding line that promised a policy check nobody was flagged to
 * perform, and (e) built the order summary from the STALE stored
 * details, ignoring the name, phone and address the customer had just
 * typed. These tests seed exactly that stale state and replay the
 * live sequence.
 */
class BrainFix2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent, as in
        // BrainFixTest: the live failures lived in the deterministic
        // layer, and that is the layer under test.
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

    /**
     * The live thread, seeded: two products, the conversation focused
     * on the FIRST at its floor (1,300 -> 1,105, floor already
     * repeated, customer already handed to a human), and the contact
     * details of the old 22 Nov order still on the record.
     *
     * @return array{Business, Product, Product, Conversation}
     */
    private function staleShop(): array
    {
        $biz = Business::create(['name' => 'Stale Thread Shop', 'public_id' => 'stale-shop-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
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

    private function activityCount(Conversation $conv): int
    {
        return AiActivity::where('conversation_id', $conv->id)->count();
    }

    public function test_price_question_naming_the_second_product_by_sku_switches_and_answers_its_price(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        // Live 14:59: answered with CRUD-101's floor line
        // ("1,105 টাকাই শেষ দাম... অর্ডার করবেন?").
        $r = $this->send($brain, $conv, 'CRUD-102 er dam koto?');

        $this->assertNotNull($r);
        $this->assertStringContainsString('850', $r->body, "the NEW product's price");
        $this->assertStringNotContainsString('1,105', $r->body, 'never the old product\u2019s floor');
        $this->assertStringNotContainsString('শেষ দাম', $r->body);

        $fresh = $conv->fresh();
        $this->assertSame($p2->id, (int) $fresh->product_id, 'the conversation switched product');
        $this->assertSame(0, (int) $fresh->neg_step, 'the old ladder is reset');
        $this->assertNull($fresh->neg_offer, 'no standing offer survives the switch');
        $this->assertTrue(AiActivity::where('conversation_id', $conv->id)->where('action', 'product_switched')->exists());
        $this->assertSame(0, AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count(), 'no floor handover for a price question');

        // Live 15:00: the re-ask got NO reply and a floor handover.
        $r2 = $this->send($brain, $conv, 'CRUD Test Panjabi 2 er dam koto? Code CRUD-102');
        $this->assertNotNull($r2);
        $this->assertStringContainsString('850', $r2->body);
        $this->assertSame(0, AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count());
    }

    public function test_bargain_push_about_the_new_product_gets_its_own_ladder_not_the_floor_handover(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        // The first message of the thread names the new product AND
        // pushes its price down, while the old product sits at floor.
        $r = $this->send($brain, $conv, 'CRUD-102-এর দামটা একটু কমান');

        $this->assertNotNull($r);
        // CRUD-102's own ladder: 850 -> 808 (floor 723).
        $this->assertSame(808.0, (float) ($r->meta['offer'] ?? 0));
        $this->assertStringContainsString('808', $r->body);
        $this->assertStringNotContainsString('1,105', $r->body);
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id);
        $this->assertSame(0, AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count(), 'the old product\u2019s floor must not hand over a new product\u2019s bargain');
    }

    public function test_confirm_message_details_override_the_stale_record(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'CRUD-102 er dam koto?');

        // The exact live 15:10 message. Live, the summary came back
        // with the 22 Nov name, phone (01700000001), Mirpur address,
        // the OLD product and its 1,105 floor — total 1,165.
        $r = $this->send($brain, $conv, 'confirm korchi. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.');

        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('Phase2 Customer', $r->body);
        $this->assertStringContainsString('01612345678', $r->body);
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r->body);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body);
        $this->assertStringContainsString('850', $r->body);
        $this->assertStringContainsString('মোট: 910', $r->body, '850 + 60 delivery');
        $this->assertStringNotContainsString('01700000001', $r->body, 'the stale phone must never reach a summary');
        $this->assertStringNotContainsString('মিরপুর', $r->body, 'the stale address must never reach a summary');
        $this->assertStringNotContainsString('1,105', $r->body);
        $this->assertStringNotContainsString('পাঞ্জাবি পাঞ্জ', $r->body, 'the stale name must never reach a summary');

        $fresh = $conv->fresh();
        $this->assertSame('Phase2 Customer', $fresh->customer_name);
        $this->assertSame('01612345678', $fresh->customer_phone);
        $this->assertSame('House 10, Road 5, Uttara, Dhaka', $fresh->customer_address);
    }

    public function test_bangla_labelled_details_are_captured_and_override(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'order confirm korchi. আমার নাম রফিক উদ্দিন, ফোন ০১৭১২৩৪৫৬৭৮, ঠিকানা: বাসা ৫, রোড ২, মিরপুর, ঢাকা');

        $fresh = $conv->fresh();
        $this->assertSame('রফিক উদ্দিন', $fresh->customer_name);
        $this->assertSame('01712345678', $fresh->customer_phone, 'Bangla digits normalised');
        $this->assertStringContainsString('মিরপুর', (string) $fresh->customer_address);
        $this->assertNotNull($r);
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('রফিক উদ্দিন', $r->body);
        $this->assertStringContainsString('01712345678', $r->body);
        $this->assertStringNotContainsString('01700000001', $r->body);
    }

    public function test_a_request_about_a_name_is_not_captured_as_a_name(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'আমার নামটা বদলাতে চাই');
        $this->assertSame('পাঞ্জাবি পাঞ্জ', $conv->fresh()->customer_name, '"change my name" must not rename the customer to its own tail');
    }

    public function test_the_whole_live_sequence_gives_every_message_exactly_one_outcome(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $sequence = [
            'CRUD-102 er dam koto?',
            'CRUD Test Panjabi 2 er dam koto? Code CRUD-102',
            'ektu kom hobe na?',
            'cash on delivery hobe?',
            'lal jama hobe? XL size lagbe',
            'thik ache, CRUD-102 (CRUD Test Panjabi 2) ta 850 takay nibo',
            'confirm korchi. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka.',
        ];
        foreach ($sequence as $i => $text) {
            $outBefore = $this->outCount($conv);
            $actBefore = $this->activityCount($conv);
            $this->send($brain, $conv, $text);
            $outDelta = $this->outCount($conv) - $outBefore;
            $actDelta = $this->activityCount($conv) - $actBefore;
            $this->assertLessThanOrEqual(1, $outDelta, "message #{$i} produced more than one reply");
            $this->assertTrue($outDelta === 1 || $actDelta >= 1, "message #{$i} ({$text}) vanished: no reply, no draft, no logged handover");
        }

        // The sequence ends in the correct summary, as in test (b):
        // the last reply carries the NEW details, at the price the
        // bargain settled (808), never the stale record.
        $last = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->orderByDesc('id')->first();
        $this->assertSame('order_summary', $last->meta['kind'] ?? null);
        $this->assertStringContainsString('01612345678', $last->body);
        $this->assertStringContainsString('মোট: 868', $last->body, '808 agreed + 60 delivery');
        $this->assertStringNotContainsString('01700000001', $last->body);
    }

    public function test_a_claimed_burst_that_produces_nothing_is_logged_unanswered(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $conv->ai_paused = true;
        $conv->save();
        $brain = app(RuleBrainService::class);

        $in = $brain->storeIncoming($conv->fresh(), 'dam koto?');
        $in->created_at = now()->subMinutes(5);
        $in->save();

        $result = $brain->processPending($conv->fresh());

        $this->assertNull($result, 'a paused conversation gets no reply');
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists(),
            'the claimed message must not vanish unlogged (batch 1 claimed bursts before handling)'
        );
        $this->assertTrue((bool) (($in->fresh()->meta ?? [])['processed'] ?? false), 'the burst was claimed — which is exactly why the log matters');
    }

    public function test_a_bargain_with_no_product_anywhere_is_logged_not_silent(): void
    {
        $biz = Business::create(['name' => 'Empty Shop', 'public_id' => 'empty-shop-' . uniqid(), 'status' => 'approved']);
        $conv = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Someone', 'status' => 'open']);
        $brain = app(RuleBrainService::class);

        $r = $brain->handleIncoming($conv->fresh(), 'দামটা একটু কমান');

        $this->assertNull($r);
        $this->assertTrue(AiActivity::where('conversation_id', $conv->id)->where('action', 'unanswered')->exists());
    }

    public function test_a_price_question_mid_bargain_never_triggers_the_floor_handover(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        // Same product, ladder AT the floor, floor already repeated:
        // a price question is still a price question.
        $r = $this->send($brain, $conv, 'CRUD-101 er dam koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('1,300', $r->body, 'the price question gets the price');
        $this->assertSame(0, AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count());

        // And mid-ladder (not yet at floor), the romanised form too.
        $conv2 = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Mid Ladder', 'product_id' => $p1->id,
            'neg_step' => 1, 'neg_offer' => 1235,
        ]);
        $r2 = $brain->handleIncoming($conv2->fresh(), 'dam koto?');
        $this->assertNotNull($r2);
        $this->assertStringContainsString('1,300', $r2->body);
        $this->assertSame(0, AiActivity::where('conversation_id', $conv2->id)->where('action', 'handed_human')->count());
    }

    public function test_cod_sends_the_acknowledgement_and_raises_needs_human(): void
    {
        $biz = Business::create(['name' => 'COD Shop', 'public_id' => 'cod-shop-' . uniqid(), 'status' => 'approved']);
        Product::create(['business_id' => $biz->id, 'name' => 'Thing', 'sku' => 'TH-1', 'price' => 500, 'stock_qty' => 5]);
        $conv = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Asker', 'status' => 'open']);
        $brain = app(RuleBrainService::class);

        // The catalog def itself: handover, holding line as the ack.
        $def = collect(AiRule::defaults())->firstWhere('match_text', 'cod');
        $this->assertSame('handover', $def['action']);

        $r = $brain->handleIncoming($conv->fresh(), 'cash on delivery hobe?');

        $this->assertNotNull($r, 'the acknowledgement still goes out');
        $this->assertStringContainsString('পেমেন্ট পদ্ধতি নিয়ে আপনার প্রশ্নটি', $r->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'a human is now flagged to actually do the promised check');
        $this->assertTrue(AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->exists());
    }

    public function test_migration_000063_flips_only_untouched_cod_rows(): void
    {
        $mk = function (string $action, string $template) {
            $bizId = (int) DB::table('businesses')->insertGetId([
                'name' => 'Legacy COD Shop', 'public_id' => 'legacy-cod-' . uniqid(), 'status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ai_rules')->insert([
                'business_id' => $bizId, 'name' => 'Cash on delivery question', 'trigger_type' => 'intent',
                'match_text' => 'cod', 'action' => $action, 'reply_template' => $template, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $bizId;
        };
        $defaultTemplate = '{honorific}, পেমেন্ট পদ্ধতি নিয়ে আপনার প্রশ্নটি দোকানের নিয়ম দেখে নিশ্চিত করে জানাচ্ছি।';
        $untouched = $mk('draft', $defaultTemplate);
        $edited = $mk('draft', 'আমাদের দোকানে COD আছে, অর্ডার করুন।');

        $migration = require database_path('migrations/2026_11_25_000063_cod_rule_becomes_handover.php');
        $migration->up();

        $this->assertSame('handover', DB::table('ai_rules')->where('business_id', $untouched)->value('action'));
        $this->assertSame('draft', DB::table('ai_rules')->where('business_id', $edited)->value('action'), 'a seller-edited COD rule keeps the seller\u2019s choice');
    }

    public function test_details_consumed_by_an_order_are_not_silently_reused(): void
    {
        $biz = Business::create(['name' => 'Lifecycle Shop', 'public_id' => 'lifecycle-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Old Customer', 'customer_phone' => '01700000001',
            'customer_address' => 'মিরপুর 10, ঢাকা', 'product_id' => $p->id,
            'neg_step' => 2, 'neg_offer' => 765,
        ]);
        $brain = app(RuleBrainService::class);

        // An order is created from a summary (the controller calls
        // this on the one creation path): the flow closes out.
        $brain->consumeCollectedDetails($conv->fresh(), 4242);
        $after = $conv->fresh();
        $this->assertSame(0, (int) $after->neg_step);
        $this->assertNull($after->neg_offer);
        // The values stay on the record (order-status lookups and the
        // channel identity read them) — they just stop COUNTING.
        $this->assertSame('01700000001', $after->customer_phone);

        // A new order flow in the same thread asks again instead of
        // summarising with the consumed details.
        $r = $this->send($brain, $conv, 'confirm korchi, arekta order korbo');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertStringNotContainsString('মিরপুর', $r->body);

        // The customer re-states the details: now the summary is
        // built from the fresh ones.
        $r2 = $this->send($brain, $conv, 'phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($r2);
        $this->assertSame('order_summary', $r2->meta['kind'] ?? null);
        $this->assertStringContainsString('01612345678', $r2->body);
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r2->body);
        $this->assertStringContainsString('মোট: 910', $r2->body);
        $this->assertStringNotContainsString('01700000001', $r2->body);
    }
}

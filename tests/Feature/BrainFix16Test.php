<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaMessageJob;
use App\Models\AiActivity;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\ImageMatchService;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Brain fix batch 16 (2026-11-26): five defects from the combined
 * live re-check of batches 11b-15 (2026-11-25 23:46-00:22, CRUD
 * Test Shop).
 *
 * (1) A cancel word right after a clarification switch got the
 *     no-rule price repeat + handover instead of the cancel
 *     acknowledgement: batch 11b's switch close-out clears the
 *     collection, the ladder, the cart and the summary so completely
 *     that flowCancellation()'s flow-active test finds nothing left
 *     to cancel — yet the customer IS closing the conversation the
 *     switch just re-aimed. The close-out now leaves a one-message
 *     arm the cancellation can attach to; a cancel with no recent
 *     flow keeps today's behaviour (pinned below).
 * (2) The photo no-match fallback still did not fire live (third
 *     attempt): ProcessMediaMessageJob treated the conversation's
 *     FOCUS product as a high-confidence identification, so the
 *     matcher never ran and any photo sent mid-conversation was
 *     answered with the focus product's price. Focus is a hint,
 *     not an identification — the matcher now decides every
 *     caption-less photo.
 * (3) Romanised attribute words (ojon, wajan, map) were missing
 *     from batch 12's attribute detection and from the catalog's
 *     Product details rule, so "CRUD-101 er ojon koto?" got the
 *     price answer.
 * (4) Romanised where-words (kothay, koi, ki obostha) were missing
 *     from batch 12's invoice-lookup gate, so "OB299 kothay ache?"
 *     never reached the status lookup.
 * (5) The batch-15 off-hours line was created with status 'sent'
 *     but never dispatched: every entry point dispatches only the
 *     message handleIncoming RETURNS, and the line is a side
 *     message. It now goes through MetaGraphService::dispatch like
 *     every other send, with the outcome persisted on its meta.
 */
class BrainFix16Test extends TestCase
{
    use RefreshDatabase;

    private const FALLBACK = 'দুঃখিত, ছবিটা ঠিক চিনতে পারিনি। আপনি কোন product-টা খুঁজছেন? নামটা বললে দামসহ জানিয়ে দিচ্ছি।';

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The live shop's shape: two Panjabis at different prices + a
     * cap; the conversation's focus is the first Panjabi.
     *
     * @return array{Business, Product, Product, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Livecheck Shop', 'public_id' => 'livecheck-' . uniqid(), 'status' => 'approved']);
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

    // ----------------------------------------------------- (1) cancel arm

    /**
     * The live A4 replay: collect for CRUD-101 → ambiguous side
     * question → clarification → pick "2" (the 11b switch) → the
     * price answer for Panjabi 2 → "বাদ দিন" must now get the
     * cancel acknowledgement, not the no-rule price repeat.
     */
    public function test_cancel_right_after_a_clarification_switch_gets_the_acknowledgement(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->send($brain, $conv, '01612345678');
        $this->assertSame('collecting', $conv->fresh()->state);

        $clarify = $this->send($brain, $conv, 'Panjabi tar dam koto?');
        $this->assertSame('product_clarify', $clarify->meta['kind'] ?? null);

        $pick = $this->send($brain, $conv, '2');
        $this->assertNotNull($pick);
        $this->assertStringContainsString('850', $pick->body);
        $this->assertNull($conv->fresh()->state, 'the switch closed the collection');

        $cancel = $this->send($brain, $conv, 'বাদ দিন');
        $this->assertNotNull($cancel, 'the cancel is answered, not dropped');
        $this->assertSame('collect_cancel', $cancel->meta['kind'] ?? null, 'the cancel acknowledgement, not the no-rule price repeat');
        $this->assertStringContainsString('ঠিক আছে, অর্ডারটা রাখা হলো না।', $cancel->body);
        $this->assertSame('sent', $cancel->status, 'full auto sends the acknowledgement');
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'a clean cancel raises no human flag');
    }

    /**
     * The arm covers exactly ONE follow-up message: a non-cancel
     * message after the switch spends it, and a cancel after THAT
     * is back to today's no-flow behaviour.
     */
    public function test_the_switch_arm_is_spent_by_the_next_message_whatever_it_is(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->send($brain, $conv, '01612345678');
        $this->send($brain, $conv, 'Panjabi tar dam koto?');
        $this->send($brain, $conv, '2');
        $this->assertNull($conv->fresh()->state);

        // A fresh price question about the switched product is
        // answered normally (and spends the arm).
        $price = $this->send($brain, $conv, 'CRUD-102 er dam koto?');
        $this->assertNotNull($price);
        $this->assertStringContainsString('850', $price->body);
        $this->assertNotSame('collect_cancel', $price->meta['kind'] ?? null);

        // The cancel that arrives now has no flow left to attach
        // to: today's focus-only behaviour (the no-rule draft +
        // flag), NOT the cancel acknowledgement.
        $cancel = $this->send($brain, $conv, 'বাদ দিন');
        $this->assertNotSame('collect_cancel', $cancel?->meta['kind'] ?? null);
    }

    /**
     * Pin: a cancel word with NO recent flow at all keeps today's
     * behaviour — with no focus it is silence + the human flag;
     * with a mere focus product it is the no-rule price draft.
     * The switch arm must not widen to either case.
     */
    public function test_a_cancel_with_no_recent_flow_keeps_todays_behaviour(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        // Focus only, no flow ever ran.
        $r = $this->send($brain, $conv, 'বাদ দিন');
        $this->assertNotNull($r);
        $this->assertNotSame('collect_cancel', $r->meta['kind'] ?? null);
        $this->assertSame('draft', $r->status, 'the no-rule fallback drafts, as today');
        $this->assertTrue((bool) $conv->fresh()->needs_human);

        // No focus, no flow.
        $bare = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Bare Customer',
        ]);
        $r2 = $this->send($brain, $bare, 'বাদ দিন');
        $this->assertNull($r2, 'no reply today, and still none');
        $this->assertTrue((bool) $bare->fresh()->needs_human);
    }

    // ----------------------------------------------------- (2) photo focus

    /** The vision matcher is stubbed: identify() returns the given verdict. */
    private function stubMatcher(array $verdict): void
    {
        $stub = new class extends ImageMatchService
        {
            public array $verdict = ['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product'];

            public function __construct()
            {
            }

            public function identify(int $businessId, string $relativePath, string $caption): array
            {
                return $this->verdict;
            }
        };
        $stub->verdict = $verdict;
        app()->instance(ImageMatchService::class, $stub);
    }

    private function photo(Conversation $conv, string $caption = ''): ChatMessage
    {
        return ChatMessage::create([
            'business_id' => $conv->business_id,
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'sender' => 'customer',
            'body' => $caption,
            'status' => 'sent',
            'attachment_type' => 'image',
            'attachment_path' => 'chat-media/' . $conv->business_id . '/test-' . uniqid() . '.jpg',
        ]);
    }

    private function runJob(ChatMessage $msg): void
    {
        (new ProcessMediaMessageJob($msg->id))->handle(
            app(RuleBrainService::class),
            app(MetaGraphService::class),
            app(ImageMatchService::class),
        );
    }

    /** @return \Illuminate\Support\Collection<int, ChatMessage> */
    private function outgoing(Conversation $conv)
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')->where('sender', 'ai')->get()->values();
    }

    /**
     * The live Part B replay: the conversation HAS a focus product
     * (the whole evening was about the Panjabis) and the customer
     * sends a photo of something else entirely. The matcher says
     * no match — the customer must get the fallback line and the
     * human flag, NOT the focus product's price.
     */
    public function test_unmatched_photo_with_a_focus_product_sends_the_fallback_not_the_focus_price(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $this->assertSame($p1->id, (int) $conv->product_id, 'a focus product stands, as in the live thread');
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv));

        $out = $this->outgoing($conv);
        $fallbacks = $out->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')->values();
        $this->assertCount(1, $fallbacks, 'exactly one fallback reply is created');
        $this->assertSame(self::FALLBACK, $fallbacks[0]->body);
        $this->assertSame('sent', $fallbacks[0]->status, 'a full-auto channel sends it');
        foreach ($out as $m) {
            $this->assertStringNotContainsString('1,300', (string) $m->body, 'no outgoing quotes the focus product price');
        }
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the human flag is raised for the photo');
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'image_no_match')->exists(),
            'the no-match activity is logged'
        );
    }

    /**
     * The same replay with the REAL matcher: a real JPEG of an
     * unrelated object, no reference hashes, no vision key — the
     * matcher itself must reach the no-match verdict even with a
     * focus product standing.
     */
    public function test_unmatched_photo_with_focus_through_the_real_matcher(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();

        $rel = 'chat-media/' . $biz->id . '/red-bag-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(64, 48);
        $red = imagecolorallocate($img, 200, 30, 30);
        imagefill($img, 0, 0, $red);
        ob_start();
        imagejpeg($img, null, 85);
        Storage::disk('public')->put($rel, (string) ob_get_clean());
        imagedestroy($img);

        $msg = ChatMessage::create([
            'business_id' => $conv->business_id,
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'sender' => 'customer',
            'body' => '',
            'status' => 'sent',
            'attachment_type' => 'image',
            'attachment_path' => $rel,
        ]);

        $this->runJob($msg);

        $fallbacks = $this->outgoing($conv)
            ->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')->values();
        $this->assertCount(1, $fallbacks, 'the real matcher reaches no-match and the fallback fires');
        $this->assertTrue((bool) $conv->fresh()->needs_human);

        Storage::disk('public')->delete($rel);
    }

    /**
     * A photo the matcher DOES identify still gets its product
     * answer — even when a different product stands in focus, the
     * identified product wins and no fallback fires.
     */
    public function test_a_matched_photo_still_answers_for_the_identified_product_not_the_focus(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $this->stubMatcher(['level' => 'high', 'product' => $p2, 'method' => 'vision', 'image_type' => 'product']);

        $this->runJob($this->photo($conv));

        $out = $this->outgoing($conv);
        $this->assertCount(0, $out->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')->values(), 'no fallback for a matched photo');
        $this->assertTrue(
            $out->contains(fn ($m) => str_contains((string) $m->body, '850')),
            'the answer is about the identified product (Panjabi 2, 850)'
        );
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'no human flag for a matched photo');
        $this->assertSame($p2->id, (int) $conv->fresh()->product_id, 'the focus follows the identified product');
    }

    // --------------------------------------------- (3) romanised attributes

    /**
     * Live C1: "CRUD-101 er ojon koto?" answered with the 1,300
     * price. Romanised attribute words (ojon, wajan, map) must
     * reach the attribute path — the Product details rule — never
     * the price; a message naming the price stays a price question.
     */
    public function test_romanised_attribute_questions_reach_the_attribute_rule_not_the_price(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        foreach (['CRUD-101 er ojon koto?', 'ojon koto?', 'wajan koto?', 'product tar map koto?'] as $q) {
            $this->assertNotSame('price', $brain->detectIntent($q, $conv->fresh()), $q);
            $rule = $brain->matchRule($biz->id, $q, 'facebook', $conv->fresh());
            $this->assertNotNull($rule, $q);
            $this->assertSame('Product details question', $rule->name, $q);
        }

        $r = $this->send($brain, $conv, 'CRUD-101 er ojon koto?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('1,300', (string) $r->body, 'the attribute answer never quotes the price');

        // Boundaries: the price named in romanised words stays price.
        $this->assertSame('price', $brain->detectIntent('size L er dam koto?', $conv->fresh()));
        $this->assertSame('price', $brain->detectIntent('ojon er dam koto?', $conv->fresh()));
    }

    // ------------------------------------------- (4) romanised invoice gate

    /**
     * Live C3: "OB299 kothay ache?" never reached batch 12's
     * invoice lookup — the where-is-it gate only knew Bengali
     * script. Romanised where-words open the same lookup, with
     * the same own-shop-only, status-only privacy.
     */
    public function test_invoice_lookup_with_romanised_where_words(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $order = Order::create(['business_id' => $biz->id, 'invoice_no' => 'OB299', 'customer_name' => 'Someone Else', 'customer_phone' => '01799999999', 'status' => 'Shipped', 'grand_total' => 868]);
        $brain = app(RuleBrainService::class);

        foreach (['OB299 kothay ache?', 'OB299 koi?', 'OB299 er ki obostha?'] as $q) {
            $this->assertSame('order_status', $brain->detectIntent($q, $conv->fresh()), $q);
        }

        $r = $this->send($brain, $conv, 'OB299 kothay ache?');
        $this->assertNotNull($r);
        $this->assertSame('order_status', $r->meta['kind'] ?? null);
        $this->assertSame($order->id, (int) ($r->meta['order_status'] ?? 0));
        $this->assertStringContainsString('OB299', $r->body);
        $this->assertStringContainsString('Shipped', $r->body);
        $this->assertStringNotContainsString('Someone Else', $r->body, 'status only — never the order\'s name');
        $this->assertStringNotContainsString('01799999999', $r->body, 'status only — never the order\'s phone');

        // Unknown number: the honest not-found reply, same as Bengali.
        $r = $this->send($brain, $conv, 'OB777 kothay ache?');
        $this->assertNotNull($r);
        $this->assertSame('order_status', $r->meta['kind'] ?? null);
        $this->assertSame('OB777', $r->meta['invoice_not_found'] ?? null);

        // Another shop's number is "not found" here, as before.
        $biz2 = Business::create(['name' => 'Other Shop', 'public_id' => 'other-' . uniqid(), 'status' => 'approved']);
        Order::create(['business_id' => $biz2->id, 'invoice_no' => 'OB555', 'customer_name' => 'Foreign', 'customer_phone' => '01733333333', 'status' => 'Delivered', 'grand_total' => 500]);
        $r = $this->send($brain, $conv, 'OB555 kothay ache?');
        $this->assertNotNull($r);
        $this->assertSame('OB555', $r->meta['invoice_not_found'] ?? null, 'a foreign number is not found in this shop');
    }

    // ------------------------------------------------- (5) off-hours dispatch

    /**
     * A live-channel shop (real external id + a saved channel
     * config) whose working hours are 9 AM - 11 PM.
     *
     * @return array{Business, Conversation}
     */
    private function liveShopOffHours(): array
    {
        $biz = Business::create(['name' => 'Off Hours Shop', 'public_id' => 'offhours-' . uniqid(), 'status' => 'approved']);
        Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'PJ-1', 'price' => 1300, 'stock_qty' => 10]);
        app(SettingsService::class)->set('channels.facebook', [
            'token_enc' => MetaGraphService::encrypt('test-token'),
            'account_id' => 'page-123',
        ]);
        app(SettingsService::class)->set("ai.{$biz->id}.working_hours", '9 AM - 11 PM');
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Night Customer', 'external_id' => 'psid-123',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-26 03:00', 'Asia/Dhaka'));

        return [$biz, $conv];
    }

    private function offHoursLine(Conversation $conv): ?ChatMessage
    {
        return ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->get()->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'off_hours')->first();
    }

    /**
     * Live Part F: the off-hours line was created 'sent' but its
     * meta carried NO send outcome and the customer never got it.
     * It must now be dispatched through MetaGraphService like any
     * other send — success recorded on the meta.
     */
    public function test_the_off_hours_line_is_dispatched_and_its_success_recorded(): void
    {
        [$biz, $conv] = $this->liveShopOffHours();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid-1'], 200),
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দাম কত?');
        $this->assertNotNull($r);
        $this->assertSame('draft', $r->status, 'off-hours the reply itself still only drafts');

        $line = $this->offHoursLine($conv);
        $this->assertNotNull($line, 'the off-hours line is created');
        $this->assertSame('sent', $line->status);
        $this->assertSame('delivered_to_meta', $line->meta['send_status'] ?? null, 'the dispatch outcome is persisted on the line');
        Http::assertSent(function ($request) {
            $payload = json_decode((string) $request->body(), true);

            return str_contains((string) ($payload['message']['text'] ?? ''), 'বন্ধ আছে');
        });
    }

    /**
     * A FAILED off-hours send must be visible exactly like any
     * other failed send: send_status 'failed' plus Meta's full
     * error (message, code, subcode, fbtrace_id) on the meta.
     */
    public function test_a_failed_off_hours_send_records_the_full_meta_error(): void
    {
        [$biz, $conv] = $this->liveShopOffHours();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Application does not have permission for this action',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'error_subcode' => 2018022,
                    'fbtrace_id' => 'AbCdEf123',
                ],
            ], 400),
        ]);
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'দাম কত?');

        $line = $this->offHoursLine($conv);
        $this->assertNotNull($line);
        $meta = $line->meta;
        $this->assertSame('failed', $meta['send_status'] ?? null);
        $this->assertSame('Application does not have permission for this action', $meta['send_error'] ?? null);
        $this->assertSame(200, $meta['send_error_code'] ?? null);
        $this->assertSame(2018022, $meta['send_error_subcode'] ?? null);
        $this->assertSame('AbCdEf123', $meta['send_fbtrace_id'] ?? null);
    }
}

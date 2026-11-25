<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaMessageJob;
use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImageHash;
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
 * Brain fix batch 17 (2026-11-26): four findings from the clean-slate
 * Round 1 Phase 2 LIVE verification (02:44-02:50, live head 08bf4e7b,
 * migration 000065 run) plus the invoice-status log label.
 *
 * (1) A clarification pick ("2") sent while a negotiation ladder
 *     stood mid-flight was consumed by the ladder: detectIntent's
 *     mid-bargain digit branch calls any digit a bargain push, so
 *     matchRule returned the bargain rule and the pick block's
 *     resume-the-clarified-intent restore (which only fires when NO
 *     rule matched) never ran — the customer got an unprompted
 *     step-2 discount instead of the plain price they asked for.
 * (2) The photo no-match fallback STILL did not fire live: two
 *     doors batch 16 left open. (a) A captioned photo is identified
 *     by productFor($biz, $caption, $conv) — and productFor returns
 *     the FOCUS product before it reads a word of the caption, so
 *     any caption at all "identifies" the focus, and the job then
 *     learn()s the photo as that product. (b) Those learned rows
 *     (written by the pre-fix code for the red-bag photo itself)
 *     are indistinguishable from gallery references to the matcher:
 *     a re-sent photo hashes to distance 0 against its own poisoned
 *     row and "matches" at high confidence forever. Customer-learned
 *     hashes are now capped: they may shortlist a product for the
 *     vision check, but only gallery references and HUMAN-CONFIRMED
 *     learns can carry a high-confidence hash match.
 * (3) "CRUD-101 er ojon koto?" still got the bare price live
 *     although migration 000065 ran: matchRule's keyword fallback
 *     returns the FIRST intent rule whose words appear, and only
 *     keyword rules visited BEFORE it in id order have competed.
 *     Shops backfilled by migrations 000061 (core intents) then
 *     000062 (the library) carry the Price rule at a lower id than
 *     the Product-details keyword rule, so 'koto' — a price word —
 *     ends the competition before the attribute rule is ever seen.
 *     Fresh installs (catalog order: keyword defs before the core
 *     intents) mask it, which is why batch 16's tests passed. The
 *     competition is now order-independent, and detectIntent's
 *     mid-bargain block lets an attribute question through like the
 *     batch-2 price-question carve-out. Migration 000067 appends
 *     the romanised words to Product-details rows a seller
 *     customised (000065 could only widen untouched defaults).
 * (4) Meta error-code persistence: every ChatMessage send path
 *     (dispatch, quick replies, off-hours line, staff reply,
 *     draft approval) funnels through MetaGraphService::dispatch /
 *     dispatchQuickReplies, which persist the full error — proven
 *     per path below; no path drops the fields.
 * (5) A successful invoice-status answer was logged as "No rule
 *     matched": the deliberate no-rule branches (invoice lookup,
 *     acknowledgement, presence) now name themselves in the record.
 */
class BrainFix17Test extends TestCase
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

    /** @return array{Business, Product, Product, Product, Conversation} */
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

    /** @return \Illuminate\Support\Collection<int, ChatMessage> */
    private function outgoing(Conversation $conv)
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')->where('sender', 'ai')->get()->values();
    }

    // ------------------------------------- (1) pick vs standing ladder

    /**
     * The live replay, state included: a ladder for Panjabi 2 stands
     * at step 1 (850 -> 808 offered, floor 723) from earlier probes.
     * "panjabi dam koto" clarifies; the pick "2" must answer the
     * PRICE question that was asked — 850, plainly — and the ladder
     * must not move. A genuine push afterwards still advances it.
     */
    public function test_a_clarification_pick_answers_the_price_and_never_advances_a_standing_ladder(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->neg_step = 1;
        $conv->neg_offer = 808;
        $conv->save();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'panjabi dam koto');
        $this->assertNotNull($r);
        $this->assertSame('product_clarify', $r->meta['kind'] ?? null, 'the category question clarifies first');

        $r = $this->send($brain, $conv, '2');
        $this->assertNotNull($r);
        $this->assertStringContainsString('850', (string) $r->body, 'the pick answers the price that was asked');
        $this->assertStringNotContainsString('765', (string) $r->body, 'no unprompted ladder discount is offered');
        $after = $conv->fresh();
        $this->assertSame(1, (int) $after->neg_step, 'the ladder did not advance on a pick');
        $this->assertSame(808.0, (float) $after->neg_offer, 'the standing offer is untouched');

        // A fresh bargain signal from the customer still bargains.
        $r = $this->send($brain, $conv, 'ektu kom hobe?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('765', (string) $r->body, 'a real push resumes the ladder where it stood');
        $this->assertSame(765.0, (float) $conv->fresh()->neg_offer);
    }

    /**
     * The pick message itself carrying a bargain push ("2, ektu kom
     * hobe?") is a fresh signal: the ladder may advance for the
     * picked product.
     */
    public function test_a_pick_carrying_its_own_bargain_push_still_bargains(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->neg_step = 1;
        $conv->neg_offer = 808;
        $conv->save();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'panjabi dam koto');
        $r = $this->send($brain, $conv, '2, ektu kom hobe?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('765', (string) $r->body, 'the message\'s own push advances the ladder for the pick');
    }

    /** The fresh-conversation control: this already worked and must keep working. */
    public function test_a_clarification_pick_with_no_ladder_still_answers_the_price(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'panjabi dam koto');
        $r = $this->send($brain, $conv, '2');
        $this->assertNotNull($r);
        $this->assertStringContainsString('850', (string) $r->body);
    }

    // ------------------------------------------------- (2) photo doors

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

    private function photo(Conversation $conv, string $caption = '', ?string $path = null): ChatMessage
    {
        return ChatMessage::create([
            'business_id' => $conv->business_id,
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'sender' => 'customer',
            'body' => $caption,
            'status' => 'sent',
            'attachment_type' => 'image',
            'attachment_path' => $path ?? 'chat-media/' . $conv->business_id . '/test-' . uniqid() . '.jpg',
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

    /**
     * Write a left-to-right luminance-gradient JPEG to the public
     * disk; returns its relative path. dHash reads horizontal
     * gradients, so a dark-to-light image and a light-to-dark one
     * sit ~64 bits apart — the two test images can never collide
     * the way two solid colours would (both hash flat).
     */
    private function gradientImage(int $bizId, string $name, array $from, array $to): string
    {
        $rel = 'chat-media/' . $bizId . '/' . $name . '-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(64, 48);
        for ($x = 0; $x < 64; $x++) {
            $t = $x / 63;
            $c = imagecolorallocate(
                $img,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t),
            );
            imagefilledrectangle($img, $x, 0, $x, 47, $c);
        }
        ob_start();
        imagejpeg($img, null, 85);
        Storage::disk('public')->put($rel, (string) ob_get_clean());
        imagedestroy($img);

        return $rel;
    }

    /**
     * Door (a): a captioned photo while a product stands in focus.
     * The caption names NOTHING ("এটা কত?") — it cannot identify
     * the focus product; the matcher decides, and its no-match
     * verdict must produce the fallback, not the focus price.
     */
    public function test_a_caption_that_names_nothing_does_not_identify_the_focus_product(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv, 'এটা কত?'));

        $out = $this->outgoing($conv);
        $fallbacks = $out->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')->values();
        $this->assertCount(1, $fallbacks, 'the matcher\'s no-match verdict produces the fallback');
        $this->assertSame(self::FALLBACK, $fallbacks[0]->body);
        foreach ($out as $m) {
            $this->assertStringNotContainsString('1,300', (string) $m->body, 'the focus product price is never quoted');
        }
    }

    /**
     * Door (a), other side: a caption that DOES name a product
     * identifies THAT product — never the focus standing behind it.
     */
    public function test_a_caption_naming_another_product_identifies_that_product_not_the_focus(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv, 'plain cap er dam koto?'));

        $out = $this->outgoing($conv);
        $this->assertTrue($out->contains(fn ($m) => str_contains((string) $m->body, '250')), 'the captioned product (Cap, 250) is answered');
        foreach ($out as $m) {
            $this->assertStringNotContainsString('1,300', (string) $m->body, 'the focus product is not answered');
        }
        $this->assertSame($p3->id, (int) $conv->fresh()->product_id, 'the focus follows the caption\'s product');
    }

    /**
     * Door (b), the live mechanism itself: the pre-fix code answered
     * this very red-bag photo as the focus product and LEARNED it —
     * a 'customer' hash row now maps the photo to Panjabi 2. A
     * re-send of the same photo must not "match" that row at high
     * confidence: the matcher runs, the row may only shortlist, and
     * with no vision verdict the outcome is the no-match fallback.
     */
    public function test_a_customer_learned_hash_alone_never_identifies_at_high_confidence(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $matcher = app(ImageMatchService::class);

        // Panjabi 1 has a real gallery photo (a light-to-dark blue
        // gradient) so the shop has trustworthy references; the red
        // bag (dark-to-light, ~64 hash bits away) is the photo the
        // old bug learned as Panjabi 2.
        $galleryRel = $this->gradientImage($biz->id, 'gallery', [80, 80, 255], [20, 20, 120]);
        $p1->image = $galleryRel;
        $p1->save();
        $matcher->seedProductHashes($biz->id);
        $redRel = $this->gradientImage($biz->id, 'red-bag', [120, 20, 20], [255, 80, 80]);
        $matcher->learn($biz->id, $p2->id, $redRel, 'customer');
        $this->assertTrue(
            ProductImageHash::where('business_id', $biz->id)->where('product_id', $p2->id)->where('source', 'customer')->exists(),
            'the poisoned learned row stands, as it does on live'
        );

        $conv->product_id = $p2->id;
        $conv->focus_at = now();
        $conv->save();
        $this->runJob($this->photo($conv, '', $redRel));

        $out = $this->outgoing($conv);
        $fallbacks = $out->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')->values();
        $this->assertCount(1, $fallbacks, 'the re-sent photo reaches the no-match fallback, not a learned "match"');
        foreach ($out as $m) {
            $this->assertStringNotContainsString('850', (string) $m->body, 'Panjabi 2\'s price is never quoted for the bag photo');
        }
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'image_no_match')->exists(),
            'the no-match is on the record'
        );

        Storage::disk('public')->delete($galleryRel);
        Storage::disk('public')->delete($redRel);
    }

    /**
     * The trust ladder's top rungs still work: a HUMAN-CONFIRMED
     * learn (the image-confirm "হ্যাঁ") and a gallery reference both
     * carry a high-confidence hash match.
     */
    public function test_confirmed_and_gallery_hashes_still_identify_at_high_confidence(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $matcher = app(ImageMatchService::class);

        $redRel = $this->gradientImage($biz->id, 'confirmed', [120, 20, 20], [255, 80, 80]);
        $matcher->learn($biz->id, $p2->id, $redRel, 'confirmed');
        $verdict = $matcher->identify($biz->id, $redRel, '');
        $this->assertSame('high', $verdict['level'], 'a confirmed learn identifies at high confidence');
        $this->assertSame($p2->id, (int) $verdict['product']->id);

        $blueRel = $this->gradientImage($biz->id, 'gallery', [80, 80, 255], [20, 20, 120]);
        $matcher->learn($biz->id, $p1->id, $blueRel, 'gallery');
        $verdict = $matcher->identify($biz->id, $blueRel, '');
        $this->assertSame('high', $verdict['level'], 'the product\'s own gallery photo identifies at high confidence');
        $this->assertSame($p1->id, (int) $verdict['product']->id);

        Storage::disk('public')->delete($redRel);
        Storage::disk('public')->delete($blueRel);
    }

    // --------------------------------------- (3) attribute rule routing

    /**
     * Reinstall the shop's rules the way the LIVE shop got them:
     * the original core intents first (migration 000061's set),
     * the rest of the library afterwards (000062+) — so the Price
     * rule carries a LOWER id than the Product-details keyword rule.
     */
    private function reinstallRulesInBackfillOrder(Business $biz): void
    {
        AiRule::where('business_id', $biz->id)->delete();
        $core = ['price', 'bargain', 'stock', 'order_intent', 'delivery', 'greeting'];
        foreach (AiRule::defaults() as $def) {
            if (($def['trigger_type'] ?? 'intent') === 'intent' && in_array($def['match_text'], $core, true)) {
                AiRule::create(['business_id' => $biz->id, 'name' => $def['name'], 'trigger_type' => 'intent', 'match_text' => $def['match_text'], 'action' => $def['action'], 'reply_template' => $def['reply_template'], 'active' => true]);
            }
        }
        AiRule::ensureDefaults($biz->id);
        $price = AiRule::where('business_id', $biz->id)->where('match_text', 'price')->first();
        $details = AiRule::where('business_id', $biz->id)->where('name', 'Product details question')->first();
        $this->assertLessThan($details->id, $price->id, 'the backfill order stands: price id below details id');
    }

    /**
     * Live C1, exact conditions: rules in backfill order, migration
     * 000065's keywords present — the attribute question must still
     * reach the Product details rule, never the bare price.
     */
    public function test_attribute_question_wins_when_rules_were_installed_in_backfill_order(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $this->reinstallRulesInBackfillOrder($biz);
        $brain = app(RuleBrainService::class);

        $rule = $brain->matchRule($biz->id, 'CRUD-101 er ojon koto?', 'facebook', $conv->fresh());
        $this->assertNotNull($rule);
        $this->assertSame('Product details question', $rule->name, 'the keyword competition is install-order independent');

        $r = $this->send($brain, $conv, 'CRUD-101 er ojon koto?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('1,300', (string) $r->body, 'the attribute answer never quotes the price');
        $this->assertStringContainsString('নিশ্চিত করে জানাচ্ছি', (string) $r->body, 'the Product details template answered');

        // A seller who customised the rule's TEMPLATE (keywords
        // intact) gets their own words through the same routing.
        AiRule::where('business_id', $biz->id)->where('name', 'Product details question')
            ->update(['reply_template' => '{honorific}, কাস্টম উত্তর: {product} এর বিবরণ দেখে জানাচ্ছি।']);
        $r = $this->send($brain, $conv, 'CRUD-101 er ojon koto?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('কাস্টম উত্তর', (string) $r->body, 'a customised template is honoured');
    }

    /**
     * Mid-bargain, the attribute question is a question, not a
     * ladder push — even carrying the product's SKU digits, which
     * detectIntent's digit branch used to read as a counter-offer.
     */
    public function test_attribute_question_mid_bargain_is_not_a_ladder_push(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        $conv->neg_step = 1;
        $conv->neg_offer = 1235;
        $conv->save();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'CRUD-101 er ojon koto?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('1,300', (string) $r->body);
        $this->assertStringNotContainsString('1,170', (string) $r->body, 'no ladder rung is quoted');
        $after = $conv->fresh();
        $this->assertSame(1, (int) $after->neg_step, 'the ladder did not move');
        $this->assertSame(1235.0, (float) $after->neg_offer);

        // Boundaries hold mid-bargain too: naming the price is a
        // price question; an attribute word beside দাম is price.
        $this->assertSame('price', $brain->detectIntent('CRUD-101 er dam koto?', $conv->fresh()));
        $this->assertSame('price', $brain->detectIntent('ojon er dam koto?', $conv->fresh()));
    }

    /**
     * Migration 000067: a Product-details row a seller customised
     * (their own keyword list, no romanised words — the rows 000065
     * could not touch) gains the romanised words by APPEND only:
     * their keywords and their reply stay byte-identical, and a
     * second run appends nothing.
     */
    public function test_migration_000067_appends_romanised_words_to_customised_details_rows_only(): void
    {
        [$biz] = $this->shop();
        $custom = AiRule::where('business_id', $biz->id)->where('name', 'Product details question')->first();
        $custom->match_text = 'ওজন কত, আমার নিজের শব্দ';
        $custom->reply_template = 'আমার নিজের উত্তর';
        $custom->save();

        $migration = require database_path('migrations/2026_11_26_000067_backfill_product_details_romanised_keywords.php');
        $migration->up();

        $row = $custom->fresh();
        $this->assertStringStartsWith('ওজন কত, আমার নিজের শব্দ', (string) $row->match_text, 'their keywords stand, untouched, at the front');
        $this->assertStringContainsString('ojon', (string) $row->match_text, 'the romanised words were appended');
        $this->assertSame('আমার নিজের উত্তর', $row->reply_template, 'the reply is never overwritten');

        $migration->up();
        $this->assertSame(1, substr_count((string) $custom->fresh()->match_text, 'ojon koto'), 'a second run appends nothing twice');
    }

    // --------------------------- (4) approval-path error persistence

    /**
     * The draft-approval send (MessagesController: brain marks the
     * draft sent, the controller dispatches it) persists Meta's
     * full error on the message meta when Meta refuses — the same
     * fields as every other send path.
     */
    public function test_a_failed_send_on_the_draft_approval_path_persists_the_full_meta_error(): void
    {
        $biz = Business::create(['name' => 'Approval Shop', 'public_id' => 'approval-' . uniqid(), 'status' => 'approved']);
        app(SettingsService::class)->set('channels.facebook', [
            'token_enc' => MetaGraphService::encrypt('test-token'),
            'account_id' => 'page-123',
        ]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Approval Customer', 'external_id' => 'psid-123',
        ]);
        $draft = ChatMessage::create([
            'business_id' => $biz->id, 'conversation_id' => $conv->id,
            'direction' => 'out', 'sender' => 'ai', 'status' => 'draft',
            'body' => 'ধন্যবাদ, আপনার মেসেজ পেয়েছি।',
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Application does not have permission for this action',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'error_subcode' => 2018022,
                    'fbtrace_id' => 'ApprovalTrace1',
                ],
            ], 400),
        ]);

        $handled = app(RuleBrainService::class)->approveDraft($draft, null, null);
        $this->assertNotNull($handled);
        $this->assertSame('sent', $handled->fresh()->status, 'approval marks the draft sent');

        [$ok] = app(MetaGraphService::class)->dispatch($handled->fresh());
        $this->assertFalse($ok);

        $meta = $handled->fresh()->meta;
        $this->assertSame('failed', $meta['send_status'] ?? null);
        $this->assertSame('Application does not have permission for this action', $meta['send_error'] ?? null);
        $this->assertSame(200, $meta['send_error_code'] ?? null);
        $this->assertSame(2018022, $meta['send_error_subcode'] ?? null);
        $this->assertSame('ApprovalTrace1', $meta['send_fbtrace_id'] ?? null);
    }

    // --------------------- (5) invoice-status answer logging truth

    /**
     * The live (d) probe in a clean state-loaded thread: the flag
     * already stands from earlier probes (as it did live), the
     * status question is answered correctly — and THIS message
     * must not log a handover, nor record its own successful
     * answer as "No rule matched".
     */
    public function test_invoice_status_answer_is_logged_as_a_status_answer_and_raises_no_handover(): void
    {
        [$biz, $p1, $p2, $p3, $conv] = $this->shop();
        Order::create(['business_id' => $biz->id, 'invoice_no' => 'OB299', 'customer_name' => 'Someone Else', 'customer_phone' => '01799999999', 'status' => 'Delivered', 'grand_total' => 868]);
        $conv->needs_human = true; // left over from earlier probes, as on live
        $conv->save();
        $handoversBefore = AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count();
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'OB299 kothay ache?');
        $this->assertNotNull($r);
        $this->assertSame('order_status', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('Delivered', (string) $r->body);

        $this->assertSame(
            $handoversBefore,
            AiActivity::where('conversation_id', $conv->id)->where('action', 'handed_human')->count(),
            'a successful status answer raises no handover of its own'
        );
        $log = AiActivity::where('conversation_id', $conv->id)
            ->whereIn('action', ['draft_created', 'auto_sent'])->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Order status answer', (string) $log->detail, 'the record names what answered');
        $this->assertStringNotContainsString('No rule matched', (string) $log->detail);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\AiTalkerService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brain fix batch (owner order 2026-11-25, from watching the live
 * Messenger test): confirmation ends a negotiation instead of
 * repeating the offer, bargain figures are product-price only, the
 * talker receives the conversation context, a text request for a
 * non-catalogue item gets a not-available answer, one customer
 * message produces exactly one reply, and the expanded Master Brain
 * Rule Library routes its new intents to their own rules.
 *
 * The regression scenarios use the EXACT customer messages from the
 * live run (13:51 double send, 13:56 confirm+details, 13:59 confirm).
 */
class BrainFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stage 2 (the model classifier) stays silent in these tests:
        // every scenario is decided by the deterministic layer, which
        // is the layer the live failures lived in.
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

    /** A shop WITH the full library (BusinessObserver provisions it). */
    private function shopWithProduct(float $price = 1300): array
    {
        $biz = Business::create(['name' => 'Brain Shop', 'public_id' => 'brain-shop-' . uniqid(), 'status' => 'approved']);
        $product = Product::create(['business_id' => $biz->id, 'name' => 'CRUD-101 Panjabi', 'sku' => 'CRUD-101', 'price' => $price, 'stock_qty' => 10]);
        $conv = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Phase2 Customer', 'status' => 'open', 'product_id' => $product->id]);

        return [$biz, $product, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        // handleIncoming stores the incoming message itself.
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function outCount(Conversation $conv): int
    {
        return ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->count();
    }

    public function test_live_1356_confirm_with_details_after_bargaining_gives_the_summary_once(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        // Two bargain rounds: 1,300 -> 1,235 -> 1,170.
        $r1 = $this->send($brain, $conv, 'দামটা একটু কম হবে?');
        $this->assertSame(1235.0, (float) $r1->meta['offer']);
        $r2 = $this->send($brain, $conv, 'আরেকটু কমান');
        $this->assertSame(1170.0, (float) $r2->meta['offer']);
        $this->assertSame(2, $this->outCount($conv));

        // The exact 13:56 live message: acceptance + full details in
        // ONE message. It must NOT be read as another bargain round.
        $r3 = $this->send($brain, $conv, 'order confirm korchi. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka. CRUD-101 Panjabi 1 piece order korte chai, confirm korun please');

        $this->assertNotNull($r3);
        $this->assertSame(3, $this->outCount($conv), 'exactly one reply to the confirm message');
        $this->assertSame('order_summary', $r3->meta['kind'] ?? null);
        $this->assertStringContainsString('Phase2 Customer', $r3->body);
        $this->assertStringContainsString('01612345678', $r3->body);
        $this->assertStringContainsString('House 10, Road 5, Uttara, Dhaka', $r3->body);
        $this->assertStringContainsString('1,170', $r3->body, 'the agreed price, not a new offer');
        $this->assertStringContainsString('ডেলিভারি চার্জ: 60', $r3->body, 'delivery on its own line');
        $this->assertStringContainsString('মোট: 1,230', $r3->body);
        $this->assertStringNotContainsString('অর্ডার করবেন?', $r3->body);
    }

    public function test_live_1359_confirm_at_the_floor_settles_and_summarises(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'দামটা একটু কম হবে?');
        $this->send($brain, $conv, 'আরেকটু কমান');
        $this->send($brain, $conv, 'order confirm korchi. Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka. CRUD-101 Panjabi 1 piece order korte chai, confirm korun please');
        $before = $this->outCount($conv);

        // The exact 13:59 live message: the customer names the floor
        // (1,105) and confirms. Live, the AI repeated the offer with
        // delivery folded in and asked "অর্ডার করবেন?" again.
        $r = $this->send($brain, $conv, 'ha, confirm korchi. 1,105 takay order ta confirm korun please');

        $this->assertNotNull($r);
        $this->assertSame($before + 1, $this->outCount($conv), 'exactly one reply');
        $this->assertSame('order_summary', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('1,105', $r->body, 'settled at the figure the customer named');
        $this->assertStringContainsString('ডেলিভারি চার্জ: 60', $r->body);
        $this->assertStringContainsString('মোট: 1,165', $r->body);
        $this->assertStringNotContainsString('অর্ডার করবেন?', $r->body);
        $this->assertSame(1105.0, (float) $conv->fresh()->neg_offer);
    }

    public function test_counter_offer_at_or_above_the_floor_is_an_agreement_at_that_figure(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1250);
        $brain = app(RuleBrainService::class);

        // Floor for 1,250 at 15% is 1,063; 1,200 is a valid agreement.
        $r = $this->send($brain, $conv, 'দামটা কমানো যাবে? ১২০০ টাকায় দিলে নিব');

        $this->assertNotNull($r);
        $this->assertSame('accept', $r->meta['quote'] ?? null);
        $this->assertSame(1200.0, (float) $r->meta['offer']);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'no details yet: ask for them, do not re-offer');
        $this->assertStringContainsString('1,200 টাকায় ফাইনাল', $r->body);
        $this->assertStringNotContainsString('অর্ডার করবেন?', $r->body);
    }

    public function test_acceptance_words_end_the_negotiation_and_move_to_collecting(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'দামটা একটু কম হবে?'); // offer 1,235 on the table
        $r = $this->send($brain, $conv, 'confirm korchi');

        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertSame(1235.0, (float) $r->meta['offer']);
        $this->assertStringContainsString('1,235 টাকায় ফাইনাল', $r->body);
        $this->assertStringNotContainsString('অর্ডার করবেন?', $r->body);
    }

    public function test_bargain_replies_never_fold_the_delivery_charge_into_the_offer(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'দামটা একটু কম হবে?');

        $this->assertStringContainsString('1,235', $r->body);
        $this->assertStringNotContainsString('1,295', $r->body, 'offer + delivery must never appear as one figure');
        $this->assertStringNotContainsString('ডেলিভারি চার্জসহ', $r->body);
        $this->assertStringNotContainsString('মোট', $r->body);
    }

    public function test_one_customer_message_produces_exactly_one_reply_even_when_processed_twice(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        // The 13:51 double send: one bargain message, answered by the
        // queued job AND the per-minute sweep. The burst is claimed
        // before the slow work now, so the second run finds nothing.
        $in = ChatMessage::create(['conversation_id' => $conv->id, 'business_id' => $biz->id, 'direction' => 'in', 'sender' => 'customer', 'body' => 'দামটা একটু কম হবে?', 'status' => 'sent']);
        $in->created_at = now()->subMinutes(5);
        $in->save();

        $first = $brain->processPending($conv->fresh());
        $second = $brain->processPending($conv->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $this->outCount($conv));
    }

    public function test_text_request_for_a_non_catalogue_item_gets_the_not_available_answer(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        $price = $this->send($brain, $conv, 'CRUD-101 Panjabi-এর দাম কত?');
        $this->assertStringContainsString('1,300', $price->body);

        // A red dress is not in the catalogue: no panjabi price, no
        // address request, no order — just a clear not-available.
        $r = $this->send($brain, $conv, 'লাল জামা XL লাগবে');

        $this->assertNotNull($r);
        $this->assertSame('out_of_stock', $r->meta['kind'] ?? null);
        $this->assertTrue($r->meta['not_available'] ?? false);
        $this->assertStringContainsString('নেই', $r->body);
        $this->assertStringNotContainsString('1,300', $r->body);
        $this->assertSame(0, Order::where('business_id', $biz->id)->count());
        $this->assertNotSame('collecting', ($conv->fresh()->state_data ?? [])['state'] ?? null);

        // The context product still answers normally afterwards.
        $again = $this->send($brain, $conv, 'এটার দাম কত?');
        $this->assertStringContainsString('1,300', $again->body);
    }

    public function test_new_library_intents_route_to_their_own_rules(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        // COD question -> the COD rule (draft), not the delivery rule.
        $cod = $this->send($brain, $conv, 'cash on delivery ache?');
        $this->assertNotNull($cod);
        $this->assertSame('Cash on delivery question', $cod->rule?->name);
        $this->assertSame('draft', $cod->status);
        $this->assertStringContainsString('পেমেন্ট পদ্ধতি নিয়ে আপনার প্রশ্নটি', $cod->body, "the library's own holding line for COD");

        // Delivery-charge negotiation -> its own rule, not bargain.
        $neg = $this->send($brain, $conv, 'delivery charge komano jabe?');
        $this->assertNotNull($neg);
        $this->assertSame('Delivery charge negotiation', $neg->rule?->name);

        // Late delivery -> handover rule: the acknowledgement goes
        // out and the human flag stays up.
        $late = $this->send($brain, $conv, 'এখনো পাইনি');
        $this->assertNotNull($late);
        $this->assertSame('Late delivery complaint', $late->rule?->name);
        $this->assertTrue((bool) $conv->fresh()->needs_human);

        // Cancel -> handover WITH the acknowledgement, flag stays up.
        $cancel = $this->send($brain, $conv, 'order cancel korun');
        $this->assertNotNull($cancel);
        $this->assertStringContainsString('বাতিলের অনুরোধ পেয়েছি', $cancel->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the handover flag survives the acknowledgement');

        // Payment proof -> handover WITH the acknowledgement.
        $proof = $this->send($brain, $conv, 'taka pathiyechi, transaction id dilam');
        $this->assertNotNull($proof);
        $this->assertStringContainsString('পেমেন্টের তথ্য পেয়েছি', $proof->body);
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }

    public function test_talker_receives_the_context_package(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);

        $talker = new class(app(SettingsService::class), app(\App\Services\AiGuardService::class)) extends AiTalkerService
        {
            /** @var array<int, array> */
            public array $calls = [];

            public function phrase(array $ctx): ?array
            {
                $this->calls[] = $ctx;

                return null; // template goes out; only the input matters here
            }
        };
        app()->instance(AiTalkerService::class, $talker);
        $brain = app(RuleBrainService::class);

        $first = $this->send($brain, $conv, 'CRUD-101 Panjabi-এর দাম কত?');
        $this->send($brain, $conv, 'দামটা একটু কম হবে?');

        $this->assertNotEmpty($talker->calls);
        $ctx = $talker->calls[array_key_last($talker->calls)];
        $this->assertSame($first->body, $ctx['previous_reply'] ?? null, 'the talker sees the reply it must not repeat');
        $this->assertNotEmpty($ctx['history'] ?? []);
        $this->assertSame('CRUD-101 Panjabi', $ctx['state']['product'] ?? null);
        $this->assertArrayHasKey('neg_step', $ctx['state'] ?? []);
    }

    public function test_migration_000062_backfills_the_expanded_library(): void
    {
        // A pre-existing business with NO rules (observer bypassed).
        $bizId = (int) DB::table('businesses')->insertGetId([
            'name' => 'Legacy Shop', 'public_id' => 'legacy-brain-shop', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, AiRule::where('business_id', $bizId)->count());

        $migration = require database_path('migrations/2026_11_25_000062_backfill_expanded_brain_rule_library.php');
        $migration->up();

        $this->assertSame(count(AiRule::defaults()), AiRule::where('business_id', $bizId)->count());
        $this->assertSame(47, count(AiRule::defaults()), 'the installed Master Library size');
    }

    public function test_staff_edits_learn_with_context_and_auto_create_a_rule_at_three_repeats(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);
        $customerText = 'panjabi tar material ki cotton?';
        $edit = 'জি, এটা সুতি কাপড়ের তৈরি। গরমে পরতে আরাম পাবেন।';

        // Three separate conversations, the same customer question,
        // the same human correction each time.
        $firstProposal = null;
        for ($i = 0; $i < 3; $i++) {
            $c = $i === 0 ? $conv : Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Phase2 Customer', 'status' => 'open', 'product_id' => $product->id]);
            $draft = $this->send($brain, $c, $customerText);
            $this->assertNotNull($draft);
            $this->assertSame('draft', $draft->status, 'the details rule drafts its reply');
            $brain->approveDraft($draft, $edit, null);
            $firstProposal ??= \App\Models\AiRuleProposal::where('business_id', $biz->id)->latest('id')->first();
        }

        // The proposal carries the context package and both texts.
        $data = $firstProposal->rule_data;
        $this->assertSame($customerText, $data['customer_message'] ?? null);
        $this->assertArrayHasKey('history', $data['context'] ?? []);
        $this->assertArrayHasKey('state', $data['context'] ?? []);
        $this->assertNotEmpty($data['ai_body'] ?? null);
        $this->assertSame($edit, $data['edited_body'] ?? null);
        $this->assertNotEmpty($data['pattern_key'] ?? null);
        $this->assertFalse($data['note_only'] ?? true, 'a real correction is not a note');

        // At three repeats the shop has its own learned rule.
        $learned = AiRule::where('business_id', $biz->id)->where('name', 'like', 'Learned:%')->get();
        $this->assertCount(1, $learned);
        $this->assertTrue((bool) $learned[0]->active);
        $this->assertSame($edit, $learned[0]->reply_template);
        $this->assertTrue(\App\Models\AiActivity::where('business_id', $biz->id)->where('action', 'rule_learned')->exists());

        // A fourth identical correction creates no duplicate.
        $c4 = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Phase2 Customer', 'status' => 'open', 'product_id' => $product->id]);
        $draft4 = $this->send($brain, $c4, $customerText);
        $brain->approveDraft($draft4, $edit, null);
        $this->assertCount(1, AiRule::where('business_id', $biz->id)->where('name', 'like', 'Learned:%')->get());

        // And the learned rule now answers that exact case itself.
        $c5 = Conversation::create(['business_id' => $biz->id, 'channel' => 'facebook', 'customer_name' => 'Phase2 Customer', 'status' => 'open', 'product_id' => $product->id]);
        $reply = $this->send($brain, $c5, $customerText);
        $this->assertNotNull($reply);
        $this->assertSame($learned[0]->id, $reply->rule?->id);
        $this->assertStringContainsString('সুতি কাপড়ের তৈরি', $reply->body);
    }

    public function test_a_whitespace_or_case_only_edit_stays_a_note_and_never_a_rule(): void
    {
        [$biz, $product, $conv] = $this->shopWithProduct(1300);
        $brain = app(RuleBrainService::class);

        $draft = ChatMessage::create([
            'conversation_id' => $conv->id, 'business_id' => $biz->id, 'direction' => 'out', 'sender' => 'ai',
            'body' => 'Apnar product ti available ache', 'status' => 'draft', 'meta' => ['kind' => 'reply'],
        ]);
        $brain->approveDraft($draft, 'apnar product ti available ache', null);

        $proposal = \App\Models\AiRuleProposal::where('business_id', $biz->id)->latest('id')->first();
        $this->assertNotNull($proposal);
        $this->assertTrue($proposal->rule_data['note_only'] ?? false);
        $this->assertNull($proposal->rule_data['pattern_key'] ?? null);
        $this->assertArrayNotHasKey('new_rule', $proposal->rule_data ?? []);
    }

    public function test_talker_failure_returns_null_never_switches_models_and_is_logged_visibly(): void
    {
        app(SettingsService::class)->set('ai.talker', [
            'enabled' => true,
            'normal_provider' => 'gemini', 'normal_model' => 'gemini-test',
            'complex_provider' => 'claude', 'complex_model' => 'claude-test',
            'gemini_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('gk'),
            'claude_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('ck'),
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response('rate limited', 429),
            'api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response(['content' => [['type' => 'text', 'text' => 'দাম মাত্র ⟦price⟧ টাকা।']]], 200),
        ]);

        $talker = app(AiTalkerService::class);
        $out = $talker->phrase(['business_id' => 0, 'incoming' => 'dam koto', 'intent' => 'price', 'draft' => 'দাম ⟦price⟧ টাকা।']);

        // NO automatic model switching (owner correction): the
        // template fallback happens, Claude is never called, and the
        // failure is on the AI Activity record.
        $this->assertNull($out);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $log = \App\Models\AiActivity::where('action', 'talker_failed')->latest('id')->first();
        $this->assertNotNull($log, 'a talker failure must be visible in AI Activity');
        $this->assertStringContainsString('gemini', (string) $log->detail);
        $this->assertStringContainsString('gemini-test', (string) $log->detail);
        $this->assertStringContainsString('rate_limited', (string) $log->detail);
    }

    public function test_talker_empty_reply_returns_null_and_is_logged(): void
    {
        app(SettingsService::class)->set('ai.talker', [
            'enabled' => true,
            'normal_provider' => 'gemini', 'normal_model' => 'gemini-test',
            'complex_provider' => 'claude', 'complex_model' => 'claude-test',
            'gemini_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('gk'),
            'claude_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('ck'),
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['candidates' => [['content' => ['parts' => [['text' => '']]]]]], 200),
            'api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response('down', 500),
        ]);

        $talker = app(AiTalkerService::class);
        $this->assertNull($talker->phrase(['business_id' => 0, 'incoming' => 'dam koto', 'intent' => 'price', 'draft' => 'দাম ⟦price⟧ টাকা।']));
        $log = \App\Models\AiActivity::where('action', 'talker_failed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('empty', (string) $log->detail);
    }

    public function test_talker_success_is_unchanged_and_logs_no_failure(): void
    {
        app(SettingsService::class)->set('ai.talker', [
            'enabled' => true,
            'normal_provider' => 'gemini', 'normal_model' => 'gemini-test',
            'complex_provider' => 'claude', 'complex_model' => 'claude-test',
            'gemini_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('gk'),
            'claude_key_enc' => \Illuminate\Support\Facades\Crypt::encryptString('ck'),
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['candidates' => [['content' => ['parts' => [['text' => 'দাম মাত্র ⟦price⟧ টাকা।']]]]]], 200),
            'api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response('down', 500),
        ]);

        $talker = app(AiTalkerService::class);
        $out = $talker->phrase(['business_id' => 0, 'incoming' => 'dam koto', 'intent' => 'price', 'draft' => 'দাম ⟦price⟧ টাকা।']);
        $this->assertNotNull($out);
        $this->assertSame('gemini', $out['provider']);
        $this->assertArrayNotHasKey('fallback', $out);
        $this->assertSame(0, \App\Models\AiActivity::where('action', 'talker_failed')->count());
    }
}

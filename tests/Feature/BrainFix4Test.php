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
 * Brain fix batch 4 (2026-11-25, wording-level, from the batch-3 LIVE
 * re-test on the CRUD Test Shop, 16:41-16:54 — the full cycle passed
 * end to end, two wording deviations remained):
 *
 *  DEVIATION 1 — the collect question asked for the mobile number
 *  ONLY ("অর্ডারের জন্য আপনার মোবাইল নম্বরটা দিন - যে নম্বরে কুরিয়ার
 *  কল করবে।"), because collection was stepwise (phone step, then
 *  address step) and orderTaking asked for $missing[0] alone. The
 *  owner's spec: when details are missing, ask for them TOGETHER —
 *  name, phone number and delivery address in one ask; after a
 *  partial answer, ask only for the still-missing pieces, naming
 *  them. The question is now composed from the flow's outstanding
 *  pieces (name counts as outstanding until the customer states one
 *  in this flow; the summary gate itself stays phone+address).
 *
 *  DEVIATION 2 — the bargain offer named NO product ("বিশেষ মূল্যে
 *  808 টাকায় রাখা যাবে, অর্ডারটি সম্পন্ন করব?"). Batch 3 made
 *  {product} a protected ⟦product⟧ slot, but the bargain draft text
 *  itself contained no product reference. Both bargain sentences
 *  (the ladder offer and the floor message) now carry ⟦product⟧,
 *  so the rendered reply names the actual product — still product
 *  price only, never a delivery charge.
 */
class BrainFix4Test extends TestCase
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
     * The batch-3 live thread's shape: two products, the conversation
     * focused on the FIRST at its floor, and a shipped order's contact
     * details still on the record.
     *
     * @return array{Business, Product, Product, Conversation}
     */
    private function staleShop(): array
    {
        $biz = Business::create(['name' => 'Wording Shop', 'public_id' => 'wording-shop-' . uniqid(), 'status' => 'approved']);
        $p1 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $p2 = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'পাঞ্জাবি পাঞ্জ',
            'customer_phone' => '01700000001',
            'customer_address' => 'মিরপুর 10, ঢাকা',
            'product_id' => $p1->id,
            'neg_step' => 3, 'neg_offer' => 1105,
            'state_data' => ['floor_repeats' => 1],
        ]);

        return [$biz, $p1, $p2, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /**
     * (a) Acceptance with no details stated in this flow: the ONE
     * collect reply asks for name AND phone AND address together —
     * and carries none of the stale record's data.
     */
    public function test_acceptance_with_no_details_asks_for_name_phone_and_address_together(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'CRUD-102 er dam koto?');
        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertSame(808.0, (float) ($offer->meta['offer'] ?? 0));

        $r = $this->send($brain, $conv, 'thik ache, CRUD-102 ta 808 takay nibo');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('808 টাকায় ফাইনাল', $r->body);
        $this->assertStringContainsString('নাম', $r->body, 'the one ask names the name');
        $this->assertStringContainsString('নম্বর', $r->body, 'the one ask names the mobile number');
        $this->assertStringContainsString('ঠিকানা', $r->body, 'the one ask names the delivery address');
        $this->assertStringNotContainsString('01700000001', $r->body, 'no stale phone in the ask');
        $this->assertStringNotContainsString('মিরপুর', $r->body, 'no stale address in the ask');
    }

    /**
     * (b) A bargain offer reply names the product, exactly — and
     * stays pure: the product price only, no delivery charge.
     */
    public function test_bargain_offer_names_the_product(): void
    {
        [$biz, $p1, $p2, $conv] = $this->staleShop();
        $brain = app(RuleBrainService::class);

        $this->send($brain, $conv, 'CRUD-102 er dam koto?');
        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r);
        $this->assertSame(808.0, (float) ($r->meta['offer'] ?? 0));
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body, 'the offer names the product verbatim');
        $this->assertStringContainsString('808', $r->body);
        $this->assertStringNotContainsString('ডেলিভারি চার্জ', $r->body, 'bargain purity: no delivery charge in an offer');
    }

    /**
     * (c) The floor message names the product too: a conversation
     * already standing at CRUD-102's floor (723) gets the floor
     * repeated once, with the product in the sentence.
     */
    public function test_floor_message_names_the_product(): void
    {
        $biz = Business::create(['name' => 'Floor Shop', 'public_id' => 'floor-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Floor Customer',
            'product_id' => $p->id,
            'neg_step' => 3, 'neg_offer' => 723,
            'state_data' => ['floor_repeats' => 0],
        ]);
        $brain = app(RuleBrainService::class);

        $r = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertNotNull($r);
        $this->assertSame('floor_repeat', $r->meta['quote'] ?? null);
        $this->assertStringContainsString('CRUD Test Panjabi 2', $r->body, 'the floor message names the product verbatim');
        $this->assertStringContainsString('723', $r->body);
        $this->assertStringContainsString('শেষ দাম', $r->body);
    }

    /**
     * (d) A partial answer — the phone number only — is followed by
     * an ask for ONLY the pieces still missing (name and address);
     * the number is not asked for again.
     */
    public function test_partial_answer_asks_only_for_the_missing_name_and_address(): void
    {
        $biz = Business::create(['name' => 'Partial Shop', 'public_id' => 'partial-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi 2', 'sku' => 'CRUD-102', 'price' => 850, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Fresh Customer',
            'product_id' => $p->id,
        ]);
        $brain = app(RuleBrainService::class);

        $ask = $this->send($brain, $conv, 'CRUD-102 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertStringContainsString('নাম', $ask->body);
        $this->assertStringContainsString('নম্বর', $ask->body);
        $this->assertStringContainsString('ঠিকানা', $ask->body);

        $r = $this->send($brain, $conv, '01612345678');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null);
        $this->assertStringContainsString('নাম', $r->body, 'the follow-up still asks for the name');
        $this->assertStringContainsString('ঠিকানা', $r->body, 'the follow-up still asks for the address');
        $this->assertStringNotContainsString('নম্বর', $r->body, 'the number is not asked for twice');
        $this->assertStringNotContainsString('ফোন', $r->body, 'the number is not asked for twice');
    }
}

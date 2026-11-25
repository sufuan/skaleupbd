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
 * Brain fix batch 12 (2026-11-25, Step 4 of the external review —
 * findings #8, #9, #19, #21, #20): intent detection order + vocabulary.
 *
 * Verified against the code before fixing: "ওজন কত?" and its kin
 * really did get price answers (the price word 'কত' fires inside
 * detectIntent before matchRule's keyword competition can reach the
 * catalog's attribute rules); "discount code ache?" really did open
 * the bargain ladder (bare 'discount' was a bargain word); the new
 * price/order vocabulary really was missing ("dam ki?", "এটা নিব"
 * matched nothing at all); "কবে পাব?" really was judged a status
 * question even with no order in existence; and the status branch
 * really only searched by phone while telling the customer to quote
 * the order number. Cases the review suspected but the code already
 * handled ("কতদিনে পাব?" already reached delivery_time, Bengali-script
 * "ডিসকাউন্ট কোড আছে?" already reached the Offer rule) are pinned
 * here as regressions, not re-fixed.
 */
class BrainFix12Test extends TestCase
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

    /** @return array{Business, Product} */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Intent Shop', 'public_id' => 'intent-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'Cotton Panjabi', 'sku' => 'PJ-1', 'price' => 1300, 'stock_qty' => 10]);

        return [$biz, $p];
    }

    private function conv(Business $biz, array $extra = []): Conversation
    {
        return Conversation::create(array_merge([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Intent Customer',
        ], $extra));
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    /**
     * (1) #8 — attribute questions reach the catalog's attribute
     * rules instead of the price answer, while a genuine price
     * question that merely mentions a size stays a price question.
     */
    public function test_attribute_questions_beat_price_but_size_plus_price_stays_price(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        foreach (['ওজন কত?', 'সাইজ কত ইঞ্চি?', 'মাপ কত?'] as $q) {
            $rule = $brain->matchRule($biz->id, $q, 'facebook', $conv);
            $this->assertNotNull($rule, $q);
            $this->assertSame('Product details question', $rule->name, $q);
            $this->assertNotSame('price', $brain->detectIntent($q, $conv), $q);
        }

        $rule = $brain->matchRule($biz->id, 'কত সাইজ নিব?', 'facebook', $conv);
        $this->assertNotNull($rule);
        $this->assertSame('Size advice', $rule->name);

        // Boundary: the price is named — a price question about a variant.
        $this->assertSame('price', $brain->detectIntent('সাইজ L এর দাম কত?', $conv));
        $rule = $brain->matchRule($biz->id, 'সাইজ L এর দাম কত?', 'facebook', $conv);
        $this->assertNotNull($rule);
        $this->assertSame('price', $rule->match_text);

        // Already handled before this batch — pinned, not re-fixed.
        $this->assertSame('Authenticity or quality guarantee', $brain->matchRule($biz->id, 'ওয়ারেন্টি আছে?', 'facebook', $conv)?->name);
    }

    /**
     * (2) #9 — coupon/offer vocabulary routes to the Offer rule and
     * never opens the ladder; a genuine "discount hobe?" price-cut
     * ask is still a bargain.
     */
    public function test_discount_code_is_offer_not_bargain(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        foreach (['discount code ache?', 'কোনো কুপন আছে?', 'promo code ache?'] as $q) {
            $this->assertNotSame('bargain', $brain->detectIntent($q, $conv), $q);
            $rule = $brain->matchRule($biz->id, $q, 'facebook', $conv);
            $this->assertNotNull($rule, $q);
            $this->assertSame('Offer or coupon enquiry', $rule->name, $q);
        }

        // Already handled before this batch — pinned, not re-fixed.
        $this->assertSame('Offer or coupon enquiry', $brain->matchRule($biz->id, 'ডিসকাউন্ট কোড আছে?', 'facebook', $conv)?->name);
        $this->assertSame('Offer or coupon enquiry', $brain->matchRule($biz->id, 'অফার আছে?', 'facebook', $conv)?->name);

        // Boundary: asking for a cut, no code/offer word — still bargain.
        $this->assertSame('bargain', $brain->detectIntent('discount hobe?', $conv));
        $this->assertSame('bargain', $brain->detectIntent('ডিসকাউন্ট হবে?', $conv));

        // End to end: the discount-code question is answered by the
        // Offer rule's own text and no ladder state moves — before
        // this batch the same words opened the bargain ladder.
        $offerConv = $this->conv($biz);
        $r = $this->send($brain, $offerConv, 'discount code ache?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('অফার বা কুপন', $r->body);
        $this->assertSame(0, (int) $offerConv->fresh()->neg_step);
        $this->assertNull($offerConv->fresh()->neg_offer);
    }

    /**
     * (3) #19 — the new price vocabulary is understood, with the
     * script-aware guards: 'দর' inside 'দরকার'/'দরজা' and 'rate'
     * inside other English words never fire.
     */
    public function test_new_price_vocabulary_with_guards(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        foreach (['dam ki?', 'dam bolen', 'rate koto?', 'রেট কত?', 'দর কত?', 'কী দর?', 'মূল্য কত?', 'koto taka?', 'কত টাকা?'] as $q) {
            $this->assertSame('price', $brain->detectIntent($q, $conv), $q);
        }

        foreach (['দরকার নাই', 'দরজা খুলুন', 'separate', 'generate', 'আমার দরকার আছে'] as $q) {
            $this->assertNotSame('price', $brain->detectIntent($q, $conv), $q);
        }
    }

    /**
     * (4) #19 — the new order vocabulary starts the order flow,
     * while the guarded phrases keep their own cases: a decline is
     * not an order, "পরে নিব" still defers, "আজকেই লাগবে" is still
     * the urgent-delivery case, and "দিলে নিব" is still a bargain.
     */
    public function test_new_order_vocabulary_with_guards(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        $conv = $this->conv($biz);

        foreach (['এটা নিব', 'nibo', 'lagbe', 'লাগবে', 'কিনব', 'kinbo'] as $q) {
            $this->assertSame('order_intent', $brain->detectIntent($q, $conv), $q);
        }

        $this->assertNotSame('order_intent', $brain->detectIntent('লাগবে না', $conv));
        $this->assertNotSame('order_intent', $brain->detectIntent('নিব না', $conv));
        // "কত লাগবে" asks the cost, not for the product: the
        // delivery-charge question keeps its own intent.
        $this->assertSame('delivery', $brain->detectIntent('ঢাকার বাইরে পাঠাতে কত লাগবে আপু?', $conv));
        $this->assertNotSame('order_intent', $brain->detectIntent('কত লাগবে?', $conv));
        $this->assertSame('Will decide later', $brain->matchRule($biz->id, 'পরে নিব', 'facebook', $conv)?->name);
        $this->assertSame('Urgent delivery', $brain->matchRule($biz->id, 'আজকেই লাগবে', 'facebook', $conv)?->name);
        $this->assertSame('bargain', $brain->detectIntent('দিলে নিব', $conv));

        // End to end: "এটা নিব" on the focused product starts order
        // taking (the details question), it does not fall silent.
        $this->send($brain, $conv, 'Cotton Panjabi dam koto?');
        $r = $this->send($brain, $conv, 'এটা নিব');
        $this->assertNotNull($r);
        $this->assertStringContainsString('নাম', $r->body);

        // But the weak verb never turns a request for an item the
        // shop does not sell into an order (batch 1's doctrine).
        $this->assertNotSame('order_intent', $brain->detectIntent('লাল জামা XL লাগবে', $conv));
    }

    /**
     * (5) #21 — "কবে পাব?" before any order is the delivery-time
     * question; with an order on the conversation it stays the
     * status question it always was.
     */
    public function test_preorder_when_will_i_get_it_is_delivery_time(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);

        $fresh = $this->conv($biz);
        $this->assertSame('delivery_time', $brain->detectIntent('কবে পাব?', $fresh));
        $this->assertSame('delivery_time', $brain->detectIntent('কবে পৌঁছাবে?', $fresh));
        // Already handled before this batch — pinned, not re-fixed.
        $this->assertSame('delivery_time', $brain->detectIntent('কতদিনে পাব?', $fresh));

        $r = $this->send($brain, $fresh, 'কবে পাব?');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString('কোনো অর্ডার খুঁজে পাইনি', $r->body);

        $withOrder = $this->conv($biz, ['customer_phone' => '01711111111']);
        Order::create(['business_id' => $biz->id, 'invoice_no' => 'OB299', 'customer_name' => 'Intent Customer', 'customer_phone' => '01711111111', 'status' => 'Processing', 'grand_total' => 1360]);
        $this->assertSame('order_status', $brain->detectIntent('কবে পাব?', $withOrder->fresh()));
        $this->assertSame('order_status', $brain->detectIntent('অর্ডার কোথায়?', $withOrder->fresh()));
    }

    /**
     * (6) #20 — a quoted invoice number is looked up in THIS shop:
     * found answers its status, an unknown number gets the honest
     * not-found reply, and another shop's number is never revealed.
     */
    public function test_order_lookup_by_invoice_number(): void
    {
        [$biz] = $this->shop();
        $brain = app(RuleBrainService::class);
        Order::create(['business_id' => $biz->id, 'invoice_no' => 'OB299', 'customer_name' => 'Someone Else', 'customer_phone' => '01799999999', 'status' => 'Shipped', 'grand_total' => 1360]);

        // The asker is NOT the order's phone — the quoted number is
        // the credential, and the answer carries status only.
        $asker = $this->conv($biz, ['customer_phone' => '01722222222']);
        $r = $this->send($brain, $asker, 'OB299 কোথায় আছে?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('OB299', $r->body);
        $this->assertStringContainsString('Shipped', $r->body);
        $this->assertStringNotContainsString('Someone Else', $r->body);

        // Spacing / dash / Bengali-digit spellings of the same number.
        $r = $this->send($brain, $asker, 'OB-299 কোথায়?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Shipped', $r->body);

        // Unknown number: honest not-found, and asks for the phone.
        $r = $this->send($brain, $asker, 'OB777 কোথায় আছে?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('খুঁজে পাইনি', $r->body);
        $this->assertStringContainsString('OB777', $r->body);

        // Another shop's order number is invisible from this shop.
        $biz2 = Business::create(['name' => 'Other Shop', 'public_id' => 'other-shop-' . uniqid(), 'status' => 'approved']);
        Order::create(['business_id' => $biz2->id, 'invoice_no' => 'OB555', 'customer_name' => 'Foreign', 'customer_phone' => '01733333333', 'status' => 'Delivered', 'grand_total' => 500]);
        $r = $this->send($brain, $asker, 'OB555 কোথায় আছে?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('খুঁজে পাইনি', $r->body);
        $this->assertStringNotContainsString('Delivered', $r->body);

        // No number quoted: the phone lookup behaves exactly as before.
        $owner = $this->conv($biz, ['customer_phone' => '01799999999']);
        $r = $this->send($brain, $owner, 'আমার অর্ডারটা কোথায়?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('OB299', $r->body);
        $this->assertStringContainsString('Shipped', $r->body);
    }
}

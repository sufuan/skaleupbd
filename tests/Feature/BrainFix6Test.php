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
 * Brain fix batch 6 (2026-11-25, from the batch-5 LIVE check): a
 * customer mid-collection sent every detail in ONE message —
 * "Amar nam Phase2 Customer, phone 01612345678, address House 10,
 * Road 5, Uttara, Dhaka" — and the summary rendered the address as
 * the WHOLE RAW MESSAGE with the phone digits blanked ("Amar nam
 * Phase2 Customer, phone , address House 10, Road 5, Uttara,
 * Dhaka"). captureContactFromText had already stored the clean
 * labelled address; handleCollect then overwrote it with the raw
 * leftover text, because its address-step capture never ran the
 * labelled extraction and never stripped the other fields' segments.
 * Had the customer confirmed, the order would have carried that
 * garbage as its delivery address.
 *
 * The rule: when a message carries labelled fields, each field's
 * value is ONLY its own segment — the text after its label, up to
 * the next label — never the whole message, never another field's
 * words, never the label words themselves.
 */
class BrainFix6Test extends TestCase
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
     * @return array{Business, Product, Conversation}
     */
    private function shop(): array
    {
        $biz = Business::create(['name' => 'Segment Shop', 'public_id' => 'segment-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => 'facebook', 'status' => 'open',
            'customer_name' => 'Segment Customer',
            'product_id' => $p->id,
        ]);

        return [$biz, $p, $conv];
    }

    private function send(RuleBrainService $brain, Conversation $conv, string $text): ?ChatMessage
    {
        return $brain->handleIncoming($conv->fresh(), $text);
    }

    private function startCollecting(RuleBrainService $brain, Conversation $conv): void
    {
        $ask = $this->send($brain, $conv, 'CRUD-101 order korte chai');
        $this->assertNotNull($ask);
        $this->assertSame('collect_question', $ask->meta['kind'] ?? null);
        $this->assertSame('collecting', $conv->fresh()->state);
    }

    /**
     * (a) The exact live message, replayed mid-collection after a
     * bargain (as it happened live): name, phone and address each
     * land as their own segment, the summary shows the clean address,
     * and the bargained price is untouched.
     */
    public function test_live_all_in_one_message_stores_each_field_as_its_own_segment(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        // Live, the phone was already on the record when the details
        // message arrived, so collection sat on the ADDRESS step —
        // the step whose capture overwrote the clean labelled value.
        $phoneFirst = $this->send($brain, $conv, '01612345678');
        $this->assertSame('collect_question', $phoneFirst->meta['kind'] ?? null);

        $offer = $this->send($brain, $conv, 'ektu kom hobe na?');
        $this->assertSame('collect_side_answer', $offer->meta['kind'] ?? null);

        $summary = $this->send($brain, $conv, 'Amar nam Phase2 Customer, phone 01612345678, address House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);

        $fresh = $conv->fresh();
        $this->assertSame('Phase2 Customer', $fresh->customer_name);
        $this->assertSame('01612345678', $fresh->customer_phone);
        $this->assertSame('House 10, Road 5, Uttara, Dhaka', $fresh->customer_address, 'the address is its own segment only');

        $this->assertStringContainsString('ঠিকানা: House 10, Road 5, Uttara, Dhaka', $summary->body);
        $this->assertStringNotContainsString('Amar nam', $summary->body, 'no name segment inside the address');
        $this->assertStringNotContainsString('phone ,', $summary->body, 'no phone label debris inside the address');
        $this->assertSame(1235.0, (float) ($summary->meta['unit_price'] ?? 0), 'the bargained price still applies');
        $this->assertSame(1295.0, (float) ($summary->meta['total'] ?? 0));
    }

    /**
     * (b) Label order does not matter: address first, phone and name
     * after it — the address segment still ends where the next label
     * begins, and the trailing fields are read as their own.
     */
    public function test_address_first_phone_last_keeps_the_segments_apart(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        $summary = $this->send($brain, $conv, 'address House 10, Road 5, Uttara, Dhaka, phone 01612345678, amar nam Phase2 Customer');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);

        $fresh = $conv->fresh();
        $this->assertSame('Phase2 Customer', $fresh->customer_name);
        $this->assertSame('01612345678', $fresh->customer_phone);
        $this->assertSame('House 10, Road 5, Uttara, Dhaka', $fresh->customer_address);
        $this->assertStringContainsString('ঠিকানা: House 10, Road 5, Uttara, Dhaka', $summary->body);
    }

    /**
     * (c) Bangla labels, one message: নাম / ফোন / ঠিকানা each read
     * as their own segment (digits land digit-normalised, as the
     * capture has always stored them).
     */
    public function test_bangla_labelled_message_keeps_the_segments_apart(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        $summary = $this->send($brain, $conv, 'আমার নাম রফিক উদ্দিন, ফোন 01612345678, ঠিকানা: বাসা ১০, রোড ৫, উত্তরা, ঢাকা');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);

        $fresh = $conv->fresh();
        $this->assertSame('রফিক উদ্দিন', $fresh->customer_name);
        $this->assertSame('01612345678', $fresh->customer_phone);
        $this->assertSame('বাসা 10, রোড 5, উত্তরা, ঢাকা', $fresh->customer_address);
        $this->assertStringContainsString('ঠিকানা: বাসা 10, রোড 5, উত্তরা, ঢাকা', $summary->body);
    }

    /**
     * (d) The paths that already worked stay working: a labelled
     * address on its own at the address step, and an unlabelled
     * address-only answer at the address step.
     */
    public function test_labelled_address_alone_and_unlabelled_address_still_work(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);
        $this->send($brain, $conv, '01612345678');

        $summary = $this->send($brain, $conv, 'address: House 1, Road 2, Dhanmondi, Dhaka');
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('House 1, Road 2, Dhanmondi, Dhaka', $conv->fresh()->customer_address);

        [$biz2, $p2, $conv2] = $this->shop();
        $this->startCollecting($brain, $conv2);
        $this->send($brain, $conv2, '01612345678');

        $summary2 = $this->send($brain, $conv2, 'House 10, Road 5, Uttara, Dhaka');
        $this->assertNotNull($summary2);
        $this->assertSame('order_summary', $summary2->meta['kind'] ?? null);
        $this->assertSame('House 10, Road 5, Uttara, Dhaka', $conv2->fresh()->customer_address);
    }

    /**
     * (e) A name+phone message with NO address in it must not leave
     * label debris behind as a stored address: the flow keeps
     * collecting and asks for the address.
     */
    public function test_name_and_phone_without_an_address_store_no_address(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $brain = app(RuleBrainService::class);
        $this->startCollecting($brain, $conv);

        $r = $this->send($brain, $conv, 'Amar nam Karim, phone 01612345678');
        $this->assertNotNull($r);
        $this->assertSame('collect_question', $r->meta['kind'] ?? null, 'still collecting: the address is asked for');

        $fresh = $conv->fresh();
        $this->assertSame('Karim', $fresh->customer_name);
        $this->assertSame('01612345678', $fresh->customer_phone);
        $this->assertEmpty($fresh->customer_address, 'no label debris is stored as an address');
        $this->assertSame('collecting', $fresh->state);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaMessageJob;
use App\Models\AiActivity;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\AiClassifierService;
use App\Services\ImageMatchService;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photo no-match fallback (owner decision 2026-11-25): when a
 * customer's photo cannot be matched to any product, the old media
 * path flagged the conversation for a human and sent the customer
 * NOTHING — silence. Now the customer gets one polite line asking
 * what they are looking for (through the normal autonomy pipeline:
 * sent on auto channels, a draft on draft channels), the human flag
 * stays up, and a burst of unmatched photos earns exactly one
 * fallback, not one per photo.
 */
class PhotoNoMatchFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const FALLBACK = 'দুঃখিত, ছবিটা ঠিক চিনতে পারিনি। আপনি কোন product-টা খুঁজছেন? নামটা বললে দামসহ জানিয়ে দিচ্ছি।';

    protected function setUp(): void
    {
        parent::setUp();

        $classifier = new class extends AiClassifierService
        {
            public function __construct()
            {
            }

            public function classify(int $businessId, string $text): ?array
            {
                return null;
            }
        };
        app()->instance(AiClassifierService::class, $classifier);
        app(SettingsService::class)->set('ai.autonomy', [
            'facebook' => 'full_auto', 'whatsapp' => 'full_auto',
            'instagram' => 'draft', 'comment' => 'draft', 'panic' => false,
        ]);
    }

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

    /**
     * @return array{Business, Product, Conversation}
     */
    private function shop(string $channel = 'facebook'): array
    {
        $biz = Business::create(['name' => 'Photo Shop', 'public_id' => 'photo-shop-' . uniqid(), 'status' => 'approved']);
        $p = Product::create(['business_id' => $biz->id, 'name' => 'CRUD Test Panjabi', 'sku' => 'CRUD-101', 'price' => 1300, 'stock_qty' => 10]);
        $conv = Conversation::create([
            'business_id' => $biz->id, 'channel' => $channel, 'status' => 'open',
            'customer_name' => 'Photo Customer',
        ]);

        return [$biz, $p, $conv];
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
    private function fallbacks(Conversation $conv)
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')->where('sender', 'ai')->get()
            ->filter(fn ($m) => ($m->meta['kind'] ?? null) === 'image_no_match')
            ->values();
    }

    /**
     * (1) An unmatched photo: the customer gets the fallback line
     * (sent, on a full-auto channel), the conversation is flagged
     * for a human, and the no-match activity is logged.
     */
    public function test_unmatched_photo_sends_the_fallback_and_flags_a_human(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv));

        $fallbacks = $this->fallbacks($conv);
        $this->assertCount(1, $fallbacks, 'exactly one fallback reply is created');
        $this->assertSame(self::FALLBACK, $fallbacks[0]->body, 'the fallback wording is the owner-approved line');
        $this->assertSame('sent', $fallbacks[0]->status, 'a full-auto channel sends it');
        $this->assertTrue((bool) $conv->fresh()->needs_human, 'the human flag stays up');
        $this->assertTrue(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'image_no_match')->exists(),
            'the no-match activity is logged'
        );
    }

    /**
     * (2) A photo whose caption names the product is matched by the
     * caption flow — no fallback, no human flag.
     */
    public function test_caption_matched_photo_sends_no_fallback(): void
    {
        [$biz, $p, $conv] = $this->shop();
        // Even a "low" vision verdict must never be reached: the
        // caption (the product SKU) matches first, at high confidence.
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv, 'CRUD-101'));

        $this->assertCount(0, $this->fallbacks($conv), 'a matched photo earns no fallback');
        $this->assertFalse((bool) $conv->fresh()->needs_human, 'no human flag for a matched photo');
        $this->assertFalse(
            AiActivity::where('conversation_id', $conv->id)->where('action', 'image_no_match')->exists(),
            'no no-match activity for a matched photo'
        );
    }

    /**
     * (3) A burst of three unmatched photos — each stored AFTER the
     * previous photo's job already ran (the ordering the job's own
     * answered-check misses) — still earns exactly ONE fallback,
     * while every photo is flagged and logged.
     */
    public function test_a_burst_of_unmatched_photos_gets_exactly_one_fallback(): void
    {
        [$biz, $p, $conv] = $this->shop();
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        foreach (range(1, 3) as $i) {
            $this->runJob($this->photo($conv));
        }

        $this->assertCount(1, $this->fallbacks($conv), 'one fallback per burst, not one per photo');
        $this->assertTrue((bool) $conv->fresh()->needs_human);
        $this->assertSame(
            3,
            AiActivity::where('conversation_id', $conv->id)->where('action', 'image_no_match')->count(),
            'every unmatched photo is still logged'
        );
    }

    /**
     * (4) On a draft-mode channel the fallback waits as a draft for
     * a human to send — it is not sent on its own.
     */
    public function test_draft_mode_channel_keeps_the_fallback_as_a_draft(): void
    {
        [$biz, $p, $conv] = $this->shop('instagram');
        $this->stubMatcher(['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product']);

        $this->runJob($this->photo($conv));

        $fallbacks = $this->fallbacks($conv);
        $this->assertCount(1, $fallbacks, 'the fallback is still written on a draft channel');
        $this->assertSame(self::FALLBACK, $fallbacks[0]->body);
        $this->assertSame('draft', $fallbacks[0]->status, 'draft mode: a human sends it, not the AI');
        $this->assertTrue((bool) $conv->fresh()->needs_human);
    }
}

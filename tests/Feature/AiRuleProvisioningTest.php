<?php

namespace Tests\Feature;

use App\Models\AiRule;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\Product;
use App\Models\User;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the live failure found 2026-11-25: real shops
 * had ZERO ai_rules rows (the defaults were only ever seeded for the
 * demo shop), so the Rule Brain never bargained and never took an order
 * on live — every message fell back to a generic price answer. These
 * tests deliberately exercise shops that do NOT come from the seeders.
 */
class AiRuleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** A business row written directly, bypassing Eloquent events: rule-less. */
    private function rawBusiness(string $publicId): int
    {
        return DB::table('businesses')->insertGetId([
            'name' => 'Raw Shop', 'public_id' => $publicId, 'status' => 'approved',
            'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_registration_creates_the_full_default_library(): void
    {
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();

        $response = $this->post(route('register.store'), [
            'name' => 'New Seller',
            'shop_name' => 'Fresh Rules Shop',
            'email' => 'fresh-rules@example.com',
            'mobile' => '01712345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'pack_id' => $pack->id,
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));

        $biz = Business::where('public_id', 'fresh-rules-shop')->firstOrFail();
        $rules = AiRule::where('business_id', $biz->id)->get();

        $this->assertCount(count(AiRule::defaults()), $rules);
        $this->assertEqualsCanonicalizing(
            collect(AiRule::defaults())->map(fn ($d) => ($d['trigger_type'] ?? 'intent') . '|' . $d['match_text'])->all(),
            $rules->map(fn (AiRule $r) => $r->trigger_type . '|' . $r->match_text)->all(),
        );
        $this->assertTrue($rules->every(fn (AiRule $r) => (bool) $r->active));
        $this->assertTrue($rules->contains('trigger_type', 'keyword'), 'the expanded library includes keyword rules');
        $byIntent = $rules->where('trigger_type', 'intent')->keyBy('match_text');
        $this->assertSame('answer', $byIntent['bargain']->action);
        $this->assertNull($byIntent['bargain']->reply_template);
        $this->assertSame('answer', $byIntent['order_intent']->action);
        $this->assertSame('hide', $byIntent['spam']->action);
        $this->assertSame('handover', $byIntent['angry']->action);
        $this->assertSame(
            collect(AiRule::defaults())->firstWhere('match_text', 'price')['reply_template'],
            $byIntent['price']->reply_template,
        );
    }

    public function test_ensure_defaults_is_idempotent_and_respects_seller_edits(): void
    {
        $biz = Business::create(['name' => 'Edit Shop', 'public_id' => 'edit-shop', 'status' => 'approved']);
        $this->assertSame(count(AiRule::defaults()), AiRule::where('business_id', $biz->id)->count());

        // The seller edits a template and deliberately switches a rule off.
        AiRule::where('business_id', $biz->id)->where('match_text', 'price')
            ->update(['reply_template' => 'SELLER CUSTOM TEMPLATE']);
        AiRule::where('business_id', $biz->id)->where('match_text', 'bargain')
            ->update(['active' => false]);

        AiRule::ensureDefaults($biz->id);
        AiRule::ensureDefaults($biz->id);

        $rules = AiRule::where('business_id', $biz->id)->get();
        $this->assertCount(count(AiRule::defaults()), $rules);
        $this->assertSame('SELLER CUSTOM TEMPLATE', $rules->firstWhere('match_text', 'price')->reply_template);
        $this->assertFalse((bool) $rules->firstWhere('match_text', 'bargain')->active);
    }

    public function test_backfill_migration_fills_a_pre_existing_rule_less_business(): void
    {
        $bizId = $this->rawBusiness('legacy-shop');
        $this->assertSame(0, AiRule::where('business_id', $bizId)->count());

        $migration = require database_path('migrations/2026_11_25_000061_backfill_default_ai_rules.php');
        $migration->up();

        $rules = AiRule::where('business_id', $bizId)->get();
        $this->assertCount(count(AiRule::defaults()), $rules);
        $this->assertTrue($rules->every(fn (AiRule $r) => (bool) $r->active));
    }

    public function test_match_rule_lazily_installs_defaults_for_a_rule_less_business(): void
    {
        $bizId = $this->rawBusiness('lazy-shop');
        $this->assertSame(0, AiRule::where('business_id', $bizId)->count());

        $rule = app(RuleBrainService::class)->matchRule($bizId, 'আসসালামু আলাইকুম');

        $this->assertSame(count(AiRule::defaults()), AiRule::where('business_id', $bizId)->count());
        $this->assertNotNull($rule);
        $this->assertSame('greeting', $rule->match_text);
    }

    /**
     * Brain-level proof on a shop that starts with NO rules at all (the
     * live state before this fix): bargaining quotes the ladder, a price
     * question gets the price rule's answer, and the order-intent
     * collecting flow ends in a real order with the point deducted.
     */
    public function test_brain_bargains_answers_and_takes_an_order_for_a_rule_less_shop(): void
    {
        $bizId = $this->rawBusiness('brain-shop');
        $biz = Business::findOrFail($bizId);
        $owner = User::create([
            'name' => 'Brain Owner', 'email' => 'brain-owner@example.com',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'business_id' => $bizId, 'is_owner' => true,
        ]);
        $product = Product::create([
            'business_id' => $bizId, 'name' => 'Test Panjabi', 'sku' => 'TP-1',
            'price' => 1250, 'cost' => 900, 'stock_qty' => 10,
        ]);

        // Points so the chat order can be charged, talker off (the brain
        // decides from templates alone, as in the existing engine checks).
        $pack = PointPack::where('is_active', true)->orderBy('price_bdt')->firstOrFail();
        app(TokenService::class)->purchase($bizId, $pack->id, null, 'test', 'brain test', $owner->id);
        $pointsBefore = (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
        $this->assertGreaterThan(0, $pointsBefore);

        $settings = app(SettingsService::class);
        $talker = (array) $settings->get('ai.talker', []);
        $talker['enabled'] = false;
        $settings->set('ai.talker', $talker);

        // The stage-2 classifier is the AI's one suggestion box: on live
        // it reads romanised Bangla ("ektu kom hobe na?") as bargaining
        // even though no bargain keyword matches it literally. Fake it
        // the same way here — a stub that suggests 'bargain' for that
        // message and nothing otherwise. Code still decides the rest:
        // the suggestion only lands if the shop HAS a bargain rule.
        $fakeClassifier = new class(app(\App\Services\AiTalkerService::class)) extends \App\Services\AiClassifierService
        {
            public ?int $productId = null;

            public function classify(int $businessId, string $text): ?array
            {
                return str_contains($text, 'kom hobe')
                    ? ['intent' => 'bargain', 'product_id' => $this->productId]
                    : null;
            }
        };
        $fakeClassifier->productId = $product->id;
        $this->app->instance(\App\Services\AiClassifierService::class, $fakeClassifier);

        $brain = app(RuleBrainService::class);
        $this->assertSame(0, AiRule::where('business_id', $bizId)->count());

        // --- Bargain: a ladder quote below list, never below the floor ---
        $conv = Conversation::create([
            'business_id' => $bizId, 'channel' => 'facebook',
            'customer_name' => 'Brain Bargainer', 'status' => 'open', 'product_id' => $product->id,
        ]);
        $msg = $brain->handleIncoming($conv, 'Test Panjabi ta ektu kom hobe na? 1100 taka dibo');
        $this->assertNotNull($msg, 'bargain produced no reply — the shop is still rule-less');
        $offer = (float) ($msg->meta['offer'] ?? 0);
        $this->assertGreaterThanOrEqual(1063.0, $offer, 'offer fell below the 15% floor');
        $this->assertLessThan(1250.0, $offer, 'offer is not below the list price');
        // The safety net fired inside the first match: the shop has rules now.
        $this->assertSame(count(AiRule::defaults()), AiRule::where('business_id', $bizId)->count());

        // --- Price: the price rule answers with the real figure ---
        $priceMsg = $brain->handleIncoming($conv->fresh(), 'Test Panjabi er dam koto?');
        $this->assertNotNull($priceMsg);
        $this->assertStringContainsString('1,250', (string) $priceMsg->body);
        $this->assertSame('price', $priceMsg->rule?->match_text);

        // --- Order intent: collect phone, then address, then summary ---
        $order = Conversation::create([
            'business_id' => $bizId, 'channel' => 'facebook',
            'customer_name' => 'Brain Buyer', 'status' => 'open', 'product_id' => $product->id,
        ]);
        $brain->handleIncoming($order, 'Test Panjabi ta nite chai, order confirm korlam');
        $this->assertSame('collecting', $order->fresh()->state);

        $brain->handleIncoming($order->fresh(), '01712345678');
        $this->assertSame('01712345678', $order->fresh()->customer_phone);

        $summary = $brain->handleIncoming($order->fresh(), 'basha 5, road 2, dhanmondi, dhaka');
        $this->assertStringContainsString('dhanmondi', (string) $order->fresh()->customer_address);
        $this->assertNotNull($summary);
        $this->assertSame('order_summary', $summary->meta['kind'] ?? null);
        $this->assertSame('draft', $summary->status);
        $this->assertSame(0, Order::where('business_id', $bizId)->count());

        // --- Approval through the real inbox route creates the order ---
        $response = $this->actingAs($owner)->post(route('messages.drafts.approve', $summary->id));
        $response->assertRedirect();

        $created = Order::where('business_id', $bizId)->sole();
        $this->assertSame('Brain Buyer', $created->customer_name);
        $this->assertSame('01712345678', $created->customer_phone);
        $this->assertEqualsWithDelta(1310.0, (float) $created->grand_total, 0.01);
        $pointsAfter = (int) DB::table('business_wallets')->where('business_id', $bizId)->value('balance');
        $this->assertSame($pointsBefore - 1, $pointsAfter, 'exactly one point must be deducted for the chat order');
    }
}

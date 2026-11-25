<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRule extends Model
{
    protected $guarded = [];

    /**
     * THE MASTER BRAIN RULE LIBRARY — the permanent, canonical source of
     * the Rule Brain's default rules, grouped by category below.
     *
     * This catalog is the single source every install path draws from:
     * the seeder (MessagesSeeder, so a fresh `migrate:fresh --seed`
     * restores the full library), the Business-created provisioning
     * hook, the backfill migration for existing shops, and the brain's
     * lazy safety net in matchRule. Nothing else may define defaults.
     *
     * The library is meant to GROW, case by case, as real customer
     * conversations teach new cases (payment questions, delivery times,
     * returns, sizes and colours, order status, cancellations,
     * complaints, wholesale, ...). To add a case: add ONE def to the
     * right category here, with a test — every shop that lacks it picks
     * it up through ensureDefaults, and existing rows are never touched.
     *
     * @return array<string, array<int, array{name: string, match_text: string, action: string, reply_template: ?string}>>
     */
    public static function catalog(): array
    {
        return [
            // ---- Sales: the selling conversation ----
            'Sales' => [
                ['name' => 'Price answer', 'match_text' => 'price', 'action' => 'answer', 'reply_template' => '{honorific}, {product}-এর দাম {price} টাকা। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।'],
                ['name' => 'Price negotiation (ladder)', 'match_text' => 'bargain', 'action' => 'draft', 'reply_template' => null],
                ['name' => 'Stock answer', 'match_text' => 'stock', 'action' => 'answer', 'reply_template' => '{honorific}, {product} এখন স্টকে আছে ({stock}টা)। অর্ডার করতে চাইলে ঠিকানাটা দিন।'],
            ],
            // ---- Orders: taking an order and getting it to the customer ----
            'Orders' => [
                ['name' => 'Order intake', 'match_text' => 'order_intent', 'action' => 'draft', 'reply_template' => null],
                ['name' => 'Delivery charge answer', 'match_text' => 'delivery', 'action' => 'answer', 'reply_template' => 'ঢাকার ভিতরে ডেলিভারি চার্জ {delivery_inside} টাকা, ঢাকার বাইরে {delivery_outside} টাকা {honorific}। কোন এলাকায় ডেলিভারি চান?'],
            ],
            // ---- Service: greeting, warmth and protection ----
            'Service' => [
                ['name' => 'Default First Reply', 'match_text' => 'greeting', 'action' => 'answer', 'reply_template' => 'আসসালামু আলাইকুম! কিভাবে সাহায্য করতে পারি? কোন পণ্যটা সম্পর্কে জানতে চান?'],
                ['name' => 'Reaction reply', 'match_text' => 'reaction', 'action' => 'answer', 'reply_template' => 'ধন্যবাদ! আর কিছু জানতে চাইলে বলুন।'],
                ['name' => 'Angry customer handover', 'match_text' => 'angry', 'action' => 'handover', 'reply_template' => null],
                ['name' => 'Spam hide', 'match_text' => 'spam', 'action' => 'hide', 'reply_template' => null],
            ],
        ];
    }

    /**
     * Every default rule in the master library, flattened across its
     * categories — the list ensureDefaults installs.
     *
     * @return array<int, array{name: string, match_text: string, action: string, reply_template: ?string}>
     */
    public static function defaults(): array
    {
        return array_merge([], ...array_values(self::catalog()));
    }

    /**
     * Install any missing default rules for a shop. Keyed on
     * (business_id, trigger_type 'intent', match_text) with firstOrCreate:
     * a row that already exists is NEVER modified, reactivated or
     * duplicated — a seller's edited template or a rule they deliberately
     * switched off stays exactly as they left it.
     */
    public static function ensureDefaults(int $businessId): void
    {
        foreach (self::defaults() as $def) {
            self::firstOrCreate(
                ['business_id' => $businessId, 'trigger_type' => 'intent', 'match_text' => $def['match_text']],
                ['name' => $def['name'], 'action' => $def['action'], 'reply_template' => $def['reply_template'], 'active' => true],
            );
        }
    }
}

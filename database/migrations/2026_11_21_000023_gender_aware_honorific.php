<?php

use App\Models\AiRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gender-aware honorifics (2026-11-21): the default rule templates no
 * longer hardcode "আপু" for every customer. They use the {honorific}
 * placeholder, which the Rule Brain fills from the customer's name
 * (auto mode), a shop-wide word, or nothing. Shops whose AI behaviour
 * setting still says the old default 'apu' (or says nothing) move to
 * 'auto'; an explicit 'bhaiya' / 'none' choice is left untouched.
 */
return new class extends Migration
{
    /** Old seeded default => new {honorific} version (exact matches only). */
    private const TEMPLATES = [
        'আপু, {product}-এর দাম {price} টাকা। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।'
            => '{honorific}, {product}-এর দাম {price} টাকা। অর্ডার করতে চাইলে নাম, ঠিকানা আর ফোন নম্বরটা পাঠিয়ে দিন।',
        'ঢাকার ভিতরে ডেলিভারি চার্জ {delivery_inside} টাকা, ঢাকার বাইরে {delivery_outside} টাকা আপু। কোন এলাকায় ডেলিভারি চান?'
            => 'ঢাকার ভিতরে ডেলিভারি চার্জ {delivery_inside} টাকা, ঢাকার বাইরে {delivery_outside} টাকা {honorific}। কোন এলাকায় ডেলিভারি চান?',
        'আপু, {product} এখন স্টকে আছে ({stock}টা)। অর্ডার করতে চাইলে ঠিকানাটা দিন।'
            => '{honorific}, {product} এখন স্টকে আছে ({stock}টা)। অর্ডার করতে চাইলে ঠিকানাটা দিন।',
    ];

    public function up(): void
    {
        foreach (self::TEMPLATES as $old => $new) {
            AiRule::where('reply_template', $old)->update(['reply_template' => $new]);
        }

        $row = DB::table('settings')->where('key', 'ai.behavior')->first();
        if ($row !== null) {
            $behavior = json_decode((string) $row->value, true);
            if (is_array($behavior) && (! isset($behavior['honorific']) || $behavior['honorific'] === 'apu')) {
                $behavior['honorific'] = 'auto';
                DB::table('settings')->where('key', 'ai.behavior')->update([
                    'value' => json_encode($behavior),
                    'type' => 'json',
                ]);
            }
        }
    }

    public function down(): void
    {
        // Wording/setting change only; no safe automatic undo.
    }
};

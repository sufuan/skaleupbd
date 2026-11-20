<?php

use App\Models\AiRule;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;

/**
 * Owner's wording rules for customer messages (2026-11-20):
 *  - amounts are written as "1,250 টাকা", never with the ৳ symbol, in the
 *    default rule templates shops already have;
 *  - every shop gets a Reaction rule, so a Like / emoji-only message gets
 *    a warm short answer instead of silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (AiRule::whereNotNull('reply_template')->get() as $rule) {
            $tpl = (string) $rule->reply_template;
            $new = str_replace(
                ['৳{price}', '৳{delivery_inside}', '৳{delivery_outside}'],
                ['{price} টাকা', '{delivery_inside} টাকা', '{delivery_outside} টাকা'],
                $tpl,
            );
            if ($new !== $tpl) {
                $rule->reply_template = $new;
                $rule->save();
            }
        }

        foreach (Business::pluck('id') as $bizId) {
            AiRule::firstOrCreate(
                ['business_id' => $bizId, 'trigger_type' => 'intent', 'match_text' => 'reaction'],
                ['name' => 'Reaction reply', 'action' => 'answer', 'reply_template' => 'ধন্যবাদ! আর কিছু জানতে চাইলে বলুন।', 'active' => true],
            );
        }
    }

    public function down(): void
    {
        // Wording change + an added default rule; no safe automatic undo.
    }
};

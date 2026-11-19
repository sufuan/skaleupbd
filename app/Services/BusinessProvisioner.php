<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\MessageTemplate;
use App\Models\OrderSource;
use App\Models\User;
use Illuminate\Support\Str;

class BusinessProvisioner
{
    /**
     * Set up everything a brand-new shop needs: the Business row, the owner's
     * link to it, and the default per-business data (order sources, the
     * standard SMS template library, finance categories and a Cash account).
     * Mirrors what the seeders create for the demo shops.
     */
    public function provision(User $user): Business
    {
        if ($user->business_id) {
            $existing = Business::find($user->business_id);
            if ($existing) {
                return $existing;
            }
        }

        $base = Str::slug((string) $user->name) ?: 'shop';
        $publicId = $base;
        $n = 1;
        while (Business::where('public_id', $publicId)->exists()) {
            $publicId = $base . '-' . (++$n);
        }

        $biz = Business::create([
            'name' => $user->name,
            'public_id' => $publicId,
            'owner_name' => $user->name,
            'email' => $user->email,
        ]);

        $user->forceFill(['business_id' => $biz->id])->save();

        $sources = [
            ['FB', 'Facebook'], ['WEB', 'Website'], ['DIRECT', 'Direct'], ['UNKNOWN', 'Unknown'],
            ['INSTAGRAM', 'Instagram'], ['TIKTOK', 'TikTok'], ['WHATSAPP', 'WhatsApp'], ['EXCHANGE', 'Exchange'],
            ['MESSENGER', 'Messenger'], ['CUSTOM_SOURCE', 'Custom Source'], ['CUSTOM_SOURCE_2', 'Custom Source 2'],
            ['INCOMPLETE_ORDER', 'Incomplete Order'], ['UPSALE', 'Upsale'],
        ];
        foreach ($sources as $i => [$code, $label]) {
            OrderSource::firstOrCreate(
                ['business_id' => $biz->id, 'code' => $code],
                ['label' => $label, 'is_visible' => true, 'sort' => $i]
            );
        }

        foreach (self::TEMPLATES as $key => [$name, $body]) {
            MessageTemplate::firstOrCreate(
                ['business_id' => $biz->id, 'key' => $key],
                ['name' => $name, 'body' => $body]
            );
        }

        app(FinanceService::class)->ensureCategories($biz->id);
        FinanceAccount::firstOrCreate(
            ['business_id' => $biz->id, 'name' => 'Cash'],
            ['type' => 'cash', 'opening_balance' => 0, 'sort' => 0]
        );

        return $biz;
    }

    /** The standard template library (same texts MarketingSeeder installs). */
    private const TEMPLATES = [
        'thankyou' => ['ধন্যবাদ (নতুন order)', 'ধন্যবাদ {name}! তোমার order {invoice} পেয়েছি। শীঘ্রই confirm করে জানাচ্ছি।'],
        'reminder' => ['Reminder (confirm হয়নি)', 'তোমার order {invoice} এখনো confirm হয়নি। Confirm করতে আমাদের call করো: {phone}'],
        'advance' => ['Advance চাওয়া', 'তোমার order-এর জন্য ৳{advance} advance পাঠাও এই নম্বরে: {number}'],
        'shipping' => ['Shipping খবর', 'তোমার parcel {courier}-এ পাঠানো হয়েছে। Tracking নম্বর: {cn}'],
        'delivered' => ['Delivered ধন্যবাদ', 'তোমার order পৌঁছে গেছে! পণ্য কেমন লাগলো, এক লাইনে জানিও।'],
        'back_in_stock' => ['Stock ফিরলে খবর', 'তুমি যে {product} চেয়েছিলে, আবার stock-এ এসেছে! ফুরোনোর আগে order করো।'],
        'winback' => ['ঘুমন্ত customer offer', 'অনেকদিন দেখা নেই! শুধু তোমার জন্য 10% ছাড় — code: FEST10'],
        'post_return' => ['Return-এর পর আহ্বান', 'আগের পণ্যটা পছন্দ হয়নি বলে দুঃখিত। আমাদের নতুন collection দেখো, পছন্দ হবেই।'],
        'offer' => ['Offer (coupon-সহ)', 'শুধু তোমার জন্য {discount} ছাড়! Code: {coupon} — order করার সময় code-টা বললেই হবে।'],
    ];
}

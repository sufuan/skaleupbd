<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\SmsCampaign;
use App\Models\SmsDoNotSend;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * M12 demo data: the shared template library, SMS balance, one sent campaign
 * plus automation SMS history — and a few fresh orders placed after those SMS
 * so the SMS Report shows real credited rows (incl. most-recent-touch wins).
 */
class MarketingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);
        $templates = [
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

        foreach (Business::all() as $biz) {
            foreach ($templates as $key => [$name, $body]) {
                MessageTemplate::updateOrCreate(['business_id' => $biz->id, 'key' => $key], ['name' => $name, 'body' => $body]);
            }
        }

        $demo = Business::where('public_id', 'demo-shop')->first();
        $other = Business::where('public_id', 'other-shop')->first();
        if (! $demo) {
            return;
        }
        $settings->set("marketing.{$demo->id}.sms_balance", 2450);
        $settings->set("marketing.{$demo->id}.sms_rate", 1.50);
        if ($other) {
            $settings->set("marketing.{$other->id}.sms_balance", 500);
            $settings->set("marketing.{$other->id}.sms_rate", 1.50);
        }

        foreach (['01700000001', '01700000002', '01700000003'] as $phone) {
            SmsDoNotSend::firstOrCreate(['business_id' => $demo->id, 'phone' => $phone]);
        }

        $admin = User::where('email', 'admin@skaleup.test')->first();
        $campaign = SmsCampaign::create([
            'business_id' => $demo->id, 'name' => 'ঈদ offer — 10% ছাড়', 'audience_key' => 'bought_30d',
            'audience_label' => 'শেষ 30 দিনে কিনেছে', 'message' => 'ঈদ offer! সব পণ্যে 10% ছাড় — Code: EID10',
            'coupon' => 'EID10', 'parts' => 1, 'status' => 'sent', 'sent_by' => $admin?->id,
            'created_at' => Carbon::now()->subDays(5), 'updated_at' => Carbon::now()->subDays(5),
        ]);

        $phones = Customer::where('business_id', $demo->id)->pluck('phone')->take(12);
        $cost = 0.0;
        foreach ($phones as $phone) {
            SmsLog::create(['business_id' => $demo->id, 'phone' => $phone, 'source_type' => 'campaign',
                'source_key' => (string) $campaign->id, 'source_label' => $campaign->name,
                'body' => $campaign->message, 'parts' => 1, 'cost' => 1.5, 'status' => 'sent',
                'created_at' => Carbon::now()->subDays(5)]);
            $cost += 1.5;
        }
        $campaign->update(['recipients' => $phones->count(), 'total_cost' => $cost]);

        // Automation history: reminder to Karim (1 day ago), win-back to Rony (3 days ago).
        SmsLog::create(['business_id' => $demo->id, 'phone' => '01898765432', 'source_type' => 'automation',
            'source_key' => 'reminder', 'source_label' => 'Confirm হয়নি — reminder SMS', 'ref' => 'OB-OLD-1',
            'body' => 'তোমার order এখনো confirm হয়নি।', 'parts' => 1, 'cost' => 1.5, 'status' => 'sent',
            'created_at' => Carbon::now()->subDays(1)]);
        SmsLog::create(['business_id' => $demo->id, 'phone' => '01911112222', 'source_type' => 'automation',
            'source_key' => 'winback', 'source_label' => 'ঘুমন্ত customer-দের offer',
            'body' => 'অনেকদিন দেখা নেই! শুধু তোমার জন্য 10% ছাড় — code: FEST10', 'parts' => 1, 'cost' => 1.5,
            'status' => 'sent', 'created_at' => Carbon::now()->subDays(3)]);

        // Fresh orders AFTER those SMS (today): Rahim → campaign credit (delivered),
        // Karim → reminder credit (most recent), Rony → win-back credit.
        $product = Product::where('business_id', $demo->id)
            ->orderByRaw('(stock_qty - reserved_qty) DESC')->first();
        if (! $product || ! $admin) {
            return;
        }
        $orders = app(OrderService::class);
        $mk = fn (string $name, string $phone) => $orders->create($demo->id, [
            'customer_name' => $name, 'customer_phone' => $phone, 'customer_address' => 'Dhaka',
            'delivery_charge' => 60, 'items' => [['product_id' => $product->id, 'qty' => 1]],
        ], $admin, 'Marketing seed');

        $o1 = $mk('Rahim Uddin', '01712345678');
        $orders->approve($o1, $admin);
        $orders->changeStatus($o1->fresh(), 'Shipped', $admin);
        $orders->changeStatus($o1->fresh(), 'Delivered', $admin);
        $mk('Karim Sheikh', '01898765432');
        $mk('Rony Das', '01911112222');
    }
}

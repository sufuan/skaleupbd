<?php

namespace App\Services;

use App\Models\Business;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\SmsLog;
use Carbon\Carbon;

/**
 * M12 automation: the 8 story-card flows. Copy and defaults come from the
 * prototype cards. Timings live on the card (seller-editable, stored in the
 * marketing.{biz}.flows setting); message bodies live in the ONE
 * message_templates library. Immediate flows fire from OrderService hooks;
 * delayed flows are processed by the runner (`marketing:run`, cron-ready).
 */
class AutomationService
{
    public const FLOWS = [
        'thankyou' => ['group' => 'order', 'title' => 'নতুন order এলে ধন্যবাদ SMS', 'when' => 'Order হওয়ার সাথে সাথে customer-এর phone-এ যাবে', 'default_on' => true, 'timing' => []],
        'reminder' => ['group' => 'order', 'title' => 'Confirm হয়নি — reminder SMS', 'when' => 'Order-এর 2 ঘণ্টা পরও confirm না হলে একবার, পরদিন সকালে আরেকবার যাবে', 'default_on' => true, 'timing' => ['first_hours' => 2, 'second_hours' => 20]],
        'advance' => ['group' => 'order', 'title' => 'Advance চাইলে SMS', 'when' => 'Risky মনে হলে order page থেকে staff চাপলেই যাবে (নিজে থেকে যাবে না)', 'default_on' => false, 'timing' => []],
        'shipped' => ['group' => 'delivery', 'title' => 'Courier-এ পাঠালেই SMS', 'when' => 'Parcel booking হওয়ার সাথে সাথে, tracking নম্বরসহ', 'default_on' => true, 'timing' => []],
        'delivered' => ['group' => 'delivery', 'title' => 'Delivered হলে ধন্যবাদ + review', 'when' => 'Delivery complete হলেই যাবে', 'default_on' => true, 'timing' => []],
        'back_in_stock' => ['group' => 'winback', 'title' => 'Stock ফিরলে খবর', 'when' => 'Preorder করা product আবার stock-এ এলেই শুধু ওই customer-দের যাবে', 'default_on' => true, 'timing' => []],
        'winback' => ['group' => 'winback', 'title' => 'ঘুমন্ত customer-দের offer', 'when' => '60 দিন ধরে না আসা customer-দের, মাসে একবার — coupon-সহ', 'default_on' => false, 'timing' => ['dormant_days' => 60]],
        'post_return' => ['group' => 'winback', 'title' => 'Return-এর পর আবার কেনার আহ্বান', 'when' => 'Return শেষ হওয়ার 3 দিন পর যাবে', 'default_on' => false, 'timing' => ['days' => 3]],
    ];

    public const DEFAULT_BODIES = [
        'thankyou' => 'ধন্যবাদ {name}! তোমার order {invoice} পেয়েছি। শীঘ্রই confirm করে জানাচ্ছি।',
        'reminder' => 'তোমার order {invoice} এখনো confirm হয়নি। Confirm করতে আমাদের call করো: {phone}',
        'advance' => 'তোমার order-এর জন্য ৳{advance} advance পাঠাও এই নম্বরে: {number}',
        'shipping' => 'তোমার parcel {courier}-এ পাঠানো হয়েছে। Tracking নম্বর: {cn}',
        'delivered' => 'তোমার order পৌঁছে গেছে! পণ্য কেমন লাগলো, এক লাইনে জানিও।',
        'back_in_stock' => 'তুমি যে {product} চেয়েছিলে, আবার stock-এ এসেছে! ফুরোনোর আগে order করো।',
        'winback' => 'অনেকদিন দেখা নেই! শুধু তোমার জন্য 10% ছাড় — code: FEST10',
        'post_return' => 'আগের পণ্যটা পছন্দ হয়নি বলে দুঃখিত। আমাদের নতুন collection দেখো, পছন্দ হবেই।',
    ];

    /** message_templates key per flow (shipped flow reads the 'shipping' template). */
    public const TEMPLATE_KEYS = [
        'thankyou' => 'thankyou', 'reminder' => 'reminder', 'advance' => 'advance',
        'shipped' => 'shipping', 'delivered' => 'delivered', 'back_in_stock' => 'back_in_stock',
        'winback' => 'winback', 'post_return' => 'post_return',
    ];

    public function __construct(private readonly SmsService $sms, private readonly SettingsService $settings) {}

    public function config(int $biz): array
    {
        $saved = $this->sms->setting($biz, 'flows', []);
        $out = [];
        foreach (self::FLOWS as $key => $f) {
            $out[$key] = [
                'enabled' => (bool) ($saved[$key]['enabled'] ?? $f['default_on']),
                'timing' => array_merge($f['timing'], $saved[$key]['timing'] ?? []),
            ];
        }

        return $out;
    }

    public function saveConfig(int $biz, array $config): void
    {
        $this->sms->setSetting($biz, 'flows', $config);
    }

    public function body(int $biz, string $flow): string
    {
        $key = self::TEMPLATE_KEYS[$flow] ?? $flow;
        $body = MessageTemplate::where('business_id', $biz)->where('key', $key)->value('body');

        return $body ?: (self::DEFAULT_BODIES[$flow] ?? '');
    }

    public function fill(string $body, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = (string) $v;
        }

        return strtr($body, $map);
    }

    private function sendFlow(int $biz, string $flow, string $phone, array $vars, ?string $ref = null, ?int $customerId = null): ?SmsLog
    {
        $cfg = $this->config($biz);
        if (! ($cfg[$flow]['enabled'] ?? false)) {
            return null;
        }
        $body = $this->fill($this->body($biz, $flow), $vars + ['phone' => (string) $this->settings->get('business.phone', '')]);

        return $this->sms->send($biz, $phone, $body, [
            'type' => 'automation', 'key' => $flow, 'label' => self::FLOWS[$flow]['title'], 'ref' => $ref,
        ], $customerId);
    }

    // ---- Immediate flows (called from OrderService hooks, failure-safe there) ----

    public function onOrderCreated(Order $order): void
    {
        $this->sendFlow($order->business_id, 'thankyou', $order->customer_phone,
            ['name' => $order->customer_name, 'invoice' => $order->invoice_no], $order->invoice_no, $order->customer_id);
    }

    public function onShipped(Order $order): void
    {
        $this->sendFlow($order->business_id, 'shipped', $order->customer_phone,
            ['courier' => $order->delivery_method ?: 'Courier', 'cn' => $order->consignment_id ?: $order->tracking_id ?: ''],
            $order->invoice_no, $order->customer_id);
    }

    public function onDelivered(Order $order): void
    {
        $this->sendFlow($order->business_id, 'delivered', $order->customer_phone,
            ['invoice' => $order->invoice_no], $order->invoice_no, $order->customer_id);
    }

    // ---- Delayed flows (runner) ----

    public function runDue(): array
    {
        $stats = ['reminder' => 0, 'winback' => 0, 'post_return' => 0, 'back_in_stock' => 0];
        foreach (Business::pluck('id') as $biz) {
            $cfg = $this->config($biz);
            $now = Carbon::now();

            if ($cfg['reminder']['enabled']) {
                $t = $cfg['reminder']['timing'];
                $orders = Order::where('business_id', $biz)->where('status', 'Pending')
                    ->where('created_at', '<=', $now->copy()->subHours((int) $t['first_hours']))->get();
                foreach ($orders as $o) {
                    $sent1 = SmsLog::where('business_id', $biz)->where('source_key', 'reminder')->where('ref', $o->invoice_no)->exists();
                    if (! $sent1) {
                        if ($this->sendFlow($biz, 'reminder', $o->customer_phone, ['invoice' => $o->invoice_no], $o->invoice_no, $o->customer_id)?->status === 'sent') {
                            $stats['reminder']++;
                        }
                        continue;
                    }
                    if ($o->created_at->lte($now->copy()->subHours((int) $t['second_hours']))) {
                        $sent2 = SmsLog::where('business_id', $biz)->where('source_key', 'reminder')->where('ref', $o->invoice_no.'#2')->exists();
                        if (! $sent2 && $this->sendFlow($biz, 'reminder', $o->customer_phone, ['invoice' => $o->invoice_no], $o->invoice_no.'#2', $o->customer_id)?->status === 'sent') {
                            $stats['reminder']++;
                        }
                    }
                }
            }

            if ($cfg['winback']['enabled']) {
                $days = (int) $cfg['winback']['timing']['dormant_days'];
                $lastOrder = Order::where('business_id', $biz)->whereNotIn('status', ['Cancelled'])
                    ->selectRaw('customer_phone, MAX(created_at) as last_at, MAX(customer_id) as cid')
                    ->groupBy('customer_phone')->get();
                foreach ($lastOrder as $row) {
                    if (Carbon::parse($row->last_at)->gt($now->copy()->subDays($days))) {
                        continue;
                    }
                    $recent = SmsLog::where('business_id', $biz)->where('source_key', 'winback')
                        ->where('phone', PhoneNormalizer::normalize($row->customer_phone))
                        ->where('created_at', '>=', $now->copy()->subDays(30))->exists();
                    if (! $recent && $this->sendFlow($biz, 'winback', $row->customer_phone, [], null, $row->cid)?->status === 'sent') {
                        $stats['winback']++;
                    }
                }
            }

            if ($cfg['post_return']['enabled']) {
                $days = (int) $cfg['post_return']['timing']['days'];
                $orders = Order::where('business_id', $biz)->where('status', 'Returned')
                    ->where('updated_at', '<=', $now->copy()->subDays($days))->get();
                foreach ($orders as $o) {
                    $sent = SmsLog::where('business_id', $biz)->where('source_key', 'post_return')->where('ref', $o->invoice_no)->exists();
                    if (! $sent && $this->sendFlow($biz, 'post_return', $o->customer_phone, [], $o->invoice_no, $o->customer_id)?->status === 'sent') {
                        $stats['post_return']++;
                    }
                }
            }

            if ($cfg['back_in_stock']['enabled']) {
                $orders = Order::where('business_id', $biz)->where('is_preorder', true)->where('preorder_notified', false)
                    ->where('status', 'Pending')->with('items')->get();
                foreach ($orders as $o) {
                    foreach ($o->items as $item) {
                        if (! $item->product_id) {
                            continue;
                        }
                        $p = Product::where('business_id', $biz)->find($item->product_id);
                        if ($p && ((int) $p->stock_qty - (int) $p->reserved_qty) >= (int) $item->qty) {
                            $log = $this->sendFlow($biz, 'back_in_stock', $o->customer_phone,
                                ['product' => $item->name], $o->invoice_no, $o->customer_id);
                            if ($log && $log->status === 'sent') {
                                $o->update(['preorder_notified' => true]);
                                $stats['back_in_stock']++;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return $stats;
    }

    /** Run stats for one flow card: all-time sent + sent today. */
    public function flowStats(int $biz, string $flow): array
    {
        $base = SmsLog::where('business_id', $biz)->where('source_type', 'automation')
            ->where('source_key', $flow)->where('status', 'sent');

        return [
            'sent_total' => (clone $base)->count(),
            'sent_today' => (clone $base)->whereDate('created_at', Carbon::today())->count(),
        ];
    }
}

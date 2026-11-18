<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Business;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\IpPhoneBlock;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/** Demo data for M2 Orders (idempotent: skips order seeding when orders exist). */
class OrdersSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);
        $orders = app(OrderService::class);

        $biz = Business::firstOrCreate(['public_id' => 'demo-shop'], ['name' => 'Demo Shop']);
        $other = Business::firstOrCreate(['public_id' => 'other-shop'], ['name' => 'Other Shop']);

        $admin = User::where('email', 'admin@skaleup.test')->first();
        if ($admin) {
            $admin->forceFill(['business_id' => $biz->id, 'is_admin' => true])->save();
        }
        User::updateOrCreate(['email' => 'tenant2@skaleup.test'],
            ['name' => 'Other Owner', 'password' => 'password', 'email_verified_at' => now(), 'business_id' => $other->id, 'is_admin' => true]);

        // Business + order settings (first-set only via SettingsService defaults below)
        foreach ([
            'business.name' => 'Demo Shop',
            'business.mobile' => '01712-345678',
            'business.address' => 'House 12, Road 5, Dhanmondi, Dhaka 1205',
            'business.invoice_slug' => 'OB',
            'business.default_delivery_cost' => 60,
            'invoice.template' => 'a4',
            'orders.show_email' => true,
            'orders.show_alt_number' => false,
            'orders.mandatory_source' => true,
            'orders.mandatory_shipping_note' => false,
            'orders.mandatory_advance_txn' => true,
            'orders.cross_sale' => true,
        ] as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
        if ($settings->get('couriers.methods') === null) {
            $settings->set('couriers.methods', ['Steadfast — Inside Dhaka', 'Pathao — Inside Dhaka', 'RedX — Dhaka', 'Carrybee', 'Manual', 'Steadfast — Outside Dhaka', 'Pathao — Outside Dhaka', 'Sundarban']);
        }

        $sources = [
            ['FB', 'Facebook'], ['WEB', 'Website'], ['DIRECT', 'Direct'], ['UNKNOWN', 'Unknown'],
            ['INSTAGRAM', 'Instagram'], ['TIKTOK', 'TikTok'], ['WHATSAPP', 'WhatsApp'], ['EXCHANGE', 'Exchange'],
            ['MESSENGER', 'Messenger'], ['CUSTOM_SOURCE', 'Custom Source'], ['CUSTOM_SOURCE_2', 'Custom Source 2'],
            ['INCOMPLETE_ORDER', 'Incomplete Order'], ['UPSALE', 'Upsale'],
        ];
        foreach ($sources as $i => [$code, $label]) {
            OrderSource::firstOrCreate(['business_id' => $biz->id, 'code' => $code],
                ['label' => $label, 'is_visible' => true, 'sort' => $i]);
        }

        MessageTemplate::firstOrCreate(['business_id' => $biz->id, 'key' => 'reminder'],
            ['name' => 'Order Reminder SMS', 'body' => 'Dear {name}, your order {invoice} (total ৳{amount}) is waiting for confirmation. Please reply YES or call us to confirm your order. — {shop}']);
        MessageTemplate::firstOrCreate(['business_id' => $biz->id, 'key' => 'advance'],
            ['name' => 'Advance Payment SMS', 'body' => 'Dear {name}, to confirm your order {invoice}, please send an advance of ৳{advance} by bKash and share the transaction ID. — {shop}']);

        ApiKey::firstOrCreate(['business_id' => $biz->id, 'key_hash' => hash('sha256', 'sk_demo_test_key_0001')],
            ['last4' => '0001', 'label' => 'Demo website']);

        $products = [
            ['Cotton Panjabi — White', 'CP-101', 1250, 800, 42],
            ['Cotton Panjabi — Blue', 'CP-102', 1250, 800, 18],
            ['Silk Saree — Red', 'SS-201', 3400, 2400, 7],
            ['T-Shirt Pack (3 pcs)', 'TS-301', 899, 520, 65],
            ['Leather Wallet', 'LW-401', 650, 380, 3],
            ['Wireless Earbuds', 'WE-501', 1450, 950, 27],
        ];
        $prod = [];
        foreach ($products as [$name, $sku, $price, $cost, $stock]) {
            $prod[$sku] = Product::firstOrCreate(['business_id' => $biz->id, 'sku' => $sku],
                ['name' => $name, 'price' => $price, 'cost' => $cost, 'stock_qty' => $stock]);
        }

        IpPhoneBlock::firstOrCreate(['business_id' => $biz->id, 'type' => 'ip', 'value' => '103.87.22.14'],
            ['reason' => 'Repeated fake orders', 'added_by' => $admin?->name]);
        IpPhoneBlock::firstOrCreate(['business_id' => $biz->id, 'type' => 'phone', 'value' => '01600000000'],
            ['reason' => 'Fraud orders', 'added_by' => $admin?->name]);
        IpPhoneBlock::firstOrCreate(['business_id' => $biz->id, 'type' => 'device', 'value' => 'dev-9f2ac1'],
            ['reason' => 'Fake orders', 'added_by' => $admin?->name]);
        IpPhoneBlock::firstOrCreate(['business_id' => $biz->id, 'type' => 'phone', 'value' => '01444444444'],
            ['reason' => 'Expired block (test fixture)', 'added_by' => $admin?->name, 'expires_at' => now()->subDay()]);

        Customer::firstOrCreate(['business_id' => $biz->id, 'phone' => '01911112222'],
            ['name' => 'Rony Ahmed', 'is_blacklisted' => true, 'address' => 'Unknown, Dhaka']);

        if (Order::where('business_id', $biz->id)->exists()) {
            return;
        }

        $item = fn (string $sku, int $qty = 1) => ['product_id' => $prod[$sku]->id, 'qty' => $qty];
        $mk = function (array $data, string $via = 'Website') use ($orders, $biz, $admin) {
            return $orders->create($biz->id, $data, $admin, $via);
        };
        $flow = function (Order $o, array $path) use ($orders, $admin) {
            foreach ($path as $step) {
                if ($step === 'approve') {
                    $orders->approve($o, $admin);
                } else {
                    $orders->changeStatus($o, $step, $admin);
                }
                $o->refresh();
            }
            return $o;
        };

        // Live queue — Pending OB282..OB286 (the dashboard courier-table names)
        $pending = [
            ['OB282', 'Anwar Hossain', '01612349876', 'CP-101', 'WEB'],
            ['OB283', 'F.A. Rayhan', '01712345678', 'TS-301', 'FB'],
            ['OB284', 'Nozrul Islam', '01512349876', 'WE-501', 'WEB'],
            ['OB285', 'Khan Tanvir Islam', '01898765432', 'SS-201', 'MESSENGER'],
            ['OB286', 'M. Fahim', '01312349876', 'CP-102', 'WEB'],
        ];
        foreach ($pending as [$inv, $name, $phone, $sku, $src]) {
            $mk(['invoice_no' => $inv, 'customer_name' => $name, 'customer_phone' => $phone,
                'customer_address' => 'Dhaka', 'district' => 'Dhaka', 'source' => $src,
                'delivery_method' => 'Steadfast — Inside Dhaka', 'delivery_charge' => 60,
                'items' => [$item($sku)]]);
        }
        // A pending order from the blacklisted customer (risky at approve)
        $mk(['customer_name' => 'Rony Ahmed', 'customer_phone' => '01911112222', 'customer_address' => 'Unknown, Dhaka',
            'district' => 'Dhaka', 'source' => 'FB', 'delivery_method' => 'Steadfast — Inside Dhaka', 'delivery_charge' => 60,
            'items' => [$item('WE-501')]]);


        // Customer history builders (delivered / returned outcomes drive success rates)
        $rahim = ['customer_name' => 'Rahim Uddin', 'customer_phone' => '01712345678', 'customer_address' => 'Mirpur 10, Dhaka', 'district' => 'Dhaka', 'source' => 'FB', 'delivery_method' => 'Steadfast — Inside Dhaka', 'delivery_charge' => 60];
        foreach ([['CP-101'], ['TS-301'], ['WE-501'], ['CP-102']] as $it) {
            $flow($mk($rahim + ['items' => [$item($it[0])]]), ['approve', 'Shipped', 'Delivered']);
        }
        $flow($mk($rahim + ['items' => [$item('LW-401')]]), ['approve', 'Shipped', 'Returned']);

        $karim = ['customer_name' => 'Karim Sheikh', 'customer_phone' => '01898765432', 'customer_address' => 'Uttara Sector 7, Dhaka', 'district' => 'Dhaka', 'source' => 'WEB', 'delivery_method' => 'Pathao — Inside Dhaka', 'delivery_charge' => 60];
        $flow($mk($karim + ['items' => [$item('SS-201')]]), ['approve', 'Shipped', 'Delivered']);
        foreach ([['CP-101'], ['WE-501'], ['TS-301']] as $it) {
            $flow($mk($karim + ['items' => [$item($it[0])]]), ['approve', 'Shipped', 'Returned']);
        }

        // Processing / Shipped / Cancelled samples
        $flow($mk(['customer_name' => 'Selina Akter', 'customer_phone' => '01755556666', 'customer_address' => 'Banani, Dhaka', 'district' => 'Dhaka', 'source' => 'WHATSAPP', 'delivery_method' => 'Pathao — Inside Dhaka', 'delivery_charge' => 60, 'items' => [$item('SS-201')]]), ['approve']);
        $flow($mk(['customer_name' => 'Tanvir Hasan', 'customer_phone' => '01655557777', 'customer_address' => 'Chattogram', 'district' => 'Chattogram', 'source' => 'WEB', 'delivery_method' => 'RedX — Dhaka', 'delivery_charge' => 120, 'items' => [$item('TS-301', 2)]]), ['approve']);
        $flow($mk(['customer_name' => 'Nusrat Jahan', 'customer_phone' => '01555558888', 'customer_address' => 'Sylhet', 'district' => 'Sylhet', 'source' => 'FB', 'delivery_method' => 'Steadfast — Inside Dhaka', 'delivery_charge' => 120, 'items' => [$item('CP-101'), $item('LW-401')]]), ['approve', 'Shipped']);
        $cancelled = $flow($mk(['customer_name' => 'Hasan Ali', 'customer_phone' => '01455559999', 'customer_address' => 'Khulna', 'district' => 'Khulna', 'source' => 'DIRECT', 'delivery_charge' => 120, 'items' => [$item('WE-501')]]), ['approve']);
        $orders->changeStatus($cancelled, 'Cancelled', $admin, 'Customer Request');

        // Preorder sample
        $mk(['customer_name' => 'Farhana Islam', 'customer_phone' => '01355551111', 'customer_address' => 'Rajshahi', 'district' => 'Rajshahi',
            'source' => 'WEB', 'delivery_charge' => 120, 'is_preorder' => true, 'preorder_reason' => 'Out of Stock',
            'notify_date' => now()->addDays(3)->toDateString(), 'items' => [['name' => 'Jamdhani Saree — Blue', 'sku' => 'JS-901', 'qty' => 1, 'price' => 5200]]]);

        foreach ([['01712345678', 'verified', 2], ['01898765432', 'no_answer', 1], ['01612349876', 'verified', 3]] as [$phone, $outcome, $mins]) {
            CallLog::create(['business_id' => $biz->id, 'phone' => $phone, 'staff_name' => $admin?->name, 'outcome' => $outcome, 'minutes' => $mins]);
        }

    }
}

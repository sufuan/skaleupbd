<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\CourierAssignmentRule;
use App\Models\CourierMethod;
use App\Models\CourierPickupStore;
use App\Models\Order;
use Illuminate\Database\Seeder;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        $biz = Business::where('public_id', 'demo-shop')->first();
        if (! $biz) {
            return;
        }

        $steadfast = CourierMethod::firstOrCreate(
            ['business_id' => $biz->id, 'courier_type' => 'STEADFAST', 'name' => 'Steadfast'],
            [
                'credentials' => ['api_key' => 'demo', 'api_secret' => 'demo'],
                'charge_inside_dhaka' => 60, 'charge_outside_dhaka' => 120,
                'is_active' => true, 'is_default' => true,
            ]
        );
        $pathao = CourierMethod::firstOrCreate(
            ['business_id' => $biz->id, 'courier_type' => 'PATHAO', 'name' => 'Pathao'],
            [
                'credentials' => ['client_id' => 'demo', 'client_secret' => 'demo', 'client_email' => 'demo@shop.test', 'password' => 'demo', 'store_id' => 'ST-100'],
                'charge_inside_dhaka' => 70, 'charge_outside_dhaka' => 130,
                'is_active' => true, 'is_preferred' => true,
            ]
        );
        CourierMethod::firstOrCreate(
            ['business_id' => $biz->id, 'courier_type' => 'OTHERS', 'name' => 'Sundarban'],
            ['credentials' => [], 'charge_inside_dhaka' => 80, 'charge_outside_dhaka' => 150, 'is_active' => true]
        );

        CourierPickupStore::firstOrCreate(
            ['courier_method_id' => $pathao->id, 'store_id' => 'ST-100'],
            ['name' => 'Main Store — Dhaka', 'is_default' => true]
        );
        CourierPickupStore::firstOrCreate(
            ['courier_method_id' => $pathao->id, 'store_id' => 'ST-200'],
            ['name' => 'Branch Store — Chattogram']
        );

        CourierAssignmentRule::firstOrCreate(
            ['business_id' => $biz->id, 'scope' => 'district', 'district' => 'Chattogram'],
            ['courier_method_id' => $pathao->id, 'priority' => 5, 'is_active' => true]
        );

        // Give the seeded history real courier links so Delivery screens open with data.
        $methods = ['Steadfast' => $steadfast, 'Pathao' => $pathao];
        Order::where('business_id', $biz->id)->whereIn('status', ['Shipped', 'Delivered', 'Returned'])->get()
            ->each(function (Order $o) use ($methods) {
                foreach ($methods as $prefix => $method) {
                    if (str_starts_with((string) $o->delivery_method, $prefix)) {
                        $o->courier_method_id = $method->id;
                        break;
                    }
                }
                $o->courier_method_id ??= $methods['Steadfast']->id;
                if ($o->status === 'Shipped') {
                    $o->booking_status = 'booked';
                    $o->tracking_id = 'SF-' . $o->invoice;
                    $o->consignment_id = 'CN' . strtoupper(substr(md5($o->invoice), 0, 10));
                    $o->courier_status = 'In Transit';
                    $o->shipped_at = $o->created_at?->copy()->addDay();
                }
                if ($o->status === 'Delivered') {
                    $o->booking_status = 'booked';
                    $o->tracking_id = 'SF-' . $o->invoice;
                    $o->consignment_id = 'CN' . strtoupper(substr(md5($o->invoice), 0, 10));
                    $o->courier_status = 'Delivered';
                    $o->shipped_at = $o->created_at?->copy()->addDay();
                    $o->delivered_at = $o->created_at?->copy()->addDays(3);
                }
                if ($o->status === 'Returned') {
                    $o->booking_status = 'booked';
                    $o->tracking_id = 'SF-' . $o->invoice;
                    $o->consignment_id = 'CN' . strtoupper(substr(md5($o->invoice), 0, 10));
                    $o->courier_status = 'Returned';
                    $o->shipped_at = $o->created_at?->copy()->addDay();
                    $o->returned_at = $o->created_at?->copy()->addDays(5);
                }
                $o->save();
            });
    }
}

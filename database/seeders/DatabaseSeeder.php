<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@skaleup.test'],
            ['name' => 'Abu Sufian', 'password' => 'password', 'email_verified_at' => now()],
        );

        $this->call(SettingsSeeder::class);
        $this->call(OrdersSeeder::class);
        $this->call(DeliverySeeder::class);
        $this->call(InventorySeeder::class);
        $this->call(MessagesSeeder::class);
        $this->call(FinanceSeeder::class);
        $this->call(PlatformSeeder::class);
        $this->call(HrmSeeder::class);
        $this->call(MarketingSeeder::class);

        $samples = [
            ['kind' => 'order', 'title' => '5 new orders are waiting', 'body' => 'Orders OB287–OB291 are Pending. Open the next order to start processing.', 'link' => '/module/orders'],
            ['kind' => 'delivery', 'title' => 'Courier booking failed for OB282', 'body' => 'Steadfast did not respond. The order is still in Ready to Ship — try Retry.', 'link' => '/module/delivery'],
            ['kind' => 'stock', 'title' => 'Low stock: Cotton Panjabi (42)', 'body' => 'Only 3 left in the Main Branch. Create a purchase or transfer stock.', 'link' => '/module/inventory'],
            ['kind' => 'task', 'title' => 'A work task is overdue', 'body' => '“Call the customer about the returned parcel” was due at 12:00 PM.', 'link' => '/module/work-inbox'],
        ];

        foreach ($samples as $sample) {
            AppNotification::updateOrCreate(['title' => $sample['title']], $sample);
        }
    }
}

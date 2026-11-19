<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\FinanceAccount;
use App\Models\Order;
use App\Models\User;
use App\Services\FinanceService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $fin = app(FinanceService::class);
        $demo = Business::where('public_id', 'demo-shop')->first();
        $other = Business::where('public_id', 'other-shop')->first();
        $admin = User::where('email', 'admin@skaleup.test')->first();
        $today = Carbon::now('Asia/Dhaka');

        if ($demo) {
            $fin->ensureCategories($demo->id);
            $cash = FinanceAccount::firstOrCreate(['business_id' => $demo->id, 'name' => 'Cash'],
                ['type' => 'cash', 'opening_balance' => 12000, 'sort' => 0]);
            $bkash = FinanceAccount::firstOrCreate(['business_id' => $demo->id, 'name' => 'bKash Merchant'],
                ['type' => 'mobile', 'opening_balance' => 5000, 'sort' => 1]);
            $bank = FinanceAccount::firstOrCreate(['business_id' => $demo->id, 'name' => 'Sonali Bank'],
                ['type' => 'bank', 'opening_balance' => 40000, 'sort' => 2]);

            $cats = \App\Models\FinanceCategory::where('business_id', $demo->id)->get()->keyBy('name');
            $post = function (FinanceAccount $acc, string $dir, string $type, float $amount, ?string $cat, string $note, int $daysAgo, string $source = 'manual') use ($fin, $demo, $admin, $cats, $today) {
                $fin->post($demo->id, [
                    'account_id' => $acc->id, 'direction' => $dir, 'type' => $type, 'amount' => $amount,
                    'category_id' => $cat && $cats->has($cat) ? $cats[$cat]->id : null,
                    'note' => $note, 'source' => $source, 'txn_date' => $today->copy()->subDays($daysAgo)->toDateString(),
                ], $admin);
            };

            $post($cash, 'out', 'expense', 1850, 'Packaging', 'Packaging সামগ্রী কেনা', 0);
            $post($bank, 'out', 'expense', 2300, 'Ad', 'Meta ad খরচ (bank থেকে কেটেছে)', 0);
            $post($bank, 'out', 'expense', 12000, 'ভাড়া', 'দোকান ভাড়া', 1);
            $post($bkash, 'in', 'courier_payout', 11275, null, 'Courier payout — Pathao (হাতে লেখা)', 1, 'courier');
            $post($cash, 'in', 'owner_in', 20000, null, 'মালিকের জমা', 2);
            $post($bank, 'out', 'expense', 8500, 'বেতন', 'কর্মীর বেতন', 3);
            $post($bkash, 'out', 'expense', 600, 'Internet', 'Internet বিল', 4);
            $post($cash, 'in', 'income', 1500, 'অন্য আয়', 'পুরনো কার্টন বিক্রি', 5);

            // One voided + reversed pair (audit trail demo).
            $bad = $fin->post($demo->id, [
                'account_id' => $cash->id, 'direction' => 'out', 'type' => 'expense', 'amount' => 999,
                'category_id' => $cats['অন্যান্য']->id ?? null, 'note' => 'ভুল entry (নমুনা)',
                'txn_date' => $today->copy()->subDays(1)->toDateString(),
            ], $admin);
            $fin->void($bad, $admin);

            // Advances already sitting on seeded orders land in the bKash account.
            foreach (Order::where('business_id', $demo->id)->where('advance', '>', 0)->get() as $o) {
                $fin->syncOrderAdvance($o, $admin);
            }
        }

        if ($other) {
            $fin->ensureCategories($other->id);
            $t2 = User::where('email', 'tenant2@skaleup.test')->first();
            $cash2 = FinanceAccount::firstOrCreate(['business_id' => $other->id, 'name' => 'Cash'],
                ['type' => 'cash', 'opening_balance' => 3000, 'sort' => 0]);
            $cats2 = \App\Models\FinanceCategory::where('business_id', $other->id)->get()->keyBy('name');
            $fin->post($other->id, [
                'account_id' => $cash2->id, 'direction' => 'out', 'type' => 'expense', 'amount' => 450,
                'category_id' => $cats2['Packaging']->id ?? null, 'note' => 'Packaging',
                'txn_date' => $today->toDateString(),
            ], $t2);
        }
    }
}

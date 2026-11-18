<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\WarrantyClaim;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $biz = Business::where('public_id', 'demo-shop')->first();
        if (! $biz) {
            return;
        }
        $products = Product::where('business_id', $biz->id)->get()->keyBy('sku');
        if ($products->isEmpty()) {
            return;
        }

        // Categories & brands, assigned to the seeded catalogue
        $cats = [];
        foreach (["Men's Wear", "Women's Wear", 'Accessories'] as $name) {
            $cats[$name] = ProductCategory::firstOrCreate(['business_id' => $biz->id, 'name' => $name]);
        }
        $brands = [];
        foreach (['Aarong', 'Ecstasy'] as $name) {
            $brands[$name] = ProductBrand::firstOrCreate(['business_id' => $biz->id, 'name' => $name]);
        }
        $assign = [
            'CP-101' => ["Men's Wear", 'Ecstasy'], 'CP-102' => ["Men's Wear", 'Ecstasy'],
            'SS-201' => ["Women's Wear", 'Aarong'], 'TS-301' => ["Men's Wear", 'Ecstasy'],
            'LW-401' => ['Accessories', 'Aarong'], 'WE-501' => ['Accessories', 'Ecstasy'],
        ];
        foreach ($assign as $sku => [$cat, $brand]) {
            if (isset($products[$sku])) {
                $products[$sku]->forceFill([
                    'category_id' => $cats[$cat]->id, 'brand_id' => $brands[$brand]->id,
                    'alert_qty' => 5, 'manage_stock' => true,
                ])->save();
            }
        }
        if (isset($products['WE-501'])) {
            $products['WE-501']->forceFill(['warranty_policy' => '1 Year brand warranty', 'warranty_coverage' => 'Manufacturing defects only'])->save();
        }
        if (isset($products['LW-401'])) {
            $products['LW-401']->forceFill(['warranty_policy' => '6 Months brand warranty', 'warranty_coverage' => 'Stitching and zipper defects'])->save();
        }

        // Branches: one central + one store; branch stock mirrors the catalogue
        $main = Branch::firstOrCreate(['business_id' => $biz->id, 'name' => 'Main Branch'], ['type' => 'central', 'address' => 'Dhanmondi, Dhaka', 'phone' => '01712-345678']);
        $ctg = Branch::firstOrCreate(['business_id' => $biz->id, 'name' => 'Chattogram Branch'], ['type' => 'store', 'address' => 'GEC Circle, Chattogram', 'phone' => '01812-345678']);
        foreach ($products as $p) {
            BranchStock::firstOrCreate(['branch_id' => $main->id, 'product_id' => $p->id], ['business_id' => $biz->id, 'qty' => $p->stock_qty]);
        }
        if (isset($products['CP-101'])) {
            BranchStock::firstOrCreate(['branch_id' => $ctg->id, 'product_id' => $products['CP-101']->id], ['business_id' => $biz->id, 'qty' => 10]);
        }
        if (isset($products['TS-301'])) {
            BranchStock::firstOrCreate(['branch_id' => $ctg->id, 'product_id' => $products['TS-301']->id], ['business_id' => $biz->id, 'qty' => 15]);
        }

        // Opening balances so the Stock Integrity replay balances from day one:
        // opening = current stock − net signed ledger movement.
        $signed = ['reserve' => 0, 'release_reserve' => 0, 'ship_out' => -1, 'return_in' => 1,
            'adjust_in' => 1, 'adjust_out' => -1, 'transfer_in' => 0, 'transfer_out' => 0, 'damage' => -1];
        $openings = [];
        foreach ($products as $p) {
            $net = StockLedger::where('business_id', $biz->id)->where('product_id', $p->id)->get()
                ->sum(fn ($r) => ($signed[$r->type] ?? 0) * $r->qty);
            $openings[$p->id] = $p->stock_qty - (int) $net;
        }
        Setting::updateOrCreate(['key' => 'inventory.opening_balances'], [
            'value' => json_encode($openings), 'type' => 'json', 'group' => 'inventory',
        ]);
        Setting::firstOrCreate(['key' => 'inventory.branch_stock_enabled'], ['value' => '0', 'type' => 'boolean', 'group' => 'inventory']);
        Setting::firstOrCreate(['key' => 'inventory.warehouse_cartons'], ['value' => '0', 'type' => 'boolean', 'group' => 'inventory']);
        Setting::firstOrCreate(['key' => 'inventory.stock_management_disabled'], ['value' => '0', 'type' => 'boolean', 'group' => 'inventory']);
        Setting::firstOrCreate(['key' => 'inventory.valuation'], ['value' => 'average_cost', 'type' => 'string', 'group' => 'inventory']);
        Setting::firstOrCreate(['key' => 'warranty.turnaround_days'], ['value' => '7', 'type' => 'number', 'group' => 'warranty']);

        // One completed transfer (already reflected in the branch stock above)
        StockTransfer::firstOrCreate(
            ['business_id' => $biz->id, 'from_branch_id' => $main->id, 'to_branch_id' => $ctg->id, 'product_id' => $products['CP-101']->id ?? 0, 'qty' => 10],
            ['status' => 'received', 'note' => 'Opening stock for the new branch']
        );

        // Warranty claims against real delivered orders' products
        $watch = $products['WE-501'] ?? null;
        $wallet = $products['LW-401'] ?? null;
        if ($watch) {
            WarrantyClaim::firstOrCreate(['business_id' => $biz->id, 'claim_no' => 'WC-0001'], [
                'product_id' => $watch->id, 'customer_name' => 'Rahim Uddin', 'customer_phone' => '01712345678',
                'serial_no' => 'WE501-2026-0117', 'invoice_no' => 'OB285', 'issue' => 'Watch stops after a few hours',
                'type' => 'Brand Warranty', 'status' => 'in_repair',
                'received_date' => now()->subDays(3)->toDateString(), 'due_date' => now()->addDays(4)->toDateString(),
            ]);
        }
        if ($wallet) {
            WarrantyClaim::firstOrCreate(['business_id' => $biz->id, 'claim_no' => 'WC-0002'], [
                'product_id' => $wallet->id, 'customer_name' => 'Karim Ahmed', 'customer_phone' => '01898765432',
                'serial_no' => 'LW401-2026-0042', 'invoice_no' => 'OB290', 'issue' => 'Zipper broken within a month',
                'type' => 'Brand Warranty', 'status' => 'received',
                'received_date' => now()->subDays(11)->toDateString(), 'due_date' => now()->subDays(4)->toDateString(),
            ]);
            WarrantyClaim::firstOrCreate(['business_id' => $biz->id, 'claim_no' => 'WC-0003'], [
                'product_id' => $wallet->id, 'customer_name' => 'Sharmin Akter', 'customer_phone' => '01911112222',
                'serial_no' => 'LW401-2026-0031', 'invoice_no' => 'OB281', 'issue' => 'Stitching came off',
                'type' => 'Brand Warranty', 'status' => 'closed', 'resolution' => 'Repaired',
                'received_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(13)->toDateString(),
                'closed_date' => now()->subDays(14)->toDateString(),
            ]);
        }
    }
}

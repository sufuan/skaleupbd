<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ComboComponent;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\SharedInventoryGroup;
use App\Models\StockLedger;
use App\Models\StockLinkGroup;
use App\Models\WarrantyClaim;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function __construct(private StockService $stock) {}

    private function biz(Request $request): int
    {
        return $request->user()->business_id;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $row = Setting::where('key', $key)->first();

        return $row ? $row->decodedValue() : $default;
    }

    private function available(Product $p): int
    {
        return $p->stock_qty - $p->reserved_qty;
    }

    private function productRow(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => $p->price,
            'cost' => $p->cost,
            'original_price' => $p->original_price,
            'stock_qty' => $p->stock_qty,
            'reserved_qty' => $p->reserved_qty,
            'available' => $this->available($p),
            'is_active' => (bool) $p->is_active,
            'manage_stock' => (bool) $p->manage_stock,
            'alert_qty' => $p->alert_qty,
            'featured' => (bool) $p->featured,
            'type' => $p->type,
            'image' => $p->image,
            'gallery' => $p->gallery ?? [],
            'category_id' => $p->category_id,
            'brand_id' => $p->brand_id,
            'weight' => $p->weight,
            'weight_unit' => $p->weight_unit,
            'short_description' => $p->short_description,
            'long_description' => $p->long_description,
            'shipping_note' => $p->shipping_note,
            'admin_note' => $p->admin_note,
            'warranty_policy' => $p->warranty_policy,
            'warranty_coverage' => $p->warranty_coverage,
        ];
    }

    // ---------------- Dashboard (Overview / Low Stock / Warehouse Lookup / Smart Restock) ----------------

    public function dashboard(Request $request)
    {
        $biz = $this->biz($request);
        $products = Product::where('business_id', $biz)->get();
        $low = $products->filter(fn ($p) => $this->available($p) > 0 && $this->available($p) <= $p->alert_qty)->values();
        $out = $products->filter(fn ($p) => $this->available($p) === 0)->values();
        $negative = $products->filter(fn ($p) => $this->available($p) < 0)->values();
        $healthy = $products->filter(fn ($p) => $this->available($p) > $p->alert_qty)->values();

        $since = now()->subDays(7);
        $moves = StockLedger::where('business_id', $biz)->where('created_at', '>=', $since);
        $sold = (clone $moves)->where('type', 'ship_out')->sum('qty');
        $purchased = (clone $moves)->whereIn('type', ['adjust_in'])->sum('qty');
        $returns = (clone $moves)->where('type', 'return_in')->sum('qty');
        $moveCount = (clone $moves)->count();

        $attention = [];
        foreach ($out as $p) {
            $attention[] = ['kind' => 'out', 'text' => "{$p->name} is out of stock", 'product_id' => $p->id];
        }
        foreach ($negative as $p) {
            $attention[] = ['kind' => 'negative', 'text' => "{$p->name} has negative stock ({$this->available($p)})", 'product_id' => $p->id];
        }
        foreach ($low as $p) {
            $attention[] = ['kind' => 'low', 'text' => "{$p->name} is low — only {$this->available($p)} left", 'product_id' => $p->id];
        }
        $overdue = WarrantyClaim::where('business_id', $biz)
            ->whereNotIn('status', ['closed', 'rejected', 'cancelled'])
            ->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count();
        if ($overdue > 0) {
            $attention[] = ['kind' => 'warranty', 'text' => "{$overdue} warranty claim(s) overdue", 'product_id' => null];
        }

        // Smart Restock: 30-day sales velocity per product
        $sold30 = StockLedger::where('business_id', $biz)->where('type', 'ship_out')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('product_id', DB::raw('SUM(qty) as total'))->groupBy('product_id')
            ->pluck('total', 'product_id');
        $restock = $products->map(function ($p) use ($sold30) {
            $s = (int) ($sold30[$p->id] ?? 0);
            return [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'available' => $this->available($p), 'alert_qty' => $p->alert_qty,
                'sold_30d' => $s,
                'speed' => $s >= 20 ? 'Fast Moving' : ($s > 0 ? 'Slow Moving' : 'No Sales'),
                'suggested' => max(0, ($p->alert_qty * 2) - $this->available($p)),
            ];
        })->sortByDesc('sold_30d')->values();

        $branches = Branch::where('business_id', $biz)->where('is_active', true)->get();
        $branchStock = BranchStock::where('business_id', $biz)->get()
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['product_id' => $r->product_id, 'qty' => $r->qty])->values());

        return Inertia::render('Inventory/Dashboard', [
            'health' => [
                'total' => $products->count(),
                'healthy' => $healthy->count(),
                'low' => $low->count(),
                'out' => $out->count(),
                'negative' => $negative->count(),
            ],
            'movement' => [
                'sold' => (int) $sold,
                'purchased' => (int) $purchased,
                'returns' => (int) $returns,
                'net' => (int) ($purchased + $returns - $sold),
                'count' => (int) $moveCount,
            ],
            'attention' => $attention,
            'lowStock' => $low->map(fn ($p) => $this->productRow($p))->values(),
            'outStock' => $out->map(fn ($p) => $this->productRow($p))->values(),
            'allProducts' => $products->map(fn ($p) => $this->productRow($p))->values(),
            'restock' => $restock,
            'branches' => $branches,
            'branchStock' => $branchStock,
            'branchStockEnabled' => (bool) $this->setting('inventory.branch_stock_enabled', false),
            'categories' => ProductCategory::where('business_id', $biz)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ---------------- Products ----------------

    public function products(Request $request)
    {
        $biz = $this->biz($request);
        $q = Product::where('business_id', $biz);
        if ($s = $request->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }
        $products = $q->orderBy('name')->paginate(20)->withQueryString();

        $all = Product::where('business_id', $biz)->get();
        $categories = ProductCategory::where('business_id', $biz)->orderBy('name')->get();
        $brands = ProductBrand::where('business_id', $biz)->orderBy('name')->get();

        $combos = $all->where('type', 'combo')->map(function ($p) {
            $comps = ComboComponent::where('combo_product_id', $p->id)->get()
                ->map(fn ($c) => ['product_id' => $c->component_product_id, 'qty' => $c->qty]);
            return array_merge($this->productRow($p), ['components' => $comps]);
        })->values();

        $shared = SharedInventoryGroup::where('business_id', $biz)->get()->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name,
            'product_ids' => DB::table('shared_inventory_members')->where('group_id', $g->id)->pluck('product_id'),
        ]);
        $links = StockLinkGroup::where('business_id', $biz)->get()->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name,
            'product_ids' => DB::table('stock_link_members')->where('group_id', $g->id)->pluck('product_id'),
        ]);
        $variants = ProductVariant::where('business_id', $biz)->get()->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => $v->price, 'cost' => $v->cost, 'stock_qty' => $v->stock_qty])->values());

        return Inertia::render('Inventory/Products', [
            'products' => $products->through(fn ($p) => $this->productRow($p)),
            'summary' => [
                'products' => $all->count(),
                'categories' => $categories->count(),
                'total_stock' => (int) $all->sum('stock_qty'),
                'est_value' => $all->sum(fn ($p) => $p->stock_qty * (float) $p->cost),
                'sell_value' => $all->sum(fn ($p) => $p->stock_qty * (float) $p->price),
                'cost_value' => $all->sum(fn ($p) => $p->stock_qty * (float) $p->cost),
                'out_of_stock' => $all->filter(fn ($p) => $this->available($p) <= 0)->count(),
            ],
            'inactive' => $all->where('is_active', false)->map(fn ($p) => $this->productRow($p))->values(),
            'combos' => $combos,
            'wraps' => $all->where('type', 'wrap')->map(fn ($p) => $this->productRow($p))->values(),
            'sharedGroups' => $shared,
            'linkGroups' => $links,
            'variants' => $variants,
            'categories' => $categories,
            'brands' => $brands,
            'filters' => ['search' => $request->query('search', '')],
        ]);
    }

    public function createProduct(Request $request)
    {
        $biz = $this->biz($request);

        return Inertia::render('Inventory/AddProduct', [
            'categories' => ProductCategory::where('business_id', $biz)->orderBy('name')->get(),
            'brands' => ProductBrand::where('business_id', $biz)->orderBy('name')->get(),
            'allProducts' => Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku']),
            'businessName' => $request->user()->business?->name,
        ]);
    }

    private function productData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer'],
            'alert_qty' => ['nullable', 'integer', 'min:0'],
            'manage_stock' => ['boolean'],
            'featured' => ['boolean'],
            'is_active' => ['boolean'],
            'type' => ['nullable', 'string', 'in:single,combo,wrap'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:kg,g'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'shipping_note' => ['nullable', 'string'],
            'admin_note' => ['nullable', 'string'],
            'warranty_policy' => ['nullable', 'string', 'max:255'],
            'warranty_coverage' => ['nullable', 'string', 'max:255'],
            'gallery' => ['nullable', 'array'],
            'image_url' => ['nullable', 'string', 'max:1024'],
        ]);
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        } elseif (! empty($data['image_url'])) {
            $data['image'] = $data['image_url'];
        }
        unset($data['image_url']);

        return $data;
    }

    public function storeProduct(Request $request)
    {
        $biz = $this->biz($request);
        $data = $this->productData($request);
        $data['business_id'] = $biz;
        $data['sku'] = $data['sku'] ?? ('PRODUCT-AUTO-' . strtoupper(substr(uniqid(), -6)));
        $initial = (int) ($data['stock_qty'] ?? 0);
        $data['stock_qty'] = 0;
        $product = Product::create($data);
        if ($initial !== 0) {
            $this->stock->adjust($product, $initial, 'Initial Stock', 'Opening stock on product creation', $request->user());
            $product->refresh();
        }
        // opening stock sits in the default branch when branch stock is used
        $defaultBranch = Branch::where('business_id', $biz)->where('is_active', true)->orderBy('id')->first();
        if ($defaultBranch && $product->stock_qty > 0) {
            BranchStock::updateOrCreate(
                ['branch_id' => $defaultBranch->id, 'product_id' => $product->id],
                ['business_id' => $biz, 'qty' => $product->stock_qty]
            );
        }
        if ($request->input('mode') === 'variants') {
            foreach ((array) $request->input('variants', []) as $v) {
                if (! empty($v['name'])) {
                    ProductVariant::create(['business_id' => $biz, 'product_id' => $product->id,
                        'name' => $v['name'], 'sku' => $v['sku'] ?? null, 'price' => $v['price'] ?? null,
                        'cost' => $v['cost'] ?? null, 'stock_qty' => (int) ($v['stock_qty'] ?? 0)]);
                }
            }
        }
        if ($product->type === 'combo') {
            foreach ((array) $request->input('components', []) as $c) {
                if (! empty($c['product_id'])) {
                    ComboComponent::create(['business_id' => $biz, 'combo_product_id' => $product->id,
                        'component_product_id' => (int) $c['product_id'], 'qty' => (int) ($c['qty'] ?? 1)]);
                }
            }
        }

        return redirect()->route('inventory.products')->with('success', 'Product created');
    }

    /** CSV import: header row name,sku,price,cost,stock_qty — creates products with an opening-stock ledger row. */
    public function importProducts(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $biz = $this->biz($request);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = null;
        $created = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim($h)), $row);
                continue;
            }
            $rec = array_combine($header, array_pad($row, count($header), null));
            if (empty($rec['name'])) {
                continue;
            }
            $product = Product::create([
                'business_id' => $biz,
                'name' => $rec['name'],
                'sku' => $rec['sku'] ?: ('PRODUCT-AUTO-' . strtoupper(substr(uniqid(), -6))),
                'price' => (float) ($rec['price'] ?? 0),
                'cost' => (float) ($rec['cost'] ?? 0),
                'stock_qty' => 0,
                'alert_qty' => 5,
                'manage_stock' => true,
                'is_active' => true,
            ]);
            $qty = (int) ($rec['stock_qty'] ?? 0);
            if ($qty !== 0) {
                $this->stock->adjust($product, $qty, 'Import', 'Opening stock from CSV import', $request->user());
            }
            $created++;
        }
        fclose($handle);

        return back()->with('success', "Imported {$created} product(s)");
    }

    public function updateProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $data = $this->productData($request);
        unset($data['stock_qty']); // stock changes go through Adjust, never the edit form
        $product->update($data);

        return back()->with('success', 'Product updated');
    }

    public function destroyProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $product->delete();

        return back()->with('success', 'Product deleted');
    }

    public function copyProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $copy = $product->replicate();
        $copy->name = $product->name . ' (Copy)';
        $copy->sku = $product->sku . '-COPY';
        $copy->stock_qty = 0;
        $copy->reserved_qty = 0;
        $copy->save();

        return back()->with('success', 'Product copied');
    }

    public function toggleProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $field = $request->input('field');
        abort_unless(in_array($field, ['is_active', 'manage_stock', 'featured'], true), 422);
        $product->update([$field => ! $product->{$field}]);

        return back();
    }

    public function adjustStock(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->stock->adjust($product, $data['delta'], $data['reason'] ?? 'Manual Adjustment', $data['note'] ?? null, $request->user());

        return back()->with('success', 'Stock adjusted');
    }

    // ---------------- Taxonomy / variants / groups ----------------

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'parent_id' => ['nullable', 'integer']]);
        ProductCategory::create(['business_id' => $this->biz($request)] + $data);

        return back()->with('success', 'Category created');
    }

    public function destroyCategory(Request $request, ProductCategory $category)
    {
        abort_unless($category->business_id === $this->biz($request), 403);
        Product::where('category_id', $category->id)->update(['category_id' => null]);
        $category->delete();

        return back()->with('success', 'Category deleted');
    }

    public function storeBrand(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        ProductBrand::create(['business_id' => $this->biz($request)] + $data);

        return back()->with('success', 'Brand created');
    }

    public function destroyBrand(Request $request, ProductBrand $brand)
    {
        abort_unless($brand->business_id === $this->biz($request), 403);
        Product::where('brand_id', $brand->id)->update(['brand_id' => null]);
        $brand->delete();

        return back()->with('success', 'Brand deleted');
    }

    public function storeVariant(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer'],
        ]);
        ProductVariant::create(['business_id' => $product->business_id, 'product_id' => $product->id] + $data);

        return back()->with('success', 'Variant added');
    }

    public function destroyVariant(Request $request, ProductVariant $variant)
    {
        abort_unless($variant->business_id === $this->biz($request), 403);
        $variant->delete();

        return back()->with('success', 'Variant deleted');
    }

    public function storeGroup(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['shared', 'links'], true), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_ids' => ['required', 'array', 'min:2'],
        ]);
        $biz = $this->biz($request);
        $model = $kind === 'shared' ? SharedInventoryGroup::class : StockLinkGroup::class;
        $memberTable = $kind === 'shared' ? 'shared_inventory_members' : 'stock_link_members';
        $group = $model::create(['business_id' => $biz, 'name' => $data['name']]);
        foreach ($data['product_ids'] as $pid) {
            DB::table($memberTable)->insert(['group_id' => $group->id, 'product_id' => (int) $pid, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', 'Group created');
    }

    public function destroyGroup(Request $request, string $kind, int $group)
    {
        abort_unless(in_array($kind, ['shared', 'links'], true), 404);
        $model = $kind === 'shared' ? SharedInventoryGroup::class : StockLinkGroup::class;
        $memberTable = $kind === 'shared' ? 'shared_inventory_members' : 'stock_link_members';
        $row = $model::where('business_id', $this->biz($request))->findOrFail($group);
        DB::table($memberTable)->where('group_id', $row->id)->delete();
        $row->delete();

        return back()->with('success', 'Group deleted');
    }
}

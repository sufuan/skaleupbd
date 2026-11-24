<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ComboComponent;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockLedger;
use App\Models\WarrantyClaim;
use App\Support\DhakaTime;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class InventoryController extends Controller
{
    public function __construct(private StockService $stock, private readonly \App\Services\SettingsService $settings) {}

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    private function available(Product $p): int
    {
        return $p->available();
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
            'offer_price' => $p->offer_price,
            'offer_starts_at' => $p->offer_starts_at?->format('Y-m-d'),
            'offer_ends_at' => $p->offer_ends_at?->format('Y-m-d'),
            'aliases' => $p->aliases,
            'max_discount_pct' => $p->max_discount_pct,
            'ai_score' => $p->aiReadiness()['score'],
            'ai_missing' => $p->aiReadiness()['missing'],
            'stock_qty' => $p->stock_qty,
            'reserved_qty' => $p->reserved_qty,
            'available' => $this->available($p),
            'is_active' => (bool) $p->is_active,
            'manage_stock' => (bool) $p->manage_stock,
            'alert_qty' => $p->alert_qty,
            'featured' => (bool) $p->featured,
            'type' => $p->type,
            'image' => $p->imageUrl(),
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
            $attention[] = ['kind' => 'out', 'text' => __(':product is out of stock', ['product' => $p->name]), 'product_id' => $p->id];
        }
        foreach ($negative as $p) {
            $attention[] = ['kind' => 'negative', 'text' => __(':product has negative stock (:count)', ['product' => $p->name, 'count' => $this->available($p)]), 'product_id' => $p->id];
        }
        foreach ($low as $p) {
            $attention[] = ['kind' => 'low', 'text' => __(':product is low - only :count left', ['product' => $p->name, 'count' => $this->available($p)]), 'product_id' => $p->id];
        }
        $overdue = WarrantyClaim::where('business_id', $biz)
            ->whereNotIn('status', ['closed', 'rejected', 'cancelled'])
            ->whereNotNull('due_date')->where('due_date', '<', DhakaTime::today())->count();
        if ($overdue > 0) {
            $attention[] = ['kind' => 'warranty', 'text' => __(':count warranty claim(s) overdue', ['count' => $overdue]), 'product_id' => null];
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
            'branchStockEnabled' => (bool) $this->settings->bizGet($biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', false),
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

        $variants = ProductVariant::where('business_id', $biz)->get()->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => $v->price, 'cost' => $v->cost, 'stock_qty' => $v->stock_qty])->values());

        return Inertia::render('Inventory/Products', [
            'products' => $products->through(fn ($p) => $this->productRow($p)),
            // Header totals describe the ACTIVE catalogue - the same set
            // the order/purchase pickers and the storefront use (Hunt
            // G-5). Inactive products stay listed and managed below, but
            // their stock must not inflate the totals a seller acts on.
            'summary' => [
                'products' => $all->where('is_active', true)->count(),
                'categories' => $categories->count(),
                'total_stock' => (int) $all->where('is_active', true)->sum('stock_qty'),
                'est_value' => $all->where('is_active', true)->sum(fn ($p) => $p->stock_qty * (float) $p->cost),
                'sell_value' => $all->where('is_active', true)->sum(fn ($p) => $p->stock_qty * (float) $p->price),
                'cost_value' => $all->where('is_active', true)->sum(fn ($p) => $p->stock_qty * (float) $p->cost),
                'out_of_stock' => $all->where('is_active', true)->filter(fn ($p) => $this->available($p) <= 0)->count(),
            ],
            'inactive' => $all->where('is_active', false)->map(fn ($p) => $this->productRow($p))->values(),
            'combos' => $combos,
            'wraps' => $all->where('type', 'wrap')->map(fn ($p) => $this->productRow($p))->values(),
            'variants' => $variants,
            'categories' => $categories,
            'brands' => $brands,
            // Full catalogue (id/name/sku) for the Edit form's combo
            // components picker - the paginated table rows are not all
            // products, and the picker must offer every one of them.
            'allProducts' => $all->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku])->values(),
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
            'offer_price' => ['nullable', 'numeric', 'min:0'],
            'offer_starts_at' => ['nullable', 'date'],
            'offer_ends_at' => ['nullable', 'date'],
            'aliases' => ['nullable', 'string', 'max:500'],
            'max_discount_pct' => ['nullable', 'integer', 'min:0', 'max:90'],
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
        // These columns are NOT NULL in the schema: an emptied form field
        // arrives as null and must fall back to the column default instead
        // of failing the insert/update with a constraint error.
        foreach (['cost' => 0, 'alert_qty' => 5, 'type' => 'single', 'weight_unit' => 'kg'] as $field => $fallback) {
            if (array_key_exists($field, $data) && ($data[$field] === null || $data[$field] === '')) {
                $data[$field] = $fallback;
            }
        }
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
                if (! empty($c['product_id']) && (int) $c['product_id'] !== $product->id) {
                    ComboComponent::create(['business_id' => $biz, 'combo_product_id' => $product->id,
                        'component_product_id' => (int) $c['product_id'], 'qty' => max(1, (int) ($c['qty'] ?? 1))]);
                }
            }
        }

        return redirect()->route('inventory.products')->with('success', 'Product created');
    }

    /** Bulk import/export sheet columns, in template order (A..K). */
    private const PRODUCT_SHEET_COLUMNS = ['name', 'sku', 'price', 'cost', 'stock_qty', 'category', 'brand', 'alert_qty', 'description', 'image_url', 'image_name'];

    /**
     * Bulk import (xlsx / xls / csv): a row whose SKU matches an existing
     * product of this business UPDATES it, otherwise a new product is made.
     * Stock always moves through StockService::adjust (create = opening
     * stock, update = the delta to the sheet's target). Photos resolve in
     * the owner's order: image_url download, then image_name inside
     * public/import-images/, then an automatic {SKU}.jpg probe of that
     * folder (with {SKU}_1 / {SKU}_2 as gallery photos). A bad row or a
     * failed photo never aborts the file.
     */
    public function importProducts(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240']]);
        $biz = $this->biz($request);
        $user = $request->user();
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = in_array($ext, ['xlsx', 'xls'], true)
            ? $this->sheetRecords($file->getRealPath())
            : $this->csvRecords($file->getRealPath());

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $photoNotes = [];
        $catCache = [];
        $brandCache = [];
        $rowNo = 1; // the header is row 1
        foreach ($rows as $rec) {
            $rowNo++;
            $name = trim((string) ($rec['name'] ?? ''));
            if ($name === '') {
                $skipped++;
                $errors[] = "Row {$rowNo}: name is missing";
                continue;
            }
            $nums = [];
            $badField = null;
            $badNegative = false;
            foreach (['price', 'cost', 'stock_qty', 'alert_qty'] as $f) {
                $v = $rec[$f] ?? null;
                if ($v === null || trim((string) $v) === '') {
                    $nums[$f] = null;
                    continue;
                }
                $clean = str_replace([',', ' '], '', trim((string) $v));
                if (! is_numeric($clean)) {
                    $badField = $f;
                    break;
                }
                // Same minimums as the single-product form (min:0): a
                // negative price, cost, stock or alert quantity must never
                // import (Hunt G-2).
                if ($clean + 0 < 0) {
                    $badField = $f;
                    $badNegative = true;
                    break;
                }
                $nums[$f] = $clean + 0;
            }
            if ($badField !== null) {
                $skipped++;
                $errors[] = $badNegative
                    ? "Row {$rowNo}: {$badField} cannot be negative"
                    : "Row {$rowNo}: {$badField} is not a number";
                continue;
            }

            $sku = trim((string) ($rec['sku'] ?? ''));
            $desc = trim((string) ($rec['description'] ?? ''));
            $catId = $this->findOrCreateNamed(ProductCategory::class, $biz, trim((string) ($rec['category'] ?? '')), $catCache);
            $brandId = $this->findOrCreateNamed(ProductBrand::class, $biz, trim((string) ($rec['brand'] ?? '')), $brandCache);
            $existing = $sku !== ''
                ? Product::where('business_id', $biz)->where('sku', $sku)->first()
                : null;

            if ($existing === null) {
                $product = Product::create([
                    'business_id' => $biz,
                    'name' => $name,
                    'sku' => $sku !== '' ? $sku : ('PRODUCT-AUTO-' . strtoupper(substr(uniqid(), -6))),
                    'price' => $nums['price'] ?? 0,
                    'cost' => $nums['cost'] ?? 0,
                    'stock_qty' => 0,
                    'alert_qty' => $nums['alert_qty'] !== null ? (int) $nums['alert_qty'] : 5,
                    'manage_stock' => true,
                    'is_active' => true,
                    'category_id' => $catId,
                    'brand_id' => $brandId,
                    'short_description' => $desc !== '' ? $desc : null,
                ]);
                $created++;
                if ($nums['stock_qty'] !== null && (int) $nums['stock_qty'] !== 0) {
                    $this->stock->adjust($product, (int) $nums['stock_qty'], 'Import', 'Opening stock from import', $user);
                    $product->refresh();
                }
            } else {
                $product = $existing;
                $fields = ['name' => $name];
                if ($nums['price'] !== null) {
                    $fields['price'] = $nums['price'];
                }
                if ($nums['cost'] !== null) {
                    $fields['cost'] = $nums['cost'];
                }
                if ($nums['alert_qty'] !== null) {
                    $fields['alert_qty'] = (int) $nums['alert_qty'];
                }
                if ($desc !== '') {
                    $fields['short_description'] = $desc;
                }
                if ($catId !== null) {
                    $fields['category_id'] = $catId;
                }
                if ($brandId !== null) {
                    $fields['brand_id'] = $brandId;
                }
                $product->update($fields);
                $updated++;
                if ($nums['stock_qty'] !== null) {
                    $delta = (int) $nums['stock_qty'] - (int) $product->stock_qty;
                    if ($delta !== 0) {
                        $this->stock->adjust($product, $delta, 'Import', 'Stock set by import', $user);
                        $product->refresh();
                    }
                }
            }

            $photoIssue = $this->importRowImage($product, $rec);
            if ($photoIssue !== null) {
                $photoNotes[] = "Row {$rowNo}: {$photoIssue}";
            }
        }

        $total = $created + $updated;
        $msg = "Imported {$total} product(s): {$created} new, {$updated} updated, {$skipped} skipped";
        if ($errors !== []) {
            $msg .= '. Problems: ' . implode('; ', array_slice($errors, 0, 3));
        }
        if ($photoNotes !== []) {
            $msg .= '. Photos: ' . implode('; ', array_slice($photoNotes, 0, 2));
        }

        return $total === 0 && $skipped > 0
            ? back()->with('error', $msg)
            : back()->with('success', $msg);
    }

    /** @param array<string, int> $cache */
    private function findOrCreateNamed(string $model, int $biz, string $name, array &$cache): ?int
    {
        if ($name === '') {
            return null;
        }
        $key = strtolower($name);
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $model::firstOrCreate(['business_id' => $biz, 'name' => $name])->id;
        }

        return $cache[$key];
    }

    /** Read a CSV/TXT file into header-keyed records (first row = headers, case-insensitive). */
    private function csvRecords(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $this->recordsFromRows($rows);
    }

    /** Read an xlsx/xls file into header-keyed records; formulas are calculated so the owner's image_url formula resolves to a link. */
    private function sheetRecords(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();

        return $this->recordsFromRows($sheet->toArray(null, true, false));
    }

    private function recordsFromRows(array $rows): array
    {
        $header = null;
        $out = [];
        foreach ($rows as $row) {
            $row = array_values((array) $row);
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $row);
                if (isset($header[0])) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                }
                continue;
            }
            $blank = true;
            foreach ($row as $v) {
                if (trim((string) $v) !== '') {
                    $blank = false;
                    break;
                }
            }
            if ($blank) {
                continue;
            }
            $rec = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $rec[$key] = $row[$i] ?? null;
                }
            }
            $out[] = $rec;
        }

        return $out;
    }

    /**
     * Resolve one import row's photo, in the owner's order. Returns a plain
     * problem note when a named photo could not be used, null otherwise.
     * The stored value mirrors the manual upload: a relative path on the
     * public disk under products/ (the manual form's ->store('products',
     * 'public')), and gallery entries are stored paths in the same folder.
     */
    private function importRowImage(Product $product, array $rec): ?string
    {
        $url = trim((string) ($rec['image_url'] ?? ''));
        $name = trim((string) ($rec['image_name'] ?? ''));
        $issue = null;

        if ($url !== '') {
            $path = $this->storeRemoteImage($url);
            if ($path !== null) {
                $product->update(['image' => $path]);

                return null;
            }
            $issue = 'photo link could not be downloaded';
        }
        if ($name !== '') {
            $path = $this->copyImportImage(basename($name));
            if ($path !== null) {
                $product->update(['image' => $path]);

                return null;
            }
            $issue ??= 'photo file was not found in the import-images folder';
        }

        $sku = trim((string) $product->sku);
        if ($sku !== '') {
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                $path = $this->copyImportImage($sku . '.' . $ext);
                if ($path === null) {
                    continue;
                }
                $product->update(['image' => $path]);
                $gallery = is_array($product->gallery) ? $product->gallery : [];
                foreach (['_1', '_2'] as $suffix) {
                    $extra = $this->copyImportImage($sku . $suffix . '.' . $ext);
                    if ($extra !== null && ! in_array($extra, $gallery, true)) {
                        $gallery[] = $extra;
                    }
                }
                if ($gallery !== (is_array($product->gallery) ? $product->gallery : [])) {
                    $product->update(['gallery' => $gallery]);
                }

                return null;
            }
        }

        return $issue;
    }

    /** Download a photo link and store it like a manual product upload. Null on any failure. */
    private function storeRemoteImage(string $url): ?string
    {
        try {
            $resp = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        $body = $resp->body();
        if ($body === '' || strlen($body) > 10 * 1024 * 1024) {
            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $resp->header('Content-Type'))[0]));
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if ($ext === null) {
            $urlExt = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($urlExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $ext = $urlExt === 'jpeg' ? 'jpg' : $urlExt;
            }
        }
        if ($ext === null || @getimagesizefromstring($body) === false) {
            return null;
        }
        $path = 'products/' . Str::random(40) . '.' . $ext;
        Storage::disk('public')->put($path, $body);

        return $path;
    }

    /** Copy one file out of public/import-images/ into product storage. Null when absent or not an image file. */
    private function copyImportImage(string $filename): ?string
    {
        $filename = basename($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return null;
        }
        $full = public_path('import-images/' . $filename);
        if (! is_file($full)) {
            return null;
        }
        $contents = @file_get_contents($full);
        if ($contents === false || $contents === '') {
            return null;
        }
        $path = 'products/' . Str::random(40) . '.' . $ext;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    /** Export every product of the business in the template column order, as xlsx or csv. */
    public function exportProducts(Request $request)
    {
        $biz = $this->biz($request);
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $cats = ProductCategory::where('business_id', $biz)->pluck('name', 'id');
        $brands = ProductBrand::where('business_id', $biz)->pluck('name', 'id');

        $rows = [self::PRODUCT_SHEET_COLUMNS];
        foreach (Product::where('business_id', $biz)->orderBy('id')->get() as $p) {
            $imageUrl = '';
            $imageName = '';
            if (! empty($p->image)) {
                $imageName = basename((string) parse_url($p->image, PHP_URL_PATH));
                $imageUrl = str_starts_with($p->image, 'http') ? $p->image : url('/storage/' . ltrim($p->image, '/'));
            }
            $rows[] = [
                $p->name, $p->sku, $p->price, $p->cost, $p->stock_qty,
                $p->category_id ? ($cats[$p->category_id] ?? '') : '',
                $p->brand_id ? ($brands[$p->brand_id] ?? '') : '',
                $p->alert_qty, $p->short_description ?? '', $imageUrl, $imageName,
            ];
        }

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                foreach ($rows as $r) {
                    fputcsv($out, $r);
                }
                fclose($out);
            }, 'products.csv', ['Content-Type' => 'text/csv']);
        }

        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($rows, null, 'A1');

        return $this->xlsxDownload($ss, 'products.xlsx');
    }

    /** Blank import template: headers plus two example rows; J2 carries the owner's image_url formula. */
    public function templateProducts(Request $request)
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([self::PRODUCT_SHEET_COLUMNS], null, 'A1');
        $sheet->fromArray(['Cotton Panjabi', 'SP-001', 1250, 800, 50, 'Panjabi', '', 5, 'Soft cotton panjabi', null, ''], null, 'A2');
        // The photo link builds itself from the SKU cell — no URL is typed by hand.
        $sheet->setCellValue('J2', '="https://scalenowbd.com/import-images/"&B2&".jpg"');
        $sheet->fromArray(['Silk Saree', 'SP-002', 3400, 2200, 30, 'Saree', '', 5, '', '', 'SP-002.jpg'], null, 'A3');
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setWidth(in_array($col, ['A', 'I', 'J'], true) ? 30 : 14);
        }

        return $this->xlsxDownload($ss, 'product-import-template.xlsx');
    }

    private function xlsxDownload(Spreadsheet $ss, string $filename)
    {
        return response()->streamDownload(function () use ($ss) {
            (new XlsxWriter($ss))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function updateProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        $data = $this->productData($request);
        unset($data['stock_qty']); // stock changes go through Adjust, never the edit form
        $product->update($data);

        // Combo recipe (K-M3): the product form's components picker sends
        // components[] together with components_present=1 - only then is
        // the recipe replaced, so an edit that never touches the picker
        // (or an older form) leaves the existing recipe alone. Same
        // acceptance shape as storeProduct().
        if ($product->type === 'combo' && $request->boolean('components_present')) {
            ComboComponent::where('combo_product_id', $product->id)->delete();
            foreach ((array) $request->input('components', []) as $c) {
                if (! empty($c['product_id']) && (int) $c['product_id'] !== $product->id) {
                    ComboComponent::create(['business_id' => $product->business_id, 'combo_product_id' => $product->id,
                        'component_product_id' => (int) $c['product_id'], 'qty' => max(1, (int) ($c['qty'] ?? 1))]);
                }
            }
        }

        return back()->with('success', 'Product updated');
    }

    public function destroyProduct(Request $request, Product $product)
    {
        abort_unless($product->business_id === $this->biz($request), 403);
        // Guard (Hunt G-1): deleting a product cascades away its whole
        // stock ledger and nulls its order items, so a product with any
        // history - or stock still reserved on a live order - must never
        // be hard-deleted. Same honesty as the purchase delete guard:
        // refuse and point the seller to deactivate instead.
        $hasHistory = (int) $product->reserved_qty > 0
            || \App\Models\OrderItem::where('product_id', $product->id)->exists()
            || StockLedger::where('product_id', $product->id)->exists();
        if ($hasHistory) {
            return back()->with('error', __('This product cannot be deleted - it has orders, stock history or reserved stock. Deactivate it instead.'));
        }
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
        // A cleared stock box arrives as null, but the column is NOT NULL
        // (same family as the product cost fix) — an omitted stock is 0.
        $data['stock_qty'] = $data['stock_qty'] ?? 0;
        ProductVariant::create(['business_id' => $product->business_id, 'product_id' => $product->id] + $data);

        return back()->with('success', 'Variant added');
    }

    public function destroyVariant(Request $request, ProductVariant $variant)
    {
        abort_unless($variant->business_id === $this->biz($request), 403);
        $variant->delete();

        return back()->with('success', 'Variant deleted');
    }

}

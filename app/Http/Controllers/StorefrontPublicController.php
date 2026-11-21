<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Storefront;
use App\Models\StorefrontReport;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use App\Services\StorefrontService;
use App\Services\StorefrontTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * M18 — the public storefront: home (blocks), product pages, checkout,
 * success and the report form. Only ever reached on a tenant host (the
 * ResolveStorefrontHost middleware attaches the storefront); every query
 * is scoped to that shop's business.
 */
class StorefrontPublicController extends Controller
{
    public function __construct(
        private readonly StorefrontService $svc,
        private readonly OrderService $orders,
        private readonly StorefrontTrackingService $tracking,
    ) {}

    private function sf(Request $request): Storefront
    {
        $sf = $request->attributes->get('storefront');
        abort_unless($sf instanceof Storefront, 404);

        return $sf;
    }

    // ------------------------------------------------------------------ pages

    public function home(Request $request)
    {
        $sf = $this->sf($request);
        if (! $sf->isPublished()) {
            return $this->unavailable($sf);
        }
        $host = $this->svc->normalizeHost($request->getHost());
        $html = $this->cachedHtml($sf, $host, '/', fn () => view('storefront.home', $this->homeData($request, $sf))->render());

        return response($html)->header('Content-Type', 'text/html');
    }

    public function product(Request $request, int $product)
    {
        $sf = $this->sf($request);
        if (! $sf->isPublished()) {
            return $this->unavailable($sf);
        }
        $p = Product::where('business_id', $sf->business_id)->where('is_active', true)->find($product);
        abort_unless($p, 404);
        $host = $this->svc->normalizeHost($request->getHost());
        $html = $this->cachedHtml($sf, $host, '/p/'.$p->id, fn () => view('storefront.product', [
            'sf' => $sf,
            'brand' => $this->brand($sf),
            'product' => $this->productVm($p),
            'districts' => StorefrontService::districts(),
            'messengerUrl' => $this->messengerUrl($sf, $p->id),
        ])->render());

        return response($html)->header('Content-Type', 'text/html');
    }

    public function success(Request $request, string $invoice)
    {
        $sf = $this->sf($request);
        $order = Order::where('business_id', $sf->business_id)->where('invoice_no', $invoice)->first();
        abort_unless($order, 404);

        return view('storefront.success', [
            'sf' => $sf,
            'brand' => $this->brand($sf),
            'order' => $order,
            'purchaseEventId' => (string) $request->query('pe', ''),
            // This shop's OWN payment numbers (seller scope) — never
            // another shop's, never the platform's.
            'advanceNumbers' => app(\App\Services\PaymentGatewayService::class)->enabledNumbers((int) $sf->business_id),
        ]);
    }

    public function reportForm(Request $request)
    {
        $sf = $this->sf($request);

        return view('storefront.report', ['sf' => $sf, 'brand' => $this->brand($sf)]);
    }

    public function reportStore(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:120'],
            'page_url' => ['nullable', 'string', 'max:300'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot
        ]);
        StorefrontReport::create([
            'storefront_id' => $sf->id,
            'business_id' => $sf->business_id,
            'reason' => $data['reason'],
            'contact' => $data['contact'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'status' => 'open',
        ]);

        return redirect('/?reported=1');
    }

    // ------------------------------------------------------------------ checkout

    public function order(Request $request)
    {
        $sf = $this->sf($request);
        if (! $sf->isPublished()) {
            return $this->unavailable($sf);
        }
        // Honeypot: a filled invisible field = a bot. Pretend all is well.
        if (trim((string) $request->input('website', '')) !== '') {
            return redirect('/');
        }
        $ipKey = 'sf-order-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return $this->orderError($request, 'Too many order attempts. Please wait a minute and try again.');
        }
        RateLimiter::hit($ipKey, 60);

        try {
            $data = $request->validate([
                'customer_name' => ['required', 'string', 'max:120'],
                'customer_phone' => ['required', 'string', 'max:20'],
                'customer_address' => ['required', 'string', 'min:10', 'max:500'],
                'district' => ['required', 'string', 'max:60'],
                'product_id' => ['required', 'integer'],
                'qty' => ['nullable', 'integer', 'min:1', 'max:20'],
                'utm_source' => ['nullable', 'string', 'max:120'],
                'utm_medium' => ['nullable', 'string', 'max:120'],
                'utm_campaign' => ['nullable', 'string', 'max:120'],
                'fbclid' => ['nullable', 'string', 'max:255'],
                'ic_event_id' => ['nullable', 'string', 'max:80'],
                'purchase_event_id' => ['nullable', 'string', 'max:80'],
            ]);
        } catch (ValidationException $e) {
            return $this->orderError($request, collect($e->errors())->flatten()->first() ?? 'Please check the form and try again.');
        }
        if (! in_array($data['district'], StorefrontService::districts(), true)) {
            return $this->orderError($request, 'Please choose your district.');
        }
        $phone = PhoneNormalizer::normalize($data['customer_phone']);
        $phoneKey = 'sf-order-phone:'.$phone;
        if (RateLimiter::tooManyAttempts($phoneKey, 5)) {
            return $this->orderError($request, 'Too many orders from this phone number. Please try again later.');
        }

        // Cross-shop guard + live price/stock: the product must be THIS
        // shop's, and the price is always recomputed from the database.
        $product = Product::where('business_id', $sf->business_id)->where('is_active', true)->find($data['product_id']);
        if (! $product) {
            return $this->orderError($request, 'This product is not available in this shop.');
        }
        $qty = (int) ($data['qty'] ?? 1);
        if ($product->manage_stock && ((int) $product->stock_qty - (int) $product->reserved_qty) < $qty) {
            return $this->orderError($request, 'Sorry, this product is out of stock right now.');
        }
        RateLimiter::hit($phoneKey, 3600);

        $price = $product->effectivePrice();
        $delivery = $data['district'] === 'Dhaka' ? $sf->deliveryInside() : $sf->deliveryOutside();
        $host = $this->svc->normalizeHost($request->getHost());
        $pageUrl = $request->getScheme().'://'.$host.'/p/'.$product->id;

        // Server InitiateCheckout — same event_id the browser fired.
        if (! empty($data['ic_event_id'])) {
            $this->tracking->send($sf, 'InitiateCheckout', $data['ic_event_id'], [
                'currency' => 'BDT', 'value' => $price * $qty,
                'content_ids' => [(string) $product->id], 'content_type' => 'product',
            ], $pageUrl, $request->ip(), $request->userAgent());
        }

        try {
            $order = $this->orders->create($sf->business_id, [
                'customer_name' => $data['customer_name'],
                'customer_phone' => $phone,
                'customer_address' => $data['customer_address'],
                'district' => $data['district'],
                'source' => $this->svc->orderSource($sf->business_id),
                'site' => $host,
                'status' => 'Pending',
                'delivery_charge' => $delivery,
                'ip' => $request->ip(),
                'storefront_id' => $sf->id,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'fbclid' => $data['fbclid'] ?? null,
                'items' => [[
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'qty' => $qty,
                    'price' => $price,
                ]],
            ], null, 'Storefront');
        } catch (InsufficientTokensException) {
            return response()->view('storefront.unable', ['sf' => $sf, 'brand' => $this->brand($sf)], 200);
        }

        AppNotification::create(['kind' => 'order', 'title' => "New order {$order->invoice_no}",
            'body' => "{$order->customer_name} · ৳".number_format((float) $order->grand_total)." · Storefront",
            'link' => "/orders/{$order->id}"]);

        // Server Purchase — same event_id the success page fires in the browser.
        if (! empty($data['purchase_event_id'])) {
            $this->tracking->send($sf, 'Purchase', $data['purchase_event_id'], [
                'currency' => 'BDT', 'value' => (float) $order->grand_total,
                'content_ids' => [(string) $product->id], 'content_type' => 'product',
                'order_id' => $order->invoice_no,
            ], $pageUrl, $request->ip(), $request->userAgent());
        }

        return redirect('/order-success/'.$order->invoice_no.'?pe='.urlencode((string) ($data['purchase_event_id'] ?? '')));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Checkout errors ride a one-shot cookie: the shop pages are render-
     * cached, so session flash errors would never appear in the HTML.
     * The layout JS reads the cookie and shows it inside the order form.
     */
    private function orderError(Request $request, string $message)
    {
        $pid = (int) $request->input('product_id', 0);
        $referer = (string) $request->headers->get('referer', '');
        $path = $referer !== '' ? (parse_url($referer, PHP_URL_PATH) ?: '/') : '/';
        $target = $path.($pid > 0 ? '#order-form-'.$pid : '');

        return redirect($target)->withCookie(cookie('sf_err', $message, 2, '/', null, false, false));
    }

    private function unavailable(Storefront $sf)
    {
        return response()->view('storefront.unavailable', [
            'sf' => $sf,
            'brand' => $this->brand($sf),
            'takenDown' => $sf->status === 'taken_down',
        ], 200);
    }

    /**
     * Render cache: full HTML for 45s, keyed by business + host + path
     * (the storefront's updated_at versions the key, so any edit or
     * publish change is a fresh render). The CSRF token is a placeholder
     * in the cached HTML and is swapped for the visitor's own token.
     */
    private function cachedHtml(Storefront $sf, string $host, string $path, callable $render): string
    {
        $key = 'sfhtml:'.$sf->business_id.':'.$host.':'.$path.':'.($sf->updated_at?->timestamp ?? 0);
        $html = Cache::remember($key, 45, $render);

        return str_replace('%%SF_CSRF%%', csrf_token(), $html);
    }

    public function brand(Storefront $sf): array
    {
        $b = (array) $sf->branding;

        return [
            'name' => (string) ($b['display_name'] ?? 'Shop'),
            'logo' => ! empty($b['logo']) ? '/storage/'.$b['logo'] : null,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['theme_color'] ?? '')) ? $b['theme_color'] : '#411550',
            'pixel' => trim((string) ($b['pixel_id'] ?? '')) ?: null,
            'ga4' => trim((string) ($b['ga4_id'] ?? '')) ?: null,
            'footer' => (string) ($b['footer_text'] ?? ''),
        ];
    }

    public function messengerUrl(Storefront $sf, int $productId): ?string
    {
        $page = $this->svc->facebookPageId($sf->business_id);

        return $page ? 'https://m.me/'.$page.'?ref='.$productId : null;
    }

    public function productVm(Product $p): array
    {
        $availableQty = (int) $p->stock_qty - (int) $p->reserved_qty;
        $images = [];
        if (! empty($p->image)) {
            $images[] = str_starts_with($p->image, 'http') ? $p->image : '/storage/'.$p->image;
        }
        foreach ((array) ($p->gallery ?? []) as $g) {
            $images[] = str_starts_with((string) $g, 'http') ? $g : '/storage/'.$g;
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => (float) $p->price,
            'effective' => $p->effectivePrice(),
            'has_offer' => $p->offerActive(),
            'available' => ! $p->manage_stock || $availableQty > 0,
            'available_qty' => $p->manage_stock ? max(0, $availableQty) : null,
            'image' => $images[0] ?? null,
            'gallery' => $images,
            'short' => (string) ($p->short_description ?? ''),
            'long' => (string) ($p->long_description ?? ''),
        ];
    }

    private function homeData(Request $request, Storefront $sf): array
    {
        $blocks = [];
        foreach ((array) $sf->blocks as $block) {
            $type = $block['type'] ?? '';
            $d = (array) ($block['data'] ?? []);
            $resolved = [];
            if ($type === 'product_grid') {
                $q = Product::where('business_id', $sf->business_id)->where('is_active', true);
                if (($d['source'] ?? 'all') === 'category' && ! empty($d['category_id'])) {
                    $q->where('category_id', (int) $d['category_id']);
                }
                $resolved['products'] = $q->latest('id')->limit((int) ($d['count'] ?? 8))
                    ->get()->map(fn ($p) => $this->productVm($p))->all();
            }
            if (in_array($type, ['product_single', 'price_offer', 'order_form', 'hero'], true)) {
                $pid = (int) ($d['product_id'] ?? $d['cta_product_id'] ?? 0);
                if ($pid) {
                    $p = Product::where('business_id', $sf->business_id)->where('is_active', true)->find($pid);
                    $resolved['product'] = $p ? $this->productVm($p) : null;
                }
            }
            if (($type === 'hero') && ! empty($d['image'])) {
                $resolved['image'] = '/storage/'.$d['image'];
            }
            $blocks[] = ['type' => $type, 'data' => $d, 'resolved' => $resolved];
        }
        // The first orderable product powers the sticky mobile CTA.
        $ctaProduct = null;
        foreach ($blocks as $b) {
            if (! empty($b['resolved']['product'])) {
                $ctaProduct = $b['resolved']['product'];
                break;
            }
            if (! empty($b['resolved']['products'][0])) {
                $ctaProduct = $b['resolved']['products'][0];
                break;
            }
        }

        return [
            'sf' => $sf,
            'brand' => $this->brand($sf),
            'blocks' => $blocks,
            'districts' => StorefrontService::districts(),
            'ctaProduct' => $ctaProduct,
            'reported' => $request->query('reported') === '1',
            'fbPage' => $this->svc->facebookPageId($sf->business_id),
        ];
    }
}

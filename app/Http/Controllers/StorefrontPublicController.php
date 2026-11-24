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
        $page = $sf->rootPage();
        if ($page) {
            if ($page->status === 'paused') {
                return $this->paused($sf);
            }
            if ($page->status !== 'live') {
                return $this->unavailable($sf);
            }
            $html = $this->cachedHtml($sf, $host, '/pg'.$page->id.':'.($page->updated_at?->timestamp ?? 0),
                fn () => $this->renderPageHtml($request, $sf, $page, (array) $page->published_blocks, false));
            $this->svc->recordView($page, $request->userAgent());

            return response($html)->header('Content-Type', 'text/html');
        }
        // No page row (defensive fallback): the legacy single-page shop.
        $html = $this->cachedHtml($sf, $host, '/', fn () => view('storefront.home', $this->homeData($request, $sf))->render());

        return response($html)->header('Content-Type', 'text/html');
    }

    /** A store page at /{slug} (system-made slug; dispatched by the host middleware). */
    public function page(Request $request, string $slug)
    {
        $sf = $this->sf($request);
        $host = $this->svc->normalizeHost($request->getHost());
        $page = $sf->pages()->where('slug', $slug)->first();
        if (! $page || $page->status === 'draft') {
            return response()->view('storefront.notfound', ['domain' => $host], 404);
        }
        if (! $sf->isPublished()) {
            return $this->unavailable($sf);
        }
        if ($page->status === 'paused') {
            return $this->paused($sf);
        }
        $html = $this->cachedHtml($sf, $host, '/pg'.$page->id.':'.($page->updated_at?->timestamp ?? 0),
            fn () => $this->renderPageHtml($request, $sf, $page, (array) $page->published_blocks, false));
        $this->svc->recordView($page, $request->userAgent());

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * THE page renderer — used by the public pages AND the seller
     * editor's preview (with draft blocks), so they can never drift.
     * Handles both the v2 editor model and legacy v1 blocks.
     */
    public function renderPageHtml(Request $request, Storefront $sf, \App\Models\StorefrontPage $page, array $blocks, bool $preview): string
    {
        if (\App\Models\StorefrontPage::isV2($blocks)) {
            return view('storefront.page', $this->pageDataV2($request, $sf, $page, $blocks, $preview))->render();
        }

        return view('storefront.home', $this->homeData($request, $sf, $blocks))->render();
    }

    /** Resolve a v2 blocks payload into render data (live products). */
    private function pageDataV2(Request $request, Storefront $sf, \App\Models\StorefrontPage $page, array $payload, bool $preview): array
    {
        $brand = $this->brand($sf);
        $sections = [];
        $firstProduct = null;
        $formSectionIdx = null;
        foreach ((array) ($payload['blocks'] ?? []) as $block) {
            $type = (string) ($block['type'] ?? '');
            $props = (array) ($block['props'] ?? []);
            $resolved = [];
            if ($type === 'products') {
                $resolved['products'] = collect($this->svc->pageProducts($sf, $props))
                    ->map(fn ($p) => $this->productVm($p))->all();
                if ($firstProduct === null && ! empty($resolved['products'])) {
                    $firstProduct = $resolved['products'][0];
                }
            }
            if ($type === 'hero' && ! empty($props['image'])) {
                $resolved['image'] = '/storage/'.$props['image'];
            }
            if ($type === 'info') {
                $resolved['lines'] = $this->infoLines($sf);
            }
            if ($type === 'countdown') {
                // ends_at is the Dhaka wall clock the seller picked.
                $ends = null;
                if (! empty($props['ends_at'])) {
                    try {
                        $ends = \Carbon\Carbon::parse((string) $props['ends_at'], 'Asia/Dhaka');
                    } catch (\Throwable) {
                        $ends = null;
                    }
                }
                $secs = $ends ? $ends->getTimestamp() - now()->getTimestamp() : -1;
                // An expired (or missing) end time renders NOTHING —
                // a timer on the page must never be fake.
                $resolved['active'] = $secs > 0;
                if ($secs > 0) {
                    $resolved['ends'] = $ends->toIso8601String();
                    $resolved['remaining'] = ($secs >= 86400 ? intdiv($secs, 86400).'d ' : '')
                        .sprintf('%02d:%02d:%02d', intdiv($secs % 86400, 3600), intdiv($secs % 3600, 60), $secs % 60);
                }
            }
            if ($type === 'order_form') {
                $formSectionIdx = count($sections);
            }
            $sections[] = ['id' => (string) ($block['id'] ?? ''), 'type' => $type, 'props' => $props, 'resolved' => $resolved];
        }
        // The order form's product: its own choice, else the page's
        // first product, else the shop's first active product.
        if ($formSectionIdx !== null) {
            $props = $sections[$formSectionIdx]['props'];
            $p = null;
            if (! empty($props['product_id'])) {
                $p = Product::where('business_id', $sf->business_id)->where('is_active', true)->find((int) $props['product_id']);
            }
            if (! $p && $firstProduct) {
                $p = Product::where('business_id', $sf->business_id)->find($firstProduct['id']);
            }
            if (! $p) {
                $p = Product::where('business_id', $sf->business_id)->where('is_active', true)->latest('id')->first();
            }
            $sections[$formSectionIdx]['resolved']['product'] = $p ? $this->productVm($p) : null;
            if ($firstProduct === null && $p) {
                $firstProduct = $this->productVm($p);
            }
        }
        $b = (array) $sf->branding;
        $returnText = trim((string) ($b['return_policy'] ?? ''));

        return [
            'sf' => $sf,
            'brand' => $brand,
            'page' => $page,
            'sections' => $sections,
            'districts' => StorefrontService::districts(),
            'ctaProduct' => $firstProduct,
            'fbPage' => $this->svc->facebookPageId($sf->business_id),
            'reported' => $request->query('reported') === '1',
            'preview' => $preview,
            'pageTitle' => $page->isRoot() ? null : $page->name,
            'legal' => [
                'terms' => StorefrontService::defaultLegalText('terms'),
                'privacy' => StorefrontService::defaultLegalText('privacy'),
                'return' => $returnText !== '' ? '<p>'.nl2br(e($returnText)).'</p>' : StorefrontService::defaultLegalText('return'),
            ],
        ];
    }

    /** Lines for the v2 info strip, from data the store already has:
     *  the delivery charges saved in Store Settings plus cash on
     *  delivery (every storefront order is COD). There is no
     *  free-delivery threshold or short return-policy line in the
     *  storefront data — checkout always charges these flat rates —
     *  so those lines are never invented. */
    private function infoLines(Storefront $sf): array
    {
        $in = $sf->deliveryInside();
        $out = $sf->deliveryOutside();

        return [
            $in > 0 ? 'ঢাকার ভিতরে ডেলিভারি চার্জ ৳'.number_format($in) : 'ঢাকার ভিতরে ফ্রি ডেলিভারি',
            $out > 0 ? 'ঢাকার বাইরে ডেলিভারি চার্জ ৳'.number_format($out) : 'ঢাকার বাইরে ফ্রি ডেলিভারি',
            'ক্যাশ অন ডেলিভারি আছে - পণ্য হাতে পেয়ে টাকা দিন',
        ];
    }

    private function paused(Storefront $sf)
    {
        return response()->view('storefront.paused', [
            'sf' => $sf,
            'brand' => $this->brand($sf),
        ], 200)->header('X-Robots-Tag', 'noindex');
    }

    public function product(Request $request, int $product)
    {
        $sf = $this->sf($request);
        if (! $sf->isPublished()) {
            return $this->unavailable($sf);
        }
        $host = $this->svc->normalizeHost($request->getHost());
        $p = Product::where('business_id', $sf->business_id)->where('is_active', true)->find($product);
        if (! $p) {
            // Same branded shop 404 as an unknown page slug - a bare
            // framework 404 on a live shop reads as if the shop is broken.
            return response()->view('storefront.notfound', ['domain' => $host], 404);
        }
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
        // The invoice number alone is not a credential (Hunt U3): the
        // numbers are sequential per shop, so the page opens only for
        // the unguessable success token handed out at checkout - a
        // missing or wrong token reads as if the page did not exist.
        $token = (string) $request->query('t', '');
        abort_unless(! empty($order->success_token) && $token !== '' && hash_equals((string) $order->success_token, $token), 404);

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
            return $this->orderError($request, __('Too many order attempts. Please wait a minute and try again.'));
        }
        RateLimiter::hit($ipKey, 60);

        // Zero balance = no new orders, said honestly (M19 rule).
        if (app(\App\Services\TokenService::class)->blocked($sf->business_id)) {
            return $this->orderError($request, __('This shop cannot take new orders right now. Please contact the shop owner.'));
        }

        try {
            $data = $request->validate([
                'customer_name' => ['required', 'string', 'max:120'],
                'customer_phone' => ['required', 'string', 'max:20'],
                'customer_address' => ['required', 'string', 'min:10', 'max:500'],
                'district' => ['required', 'string', 'max:60'],
                'product_id' => ['required', 'integer'],
                'variant_id' => ['nullable', 'integer'],
                'page_id' => ['nullable', 'integer'],
                'idem' => ['nullable', 'string', 'max:80'],
                'qty' => ['nullable', 'integer', 'min:1', 'max:20'],
                'utm_source' => ['nullable', 'string', 'max:120'],
                'utm_medium' => ['nullable', 'string', 'max:120'],
                'utm_campaign' => ['nullable', 'string', 'max:120'],
                'fbclid' => ['nullable', 'string', 'max:255'],
                'ic_event_id' => ['nullable', 'string', 'max:80'],
                'purchase_event_id' => ['nullable', 'string', 'max:80'],
            ], [
                // The shop pages are Bangla-first; the framework's raw
                // English validation sentences must not leak into the form.
                'customer_name.required' => __('Please enter your name.'),
                'customer_phone.required' => __('Please enter your phone number.'),
                'customer_address.required' => __('Please enter your full address.'),
                'customer_address.min' => __('Please enter your full address.'),
                'district.required' => __('Please choose your district.'),
            ]);
        } catch (ValidationException $e) {
            return $this->orderError($request, collect($e->errors())->flatten()->first() ?? __('Please check the form and try again.'));
        }
        if (! in_array($data['district'], StorefrontService::districts(), true)) {
            return $this->orderError($request, __('Please choose your district.'));
        }
        $phone = PhoneNormalizer::normalize($data['customer_phone']);
        $phoneKey = 'sf-order-phone:'.$phone;
        if (RateLimiter::tooManyAttempts($phoneKey, 5)) {
            return $this->orderError($request, __('Too many orders from this phone number. Please try again later.'));
        }

        // Cross-shop guard + live price/stock: the product must be THIS
        // shop's, and the price is always recomputed from the database.
        $product = Product::where('business_id', $sf->business_id)->where('is_active', true)->find($data['product_id']);
        if (! $product) {
            return $this->orderError($request, __('This product is not available in this shop.'));
        }
        $qty = (int) ($data['qty'] ?? 1);
        // Variant: must belong to this product; its own stock + price rule.
        // A variant line is judged on the VARIANT's pool alone - the
        // parent product's stock is a different pool and must not block
        // (or allow) the sale.
        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = $product->variants()->find((int) $data['variant_id']);
            if (! $variant) {
                return $this->orderError($request, __('That variant is not available. Please choose another.'));
            }
            if ($product->manage_stock && $variant->available() < $qty) {
                return $this->orderError($request, $variant->available() <= 0
                    ? __('Sorry, this variant is out of stock right now.')
                    : __('Only :count left for this variant. Please lower the quantity.', ['count' => $variant->available()]));
            }
        } elseif ($product->manage_stock && $product->available() < $qty) {
            // available() is the one definition: for a combo it counts
            // how many full combos the components can still make, else
            // the product's own stock minus reservations.
            return $this->orderError($request, $product->available() <= 0
                ? __('Sorry, this product is out of stock right now.')
                : __('Only :count left in stock. Please lower the quantity.', ['count' => $product->available()]));
        }
        RateLimiter::hit($phoneKey, 3600);

        $price = $variant ? $variant->effectivePrice() : $product->effectivePrice();

        // Page attribution: the page the form lives on (validated as
        // this shop's), else the store root page.
        $pageId = null;
        if (! empty($data['page_id'])) {
            $pageId = $sf->pages()->whereKey((int) $data['page_id'])->value('id');
        }
        if (! $pageId) {
            $pageId = $sf->rootPage()?->id;
        }

        // Idempotency: one form render = one order, however many times
        // it is submitted (double-tap, refresh, back button).
        $idem = trim((string) ($data['idem'] ?? ''));
        if ($idem !== '') {
            $existing = Order::where('business_id', $sf->business_id)->where('idempotency_key', $idem)->first();
            if ($existing) {
                return redirect($this->successUrl($existing, (string) ($data['purchase_event_id'] ?? '')));
            }
        }
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

        $makeOrder = fn () => $this->orders->create($sf->business_id, [
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
            'storefront_page_id' => $pageId,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'fbclid' => $data['fbclid'] ?? null,
            'items' => [[
                'product_id' => $product->id,
                'name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'qty' => $qty,
                'price' => $price,
            ]],
        ], null, 'Storefront');

        $isDuplicate = false;
        try {
            if ($idem !== '') {
                // Serialize same-key submissions; the loser finds the
                // winner's order and lands on the same success page.
                $order = Cache::lock('sfidem:'.$sf->business_id.':'.sha1($idem), 10)->block(8,
                    function () use ($makeOrder, $sf, $idem, &$isDuplicate) {
                        $found = Order::where('business_id', $sf->business_id)->where('idempotency_key', $idem)->first();
                        if ($found) {
                            $isDuplicate = true;

                            return $found;
                        }

                        return $makeOrder();
                    });
            } else {
                $order = $makeOrder();
            }
        } catch (InsufficientTokensException) {
            return response()->view('storefront.unable', ['sf' => $sf, 'brand' => $this->brand($sf)], 200);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $this->orderError($request, __('Your order is being placed. Please wait a moment and check again.'));
        }

        if (! $isDuplicate) {
            AppNotification::create(['business_id' => $order->business_id, 'kind' => 'order', 'title' => "New order {$order->invoice_no}",
                'body' => "{$order->customer_name} · ৳".number_format((float) $order->grand_total)." · Storefront",
                'link' => "/orders/{$order->id}"]);
            if ($pageId) {
                $this->svc->bumpDailyStat($pageId, 'orders');
            }
        }

        // Server Purchase — same event_id the success page fires in the browser.
        if (! $isDuplicate && ! empty($data['purchase_event_id'])) {
            $this->tracking->send($sf, 'Purchase', $data['purchase_event_id'], [
                'currency' => 'BDT', 'value' => (float) $order->grand_total,
                'content_ids' => [(string) $product->id], 'content_type' => 'product',
                'order_id' => $order->invoice_no,
            ], $pageUrl, $request->ip(), $request->userAgent());
        }

        return redirect($this->successUrl($order, (string) ($data['purchase_event_id'] ?? '')));
    }

    // ------------------------------------------------------------------ helpers

    /** The success-page URL for an order, token included (Hunt U3). */
    private function successUrl(Order $order, string $pe = ''): string
    {
        if (empty($order->success_token)) {
            // An order minted before tokens existed still needs its one
            // good link when a checkout replay lands on it.
            $order->success_token = \Illuminate\Support\Str::random(48);
            $order->save();
        }

        return '/order-success/'.$order->invoice_no.'?t='.urlencode((string) $order->success_token).'&pe='.urlencode($pe);
    }

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

        // Per-response tokens are swapped in AFTER the cache read: the
        // CSRF token and the checkout idempotency key must never be
        // shared between visitors of the same cached page.
        return str_replace(
            ['%%SF_CSRF%%', '%%SF_IDEM%%'],
            [csrf_token(), \Illuminate\Support\Str::random(32)],
            $html
        );
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
        $variants = $p->variants()->orderBy('id')->get();
        // The one availability definition, per pool: a variant line is
        // judged on the variant's own pool (stock minus reservations,
        // ProductVariant::available()), a plain or combo line on the
        // product's own available(). A product WITH variants sells from
        // its variant pools alone - sellers keep all stock there and
        // the parent row commonly sits at zero - so the public figure
        // is the sum of the variant pools; without variants it is the
        // product's own available(), exactly as checkout enforces.
        $availableQty = $variants->isNotEmpty()
            ? (int) $variants->sum(fn ($v) => $v->available())
            : $p->available();
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
            'variants' => $variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => (string) $v->name,
                'price' => $v->effectivePrice(),
                'available' => $v->available() > 0,
                'available_qty' => $v->available(),
            ])->all(),
        ];
    }

    private function homeData(Request $request, Storefront $sf, ?array $blocksOverride = null): array
    {
        $blocks = [];
        foreach ((array) ($blocksOverride ?? $sf->blocks) as $block) {
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

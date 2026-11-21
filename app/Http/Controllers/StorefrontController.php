<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Storefront;
use App\Models\StorefrontReport;
use App\Services\StorefrontService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M18 — the seller's Storefront section: Overview, Subdomain, Branding,
 * Page Builder, Domains. Everything is scoped to the user's business.
 */
class StorefrontController extends Controller
{
    public function __construct(private readonly StorefrontService $svc) {}

    private function sf(Request $request): Storefront
    {
        abort_unless($request->user()?->business_id, 403);

        return $this->svc->ensureFor((int) $request->user()->business_id);
    }

    private function view(Request $request, Storefront $sf): array
    {
        return [
            'id' => $sf->id,
            'status' => $sf->status,
            'subdomain' => $sf->subdomain,
            'subdomainUrl' => $sf->subdomainUrl(),
            'customDomain' => $sf->custom_domain,
            'domainStatus' => $sf->domain_status,
            'domainTxtToken' => $sf->domain_txt_token,
            'customDomainUrl' => $sf->customDomainUrl(),
            'phoneVerified' => $sf->phone_verified_at !== null,
            'phoneToVerify' => $sf->phone_to_verify,
            'publishedAt' => $sf->published_at?->format('d M Y, h:i A'),
            'unpublishedReason' => $sf->unpublished_reason,
            'platformDomain' => $this->svc->platformDomain(),
            'serverIp' => (string) config('storefront.server_ip'),
            'cnameTarget' => (string) config('storefront.cname_target'),
            'domainLocked' => $this->svc->addressLocked($sf),
            'hasPaid' => $this->svc->hasPaid((int) $sf->business_id),
        ];
    }

    private const LOCK_MESSAGE = 'Your shop address unlocks after your first pack payment is confirmed. Buy a points pack to unlock it.';

    // ------------------------------------------------------------- overview

    public function overview(Request $request): Response
    {
        $sf = $this->sf($request);

        return Inertia::render('Storefront/Overview', [
            'sf' => $this->view($request, $sf),
            'orderCount' => Order::where('storefront_id', $sf->id)->count(),
            'openReports' => StorefrontReport::where('storefront_id', $sf->id)->where('status', 'open')->count(),
            'hasAddress' => $sf->subdomain !== null || $sf->domain_status === 'active',
        ]);
    }

    public function publish(Request $request)
    {
        $sf = $this->sf($request);
        if ($this->svc->addressLocked($sf)) {
            return back()->with('error', self::LOCK_MESSAGE);
        }
        if (! $sf->subdomain && $sf->domain_status !== 'active') {
            return back()->with('error', 'Choose your free subdomain first (Storefront → Subdomain).');
        }
        if (! $sf->phone_verified_at) {
            return back()->with('error', 'Verify your mobile number first — publishing needs a verified phone.');
        }
        if (empty($sf->blocks)) {
            return back()->with('error', 'Your page is empty. Add some blocks in the Page Builder first.');
        }
        $sf->status = 'published';
        $sf->published_at = now();
        $sf->unpublished_reason = null;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Your storefront is now live.');
    }

    public function unpublish(Request $request)
    {
        $sf = $this->sf($request);
        $sf->status = 'draft';
        $sf->unpublished_reason = 'seller';
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Your storefront is unpublished. Visitors now see a "coming soon" page.');
    }

    // ------------------------------------------------------------- phone OTP

    public function sendOtp(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $res = $this->svc->sendOtp($sf, $data['phone']);
        if (! $res['sent']) {
            return back()->with('error', 'The SMS could not be sent. Check your SMS settings balance and try again.');
        }

        return back()->with('success', 'A 6-digit code was sent to '.$res['phone'].'. It is valid for 10 minutes.');
    }

    public function verifyOtp(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        if (! $this->svc->verifyOtp($sf, $data['code'])) {
            return back()->with('error', 'That code is wrong or has expired. Please request a new code.');
        }

        return back()->with('success', 'Mobile number verified. You can publish your storefront now.');
    }

    // ------------------------------------------------------------- subdomain

    public function subdomain(Request $request): Response
    {
        $sf = $this->sf($request);

        return Inertia::render('Storefront/Subdomain', ['sf' => $this->view($request, $sf)]);
    }

    public function availability(Request $request)
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $res = $this->svc->availability($slug, (int) ($request->user()?->business_id ?? 0));

        return response()->json($res + ['slug' => $slug]);
    }

    public function saveSubdomain(Request $request)
    {
        $sf = $this->sf($request);
        if ($this->svc->addressLocked($sf)) {
            return back()->with('error', self::LOCK_MESSAGE);
        }
        $data = $request->validate(['subdomain' => ['required', 'string', 'max:30']]);
        $slug = strtolower(trim($data['subdomain']));
        $res = $this->svc->availability($slug, $sf->business_id);
        if ($res['state'] !== 'available') {
            return back()->with('error', $res['message']);
        }
        $old = $sf->subdomain;
        $sf->subdomain = $slug;
        $sf->save();
        if ($old && $old !== $slug) {
            \Illuminate\Support\Facades\Cache::forget('sfhost:'.$old.'.'.$this->svc->platformDomain());
        }
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Your shop address is now '.$sf->subdomainUrl());
    }

    // ------------------------------------------------------------- branding

    public function branding(Request $request): Response
    {
        $sf = $this->sf($request);
        $b = (array) $sf->branding;

        return Inertia::render('Storefront/Branding', [
            'sf' => $this->view($request, $sf),
            'branding' => [
                'display_name' => (string) ($b['display_name'] ?? ''),
                'logo' => $b['logo'] ?? null,
                'logoUrl' => ! empty($b['logo']) ? '/storage/'.$b['logo'] : null,
                'theme_color' => (string) ($b['theme_color'] ?? '#411550'),
                'pixel_id' => (string) ($b['pixel_id'] ?? ''),
                'ga4_id' => (string) ($b['ga4_id'] ?? ''),
                'footer_text' => (string) ($b['footer_text'] ?? ''),
                'capi_token_set' => ! empty($b['capi_token_enc']),
            ],
            'charges' => [
                'delivery_inside' => $sf->deliveryInside(),
                'delivery_outside' => $sf->deliveryOutside(),
            ],
            'imageUsageMb' => round($this->svc->imageUsageBytes($sf->business_id) / 1048576, 2),
            'imageQuotaMb' => round($this->svc->imageQuotaBytes() / 1048576, 1),
        ]);
    }

    public function saveBranding(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'theme_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pixel_id' => ['nullable', 'string', 'max:40'],
            'ga4_id' => ['nullable', 'string', 'max:40'],
            'footer_text' => ['nullable', 'string', 'max:300'],
            'capi_token' => ['nullable', 'string', 'max:500'],
            'delivery_inside' => ['required', 'numeric', 'min:0', 'max:10000'],
            'delivery_outside' => ['required', 'numeric', 'min:0', 'max:10000'],
            'logo' => ['nullable', 'file', 'image', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $b = (array) $sf->branding;
        $b['display_name'] = trim($data['display_name']);
        $b['theme_color'] = $data['theme_color'];
        $b['pixel_id'] = trim((string) ($data['pixel_id'] ?? ''));
        $b['ga4_id'] = trim((string) ($data['ga4_id'] ?? ''));
        $b['footer_text'] = trim((string) ($data['footer_text'] ?? ''));
        if (! empty($data['capi_token'])) {
            $b['capi_token_enc'] = Crypt::encryptString(trim($data['capi_token']));
        }
        if ($request->hasFile('logo')) {
            $b['logo'] = $this->svc->storeImage($sf->business_id, $request->file('logo'));
        } elseif (! empty($data['remove_logo'])) {
            $b['logo'] = null;
        }
        $sf->branding = $b;
        $settings = (array) $sf->settings;
        $settings['delivery_inside'] = (float) $data['delivery_inside'];
        $settings['delivery_outside'] = (float) $data['delivery_outside'];
        $sf->settings = $settings;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Branding saved.');
    }

    // ------------------------------------------------------------- builder

    public function builder(Request $request): Response
    {
        $sf = $this->sf($request);
        $biz = $sf->business_id;

        return Inertia::render('Storefront/Builder', [
            'sf' => $this->view($request, $sf),
            'blocks' => (array) $sf->blocks,
            'products' => Product::where('business_id', $biz)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'price'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price])->all(),
            'categories' => ProductCategory::where('business_id', $biz)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ]);
    }

    public function saveBlocks(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate(['blocks' => ['required', 'array', 'max:40']]);
        $sf->blocks = $this->svc->sanitizeBlocks($data['blocks']);
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Page saved.');
    }

    /** Builder/branding image upload — returns the stored WebP path as JSON. */
    public function uploadImage(Request $request)
    {
        $sf = $this->sf($request);
        $request->validate(['image' => ['required', 'file', 'image', 'max:10240']]);
        $path = $this->svc->storeImage($sf->business_id, $request->file('image'));

        return response()->json(['path' => $path, 'url' => '/storage/'.$path]);
    }

    // ------------------------------------------------------------- domains

    public function domains(Request $request): Response
    {
        $sf = $this->sf($request);

        return Inertia::render('Storefront/Domains', ['sf' => $this->view($request, $sf)]);
    }

    public function requestDomain(Request $request)
    {
        $sf = $this->sf($request);
        if ($this->svc->addressLocked($sf)) {
            return back()->with('error', self::LOCK_MESSAGE);
        }
        $data = $request->validate(['custom_domain' => ['required', 'string', 'max:120']]);
        $domain = strtolower(trim($data['custom_domain']));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return back()->with('error', 'That does not look like a valid domain name.');
        }
        if (str_ends_with($domain, '.'.$this->svc->platformDomain()) || $domain === $this->svc->platformDomain()) {
            return back()->with('error', 'That is a platform address. Enter your own domain, e.g. yourshop.com');
        }
        $taken = Storefront::where('custom_domain', $domain)->where('business_id', '!=', $sf->business_id)->exists();
        if ($taken) {
            return back()->with('error', 'This domain is already connected to another shop.');
        }
        $sf->custom_domain = $domain;
        $sf->domain_status = 'pending_dns';
        $sf->domain_txt_token = 'skaleup-verify='.Str::random(24);
        $sf->domain_verified_at = null;
        $sf->domain_added_at = null;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Domain saved. Add the DNS records below, then press Verify.');
    }

    public function verifyDomain(Request $request)
    {
        $sf = $this->sf($request);
        if (! $sf->custom_domain) {
            return back()->with('error', 'Enter your domain first.');
        }
        if ($this->svc->domainTxtFound($sf)) {
            $sf->domain_status = 'verified';
            $sf->domain_verified_at = now();
            $sf->save();
            $this->svc->forgetHostCache($sf);

            return back()->with('success', 'Domain verified. Our team will now connect it — you will see it active here.');
        }

        return back()->with('error', 'We could not find the verification TXT record yet. DNS changes can take a while — check the record and try again.');
    }

    public function removeDomain(Request $request)
    {
        $sf = $this->sf($request);
        $sf->custom_domain = null;
        $sf->domain_status = 'none';
        $sf->domain_txt_token = null;
        $sf->domain_verified_at = null;
        $sf->domain_added_at = null;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Custom domain removed. Your free subdomain keeps working.');
    }
}

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

    /** The label connectKey() stamps on the website connection key. */
    private const CONNECTION_KEY_LABEL = 'Storefront Connect Website';

    /**
     * The business's website connection key - the one connectKey()
     * makes - never a scoped key from the API keys table, however
     * new that scoped key happens to be.
     */
    private function connectionKey(int $biz): ?\App\Models\ApiKey
    {
        return \App\Models\ApiKey::where('business_id', $biz)
            ->where('label', self::CONNECTION_KEY_LABEL)
            ->active()
            ->latest('id')->first();
    }

    private function sf(Request $request): Storefront
    {
        $biz = \App\Support\ShopContext::id($request);
        abort_unless($biz, 403);

        // View-as-seller (ধাপ ৩) must not write: a shop with no
        // storefront yet resolves to an unsaved draft instead of
        // ensureFor's firstOrCreate. The owner's own visit keeps the
        // creating behaviour, exactly as before.
        return \App\Support\ShopContext::isViewMode($request)
            ? $this->svc->findOrDraft($biz)
            : $this->svc->ensureFor($biz);
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
            // The shop's saved contact mobile (register form / business
            // profile) — the wizard's phone step starts filled with it.
            'contactMobile' => app(\App\Services\SettingsService::class)->shopContact((int) $sf->business_id, 'mobile'),
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

    public function overview(Request $request)
    {
        $sf = $this->sf($request);
        $state = $this->svc->menuState($sf->business_id);
        // No store yet and not a Door 2 seller: the wizard is the entry.
        if (! $state['hasStore'] && $state['mode'] !== 'connect') {
            return redirect('/storefront/wizard');
        }
        $root = $sf->rootPage();
        $viewsTotal = $root ? (int) \App\Models\StorefrontDailyStat::whereIn('page_id', $sf->pages()->pluck('id'))->sum('views') : 0;

        return Inertia::render('Storefront/Overview', [
            'sf' => $this->view($request, $sf),
            'orderCount' => Order::where('storefront_id', $sf->id)->count(),
            'openReports' => StorefrontReport::where('storefront_id', $sf->id)->where('status', 'open')->count(),
            'hasAddress' => ! empty($sf->subdomain) || $sf->domain_status === 'active',
            'state' => $state,
            'pageCount' => $sf->pages()->count(),
            'livePageCount' => $sf->pages()->where('status', 'live')->count(),
            'viewsTotal' => $viewsTotal,
            'shopUrl' => $root?->publicUrl($sf) ?? $sf->subdomainUrl(),
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
            return back()->with('error', 'Verify your mobile number first - publishing needs a verified phone.');
        }
        $hasPage = $sf->pages()->whereNotNull('published_blocks')->exists();
        if (! $hasPage && empty($sf->blocks)) {
            return back()->with('error', 'Your page is empty. Create a page first (Storefront → My Pages).');
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
        if (! empty($res['cooldown'])) {
            return back()->with('error', __('Please wait :count seconds before asking for a new code.', ['count' => $res['cooldown']]));
        }
        if (! $res['sent']) {
            $reason = ! empty($res['error']) ? ' Reason: '.$res['error'].'.' : '';

            return back()->with('error', 'The SMS could not be sent.'.$reason.' Please try again.');
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

    public function availability(Request $request)
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $res = $this->svc->availability($slug, \App\Support\ShopContext::id($request));

        return response()->json($res + ['slug' => $slug]);
    }

    public function saveSubdomain(Request $request)
    {
        $sf = $this->sf($request);
        if (! empty($sf->subdomain)) {
            // One subdomain per seller, chosen once — never changeable.
            return back()->with('error', 'Your shop link is already set and cannot be changed. Only one subdomain will be applied.');
        }
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

    public function requestDomain(Request $request)
    {
        $sf = $this->sf($request);
        if ($this->svc->addressLocked($sf)) {
            return back()->with('error', self::LOCK_MESSAGE);
        }
        $raw = (string) ($request->input('domain') ?: $request->input('custom_domain', ''));
        $domain = strtolower(trim($raw));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return back()->with('error', 'That does not look like a valid domain name.');
        }
        if (str_ends_with($domain, '.'.$this->svc->platformDomain()) || $domain === $this->svc->platformDomain()) {
            return back()->with('error', 'That is a platform address. Enter your own domain, e.g. yourshop.com');
        }
        $taken = \App\Models\StorefrontDomain::where('domain', $domain)->where('business_id', '!=', $sf->business_id)->exists()
            || Storefront::where('custom_domain', $domain)->where('business_id', '!=', $sf->business_id)->exists();
        if ($taken) {
            return back()->with('error', 'This domain is already connected to another shop.');
        }
        $row = $sf->domains()->firstOrNew(['domain' => $domain]);
        $row->business_id = $sf->business_id;
        $row->status = 'pending_dns';
        $row->txt_token = 'skaleup-verify='.Str::random(24);
        $row->is_primary = $row->is_primary || $sf->domains()->count() === 0;
        $row->verified_at = null;
        $row->added_at = null;
        $row->save();
        $this->svc->syncDomainMirror($sf);

        return back()->with('success', 'Domain saved. Add the TXT record shown below, then press Check now.');
    }

    public function verifyDomain(Request $request, $domain)
    {
        $sf = $this->sf($request);
        $row = $sf->domains()->findOrFail($domain);
        if ($this->svc->domainTxtFoundFor($row)) {
            $row->status = 'verified';
            $row->verified_at = now();
            $row->save();
            $this->svc->syncDomainMirror($sf);

            return back()->with('success', 'Domain verified. Our team will now connect it - you will see it active here.');
        }

        return back()->with('error', 'We could not find the verification TXT record yet. DNS changes can take a while - check the record and try again.');
    }

    public function primaryDomain(Request $request, $domain)
    {
        $sf = $this->sf($request);
        $row = $sf->domains()->findOrFail($domain);
        $sf->domains()->update(['is_primary' => false]);
        $row->is_primary = true;
        $row->save();
        $this->svc->syncDomainMirror($sf);

        return back()->with('success', 'Main domain updated.');
    }

    public function removeDomain(Request $request, $domain)
    {
        $sf = $this->sf($request);
        $row = $sf->domains()->findOrFail($domain);
        $wasPrimary = $row->is_primary;
        $row->delete();
        if ($wasPrimary && ($next = $sf->domains()->orderBy('id')->first())) {
            $next->is_primary = true;
            $next->save();
        }
        $this->svc->syncDomainMirror($sf);

        return back()->with('success', 'Custom domain removed. Your free subdomain keeps working.');
    }

    // ------------------------------------------------------------- state

    /** GET /storefront/state — the server-computed menu + store state. */
    public function state(Request $request)
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        abort_unless($biz, 403);

        return response()->json($this->svc->menuState($biz));
    }

    // ------------------------------------------------------------- wizard

    public function wizard(Request $request)
    {
        $sf = $this->sf($request);
        if (! empty($sf->subdomain)) {
            return redirect('/storefront/pages');
        }
        $ob = \App\Models\StorefrontOnboarding::firstOrCreate(['business_id' => $sf->business_id], ['step' => 0, 'payload' => []]);
        // The name step starts filled with the name the shop already
        // trades under (branding display name, else the business name);
        // the seller can still change it freely before saving.
        $branding = (array) $sf->branding;
        $shopName = trim((string) ($branding['display_name'] ?? ''));
        if ($shopName === '') {
            $shopName = (string) (\App\Models\Business::where('id', $sf->business_id)->value('name') ?? '');
        }

        return Inertia::render('Storefront/Wizard', [
            'sf' => $this->view($request, $sf),
            'shopName' => $shopName,
            'onboarding' => ['step' => (int) $ob->step, 'payload' => (array) $ob->payload],
            'presetColors' => StorefrontService::PRESET_COLORS,
            'state' => $this->svc->menuState($sf->business_id),
        ]);
    }

    /** Autosave one wizard step (payload merges; Back never loses data). */
    public function saveOnboarding(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate([
            'step' => ['required', 'integer', 'min:0', 'max:4'],
            'payload' => ['nullable', 'array'],
        ]);
        $payload = (array) ($data['payload'] ?? []);
        // Step 0 "I already have a website" flips the store to Door 2.
        if (array_key_exists('has_website', $payload)) {
            $settings = (array) $sf->settings;
            $settings['mode'] = ! empty($payload['has_website']) ? 'connect' : 'hosted';
            $sf->settings = $settings;
            $sf->save();
        }
        $ob = \App\Models\StorefrontOnboarding::firstOrCreate(['business_id' => $sf->business_id], ['step' => 0, 'payload' => []]);
        $ob->step = (int) $data['step'];
        $ob->payload = array_merge((array) $ob->payload, $payload);
        $ob->save();

        return response()->json(['ok' => true, 'step' => $ob->step]);
    }

    public function wizardPublish(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate([
            'template' => ['required', 'string', 'in:single,store,offer'],
            'name' => ['required', 'string', 'max:60'],
            'logo' => ['nullable', 'string', 'max:300'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'slug' => ['required', 'string', 'max:30'],
            'agree' => ['required', 'boolean'],
        ]);
        $res = $this->svc->wizardPublish($sf->business_id, $data);
        if (! $res['ok']) {
            return back()->with('error', $res['error'])->with('slug_suggestions', $res['suggestions'] ?? []);
        }

        return redirect('/storefront/published');
    }

    public function wizardDone(Request $request)
    {
        $sf = $this->sf($request);
        abort_unless($sf->subdomain, 404);
        $page = $sf->rootPage();

        return Inertia::render('Storefront/Published', [
            'sf' => $this->view($request, $sf),
            'url' => $page?->publicUrl($sf) ?? $sf->subdomainUrl(),
        ]);
    }

    /** Logo upload for the wizard + settings (256px WebP, EXIF stripped). Persists to branding at once — both UIs treat the upload as final. */
    public function uploadLogo(Request $request)
    {
        $sf = $this->sf($request);
        $request->validate(['logo' => ['required', 'file', 'image', 'max:1024']]);
        $path = $this->svc->storeImage($sf->business_id, $request->file('logo'), 256);
        $b = (array) $sf->branding;
        $b['logo'] = $path;
        $sf->branding = $b;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return response()->json(['path' => $path, 'url' => '/storage/'.$path]);
    }

    // ------------------------------------------------------------- my pages

    private function pageView(Storefront $sf, \App\Models\StorefrontPage $p): array
    {
        $totals = $this->svc->pageTotals($p);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'template' => $p->template,
            'status' => $p->status,
            'url' => $p->publicUrl($sf),
            'views' => $totals['views'],
            'orders' => $totals['orders'],
            'isRoot' => $p->isRoot(),
            'hasUnpublishedChanges' => $p->hasUnpublishedChanges(),
            'publishedAt' => $p->published_at?->format('d M Y, h:i A'),
            'updated' => $p->updated_at?->format('d M, h:i A'),
        ];
    }

    public function pages(Request $request): Response
    {
        $sf = $this->sf($request);

        return Inertia::render('Storefront/Pages', [
            'sf' => $this->view($request, $sf),
            'pages' => $sf->pages()->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($p) => $this->pageView($sf, $p))->all(),
            'state' => $this->svc->menuState($sf->business_id),
        ]);
    }

    public function createPage(Request $request)
    {
        $sf = $this->sf($request);
        if (empty($sf->subdomain)) {
            return back()->with('error', 'Create your store first - the wizard sets your shop link.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'template' => ['required', 'string', 'in:single,store,offer'],
        ]);
        $page = $this->svc->createPage($sf, trim($data['name']), $data['template']);

        return redirect('/storefront/pages/'.$page->id.'/edit')->with('success', 'Page created as a draft. Edit it, then publish.');
    }

    private function ownPage(Request $request, int $page): \App\Models\StorefrontPage
    {
        $sf = $this->sf($request);
        $p = $sf->pages()->find($page);
        abort_unless($p, 404);

        return $p;
    }

    public function duplicatePage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        $sf = $p->storefront;
        $copy = $this->svc->createPage($sf, $p->name.' (copy)', $p->template, $p->slug ?: \Illuminate\Support\Str::slug($p->name));
        $copy->draft_blocks = $p->draft_blocks;
        $copy->save();

        return back()->with('success', 'Page duplicated as a draft.');
    }

    public function pausePage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        if ($p->status === 'live') {
            $p->status = 'paused';
            $p->save();
            $this->svc->forgetHostCache($p->storefront);
        }

        return back()->with('success', 'Page paused. Visitors see a paused notice.');
    }

    public function resumePage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        if ($p->status === 'paused') {
            $p->status = 'live';
            $p->save();
            $this->svc->forgetHostCache($p->storefront);
        }

        return back()->with('success', 'Page is live again.');
    }

    public function deletePage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        if ($p->isRoot()) {
            return back()->with('error', 'Your main shop page cannot be deleted. Unpublish the store instead.');
        }
        $p->delete();
        $this->svc->forgetHostCache($p->storefront);

        return back()->with('success', 'Page deleted. Orders it brought in keep their record.');
    }

    // ------------------------------------------------------------- editor

    public function editPage(Request $request, int $page): Response
    {
        $p = $this->ownPage($request, $page);
        $sf = $p->storefront;
        $draft = $p->draft_blocks;
        if (! \App\Models\StorefrontPage::isV2($draft)) {
            // A migrated legacy page: convert once into the v2 editor
            // model. The live page keeps rendering the legacy blocks
            // until the seller presses Publish changes.
            $draft = $this->svc->legacyToV2((array) ($p->published_blocks ?? $draft ?? []));
            $p->draft_blocks = $draft;
            $p->save();
        }

        $b = (array) $sf->branding;

        return Inertia::render('Storefront/Editor', [
            'sf' => $this->view($request, $sf),
            'page' => $this->pageView($sf, $p) + [
                'draft' => $draft,
                'published' => $p->published_blocks,
                'versions' => $p->versions()->latest('id')->take(5)->get()
                    ->map(fn ($v) => ['id' => $v->id, 'at' => $v->created_at?->format('d M Y, h:i A')])->all(),
            ],
            // The Design tab edits the store colour in place; it needs
            // the full current branding values to post them back with
            // only the colour changed (POST /storefront/branding).
            'branding' => [
                'display_name' => (string) ($b['display_name'] ?? '') ?: (string) (\App\Models\Business::where('id', $sf->business_id)->value('name') ?? ''),
                'theme_color' => (string) ($b['theme_color'] ?? '#411550'),
                'pixel_id' => (string) ($b['pixel_id'] ?? ''),
                'ga4_id' => (string) ($b['ga4_id'] ?? ''),
                'footer_text' => (string) ($b['footer_text'] ?? ''),
                'delivery_inside' => $sf->deliveryInside(),
                'delivery_outside' => $sf->deliveryOutside(),
            ],
            'presetColors' => StorefrontService::PRESET_COLORS,
            'previewUrl' => '/storefront/pages/'.$p->id.'/preview',
            'products' => Product::where('business_id', $sf->business_id)->where('is_active', true)
                ->orderBy('name')->get()->map(fn ($pr) => ['id' => $pr->id, 'name' => $pr->name,
                    'price' => (float) $pr->effectivePrice(), 'stock' => (int) $pr->stock_qty])->all(),
        ]);
    }

    public function saveDraft(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        $data = $request->validate(['blocks' => ['required', 'array']]);
        $p->draft_blocks = $this->svc->sanitizePageBlocks($data['blocks']);
        $p->save();

        return response()->json(['ok' => true, 'saved_at' => now()->format('h:i A')]);
    }

    public function publishPage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        $this->svc->publishChanges($p);

        return back()->with('success', 'Changes published. Your live page is updated.');
    }

    public function restorePage(Request $request, int $page, int $version)
    {
        $p = $this->ownPage($request, $page);
        $v = $p->versions()->find($version);
        abort_unless($v, 404);
        $this->svc->restoreVersion($p, $v);

        return back()->with('success', 'That version is in your draft - publish to make it live.');
    }

    /** Editor preview: the SAME renderer as the public page, on the draft. */
    public function previewPage(Request $request, int $page)
    {
        $p = $this->ownPage($request, $page);
        $sf = $p->storefront;
        $html = app(StorefrontPublicController::class)->renderPageHtml($request, $sf, $p, (array) $p->draft_blocks, true);

        // Editor bridge (preview only, never on the public page):
        // clicking a section tells the editor to select it; the editor
        // answers with a highlight for the current selection.
        $bridge = <<<'HTML'
<script>
document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-bid]');
    if (el && el.getAttribute('data-bid')) parent.postMessage({ sfSelect: el.getAttribute('data-bid') }, '*');
});
window.addEventListener('message', function (e) {
    if (!e.data || !e.data.sfHighlight) return;
    document.querySelectorAll('[data-bid]').forEach(function (el) {
        var on = el.getAttribute('data-bid') === e.data.sfHighlight;
        el.style.outline = on ? '2px solid #411550' : '';
        // Outside the section, not inset: an inset outline touches the
        // hero title, whose text starts flush at the section's left edge.
        // This bridge runs only in the editor preview, so the public
        // page rendering is unaffected.
        el.style.outlineOffset = on ? '3px' : '';
        if (on) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
});
</script>
HTML;
        $html = str_contains($html, '</body>') ? str_replace('</body>', $bridge.'</body>', $html) : $html.$bridge;

        return response($html)->header('Content-Type', 'text/html');
    }

    // ------------------------------------------------------------- store settings

    public function settingsPage(Request $request): Response
    {
        $sf = $this->sf($request);
        $b = (array) $sf->branding;

        return Inertia::render('Storefront/Settings', [
            'sf' => $this->view($request, $sf),
            'identity' => [
                'display_name' => (string) ($b['display_name'] ?? ''),
                'logo' => $b['logo'] ?? null,
                'logoUrl' => ! empty($b['logo']) ? '/storage/'.$b['logo'] : null,
                'theme_color' => (string) ($b['theme_color'] ?? '#411550'),
            ],
            'contact' => [
                'phone' => (string) ($b['contact_phone'] ?? ''),
                'whatsapp' => (string) ($b['whatsapp'] ?? ''),
                'facebook' => (string) ($b['facebook'] ?? ''),
                'address' => (string) ($b['address'] ?? ''),
                'return_policy' => (string) ($b['return_policy'] ?? ''),
                'footer_text' => (string) ($b['footer_text'] ?? ''),
            ],
            'tracking' => [
                'pixel_id' => (string) ($b['pixel_id'] ?? ''),
                'ga4_id' => (string) ($b['ga4_id'] ?? ''),
                'capi_token_set' => ! empty($b['capi_token_enc']),
            ],
            'charges' => [
                'delivery_inside' => $sf->deliveryInside(),
                'delivery_outside' => $sf->deliveryOutside(),
            ],
            'presetColors' => StorefrontService::PRESET_COLORS,
            'imageUsageMb' => round($this->svc->imageUsageBytes($sf->business_id) / 1048576, 2),
            'imageQuotaMb' => round($this->svc->imageQuotaBytes() / 1048576, 1),
            'state' => $this->svc->menuState($sf->business_id),
            'domains' => $sf->domains()->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn ($d) => ['id' => $d->id, 'domain' => $d->domain, 'status' => $d->status,
                    'isPrimary' => (bool) $d->is_primary, 'txtValue' => $d->txt_token])->all(),
            'reports' => StorefrontReport::where('storefront_id', $sf->id)->latest('id')->take(10)->get()
                ->map(fn ($r) => ['id' => $r->id, 'reason' => $r->reason, 'status' => $r->status,
                    'created' => $r->created_at?->format('d M Y')])->all(),
            'pageConnected' => $this->svc->facebookPageId($sf->business_id) !== null,
        ]);
    }

    public function saveSettings(Request $request)
    {
        $sf = $this->sf($request);
        $section = (string) $request->input('section', '');
        $b = (array) $sf->branding;
        if ($section === 'identity') {
            $data = $request->validate([
                'display_name' => ['required', 'string', 'min:2', 'max:40'],
                'theme_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'logo' => ['nullable', 'string', 'max:300'],
                'remove_logo' => ['nullable', 'boolean'],
            ]);
            if ($err = $this->svc->storeNameError(trim($data['display_name']))) {
                return back()->with('error', $err);
            }
            $b['display_name'] = trim($data['display_name']);
            $b['theme_color'] = $this->svc->ensureContrast($data['theme_color']);
            if (! empty($data['logo'])) {
                $b['logo'] = $data['logo'];
            } elseif (! empty($data['remove_logo'])) {
                $b['logo'] = null;
            }
        } elseif ($section === 'contact') {
            $data = $request->validate([
                'phone' => ['nullable', 'string', 'max:20'],
                'whatsapp' => ['nullable', 'string', 'max:20'],
                'facebook' => ['nullable', 'string', 'max:200'],
                'address' => ['nullable', 'string', 'max:300'],
                'return_policy' => ['nullable', 'string', 'max:1000'],
                'footer_text' => ['nullable', 'string', 'max:300'],
            ]);
            $b['contact_phone'] = trim((string) ($data['phone'] ?? ''));
            $b['whatsapp'] = trim((string) ($data['whatsapp'] ?? ''));
            $b['facebook'] = trim((string) ($data['facebook'] ?? ''));
            $b['address'] = trim((string) ($data['address'] ?? ''));
            $b['return_policy'] = trim((string) ($data['return_policy'] ?? ''));
            $b['footer_text'] = trim((string) ($data['footer_text'] ?? ''));
        } elseif ($section === 'tracking') {
            $data = $request->validate([
                'pixel_id' => ['nullable', 'string', 'max:40'],
                'ga4_id' => ['nullable', 'string', 'max:40'],
                'capi_token' => ['nullable', 'string', 'max:500'],
            ]);
            $b['pixel_id'] = trim((string) ($data['pixel_id'] ?? ''));
            $b['ga4_id'] = trim((string) ($data['ga4_id'] ?? ''));
            if (! empty($data['capi_token'])) {
                $b['capi_token_enc'] = Crypt::encryptString(trim($data['capi_token']));
            }
        } elseif ($section === 'ordering') {
            $data = $request->validate([
                'delivery_inside' => ['required', 'numeric', 'min:0', 'max:10000'],
                'delivery_outside' => ['required', 'numeric', 'min:0', 'max:10000'],
            ]);
            $settings = (array) $sf->settings;
            $settings['delivery_inside'] = (float) $data['delivery_inside'];
            $settings['delivery_outside'] = (float) $data['delivery_outside'];
            $sf->settings = $settings;
        } else {
            return back()->with('error', 'Unknown settings section.');
        }
        $sf->branding = $b;
        $sf->save();
        $this->svc->forgetHostCache($sf);

        return back()->with('success', 'Saved.');
    }

    // ------------------------------------------------------------- door 2: connect website

    public function connect(Request $request): Response
    {
        $sf = $this->sf($request);
        $biz = $sf->business_id;
        $key = $this->connectionKey($biz);
        $apiOrders = Order::where('business_id', $biz)
            ->where(fn ($q) => $q->where('source', 'WEB')->orWhereNotNull('external_ref'))
            ->latest('id')->take(5)->get()
            ->map(fn ($o) => ['invoice' => $o->invoice_no, 'customer' => $o->customer_name, 'total' => (float) $o->grand_total,
                'status' => $o->status, 'at' => $o->created_at?->format('d M Y, h:i A')])->all();

        return Inertia::render('Storefront/Connect', [
            'sf' => $this->view($request, $sf),
            'endpoint' => url('/api/external/v1/orders'),
            'businessPublicId' => (string) (\App\Models\Business::find($biz)?->public_id ?? ''),
            'apiKey' => $key ? ['last4' => (string) $key->last4, 'label' => (string) $key->label,
                'last_used_at' => $key->last_used_at?->format('d M Y, h:i A'), 'webhook_url' => (string) ($key->webhook_url ?? '')] : null,
            'freshKey' => $request->session()->pull('fresh_api_key'),
            'apiOrders' => $apiOrders,
            'state' => $this->svc->menuState($biz),
            // Website integrations (WooCommerce + custom site kit): the
            // sections fetch their own data from the integrations JSON
            // endpoints; these props only drive the platform chooser's
            // default tab, the per-connection health cards and the
            // WooCommerce status-map visibility.
            'wooState' => (int) ($request->user()?->business_id ?? 0) === (int) $biz
                ? app(\App\Http\Controllers\Integrations\WooConnectController::class)->show($request)->getData(true)
                : ['configured' => false],
            'healthConnections' => \App\Models\IntegrationConnection::where('business_id', $biz)
                ->get(['id', 'platform'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'platform' => (string) $c->platform])->all(),
            'topUpUrl' => '/billing',
        ]);
    }

    /** Create or regenerate the connection key. The full key is shown ONCE. */
    public function connectKey(Request $request)
    {
        $sf = $this->sf($request);
        $plain = 'sk_'.Str::random(40);
        // Rotation replaces ONLY the connection key. Scoped keys the
        // seller made in the API keys table belong to other
        // integrations and must survive untouched.
        \App\Models\ApiKey::where('business_id', $sf->business_id)
            ->where('label', self::CONNECTION_KEY_LABEL)->delete();
        \App\Models\ApiKey::create([
            'business_id' => $sf->business_id,
            'key_hash' => hash('sha256', $plain),
            'last4' => substr($plain, -4),
            'label' => self::CONNECTION_KEY_LABEL,
            'webhook_secret_enc' => Crypt::encryptString(Str::random(32)),
        ]);

        return redirect('/storefront/connect')->with('fresh_api_key', $plain)
            ->with('success', 'Your new connection key is ready. Copy it now - it will not be shown in full again.');
    }

    public function connectWebhook(Request $request)
    {
        $sf = $this->sf($request);
        $data = $request->validate(['webhook_url' => ['nullable', 'string', 'max:300', 'url']]);
        $key = $this->connectionKey($sf->business_id);
        if (! $key) {
            return back()->with('error', 'Create your connection key first.');
        }
        $key->webhook_url = $data['webhook_url'] ?: null;
        $key->save();

        return back()->with('success', 'Webhook address saved.');
    }

    /** A test order through the same intake the seller's website uses. */
    public function connectTestOrder(Request $request)
    {
        $sf = $this->sf($request);
        $product = Product::where('business_id', $sf->business_id)->where('is_active', true)->first();
        try {
            $order = app(\App\Services\OrderService::class)->create($sf->business_id, [
                'customer_name' => 'Test Customer',
                'customer_phone' => '01700000000',
                'customer_address' => 'Test address, Dhaka',
                'district' => 'Dhaka',
                'source' => 'WEB',
                'site' => 'Connect Website test',
                'status' => 'Pending',
                'external_ref' => 'connect-test',
                'delivery_note' => 'Test order from Storefront → Connect Website. Cancel it any time.',
                'items' => [[
                    'product_id' => $product?->id,
                    'name' => $product?->name ?? 'Test item',
                    'qty' => 1,
                    'price' => $product ? $product->effectivePrice() : 100,
                ]],
            ], $request->user(), 'External API');
        } catch (\App\Exceptions\InsufficientTokensException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Test order created: '.$order->invoice_no.'. It is in your Orders list like a real website order.');
    }
}

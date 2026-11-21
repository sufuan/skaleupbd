<?php

namespace App\Services;

use App\Models\BusinessWallet;
use App\Models\OrderSource;
use App\Models\Storefront;
use App\Models\WorkTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M18 Storefront — slugs, blocks, host resolution, DNS, images, OTP and
 * the plan-lapse sweep. The blocks JSON on the storefront row is the
 * single source of truth for the public page.
 */
class StorefrontService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SmsService $sms,
    ) {}

    // ---------------------------------------------------------------- hosts

    public function platformDomain(): string
    {
        return strtolower((string) config('storefront.domain', 'scalenowbd.com'));
    }

    public function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    /** The platform's own hosts: the app/dashboard lives here, never a shop. */
    public function isMainHost(string $host): bool
    {
        $domain = $this->platformDomain();
        if (in_array($host, [$domain, 'www.'.$domain, 'localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        $appHost = $this->normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');

        return $appHost !== '' && $host === $appHost;
    }

    public function subdomainSlug(string $host): ?string
    {
        $suffix = '.'.$this->platformDomain();
        if (str_ends_with($host, $suffix)) {
            $slug = substr($host, 0, -strlen($suffix));

            return $slug !== '' && ! str_contains($slug, '.') ? $slug : null;
        }

        return null;
    }

    /**
     * Resolve a request host to a storefront (any status). Cached briefly;
     * returns ['storefront' => Storefront, 'kind' => 'subdomain'|'custom'].
     */
    public function resolveHost(string $host): ?array
    {
        $host = $this->normalizeHost($host);
        $id = Cache::remember('sfhost:'.$host, 45, function () use ($host) {
            $slug = $this->subdomainSlug($host);
            if ($slug !== null) {
                return Storefront::where('subdomain', $slug)->value('id') ?? 0;
            }

            return Storefront::where('custom_domain', $host)->where('domain_status', 'active')->value('id') ?? 0;
        });
        if (! $id) {
            return null;
        }
        $sf = Storefront::find($id);

        return $sf ? ['storefront' => $sf, 'kind' => $this->subdomainSlug($host) !== null ? 'subdomain' : 'custom'] : null;
    }

    public function forgetHostCache(Storefront $sf): void
    {
        if ($sf->subdomain) {
            Cache::forget('sfhost:'.$sf->subdomain.'.'.$this->platformDomain());
        }
        if ($sf->custom_domain) {
            Cache::forget('sfhost:'.$sf->custom_domain);
            Cache::forget('sfhost:www.'.$sf->custom_domain);
        }
        Cache::forget('sfpage:'.$sf->id);
    }

    // ---------------------------------------------------------------- slugs

    /** Names a seller can never claim: platform/reserved + brand impersonation. */
    public function blockedSlugs(): array
    {
        $reserved = ['www', 'app', 'admin', 'api', 'mail', 'ftp', 'cpanel', 'webmail', 'whm', 'webdisk',
            'autodiscover', 'support', 'help', 'blog', 'pricing', 'login', 'register', 'dashboard',
            'billing', 'store', 'shop', 'official', 'secure', 'account', 'accounts', 'ns1', 'ns2',
            'scalenowbd', 'scalenow', 'skaleup', 'skaleupbd'];
        $brands = ['bkash', 'nagad', 'rocket', 'upay', 'pathao', 'steadfast', 'redx', 'carrybee',
            'paperfly', 'ecourier', 'facebook', 'meta', 'instagram', 'whatsapp', 'google', 'youtube',
            'tiktok', 'daraz', 'bikroy', 'ajkerdeal', 'priyoshop', 'grameenphone', 'banglalink', 'robi'];
        $extra = (array) $this->settings->get('storefront.blocklist', []);

        return array_values(array_unique(array_merge($reserved, $brands, array_map('strtolower', $extra))));
    }

    /** @return array{state: string, message: string} state: available|taken|blocked|invalid */
    public function availability(string $slug, ?int $forBusinessId = null): array
    {
        $slug = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])?$/', $slug) || strlen($slug) < 3 || strlen($slug) > 30) {
            return ['state' => 'invalid', 'message' => 'Use 3–30 characters: lowercase letters, numbers and hyphens only.'];
        }
        if (in_array($slug, $this->blockedSlugs(), true)) {
            return ['state' => 'blocked', 'message' => 'This name is reserved and cannot be used.'];
        }
        $owner = Storefront::where('subdomain', $slug)->value('business_id');
        if ($owner !== null && (int) $owner !== (int) $forBusinessId) {
            return ['state' => 'taken', 'message' => 'This name is already taken by another shop.'];
        }

        return ['state' => 'available', 'message' => 'This name is available.'];
    }

    // ---------------------------------------------------------------- record

    /**
     * Rule 2: a shop address (subdomain / custom domain / publish)
     * unlocks only after the first pack payment succeeds — the
     * wallet's first_purchase_at, set by an approved claim or an
     * admin-recorded purchase. A manual points grant does NOT count.
     */
    public function hasPaid(int $businessId): bool
    {
        $wallet = BusinessWallet::where('business_id', $businessId)->first();

        return $wallet !== null && $wallet->first_purchase_at !== null;
    }

    /**
     * The address lock applies only while the shop has no address yet:
     * a shop that already saved a subdomain (or has an active custom
     * domain) keeps working even before its first purchase.
     */
    public function addressLocked(Storefront $sf): bool
    {
        if ($this->hasPaid((int) $sf->business_id)) {
            return false;
        }

        return empty($sf->subdomain) && $sf->domain_status !== 'active';
    }

    public function ensureFor(int $businessId): Storefront
    {
        return Storefront::firstOrCreate(
            ['business_id' => $businessId],
            [
                'status' => 'draft',
                'blocks' => $this->defaultBlocks(),
                'branding' => $this->defaultBranding($businessId),
                'settings' => ['delivery_inside' => 60, 'delivery_outside' => 120],
            ]
        );
    }

    public function defaultBranding(int $businessId): array
    {
        $name = \App\Models\Business::where('id', $businessId)->value('name') ?? 'My Shop';

        return [
            'display_name' => $name,
            'logo' => null,
            'theme_color' => '#411550',
            'pixel_id' => '',
            'capi_token_enc' => '',
            'ga4_id' => '',
            'footer_text' => '',
        ];
    }

    public function defaultBlocks(): array
    {
        $mk = fn (string $type, array $data = []) => ['id' => Str::random(8), 'type' => $type, 'data' => $data];

        return [
            $mk('hero', ['title' => 'Coming soon', 'subtitle' => '', 'image' => null, 'cta_text' => 'Shop now', 'cta_product_id' => null]),
            $mk('product_grid', ['source' => 'all', 'category_id' => null, 'count' => 24, 'title' => 'Our products']),
            $mk('faq', ['title' => 'Questions', 'items' => [
                ['q' => 'How do I order?', 'a' => 'Pick a product, fill in your name, phone and address, and press Order. We will call to confirm.'],
                ['q' => 'How do I pay?', 'a' => 'Cash on delivery — pay when your product arrives.'],
            ]]),
            $mk('legal', ['kind' => 'terms', 'title' => 'Terms & Conditions', 'text' => self::defaultLegalText('terms')]),
            $mk('legal', ['kind' => 'privacy', 'title' => 'Privacy Policy', 'text' => self::defaultLegalText('privacy')]),
            $mk('legal', ['kind' => 'return', 'title' => 'Return Policy', 'text' => self::defaultLegalText('return')]),
        ];
    }

    public static function defaultLegalText(string $kind): string
    {
        return match ($kind) {
            'privacy' => '<p>We collect your name, phone number and address only to process and deliver your order. We never sell your information. Order details are kept for record-keeping and customer support.</p>',
            'return' => '<p>If your product arrives damaged or is not what you ordered, contact us within 48 hours of delivery with a photo and we will replace it or refund you. Products must be unused and in their original packaging.</p>',
            default => '<p>By ordering from this shop you agree to provide a correct name, phone number and delivery address. Prices and delivery charges are confirmed at checkout. We may call to confirm your order before sending it. Delivery usually takes 24–72 hours depending on your area.</p>',
        };
    }

    public const BLOCK_TYPES = ['hero', 'product_single', 'product_grid', 'price_offer', 'order_form', 'testimonials', 'faq', 'countdown', 'legal'];

    // ---------------------------------------------------------------- sanitize

    /** Strip everything dangerous out of a rich-text field; keep simple formatting. */
    public function sanitizeHtml(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(script|style|iframe|object|embed|form|textarea|input|button|link|meta)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h3><h4>');
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//@*') as $attr) {
            $keep = $attr->nodeName === 'href' && $attr->ownerElement?->nodeName === 'a'
                && preg_match('#^https?://#i', trim($attr->value));
            if (! $keep) {
                $attr->ownerElement?->removeAttribute($attr->nodeName);
            }
        }
        $div = $dom->getElementsByTagName('div')->item(0);
        $out = '';
        if ($div) {
            foreach ($div->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return trim($out);
    }

    private function cleanText(?string $s, int $max = 500): string
    {
        return mb_substr(trim(strip_tags((string) $s)), 0, $max);
    }

    /** Validate + sanitise a blocks payload from the builder. */
    public function sanitizeBlocks(array $blocks): array
    {
        $out = [];
        foreach (array_slice($blocks, 0, 40) as $block) {
            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $d = (array) ($block['data'] ?? []);
            $clean = match ($type) {
                'hero' => [
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'subtitle' => $this->cleanText($d['subtitle'] ?? '', 240),
                    'image' => $this->cleanImagePath($d['image'] ?? null),
                    'cta_text' => $this->cleanText($d['cta_text'] ?? '', 40),
                    'cta_product_id' => ! empty($d['cta_product_id']) ? (int) $d['cta_product_id'] : null,
                ],
                'product_single' => ['product_id' => ! empty($d['product_id']) ? (int) $d['product_id'] : null],
                'product_grid' => [
                    'source' => ($d['source'] ?? 'all') === 'category' ? 'category' : 'all',
                    'category_id' => ! empty($d['category_id']) ? (int) $d['category_id'] : null,
                    'count' => max(1, min(24, (int) ($d['count'] ?? 24))),
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                ],
                'price_offer' => [
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'text' => $this->sanitizeHtml($d['text'] ?? ''),
                    'product_id' => ! empty($d['product_id']) ? (int) $d['product_id'] : null,
                ],
                'order_form' => [
                    'product_id' => ! empty($d['product_id']) ? (int) $d['product_id'] : null,
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                ],
                'testimonials' => [
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'items' => collect((array) ($d['items'] ?? []))->take(6)->map(fn ($i) => [
                        'name' => $this->cleanText($i['name'] ?? '', 80),
                        'text' => $this->cleanText($i['text'] ?? '', 300),
                    ])->all(),
                ],
                'faq' => [
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'items' => collect((array) ($d['items'] ?? []))->take(10)->map(fn ($i) => [
                        'q' => $this->cleanText($i['q'] ?? '', 200),
                        'a' => $this->cleanText($i['a'] ?? '', 500),
                    ])->all(),
                ],
                'countdown' => [
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'ends_at' => ! empty($d['ends_at']) ? date('Y-m-d H:i:s', strtotime((string) $d['ends_at']) ?: time()) : null,
                ],
                'legal' => [
                    'kind' => in_array($d['kind'] ?? '', ['terms', 'privacy', 'return'], true) ? $d['kind'] : 'terms',
                    'title' => $this->cleanText($d['title'] ?? '', 120),
                    'text' => $this->sanitizeHtml($d['text'] ?? ''),
                ],
                default => [],
            };
            $out[] = ['id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? Str::random(8))) ?: Str::random(8),
                'type' => $type, 'data' => $clean];
        }

        return $out;
    }

    private function cleanImagePath(mixed $path): ?string
    {
        $path = trim((string) $path);

        return $path !== '' && ! preg_match('#^(https?:)?//#i', $path) && ! str_contains($path, '..') ? $path : null;
    }

    // ---------------------------------------------------------------- images

    public function imageQuotaBytes(): int
    {
        return max(1, (int) $this->settings->get('storefront.image_quota_mb', 20)) * 1024 * 1024;
    }

    public function imageUsageBytes(int $businessId): int
    {
        $dir = Storage::disk('public')->path('storefront/'.$businessId);
        if (! is_dir($dir)) {
            return 0;
        }
        $sum = 0;
        foreach (glob($dir.'/*') ?: [] as $f) {
            $sum += is_file($f) ? filesize($f) : 0;
        }

        return $sum;
    }

    /** Convert an uploaded image to WebP under the seller's quota; returns the public-disk path. */
    public function storeImage(int $businessId, UploadedFile $file): string
    {
        if (! $file->isValid() || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw ValidationException::withMessages(['image' => 'Please choose a valid image file.']);
        }
        $src = match ($file->getMimeType()) {
            'image/jpeg' => @imagecreatefromjpeg($file->getPathname()),
            'image/png' => @imagecreatefrompng($file->getPathname()),
            'image/gif' => @imagecreatefromgif($file->getPathname()),
            'image/webp' => @imagecreatefromwebp($file->getPathname()),
            default => null,
        };
        if (! $src) {
            throw ValidationException::withMessages(['image' => 'This image format is not supported.']);
        }
        // Cap dimensions at 1600px on the long side — storefront images never need more.
        $w = imagesx($src);
        $h = imagesy($src);
        $max = 1600;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $resized = imagecreatetruecolor((int) ($w * $scale), (int) ($h * $scale));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, (int) ($w * $scale), (int) ($h * $scale), $w, $h);
            imagedestroy($src);
            $src = $resized;
        }
        $name = 'storefront/'.$businessId.'/'.Str::random(24).'.webp';
        $full = Storage::disk('public')->path($name);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        imagewebp($src, $full, 82);
        imagedestroy($src);
        $size = filesize($full) ?: 0;
        if ($this->imageUsageBytes($businessId) > $this->imageQuotaBytes()) {
            @unlink($full);
            throw ValidationException::withMessages(['image' => 'Your storefront image quota is full. Delete some images or ask the platform for more space.']);
        }

        return $name;
    }

    // ---------------------------------------------------------------- channels / OTP

    /** The shop's connected Facebook Page ID (Settings → Messaging Channels), if any. */
    public function facebookPageId(int $businessId): ?string
    {
        $cfg = (array) $this->settings->get('channels.facebook', []);
        $page = trim((string) ($cfg['account_id'] ?? ''));

        return $page !== '' ? $page : null;
    }

    /**
     * Send a 6-digit publish-verification code to the seller's mobile.
     * Uses the seller's own SMS setup; if that cannot send, the platform's
     * own (business 1) SMS setup sends it instead — the code must arrive
     * or the seller can never publish.
     */
    public function sendOtp(Storefront $sf, string $phone): array
    {
        $phone = PhoneNormalizer::normalize($phone);
        $code = (string) random_int(100000, 999999);
        $sf->phone_to_verify = $phone;
        $sf->otp_hash = Hash::make($code);
        $sf->otp_expires_at = now()->addMinutes(10);
        $sf->save();

        $body = "Your SkaleUp storefront verification code is {$code}. It is valid for 10 minutes.";
        $source = ['type' => 'transactional', 'key' => 'storefront_otp', 'label' => 'Storefront verification'];
        // Sender: the seller's own gateway when they saved one, else the
        // platform's (business 1). The send is transactional — it is not
        // gated on any shop's prepaid marketing balance (a fresh seller
        // has ৳0 there even though the platform gateway is funded).
        $via = $sf->business_id;
        if ((int) $sf->business_id !== 1 && ! $this->sms->providerConfig((int) $sf->business_id)['has_key']) {
            $via = 1;
        }
        $log = $this->sms->sendPlatform($via, $phone, $body, $source, (int) $sf->business_id);
        $sent = $log->status === 'sent';
        if (! $sent) {
            // The code never left — it must not be usable if it leaks
            // from the log row. A fresh Send code issues a new one.
            $sf->otp_hash = null;
            $sf->otp_expires_at = null;
            $sf->save();
        }

        return ['sent' => $sent, 'via' => $via, 'phone' => $phone, 'error' => $sent ? null : $log->error];
    }

    public function verifyOtp(Storefront $sf, string $code): bool
    {
        if (! $sf->otp_hash || ! $sf->otp_expires_at || $sf->otp_expires_at->isPast()) {
            return false;
        }
        if (! Hash::check(trim($code), $sf->otp_hash)) {
            return false;
        }
        $sf->phone_verified_at = now();
        $sf->otp_hash = null;
        $sf->otp_expires_at = null;
        $sf->save();

        return true;
    }

    // ---------------------------------------------------------------- domains

    /** DNS TXT lookup — a seam so engine checks can stub resolution. */
    protected function dnsTxtRecords(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $r) {
            $out[] = $r['txt'] ?? implode('', (array) ($r['entries'] ?? []));
        }

        return $out;
    }

    public function domainTxtFound(Storefront $sf): bool
    {
        if (! $sf->custom_domain || ! $sf->domain_txt_token) {
            return false;
        }
        foreach (['_scalenow-verify.'.$sf->custom_domain, $sf->custom_domain] as $host) {
            foreach ($this->dnsTxtRecords($host) as $txt) {
                if (str_contains((string) $txt, $sf->domain_txt_token)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function domainCap(): int
    {
        return max(1, (int) $this->settings->get('storefront.custom_domain_cap', 18));
    }

    public function domainWarnAt(): int
    {
        return max(1, (int) $this->settings->get('storefront.warn_at', 15));
    }

    public function activeDomainCount(): int
    {
        return Storefront::where('domain_status', 'active')->whereNotNull('custom_domain')->count();
    }

    public function activationAllowed(): bool
    {
        return $this->activeDomainCount() < $this->domainCap();
    }

    // ---------------------------------------------------------------- lifecycle

    /** The moment the shop's current pack container ends (null = never had one). */
    public function containerEnd(int $businessId): ?\Carbon\Carbon
    {
        $wallet = BusinessWallet::where('business_id', $businessId)->first();
        if (! $wallet) {
            return null;
        }
        $ends = array_filter([$wallet->cycle_end, $wallet->wallet_expires_at]);

        return $ends ? max($ends) : null;
    }

    public function hasActiveContainer(int $businessId): bool
    {
        $end = $this->containerEnd($businessId);

        return $end !== null && $end->isFuture();
    }

    /**
     * Daily sweep: a published storefront whose pack container has been
     * gone for longer than the grace window is unpublished (back to
     * draft) and the seller gets a Work Inbox task.
     */
    public function lapseSweep(): array
    {
        $grace = max(0, (int) $this->settings->get('storefront.lapse_grace_days', 7));
        $stats = ['checked' => 0, 'unpublished' => 0];
        foreach (Storefront::where('status', 'published')->get() as $sf) {
            $stats['checked']++;
            if ($this->hasActiveContainer($sf->business_id)) {
                continue;
            }
            $since = $this->containerEnd($sf->business_id) ?? $sf->published_at ?? $sf->created_at;
            if (! $since || now()->lte($since->copy()->addDays($grace))) {
                continue;
            }
            $sf->status = 'draft';
            $sf->unpublished_reason = 'plan_lapsed';
            $sf->save();
            $this->forgetHostCache($sf);
            $exists = WorkTask::where('business_id', $sf->business_id)
                ->where('source_type', 'storefront_lapse')->where('source_id', $sf->id)
                ->whereIn('status', WorkTask::OPEN_STATUSES ?? ['open', 'in_progress'])->exists();
            if (! $exists) {
                WorkTask::create([
                    'business_id' => $sf->business_id,
                    'rule_key' => null,
                    'source' => 'storefront',
                    'title' => 'Storefront unpublished — your pack has ended',
                    'detail' => 'Your storefront was taken offline because your points pack ended and the grace period is over. Buy a pack and publish again from Storefront → Overview.',
                    'priority' => 'high',
                    'status' => 'open',
                    'assignee_mode' => 'pool',
                    'source_type' => 'storefront_lapse',
                    'source_id' => $sf->id,
                    'source_label' => 'Storefront',
                    'link' => '/storefront',
                    'due_at' => now()->addDay(),
                ]);
            }
            $stats['unpublished']++;
        }

        return $stats;
    }

    /** The order source every storefront order carries (find-or-create per business). */
    public function orderSource(int $businessId): string
    {
        OrderSource::firstOrCreate(
            ['business_id' => $businessId, 'code' => 'STOREFRONT'],
            ['label' => 'Storefront', 'is_visible' => true, 'sort' => 20]
        );

        return 'STOREFRONT';
    }

    /** Bangladesh districts for the public order form (longest first). */
    public static function districts(): array
    {
        return ['Dhaka', 'Chattogram', 'Khulna', 'Rajshahi', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh',
            'Comilla', 'Narayanganj', 'Gazipur', 'Narsingdi', "Cox's Bazar", 'Bogura', 'Dinajpur', 'Jashore',
            'Kushtia', 'Pabna', 'Tangail', 'Faridpur', 'Noakhali', 'Feni', 'Brahmanbaria', 'Chandpur',
            'Lakshmipur', 'Habiganj', 'Moulvibazar', 'Sunamganj', 'Sirajganj', 'Naogaon', 'Natore',
            'Joypurhat', 'Chapainawabganj', 'Satkhira', 'Bagerhat', 'Chuadanga', 'Jhenaidah', 'Magura',
            'Meherpur', 'Narail', 'Gopalganj', 'Madaripur', 'Rajbari', 'Shariatpur', 'Manikganj',
            'Munshiganj', 'Kishoreganj', 'Netrokona', 'Jamalpur', 'Sherpur', 'Kurigram', 'Lalmonirhat',
            'Nilphamari', 'Panchagarh', 'Thakurgaon', 'Gaibandha', 'Rangamati', 'Khagrachari', 'Bandarban',
            'Barguna', 'Patuakhali', 'Pirojpur', 'Jhalokati', 'Bhola'];
    }
}

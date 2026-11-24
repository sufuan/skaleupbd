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
    // Phone-OTP guards (ধাপ ৭ Stage A), mirroring the registration
    // OTP numbers in RegistrationVerificationService.
    public const OTP_MAX_ATTEMPTS = 5;

    public const OTP_TTL_MINUTES = 10;

    public const OTP_RESEND_COOLDOWN_SECONDS = 60;

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

            $fromTable = \App\Models\StorefrontDomain::where('domain', $host)->where('status', 'active')->value('storefront_id');
            if ($fromTable) {
                return $fromTable;
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

    /**
     * Read-only twin of ensureFor() for view-as-seller (ধাপ ৩): the
     * existing storefront, or an UNSAVED draft carrying the same
     * defaults ensureFor would persist - never a write.
     */
    public function findOrDraft(int $businessId): Storefront
    {
        return Storefront::where('business_id', $businessId)->first()
            ?? new Storefront([
                'business_id' => $businessId,
                'status' => 'draft',
                'blocks' => $this->defaultBlocks(),
                'branding' => $this->defaultBranding($businessId),
                'settings' => ['delivery_inside' => 60, 'delivery_outside' => 120],
            ]);
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
                ['q' => 'How do I pay?', 'a' => 'Cash on delivery - pay when your product arrives.'],
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

    /** Parse a seller-supplied date/time into 'Y-m-d H:i:s' (the wall
     *  clock the seller picked), or null when empty/unparseable. */
    private function cleanDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
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
    public function storeImage(int $businessId, UploadedFile $file, int $maxDim = 1600): string
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
        // Cap dimensions on the long side (1600px pages, 256px logos) —
        // re-encoding to WebP also strips EXIF and any payload.
        $w = imagesx($src);
        $h = imagesy($src);
        $max = $maxDim;
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
        // ধাপ ৫: the shop's OWN Facebook Page (its connection row);
        // the legacy global config remains the fallback for the shop
        // the old single connection belonged to.
        $conn = \App\Models\ChannelConnection::forBusiness($businessId, 'facebook');
        if ($conn && trim((string) $conn->external_id) !== '') {
            return trim((string) $conn->external_id);
        }
        $cfg = (array) $this->settings->get('channels.facebook', []);
        $page = trim((string) ($cfg['account_id'] ?? ''));

        return $page !== '' ? $page : null;
    }

    /** Seconds left before another code may be sent to this shop (0 = send now). */
    public function otpCooldownSeconds(Storefront $sf): int
    {
        if (! $sf->otp_last_sent_at) {
            return 0;
        }

        return max(0, self::OTP_RESEND_COOLDOWN_SECONDS - (int) $sf->otp_last_sent_at->diffInSeconds(now()));
    }

    /**
     * Send a 6-digit publish-verification code to the seller's mobile.
     * Uses the seller's own SMS setup; if that cannot send, the platform's
     * own (business 1) SMS setup sends it instead — the code must arrive
     * or the seller can never publish.
     *
     * One code per minute: inside the resend cooldown nothing is sent
     * and the live code stays untouched; the result then carries
     * 'cooldown' => seconds remaining. A fresh send always issues a NEW
     * code (the old one stops working) and resets the attempt count.
     */
    public function sendOtp(Storefront $sf, string $phone): array
    {
        $phone = PhoneNormalizer::normalize($phone);
        $wait = $this->otpCooldownSeconds($sf);
        if ($wait > 0) {
            return ['sent' => false, 'via' => (int) $sf->business_id, 'phone' => $phone, 'error' => null, 'cooldown' => $wait];
        }
        $code = (string) random_int(100000, 999999);
        $sf->phone_to_verify = $phone;
        $sf->otp_hash = Hash::make($code);
        $sf->otp_expires_at = now()->addMinutes(self::OTP_TTL_MINUTES);
        $sf->otp_attempts = 0;
        $sf->otp_last_sent_at = now();
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
        // Attempt cap (ধাপ ৭ Stage A): after OTP_MAX_ATTEMPTS wrong
        // tries the code is dead, even for the right code — the seller
        // asks for a fresh one (subject to the resend cooldown).
        if ((int) $sf->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            return false;
        }
        if (! Hash::check(trim($code), $sf->otp_hash)) {
            $sf->increment('otp_attempts');
            if ((int) $sf->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
                $sf->otp_hash = null;
                $sf->otp_expires_at = null;
                $sf->save();
            }

            return false;
        }
        $sf->phone_verified_at = now();
        $sf->otp_hash = null;
        $sf->otp_expires_at = null;
        $sf->otp_attempts = 0;
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

        return $this->domainTxtMatches($sf->custom_domain, $sf->domain_txt_token);
    }

    public function domainTxtFoundFor(\App\Models\StorefrontDomain $domain): bool
    {
        if (! $domain->txt_token) {
            return false;
        }

        return $this->domainTxtMatches($domain->domain, $domain->txt_token);
    }

    private function domainTxtMatches(string $domain, string $token): bool
    {
        foreach (['_scalenow-verify.'.$domain, '_skaleup-verify.'.$domain, $domain] as $host) {
            foreach ($this->dnsTxtRecords($host) as $txt) {
                if (str_contains((string) $txt, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Keep the storefront row's legacy domain columns mirroring
     *  its primary domain row (admin screens + resolver read them). */
    public function syncDomainMirror(Storefront $sf): void
    {
        $primary = $sf->primaryDomain();
        $sf->custom_domain = $primary?->domain;
        $sf->domain_status = $primary ? $primary->status : 'none';
        $sf->domain_txt_token = $primary?->txt_token;
        $sf->domain_verified_at = $primary?->verified_at;
        $sf->domain_added_at = $primary?->added_at;
        $sf->save();
        $this->forgetHostCache($sf);
        if ($primary) {
            \Illuminate\Support\Facades\Cache::forget('sfhost:'.$primary->domain);
            \Illuminate\Support\Facades\Cache::forget('sfhost:www.'.$primary->domain);
        }
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
        // Union of the multi-domain rows and any storefront whose legacy
        // single-domain columns say active without a matching active row
        // (the columns mirror the primary row, but never under-count).
        $activeRowDomains = \App\Models\StorefrontDomain::where('status', 'active')->pluck('domain')->all();
        $legacyOnly = Storefront::where('domain_status', 'active')
            ->whereNotNull('custom_domain')
            ->whereNotIn('custom_domain', $activeRowDomains ?: [''])
            ->count();

        return count($activeRowDomains) + $legacyOnly;
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
        $this->flushViewStats();
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
                    'title' => 'Storefront unpublished - your pack has ended',
                    'detail' => 'Your storefront was taken offline because your points pack ended and the grace period is over. Buy a pack and publish again from Storefront → Overview.',
                    'priority' => 'urgent',
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

    // ------------------------------------------------------------ pages (v2)

    public const PAGE_BLOCK_TYPES = ['hero', 'offer', 'products', 'faq', 'order_form', 'info', 'countdown'];

    public const TEMPLATES = ['single', 'store', 'offer'];

    public const PRESET_COLORS = ['#e11d48', '#ea580c', '#15803d', '#2347e8', '#7c3aed', '#111827'];

    /** Validate + sanitise a v2 page-blocks payload ({version, blocks:[{id,type,props}]}). */
    public function sanitizePageBlocks(mixed $payload): array
    {
        $list = is_array($payload) && array_key_exists('blocks', $payload) ? (array) $payload['blocks'] : (array) $payload;
        $out = [];
        foreach (array_slice($list, 0, 12) as $block) {
            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, self::PAGE_BLOCK_TYPES, true)) {
                continue;
            }
            $p = (array) ($block['props'] ?? $block['data'] ?? []);
            $clean = match ($type) {
                'hero' => [
                    'title' => $this->cleanText($p['title'] ?? '', 60),
                    'subtitle' => $this->cleanText($p['subtitle'] ?? '', 120),
                    'image' => $this->cleanImagePath($p['image'] ?? null),
                    'alt' => $this->cleanText($p['alt'] ?? '', 120),
                    'hidden' => (bool) ($p['hidden'] ?? false),
                ],
                'offer' => [
                    'text' => $this->cleanText($p['text'] ?? '', 80),
                    'hidden' => (bool) ($p['hidden'] ?? false),
                ],
                'products' => [
                    'product_ids' => collect((array) ($p['product_ids'] ?? []))->map(fn ($i) => (int) $i)
                        ->filter(fn ($i) => $i > 0)->unique()->take(24)->values()->all(),
                    'title' => $this->cleanText($p['title'] ?? '', 120),
                ],
                'faq' => [
                    'items' => collect((array) ($p['items'] ?? []))->take(10)->map(fn ($i) => [
                        'q' => $this->cleanText($i['q'] ?? '', 200),
                        'a' => $this->cleanText($i['a'] ?? '', 500),
                    ])->filter(fn ($i) => $i['q'] !== '' || $i['a'] !== '')->values()->all(),
                    'hidden' => (bool) ($p['hidden'] ?? false),
                ],
                'order_form' => [
                    'product_id' => ! empty($p['product_id']) ? (int) $p['product_id'] : null,
                    'title' => $this->cleanText($p['title'] ?? '', 120),
                ],
                // Nothing editable: the strip renders from Store Settings.
                'info' => [
                    'hidden' => (bool) ($p['hidden'] ?? false),
                ],
                'countdown' => [
                    'ends_at' => $this->cleanDateTime($p['ends_at'] ?? null),
                    'hidden' => (bool) ($p['hidden'] ?? false),
                ],
                default => [],
            };
            $out[] = ['id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? Str::random(8))) ?: Str::random(8),
                'type' => $type, 'props' => $clean];
        }

        return ['version' => 2, 'blocks' => $out];
    }

    /** Default FAQ copy (Bangla) every new page starts with. */
    public function defaultFaqItems(): array
    {
        return [
            ['q' => 'ডেলিভারি কতদিনে পাবো?', 'a' => 'ঢাকায় ১–২ দিন, ঢাকার বাইরে ৩–৫ দিন।'],
            ['q' => 'ক্যাশ অন ডেলিভারি আছে?', 'a' => 'হ্যাঁ, পণ্য হাতে পেয়ে টাকা দিন।'],
        ];
    }

    /** The starting blocks of a page made from a template. Products are
     *  automatic (live Inventory) — a products block with no ids means
     *  "all the shop's products", an order form with no product resolves
     *  to the page's first product at render time. */
    public function templateBlocks(string $template, string $storeName): array
    {
        $mk = fn (string $type, array $props = []) => ['id' => Str::random(8), 'type' => $type, 'props' => $props];
        $hero = $mk('hero', ['title' => $storeName, 'subtitle' => 'সরাসরি আমাদের কাছ থেকে কিনুন', 'image' => null, 'alt' => '', 'hidden' => false]);
        $products = $mk('products', ['product_ids' => [], 'title' => '']);
        $form = $mk('order_form', ['product_id' => null, 'title' => 'অর্ডার করুন']);
        $faq = $mk('faq', ['items' => $this->defaultFaqItems(), 'hidden' => false]);
        $blocks = match ($template) {
            'offer' => [$hero, $mk('offer', ['text' => 'আজই অর্ডার করলে ডেলিভারিতে ছাড়', 'hidden' => false]), $products, $form, $faq],
            'single' => [$hero, $products, $form, $faq],
            default => [$hero, $products, $faq, $form],
        };

        return ['version' => 2, 'blocks' => $blocks];
    }

    /** Convert legacy (v1) blocks into the v2 editor model. Lossy by
     *  design: testimonials/legal are not v2 editor sections (legal
     *  pages are appended by the renderer automatically). A v1
     *  countdown maps to the v2 countdown block (its end time carries
     *  over; its custom title does not — v2 locks the label). */
    public function legacyToV2(array $legacy): array
    {
        $out = [];
        $formProduct = null;
        foreach ($legacy as $block) {
            $type = (string) ($block['type'] ?? '');
            $d = (array) ($block['data'] ?? []);
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? Str::random(8))) ?: Str::random(8);
            switch ($type) {
                case 'hero':
                    $out[] = ['id' => $id, 'type' => 'hero', 'props' => [
                        'title' => mb_substr((string) ($d['title'] ?? ''), 0, 60),
                        'subtitle' => mb_substr((string) ($d['subtitle'] ?? ''), 0, 120),
                        'image' => $d['image'] ?? null, 'alt' => '', 'hidden' => false]];
                    if (! empty($d['cta_product_id'])) {
                        $formProduct = (int) $d['cta_product_id'];
                    }
                    break;
                case 'price_offer':
                    $text = trim(strip_tags((string) ($d['text'] ?? ''))) ?: (string) ($d['title'] ?? '');
                    $out[] = ['id' => $id, 'type' => 'offer', 'props' => ['text' => mb_substr($text, 0, 80), 'hidden' => false]];
                    if (! empty($d['product_id'])) {
                        $formProduct = (int) $d['product_id'];
                    }
                    break;
                case 'product_grid':
                    $out[] = ['id' => $id, 'type' => 'products', 'props' => ['product_ids' => [], 'title' => (string) ($d['title'] ?? '')]];
                    break;
                case 'product_single':
                    $pid = ! empty($d['product_id']) ? (int) $d['product_id'] : null;
                    $out[] = ['id' => $id, 'type' => 'products', 'props' => ['product_ids' => $pid ? [$pid] : [], 'title' => '']];
                    if ($pid) {
                        $formProduct = $pid;
                    }
                    break;
                case 'order_form':
                    $out[] = ['id' => $id, 'type' => 'order_form', 'props' => [
                        'product_id' => ! empty($d['product_id']) ? (int) $d['product_id'] : null,
                        'title' => (string) ($d['title'] ?? 'অর্ডার করুন')]];
                    break;
                case 'faq':
                    $out[] = ['id' => $id, 'type' => 'faq', 'props' => ['items' => (array) ($d['items'] ?? []), 'hidden' => false]];
                    break;
                case 'countdown':
                    $out[] = ['id' => $id, 'type' => 'countdown', 'props' => [
                        'ends_at' => ! empty($d['ends_at']) ? (string) $d['ends_at'] : null, 'hidden' => false]];
                    break;
                default:
                    break; // testimonials, legal: not editor sections
            }
        }
        $types = array_column($out, 'type');
        if (! in_array('products', $types, true)) {
            $out[] = ['id' => Str::random(8), 'type' => 'products', 'props' => ['product_ids' => [], 'title' => '']];
        }
        if (! in_array('order_form', $types, true)) {
            $out[] = ['id' => Str::random(8), 'type' => 'order_form', 'props' => ['product_id' => $formProduct, 'title' => 'অর্ডার করুন']];
        }

        return $this->sanitizePageBlocks(['version' => 2, 'blocks' => $out]);
    }

    /** Live products for a v2 products block: the listed own active
     *  products, or all of the shop's active products when none listed. */
    public function pageProducts(Storefront $sf, array $props): array
    {
        $ids = collect((array) ($props['product_ids'] ?? []))->map(fn ($i) => (int) $i)->filter()->all();
        $q = \App\Models\Product::where('business_id', $sf->business_id)->where('is_active', true);
        if ($ids) {
            $found = $q->whereIn('id', $ids)->get()->keyBy('id');

            return collect($ids)->map(fn ($id) => $found->get($id))->filter()->values()->all();
        }

        return $q->latest('id')->limit(24)->get()->all();
    }

    /** Darken a hex colour until white text on it passes WCAG AA (4.5:1). */
    public function ensureContrast(string $hex): string
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return '#411550';
        }
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $lum = function (int $r, int $g, int $b): float {
            $f = fn (int $c) => ($c / 255) <= 0.03928 ? ($c / 255) / 12.92 : pow((($c / 255) + 0.055) / 1.055, 2.4);

            return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
        };
        for ($i = 0; $i < 24 && (1.05 / ($lum($r, $g, $b) + 0.05)) < 4.5; $i++) {
            $r = (int) ($r * 0.85);
            $g = (int) ($g * 0.85);
            $b = (int) ($b * 0.85);
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** Brand names a store name may not impersonate (token match, plus
     *  a flat contains-match for the longer brands so "BkashShop" is
     *  caught without refusing honest names like "Robiul Store"). */
    public const BRAND_NAMES = ['bkash', 'nagad', 'rocket', 'upay', 'pathao', 'steadfast', 'redx',
        'carrybee', 'paperfly', 'ecourier', 'facebook', 'meta', 'instagram', 'whatsapp', 'google',
        'youtube', 'tiktok', 'daraz', 'bikroy', 'grameenphone', 'banglalink', 'robi', 'scalenow', 'skaleup'];

    /** Store-name safety: length is validated by callers; this rejects
     *  brand impersonation and abusive words. Returns an error or null. */
    public function storeNameError(string $name): ?string
    {
        $lower = strtolower($name);
        $tokens = preg_split('/[^a-z0-9]+/', $lower) ?: [];
        $flat = preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
        foreach (self::BRAND_NAMES as $bad) {
            if (in_array($bad, $tokens, true) || (strlen($bad) >= 5 && str_contains($flat, $bad))) {
                return 'This name looks like another brand. Please choose your own shop name.';
            }
        }
        foreach (['fuck', 'shit', 'bitch', 'porn'] as $bad) {
            if (in_array($bad, $tokens, true) || str_contains($flat, $bad)) {
                return 'Please choose a proper shop name.';
            }
        }

        return null;
    }

    /** Friendly alternatives when a slug is taken at publish time. */
    public function slugSuggestions(string $slug, int $businessId): array
    {
        $out = [];
        foreach (['-shop', '-bd', '-store', '-1', '-2'] as $suffix) {
            $candidate = mb_substr($slug, 0, 30 - strlen($suffix)).$suffix;
            if ($this->availability($candidate, $businessId)['state'] === 'available') {
                $out[] = $candidate;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ menu state

    /**
     * The server-computed Storefront menu state (brief §1): the
     * frontend renders exactly this and never decides visibility.
     * @return array{hasStore: bool, mode: string, suspended: bool, hasPaid: bool, phoneVerified: bool, menu: string[], subdomain: ?string}
     */
    public function menuState(int $businessId): array
    {
        $sf = Storefront::where('business_id', $businessId)->first();
        $hasStore = $sf !== null && $sf->hasStore();
        $mode = $sf?->mode() ?? 'hosted';
        $suspended = $sf !== null && $sf->status === 'taken_down';
        $menu = [];
        if ($suspended) {
            $menu = ['overview'];
        } elseif ($hasStore) {
            $menu = ['overview', 'pages', 'settings'];
        } elseif ($mode === 'connect') {
            $menu = ['overview', 'connect'];
        } else {
            // No store yet and no path chosen: the Overview child is
            // the door to the wizard / path choice. Never return an
            // empty menu for this state - the shell renders an empty
            // children list as a toggle that opens onto nothing, and
            // a new seller would have no sidebar path to the wizard.
            $menu = ['overview'];
        }

        return [
            'hasStore' => $hasStore,
            'mode' => $mode,
            'suspended' => $suspended,
            'hasPaid' => $this->hasPaid($businessId),
            'phoneVerified' => $sf?->phone_verified_at !== null,
            'menu' => $menu,
            'subdomain' => $sf?->subdomain,
        ];
    }

    // ------------------------------------------------------------ wizard

    /**
     * The wizard's publish: one transaction that sets the (single,
     * immutable) subdomain, branding and the first live page, after
     * every gate in brief §2.1 has passed. Products are automatic.
     * @return array{ok: bool, error?: string, suggestions?: string[], storefront?: Storefront, page?: \App\Models\StorefrontPage}
     */
    public function wizardPublish(int $businessId, array $payload): array
    {
        $sf = $this->ensureFor($businessId);
        if (! empty($sf->subdomain)) {
            return ['ok' => false, 'error' => 'Your shop already has its link. It cannot be changed.'];
        }
        if (! $this->hasPaid($businessId)) {
            return ['ok' => false, 'error' => 'Your shop unlocks after your first pack payment is confirmed. Buy a points pack first.'];
        }
        if (! $sf->phone_verified_at) {
            return ['ok' => false, 'error' => 'Verify your mobile number first (the OTP step).'];
        }
        if (empty($payload['agree'])) {
            return ['ok' => false, 'error' => 'Please tick "I agree to the Storefront rules" to publish.'];
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return ['ok' => false, 'error' => 'Shop name must be 2–40 characters.'];
        }
        if ($err = $this->storeNameError($name)) {
            return ['ok' => false, 'error' => $err];
        }
        $template = in_array($payload['template'] ?? '', self::TEMPLATES, true) ? $payload['template'] : 'store';
        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $avail = $this->availability($slug, $businessId);
        if ($avail['state'] !== 'available') {
            return ['ok' => false, 'error' => $avail['message'], 'suggestions' => $this->slugSuggestions($slug, $businessId)];
        }
        $color = $this->ensureContrast((string) ($payload['color'] ?? '#411550'));
        $logo = $this->cleanImagePath($payload['logo'] ?? null);

        try {
            $page = \Illuminate\Support\Facades\DB::transaction(function () use ($sf, $name, $template, $slug, $color, $logo) {
                $b = (array) $sf->branding;
                $b['display_name'] = $name;
                $b['theme_color'] = $color;
                if ($logo) {
                    $b['logo'] = $logo;
                }
                $sf->branding = $b;
                $sf->subdomain = $slug;
                $settings = (array) $sf->settings;
                $settings['mode'] = 'hosted';
                $sf->settings = $settings;
                $sf->status = 'published';
                $sf->published_at = now();
                $sf->unpublished_reason = null;
                $sf->save();

                $blocks = $this->templateBlocks($template, $name);
                $page = $sf->pages()->create([
                    'business_id' => $sf->business_id,
                    'name' => $name,
                    'slug' => null,
                    'template' => $template,
                    'status' => 'live',
                    'draft_blocks' => $blocks,
                    'published_blocks' => $blocks,
                    'published_at' => now(),
                    'sort' => 0,
                ]);
                \App\Models\StorefrontOnboarding::where('business_id', $sf->business_id)->delete();

                return $page;
            });
        } catch (\Illuminate\Database\QueryException) {
            // The unique index caught a slug race — one winner only.
            return ['ok' => false, 'error' => 'This link was just taken by another shop.', 'suggestions' => $this->slugSuggestions($slug, $businessId)];
        }
        $this->forgetHostCache($sf);

        return ['ok' => true, 'storefront' => $sf->fresh(), 'page' => $page];
    }

    // ------------------------------------------------------------ page lifecycle

    /** Create an extra page (name + template; slug is system-made). */
    public function createPage(Storefront $sf, string $name, string $template, ?string $slugBase = null): \App\Models\StorefrontPage
    {
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'store';
        $brand = (string) (($sf->branding['display_name'] ?? '') ?: $name);
        $page = $sf->pages()->create([
            'business_id' => $sf->business_id,
            'name' => $name,
            'slug' => null,
            'template' => $template,
            'status' => 'draft',
            'draft_blocks' => $this->templateBlocks($template, $brand),
            'published_blocks' => null,
            'sort' => (int) $sf->pages()->max('sort') + 1,
        ]);
        // System-made slug: from the name when it slugifies, else the
        // page id; suffixed until unique inside this store. A duplicate
        // passes its source page's slug as the base (about-us -> about-us-2).
        $base = $slugBase !== null && $slugBase !== '' ? $slugBase : \Illuminate\Support\Str::slug($name);
        if (strlen($base) < 3) {
            $base = 'page-'.$page->id;
        }
        $candidate = $base;
        for ($i = 2; $sf->pages()->withTrashed()->where('slug', $candidate)->where('id', '!=', $page->id)->exists(); $i++) {
            $candidate = $base.'-'.$i;
        }
        $page->slug = $candidate;
        $page->save();

        return $page;
    }

    /**
     * The editor's publish-blocking checks, enforced on the server too —
     * the React dialog's disabled button is not a boundary, and a direct
     * POST must not publish what the UI calls unpublishable. Mirrors the
     * ERROR level of computeChecks() in Editor.jsx exactly: a hero needs
     * a title, and a visible countdown needs an end time in the future.
     * Warnings (stock, FAQ gaps) never block, here or there.
     */
    private function assertPublishable(\App\Models\StorefrontPage $page): void
    {
        $errors = [];
        foreach ((array) ($page->draft_blocks ?? []) as $b) {
            $p = (array) ($b['props'] ?? []);
            if (($b['type'] ?? '') === 'hero' && trim((string) ($p['title'] ?? '')) === '') {
                $errors[] = 'Top banner title is empty';
            }
            if (($b['type'] ?? '') === 'countdown' && empty($p['hidden'])) {
                $end = isset($p['ends_at']) && (string) $p['ends_at'] !== '' ? strtotime((string) $p['ends_at']) : false;
                if ($end === false || $end <= time()) {
                    $errors[] = 'Countdown end time is missing or has passed';
                }
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['blocks' => array_values(array_unique($errors))]);
        }
    }

    /** Publish changes: snapshot the current live blocks (keep 5), then copy draft → live. */
    public function publishChanges(\App\Models\StorefrontPage $page): void
    {
        $this->assertPublishable($page);
        \Illuminate\Support\Facades\DB::transaction(function () use ($page) {
            if (! empty($page->published_blocks)) {
                $page->versions()->create(['blocks' => $page->published_blocks]);
                $keep = $page->versions()->latest('id')->take(5)->pluck('id');
                $page->versions()->whereNotIn('id', $keep)->delete();
            }
            $page->published_blocks = $page->draft_blocks;
            $page->published_at = now();
            if ($page->status === 'draft') {
                $page->status = 'live';
            }
            $page->save();
        });
        $sf = $page->storefront;
        if ($sf) {
            $this->forgetHostCache($sf);
        }
    }

    /** Restore a snapshot into the DRAFT only — the live page and its
     *  status stay untouched until the seller publishes (spec §4/§8). */
    public function restoreVersion(\App\Models\StorefrontPage $page, \App\Models\StorefrontPageVersion $version): void
    {
        $page->draft_blocks = $version->blocks;
        $page->save();
    }

    // ------------------------------------------------------------ stats

    private function isBot(?string $ua): bool
    {
        $ua = strtolower((string) $ua);

        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless/', $ua);
    }

    /**
     * Count a page view WITHOUT a DB write per view: views accumulate
     * in cache counters and are flushed into storefront_daily_stats
     * in batches (every 20th view, and on the daily sweep).
     */
    public function recordView(\App\Models\StorefrontPage $page, ?string $userAgent): void
    {
        if ($this->isBot($userAgent)) {
            return;
        }
        $key = 'sfview:'.$page->id.':'.now()->toDateString();
        // The database cache store does not create a key on increment.
        \Illuminate\Support\Facades\Cache::add($key, 0, now()->addDays(3));
        $count = (int) \Illuminate\Support\Facades\Cache::increment($key);
        $registry = (array) (\Illuminate\Support\Facades\Cache::get('sfview:registry') ?: []);
        if (! in_array($key, $registry, true)) {
            $registry[] = $key;
            \Illuminate\Support\Facades\Cache::put('sfview:registry', $registry, now()->addDays(3));
        }
        if ($count % 20 === 0) {
            $this->flushViewStats();
        }
    }

    public function flushViewStats(): void
    {
        $registry = (array) (\Illuminate\Support\Facades\Cache::get('sfview:registry') ?: []);
        foreach ($registry as $key) {
            $count = (int) \Illuminate\Support\Facades\Cache::pull($key, 0);
            if ($count <= 0) {
                continue;
            }
            $parts = explode(':', $key);
            $pageId = (int) ($parts[1] ?? 0);
            $date = (string) ($parts[2] ?? '');
            if (! $pageId || ! $date) {
                continue;
            }
            $this->bumpDailyStat($pageId, 'views', $count, $date);
        }
        \Illuminate\Support\Facades\Cache::forget('sfview:registry');
    }

    /** Add to a page's daily counter (SQLite stores date casts as
     *  'Y-m-d 00:00:00', so the lookup must use whereDate). */
    public function bumpDailyStat(int $pageId, string $field, int $amount = 1, ?string $date = null): void
    {
        $date = $date ?: now()->toDateString();
        $row = \App\Models\StorefrontDailyStat::where('page_id', $pageId)->whereDate('date', $date)->first();
        if (! $row) {
            try {
                $row = \App\Models\StorefrontDailyStat::create(['page_id' => $pageId, 'date' => $date, 'views' => 0, 'orders' => 0]);
            } catch (\Illuminate\Database\QueryException) {
                $row = \App\Models\StorefrontDailyStat::where('page_id', $pageId)->whereDate('date', $date)->first();
            }
        }
        $row?->increment($field, $amount);
    }

    /** Views (flushed) + orders totals for one page. The root page also
     *  owns the shop's older orders that carry no page reference. */
    public function pageTotals(\App\Models\StorefrontPage $page): array
    {
        $views = (int) \App\Models\StorefrontDailyStat::where('page_id', $page->id)->sum('views');
        $orders = \App\Models\Order::where('storefront_page_id', $page->id);
        if ($page->isRoot()) {
            $orders->orWhere(fn ($q) => $q->where('storefront_id', $page->storefront_id)->whereNull('storefront_page_id'));
        }

        return ['views' => $views, 'orders' => $orders->count()];
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

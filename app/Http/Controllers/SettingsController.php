<?php

namespace App\Http\Controllers;

use App\Support\DhakaTime;
use App\Models\DeletionLog;
use App\Models\MessageTemplate;
use App\Models\OrderSource;
use App\Services\SettingsService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TotpService $totp,
    ) {}

    public function index(Request $request): Response
    {
        // Shop scope resolves through ShopContext (ধাপ ৩): a seller
        // sees his own shop; a platform admin inside view-as-seller
        // sees the VIEWED shop, with the redactions below applied.
        $biz = \App\Support\ShopContext::id($request);
        $isPlatform = (bool) ($request->user()?->is_platform_admin);
        $viewing = \App\Support\ShopContext::isViewMode($request);

        // Settings Home is computed from the shop's REAL state — the
        // checklist and cards must never show hardcoded prototype copy.
        $profileFilled = trim($this->settings->shopName($biz)) !== ''
            && trim($this->settings->shopContact($biz, 'mobile')) !== ''
            && trim($this->settings->shopContact($biz, 'address')) !== '';
        $courierOn = \App\Models\CourierMethod::where('business_id', $biz)->where('is_active', true)->exists();
        // ধাপ ৫: "connected" for a shop means ITS OWN connection row.
        // The legacy global config still counts for the platform
        // context and for the one shop the old routing pointed at
        // (channels.business_id), so the home checks do not change
        // for the connection that predates per-seller rows.
        $legacyBiz = (int) $this->settings->get('channels.business_id', 0);
        $ownRow = fn (string $ch) => $biz > 0 && \App\Models\ChannelConnection::where('business_id', $biz)
            ->where('channel', $ch)->where('status', '!=', 'disconnected')->whereNotNull('token_enc')->exists();
        $legacyOn = fn (string $ch) => ! empty(((array) $this->settings->get("channels.{$ch}", []))['token_enc'])
            && ($biz <= 0 || $biz === $legacyBiz);
        $fbOn = $ownRow('facebook') || $legacyOn('facebook');
        $waOn = $ownRow('whatsapp') || $legacyOn('whatsapp');
        $igOn = ! empty(((array) $this->settings->get('channels.instagram', []))['token_enc']);
        $apiKey = \App\Models\ApiKey::where('business_id', $biz)->latest('id')->first();
        $gw = app(\App\Services\PaymentGatewayService::class)->view($biz);
        $gatewayOn = ($gw['bkash_enabled'] && $gw['bkash_number'] !== '') || ($gw['nagad_enabled'] && $gw['nagad_number'] !== '')
            || ($gw['rocket_enabled'] && $gw['rocket_number'] !== '') || $gw['ssl_enabled'] || $gw['shurjo_enabled'];
        $connCount = (int) $courierOn + (int) $fbOn + (int) $waOn + (int) $igOn + (int) ($apiKey !== null) + (int) $gatewayOn;
        $visibleSources = OrderSource::where('business_id', $biz)->where('is_visible', true)->count();
        $autonomy = app(\App\Services\RuleBrainService::class)->autonomy($biz > 0 ? $biz : null);
        $aiModeLabel = ['auto' => 'Auto reply on', 'draft' => 'Draft only', 'off' => 'Auto reply off'][$autonomy['facebook'] ?? 'draft'] ?? 'Draft only';
        $totpOn = $this->totp->enabled();
        $homeStats = [
            'checks' => [
                ['label' => 'Business profile filled', 'done' => $profileFilled],
                ['label' => 'At least one courier connected', 'done' => $courierOn],
                ['label' => 'Facebook Page connected', 'done' => $fbOn],
                ['label' => 'WhatsApp connected', 'done' => $waOn],
                ['label' => 'Order sources set up', 'done' => $visibleSources > 0],
                ['label' => 'AI reply level chosen', 'done' => ! empty((array) $this->settings->bizGet($biz, "ai.{$biz}.autonomy", 'ai.autonomy', []))],
            ],
            'cards' => [
                ['label' => 'Business Settings', 'value' => $profileFilled ? 'Profile complete' : 'Profile incomplete', 'pill' => $profileFilled ? 'green' : 'amber'],
                ['label' => 'API Connection', 'value' => __(':count of 6 connected', ['count' => $connCount]), 'pill' => $connCount >= 6 ? 'green' : 'amber'],
                ['label' => 'Calls & Orders', 'value' => __(':count sources active', ['count' => $visibleSources]), 'pill' => $visibleSources > 0 ? 'green' : 'amber'],
                ['label' => 'AI & Auto Reply', 'value' => $aiModeLabel, 'pill' => ($autonomy['facebook'] ?? 'draft') === 'auto' ? 'green' : 'amber'],
                ['label' => 'Security & Records', 'value' => $totpOn ? 'Two-factor on' : 'Two-factor off', 'pill' => $totpOn ? 'green' : 'amber'],
            ],
        ];

        return Inertia::render('Settings/Index', [
            'home' => $homeStats,
            // False only for a platform admin outside view-as: there is
            // no shop in scope, so every shop-scoped group/tab renders
            // the "belongs to a shop" notice instead of a form whose
            // values would be a phantom business 0 (or worse, another
            // shop's legacy globals).
            'hasShop' => $biz > 0,
            'integration' => [
                'endpoint' => url('/api/external/v1/orders'),
                'api_key' => $apiKey ? ['last4' => (string) $apiKey->last4, 'label' => (string) $apiKey->label, 'last_used_at' => DhakaTime::fmt($apiKey->last_used_at, 'd M Y, h:i A')] : null,
            ],
            'group' => $request->query('group', 'home'),
            'couriers' => [
                'methods' => \App\Models\CourierMethod::where('business_id', $biz)->with('pickupStores')->orderBy('name')->get()
                    ->map(fn ($m) => \App\Http\Controllers\CourierSettingsController::methodView($m, $viewing))->all(),
                'rules' => \App\Models\CourierAssignmentRule::where('business_id', $biz)->with('method')->orderBy('priority')->get()
                    ->map(fn ($r) => ['id' => $r->id, 'scope' => $r->scope, 'product_id' => $r->product_id, 'category' => $r->category, 'district' => $r->district, 'area' => $r->area, 'method_id' => $r->courier_method_id, 'method_label' => $r->method?->label(), 'priority' => $r->priority])->all(),
                'products' => \App\Models\Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku'])->all(),
            ],
            'autoApprove' => [
                'enabled' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.auto_approve_enabled", 'orders.auto_approve_enabled', true),
                'threshold' => (int) $this->settings->bizGet($biz, "orders.{$biz}.auto_approve_threshold", 'orders.auto_approve_threshold', 60),
            ],
            'ai' => [
                'rules' => \App\Models\AiRule::where('business_id', $biz)->orderBy('id')->get()
                    ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'trigger_type' => $r->trigger_type, 'match_text' => $r->match_text, 'action' => $r->action, 'reply_template' => $r->reply_template, 'channel' => $r->channel, 'active' => (bool) $r->active])->all(),
                'autonomy' => app(\App\Services\RuleBrainService::class)->autonomy($biz > 0 ? $biz : null),
                'talker' => app(\App\Services\AiTalkerService::class)->config(),
                // The provider form (model + API keys) is the platform's:
                // only a platform admin outside view-as may edit it. A
                // seller sees it read-only - their own AI behaviour
                // (levels, negotiation, behaviour) is namespaced to
                // their shop and fully theirs to save.
                'talkerEditable' => $isPlatform && ! $viewing,
                'behavior' => $biz > 0
                    ? (array) $this->settings->bizGet($biz, "ai.{$biz}.behavior", 'ai.behavior', [])
                    : (array) $this->settings->get('ai.behavior', []),
                'working_hours' => $biz > 0
                    ? (string) $this->settings->bizGet($biz, "ai.{$biz}.working_hours", 'ai.working_hours', app()->getLocale() === 'bn' ? 'সকাল ৯টা - রাত ১১টা' : '9 AM - 11 PM')
                    : (string) $this->settings->get('ai.working_hours', app()->getLocale() === 'bn' ? 'সকাল ৯টা - রাত ১১টা' : '9 AM - 11 PM'),
                'negotiation' => app(\App\Services\NegotiationService::class)->config($biz > 0 ? $biz : null),
                'knowledge' => \App\Models\KnowledgeEntry::where('business_id', $biz)->orderBy('id')->get(['id', 'title', 'body'])->all(),
                'proposals' => \App\Models\AiRuleProposal::where('business_id', $biz)->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->get()
                    ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'detail' => $p->detail, 'status' => $p->status,
                        // A proposal is actionable only when approving
                        // it applies a real rule change; staff-edit
                        // records are notes, and the UI labels them so
                        // (Hunt Y2-7).
                        'actionable' => ! empty($p->rule_data['new_rule']) || ! empty($p->rule_data['edit_rule_match'])])->all(),
            ],
            // Staff & Roles group (ধাপ ৪): staff logins and seller-
            // defined roles live here now, not in HRM. Null for
            // platform admins and anyone without a shop.
            'staffRoles' => app(\App\Http\Controllers\StaffRolesController::class)->pageData($request),
            'totpEnabled' => $this->totp->enabled(),
            'totpEnrolled' => (bool) $request->user()?->hasTotpEnabled(),
            'isPlatform' => (bool) ($request->user()?->is_platform_admin),
            // Payment Gateway: seller scope for the caller's own shop;
            // platform scope only for platform admins. Same form, both.
            'paymentGateway' => $biz ? app(\App\Services\PaymentGatewayService::class)->view($biz, $viewing) : null,
            // The platform scope is a platform surface: it never
            // renders inside a viewed shop (ধাপ ৩).
            'platformPaymentGateway' => ($request->user()?->is_platform_admin && ! $viewing)
                ? app(\App\Services\PaymentGatewayService::class)->view(null) : null,
            // The Meta connection is the PLATFORM's single connection,
            // not the shop's: its values (the verify token above all)
            // are shared with platform admins only. Sellers receive the
            // same shape, empty, so the tab still renders. The secret
            // VALUE is never shared with anyone — only app_secret_set.
            // In view-as-seller the channels block takes the empty
            // (seller) shape: the Meta connection is the PLATFORM's
            // own surface and must not render inside a viewed shop.
            'channels' => [
                'webhook_url' => url('/api/webhooks/meta'),
                'verify_token' => $isPlatform && ! $viewing ? (string) $this->settings->get('meta.verify_token', '') : '',
                'app_id' => $isPlatform && ! $viewing ? (string) $this->settings->get('meta.app_id', '') : '',
                'app_secret_set' => $isPlatform && ! $viewing && ! empty($this->settings->get('meta.app_secret_enc')),
                'facebook' => $isPlatform && ! $viewing ? $this->channelView('facebook', ['page_name']) : $this->emptyChannelView(['page_name']),
                'whatsapp' => $isPlatform && ! $viewing ? $this->channelView('whatsapp', ['waba_id', 'phone_number']) : $this->emptyChannelView(['waba_id', 'phone_number']),
                'instagram' => $isPlatform && ! $viewing ? $this->channelView('instagram', ['username']) : $this->emptyChannelView(['username']),
            ],
            // ধাপ ৫: the shop's OWN channel connections (token-free
            // view: set-flag + last4 only; in view-as the last4 is
            // redacted too). Null when there is no shop in scope (a
            // platform admin outside view-as), which is how the page
            // knows to show the platform Meta form instead.
            'myChannels' => app(\App\Services\ChannelConnectionService::class)->viewFor($biz, $viewing),
            'channelsPlatform' => $isPlatform && ! $viewing,
            'packagesData' => ($request->user()?->is_platform_admin && ! $viewing) ? [
                'packs' => \App\Models\PointPack::orderBy('sort')->orderBy('id')->get()->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'price_bdt' => (float) $p->price_bdt, 'credits' => (int) $p->credits,
                    'validity_type' => $p->validity_type, 'rate' => round($p->rate(), 2), 'active' => (bool) $p->is_active,
                    'sellers' => \App\Models\BusinessWallet::where('pack_id', $p->id)->count(),
                ])->all(),
                'pricing' => [
                    'points_per_order' => app(\App\Services\TokenService::class)->pointsPerOrder(),
                    'onboarding_fee_bdt' => app(\App\Services\TokenService::class)->onboardingFee(),
                    'low_balance_threshold' => app(\App\Services\TokenService::class)->lowThreshold(),
                    'payment_instructions' => app(\App\Services\TokenService::class)->paymentInstructions(),
                    'enforcement_enabled' => app(\App\Services\TokenService::class)->enforcementOn(),
                ],
                'registration' => [
                    'verify_enabled' => app(\App\Services\RegistrationVerificationService::class)->enabled(),
                    'verify_channel' => app(\App\Services\RegistrationVerificationService::class)->channel(),
                ],
            ] : null,
            'm2' => [
                'business' => [
                    'name' => $this->settings->shopName($biz),
                    'mobile' => $this->settings->shopContact($biz, 'mobile'),
                    'address' => $this->settings->shopContact($biz, 'address'),
                    'invoice_slug' => (string) $this->settings->bizGet($biz, "business.{$biz}.invoice_slug", 'business.invoice_slug', 'OB'),
                    'default_delivery_cost' => (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60),
                    // The chat summary's outside-Dhaka charge (brain
                    // fix batch 13): its own setting since this batch —
                    // before it the figure was hardcoded in the brain.
                    'default_delivery_cost_outside' => (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost_outside", 'business.default_delivery_cost_outside', 120),
                ],
                // Merchant Profile: the shop's own verification details,
                // stored per business like the profile above (the tab
                // previously showed hard-coded stub values to every shop).
                'merchant' => [
                    'trade_licence' => (string) $this->settings->get("business.{$biz}.merchant_trade_licence", ''),
                    'tin' => (string) $this->settings->get("business.{$biz}.merchant_tin", ''),
                    'bank_account_name' => (string) $this->settings->get("business.{$biz}.merchant_bank_account_name", ''),
                    'bank_branch' => (string) $this->settings->get("business.{$biz}.merchant_bank_branch", ''),
                    'account_number' => (string) $this->settings->get("business.{$biz}.merchant_account_number", ''),
                ],
                'sources' => OrderSource::where('business_id', $biz)->orderBy('sort')
                    ->get(['id', 'code', 'label', 'is_visible']),
                'templates' => [
                    'reminder' => MessageTemplate::where('business_id', $biz)->where('key', 'reminder')->value('body') ?? '',
                    'advance' => MessageTemplate::where('business_id', $biz)->where('key', 'advance')->value('body') ?? '',
                    'invoice_template' => (string) $this->settings->bizGet($biz, "invoice.{$biz}.template", 'invoice.template', 'a4'),
                ],
                'inventory' => [
                    'branch_stock_enabled' => (bool) $this->settings->bizGet($biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', false),
                    'warehouse_cartons' => (bool) $this->settings->bizGet($biz, "inventory.{$biz}.warehouse_cartons", 'inventory.warehouse_cartons', false),
                    'stock_management_disabled' => (bool) $this->settings->bizGet($biz, "inventory.{$biz}.stock_management_disabled", 'inventory.stock_management_disabled', false),
                    'valuation' => (string) $this->settings->bizGet($biz, "inventory.{$biz}.valuation", 'inventory.valuation', 'average_cost'),
                ],
                'finance' => [
                    'daily_closing' => (bool) $this->settings->get('finance.' . $biz . '.daily_closing', false),
                    'advance_account_id' => (int) ($this->settings->get('finance.' . $biz . '.advance_account_id', 0) ?: 0),
                    'accounts' => \App\Models\FinanceAccount::where('business_id', $biz)->where('active', true)
                        ->orderBy('id')->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->all(),
                ],
                'marketing_sms' => [
                    'rate' => (float) $this->settings->get('marketing.' . $biz . '.sms_rate', 1.50),
                    'cap_day' => (int) $this->settings->get('marketing.' . $biz . '.cap_day', 1),
                    'cap_week' => (int) $this->settings->get('marketing.' . $biz . '.cap_week', 3),
                    'balance' => (float) $this->settings->get('marketing.' . $biz . '.sms_balance', 0),
                    // Per-seller provider account (the key itself is never sent).
                    'provider' => (string) $this->settings->get('marketing.' . $biz . '.sms_provider', 'nishchit'),
                    'mode' => (string) $this->settings->get('marketing.' . $biz . '.sms_mode', 'demo'),
                    'sender_id' => (string) $this->settings->get('marketing.' . $biz . '.sms_sender_id', ''),
                    // Only the seller's OWN key counts — the platform's
                    // legacy global key is not the seller's configuration.
                    'has_key' => (bool) $this->settings->get('marketing.' . $biz . '.sms_api_key_enc'),
                    'has_webhook_secret' => (bool) $this->settings->get('marketing.' . $biz . '.sms_webhook_secret_enc'),
                ],
                'toggles' => [
                    'show_email' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.show_email", 'orders.show_email', true),
                    'show_alt_number' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.show_alt_number", 'orders.show_alt_number', false),
                    'mandatory_source' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_source", 'orders.mandatory_source', true),
                    'mandatory_shipping_note' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_shipping_note", 'orders.mandatory_shipping_note', false),
                    'mandatory_advance_txn' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_advance_txn", 'orders.mandatory_advance_txn', true),
                    'cross_sale' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.cross_sale", 'orders.cross_sale', true),
                ],
                'deletionLogs' => DeletionLog::where('business_id', $biz)->latest('created_at')->limit(50)->get()
                    ->map(fn ($l) => ['ref' => $l->order_ref, 'by' => $l->deleted_by, 'reason' => $l->reason,
                        'when' => DhakaTime::fmt($l->created_at, 'd M Y, h:i A')]),
                // Change History is attributed by the business_id recorded
                // on each row: platform admins see everything, a seller
                // sees only their own shop's changes. Rows written before
                // attribution existed (business_id null) stay admin-only.
                'changeHistory' => DB::table('settings_changes')
                    ->when(! $isPlatform || $viewing, fn ($q) => $q->where('business_id', $biz))
                    ->latest('created_at')->limit(30)->get()
                    ->map(fn ($c) => ['key' => $c->key, 'old' => $this->maskHistoryValue((string) $c->key, $c->old_value), 'new' => $this->maskHistoryValue((string) $c->key, $c->new_value),
                        'by' => $c->changed_by, 'when' => DhakaTime::fmt($c->created_at, 'd M Y, h:i A')]),
            ],
        ]);
    }

    /**
     * Change History must never expose secrets: values stored for keys that
     * hold encrypted material (*_enc), tokens, secrets or passwords are
     * masked, and JSON values are scrubbed key by key (channel configs are
     * arrays that carry token_enc inside).
     */
    private function maskHistoryValue(string $key, mixed $value): string
    {
        $sensitive = (bool) preg_match('/(_enc|secret|token|password|api_key)/i', $key);
        $raw = (string) ($value ?? '');
        // A bare Laravel ciphertext (base64 of {"iv":...}) is masked
        // wherever it appears, even under an innocent-looking key.
        if ($sensitive || preg_match('/^eyJ[A-Za-z0-9+\/=]{12,}$/', $raw)) {
            return $raw === '' ? '' : '•••••• (hidden)';
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $scrub = function ($v) use (&$scrub) {
                if (! is_array($v)) {
                    return $v;
                }
                foreach ($v as $k => $item) {
                    $v[$k] = preg_match('/(_enc|secret|token|password|api_key)/i', (string) $k)
                        ? '•••••• (hidden)'
                        : $scrub($item);
                }

                return $v;
            };

            return (string) json_encode($scrub($decoded));
        }

        return $raw;
    }

    private function logChange(Request $request, string $key, mixed $old, mixed $new): void
    {
        DB::table('settings_changes')->insert([
            'key' => $key,
            'business_id' => $request->user()?->business_id,
            'old_value' => is_bool($old) ? ($old ? 'On' : 'Off') : (is_array($old) ? json_encode($old) : (string) ($old ?? '')),
            'new_value' => is_bool($new) ? ($new ? 'On' : 'Off') : (is_array($new) ? json_encode($new) : (string) ($new ?? '')),
            'changed_by' => $request->user()?->name,
            'created_at' => now(),
        ]);
    }

    private function setLogged(Request $request, string $key, mixed $value): void
    {
        $old = $this->settings->get($key);
        $this->settings->set($key, $value, $request->user()?->id);
        if (json_encode($old) !== json_encode($value)) {
            $this->logChange($request, $key, $old, $value);
        }
    }

    /**
     * Save a per-business preference under its namespaced key. The logged
     * "old" value is the EFFECTIVE one (the shop's saved value, or the
     * legacy global it was falling back to), so the history reads true.
     */
    private function setLoggedBiz(Request $request, int $biz, string $scopedKey, string $legacyKey, mixed $value): void
    {
        $old = $this->settings->bizGet($biz, $scopedKey, $legacyKey);
        $this->settings->set($scopedKey, $value, $request->user()?->id);
        if (json_encode($old) !== json_encode($value)) {
            $this->logChange($request, $scopedKey, $old, $value);
        }
    }

    /**
     * The shop a shop-scoped Settings save belongs to. These saves key
     * everything by the signed-in user's own business; a platform admin
     * has none, and writing under business 0 silently buried the values
     * where no shop would ever read them (the platform admin's Business
     * Settings "saved" a delivery charge no shop ever saw). Refuse
     * instead. View-as writes never reach here - ViewAsReadOnly blocks
     * them first - and identity surfaces (Billing, Staff & Roles) do
     * not use this helper.
     */
    private function shopBiz(Request $request): int
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        abort_if($biz <= 0, 403, 'These settings belong to a shop. Sign in as a shop user to change them.');

        return $biz;
    }

    /** Master switch for two-factor authentication (Settings → Security & Records). */
    public function updateTotp(Request $request): RedirectResponse
    {
        // security.totp_enabled is platform-global: one switch for every
        // user on the platform, so only a platform admin may flip it.
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $request->validate(['enabled' => ['required', 'boolean']]);

        $this->setLogged($request, 'security.totp_enabled', $request->boolean('enabled'));

        return back();
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:300'],
            'invoice_slug' => ['required', 'string', 'max:10'],
            'default_delivery_cost' => ['required', 'numeric', 'min:0'],
            // Optional so a caller that predates the field keeps
            // saving the form untouched; when it IS sent it must be
            // a sane figure.
            'default_delivery_cost_outside' => ['nullable', 'numeric', 'min:0'],
        ]);
        // One source (Hunt Z4-M3): the businesses row carries the
        // shop's name, phone and address - the seller header, the
        // platform Sellers list and the Y1-F1 phone fallback all read
        // it there. The per-business settings keys are still written
        // with the SAME values (and still change-logged), so every
        // reader of the old keys sees the same truth and the two
        // stores can no longer disagree.
        $biz = $this->shopBiz($request);
        \App\Models\Business::whereKey($biz)->update([
            'name' => $data['name'],
            'phone' => $data['mobile'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        $this->setLogged($request, "business.{$biz}.profile_name", $data['name']);
        $this->setLogged($request, "business.{$biz}.mobile", $data['mobile'] ?? '');
        $this->setLogged($request, "business.{$biz}.address", $data['address'] ?? '');
        $this->setLoggedBiz($request, $biz, "business.{$biz}.invoice_slug", 'business.invoice_slug', strtoupper($data['invoice_slug']));
        $this->setLoggedBiz($request, $biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', (float) $data['default_delivery_cost']);
        if (isset($data['default_delivery_cost_outside']) && $data['default_delivery_cost_outside'] !== null && $data['default_delivery_cost_outside'] !== '') {
            $this->setLoggedBiz($request, $biz, "business.{$biz}.default_delivery_cost_outside", 'business.default_delivery_cost_outside', (float) $data['default_delivery_cost_outside']);
        }

        return back()->with('success', __('Business settings saved'));
    }

    /** Merchant Profile tab: the shop's own licence / TIN / bank details. */
    public function updateMerchant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trade_licence' => ['nullable', 'string', 'max:120'],
            'tin' => ['nullable', 'string', 'max:60'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:60'],
        ]);
        // Per business, like the profile: each shop keeps its own
        // verification details (the tab previously showed one hard-coded
        // stub - including another shop's name - to every seller).
        $biz = $this->shopBiz($request);
        $this->setLogged($request, "business.{$biz}.merchant_trade_licence", $data['trade_licence'] ?? '');
        $this->setLogged($request, "business.{$biz}.merchant_tin", $data['tin'] ?? '');
        $this->setLogged($request, "business.{$biz}.merchant_bank_account_name", $data['bank_account_name'] ?? '');
        $this->setLogged($request, "business.{$biz}.merchant_bank_branch", $data['bank_branch'] ?? '');
        $this->setLogged($request, "business.{$biz}.merchant_account_number", $data['account_number'] ?? '');

        return back()->with('success', __('Merchant settings saved'));
    }

    public function updateSources(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sources' => ['required', 'array'],
            'sources.*.id' => ['required', 'integer'],
            'sources.*.label' => ['required', 'string', 'max:60'],
            'sources.*.is_visible' => ['required', 'boolean'],
        ]);
        $biz = $this->shopBiz($request);
        foreach ($data['sources'] as $row) {
            $source = OrderSource::where('business_id', $biz)->find($row['id']);
            if (! $source) {
                continue;
            }
            if ($source->label !== $row['label']) {
                $this->logChange($request, "source.{$source->code}", $source->label, $row['label']);
            }
            $source->update(['label' => $row['label'], 'is_visible' => $row['is_visible']]);
        }

        return back()->with('success', __('Order sources saved'));
    }

    public function resetSources(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $defaults = [
            'FB' => 'Facebook', 'WEB' => 'Website', 'DIRECT' => 'Direct', 'UNKNOWN' => 'Unknown',
            'INSTAGRAM' => 'Instagram', 'TIKTOK' => 'TikTok', 'WHATSAPP' => 'WhatsApp', 'EXCHANGE' => 'Exchange',
            'MESSENGER' => 'Messenger', 'CUSTOM_SOURCE' => 'Custom Source', 'CUSTOM_SOURCE_2' => 'Custom Source 2',
            'INCOMPLETE_ORDER' => 'Incomplete Order', 'UPSALE' => 'Upsale',
        ];
        foreach ($defaults as $code => $label) {
            OrderSource::where('business_id', $biz)->where('code', $code)
                ->update(['label' => $label, 'is_visible' => true]);
        }

        return back()->with('success', __('Order sources reset to default'));
    }

    public function updateTemplates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminder' => ['required', 'string', 'max:1000'],
            'advance' => ['required', 'string', 'max:1000'],
            'invoice_template' => ['required', 'string', 'max:20'],
        ]);
        $biz = $this->shopBiz($request);
        MessageTemplate::updateOrCreate(['business_id' => $biz, 'key' => 'reminder'], ['name' => 'Order Reminder SMS', 'body' => $data['reminder']]);
        MessageTemplate::updateOrCreate(['business_id' => $biz, 'key' => 'advance'], ['name' => 'Advance Payment SMS', 'body' => $data['advance']]);
        $this->setLoggedBiz($request, $biz, "invoice.{$biz}.template", 'invoice.template', $data['invoice_template']);

        return back()->with('success', __('Templates saved'));
    }

    public function updateInventory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_stock_enabled' => ['required', 'boolean'],
            'warehouse_cartons' => ['required', 'boolean'],
            'stock_management_disabled' => ['required', 'boolean'],
            'valuation' => ['required', 'string', 'in:average_cost'],
        ]);
        $biz = $this->shopBiz($request);
        $wasBranchStock = (bool) $this->settings->bizGet($biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', false);
        $this->setLoggedBiz($request, $biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', (bool) $data['branch_stock_enabled']);
        if ((bool) $data['branch_stock_enabled'] && ! $wasBranchStock) {
            // Same rule as the Branches-page toggle (Hunt K-H4): switching
            // branch stock ON re-derives branch_stock from products first,
            // so the figures start true whichever screen flipped the switch.
            app(\App\Services\StockService::class)->rederiveBranchStock($biz);
        }
        $this->setLoggedBiz($request, $biz, "inventory.{$biz}.warehouse_cartons", 'inventory.warehouse_cartons', (bool) $data['warehouse_cartons']);
        $this->setLoggedBiz($request, $biz, "inventory.{$biz}.stock_management_disabled", 'inventory.stock_management_disabled', (bool) $data['stock_management_disabled']);
        $this->setLoggedBiz($request, $biz, "inventory.{$biz}.valuation", 'inventory.valuation', $data['valuation']);

        return back()->with('success', __('Inventory settings saved'));
    }

    public function updateFinance(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate([
            'daily_closing' => ['required', 'boolean'],
            'advance_account_id' => ['nullable', 'integer'],
        ]);
        $this->setLogged($request, 'finance.' . $biz . '.daily_closing', (bool) $data['daily_closing']);
        $this->setLogged($request, 'finance.' . $biz . '.advance_account_id', (int) ($data['advance_account_id'] ?? 0));

        return back()->with('success', __('Finance settings saved'));
    }

    public function updateMarketingSms(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cap_day' => ['required', 'integer', 'min:0', 'max:50'],
            'cap_week' => ['required', 'integer', 'min:0', 'max:200'],
            'add_balance' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'provider' => ['required', 'string', 'in:amarsms,nishchit'],
            'mode' => ['required', 'string', 'in:demo,live'],
            'sender_id' => ['nullable', 'string', 'max:40'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);
        $sms = app(\App\Services\SmsService::class);
        $actor = $request->user();
        $sms->setSettingLogged($biz, 'sms_rate', (float) $data['rate'], $actor);
        $sms->setSettingLogged($biz, 'cap_day', (int) $data['cap_day'], $actor);
        $sms->setSettingLogged($biz, 'cap_week', (int) $data['cap_week'], $actor);
        if (! empty($data['add_balance'])) {
            $sms->setSettingLogged($biz, 'sms_balance', round($sms->balance($biz) + (float) $data['add_balance'], 2), $actor);
        }
        // The provider account is the seller's own: every field is saved
        // per business (marketing.{biz}.sms_*), never platform-global.
        $sms->setSettingLogged($biz, 'sms_provider', $data['provider'], $actor);
        $sms->setSettingLogged($biz, 'sms_mode', $data['mode'], $actor);
        $sms->setSettingLogged($biz, 'sms_sender_id', $data['sender_id'] ?? '', $actor);
        if (! empty($data['api_key'])) {
            $sms->setSettingLogged($biz, 'sms_api_key_enc', \Illuminate\Support\Facades\Crypt::encryptString($data['api_key']), $actor);
            // Plain marker so views can tell a key exists without decrypting it.
            $sms->setSettingLogged($biz, 'sms_api_key', 'set', $actor);
        }
        if (! empty($data['webhook_secret'])) {
            $sms->setSettingLogged($biz, 'sms_webhook_secret_enc', \Illuminate\Support\Facades\Crypt::encryptString($data['webhook_secret']), $actor);
            $sms->setSettingLogged($biz, 'sms_webhook_secret', 'set', $actor);
        }

        return back()->with('success', __('SMS settings saved'));
    }

    /** Settings → Marketing SMS "Check connection": provider balance as JSON. */
    public function checkSmsConnection(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate(['key' => ['nullable', 'string', 'max:255']]);
        // A key typed into the form is tested as typed; blank tests the saved one.
        $result = app(\App\Services\SmsService::class)->testConnection($biz, $data['key'] ?? null);

        return response()->json($result);
    }

    /** Settings → Marketing SMS "Send test SMS": one send in the current mode. */
    public function sendTestSms(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $body = trim((string) ($data['message'] ?? '')) ?: 'SkaleUp test SMS';
        $log = app(\App\Services\SmsService::class)->sendTest($biz, $data['phone'], $body);
        if ($log->status === 'sent') {
            return back()->with('success', __('Test SMS sent'));
        }

        return back()->with('error', __('Test SMS not sent - :error', ['error' => $log->error ?: __('unknown error')]));
    }

    public function updateOrderToggles(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'show_email' => ['required', 'boolean'],
            'show_alt_number' => ['required', 'boolean'],
            'mandatory_source' => ['required', 'boolean'],
            'mandatory_shipping_note' => ['required', 'boolean'],
            'mandatory_advance_txn' => ['required', 'boolean'],
            'cross_sale' => ['required', 'boolean'],
        ]);
        $biz = $this->shopBiz($request);
        foreach ($data as $key => $value) {
            $this->setLoggedBiz($request, $biz, "orders.{$biz}.{$key}", "orders.{$key}", (bool) $value);
        }

        return back()->with('success', __('Order settings saved'));
    }

    public function updateAutoApprove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_approve_enabled' => ['required', 'boolean'],
            'auto_approve_threshold' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $biz = $this->shopBiz($request);
        $this->setLoggedBiz($request, $biz, "orders.{$biz}.auto_approve_enabled", 'orders.auto_approve_enabled', (bool) $data['auto_approve_enabled']);
        $this->setLoggedBiz($request, $biz, "orders.{$biz}.auto_approve_threshold", 'orders.auto_approve_threshold', (int) $data['auto_approve_threshold']);

        return back()->with('success', __('Auto-approve settings saved'));
    }

    public function addCourierMethod(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        // Delivery-method names are per shop — never one global list.
        $biz = $this->shopBiz($request);
        $list = app(\App\Services\DeliveryMethodList::class);
        $methods = $list->forBusiness($biz);
        $exists = collect($methods)->contains(fn ($m) => strtolower((string) $m) === strtolower($data['name']));
        if (! $exists) {
            $methods[] = $data['name'];
            $this->setLogged($request, $list->key($biz), array_values($methods));
        }

        return back()->with('success', __('Delivery method added'));
    }

    // ---------- AI & Auto Reply (M6) ----------

    public function storeAiRule(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', 'string', 'in:intent,keyword'],
            'match_text' => ['nullable', 'string', 'max:200'],
            'action' => ['required', 'string', 'in:answer,draft,hide,handover'],
            'reply_template' => ['nullable', 'string', 'max:2000'],
        ]);
        \App\Models\AiRule::create($data + ['business_id' => $biz, 'active' => true]);

        return back()->with('success', __('Rule added'));
    }

    public function updateAiRule(Request $request, \App\Models\AiRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', 'string', 'in:intent,keyword'],
            'match_text' => ['nullable', 'string', 'max:200'],
            'action' => ['required', 'string', 'in:answer,draft,hide,handover'],
            'reply_template' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ]);
        $rule->update($data);

        return back()->with('success', __('Rule saved'));
    }

    public function deleteAiRule(Request $request, \App\Models\AiRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $rule->delete();

        return back()->with('success', __('Rule deleted'));
    }

    public function testAiRule(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $brain = app(\App\Services\RuleBrainService::class);
        $rule = $brain->matchRule($biz, $data['text']);
        if (! $rule) {
            return response()->json(['matched' => false, 'note' => 'কোনো নিয়ম মেলেনি - AI product-এর বিবরণ পড়ে draft বানাবে, মানুষ দেখে পাঠাবে।']);
        }
        $product = $brain->productFor($biz, $data['text']);
        $reply = $rule->action === 'hide' ? '(comment hide হবে)' : ($rule->action === 'handover' ? '(মানুষের হাতে যাবে)' : $brain->buildReply($rule, $product, $biz));

        return response()->json([
            'matched' => true, 'rule' => $rule->name, 'action' => $rule->action, 'reply' => $reply,
            'verified' => $brain->moneyCheck($biz, (string) $reply, $product),
        ]);
    }

    public function updateAiReply(Request $request): RedirectResponse
    {
        // The reply levels and working hours are each shop's own
        // behaviour: a shop user saves them under ai.{biz}.* and the
        // platform defaults stay untouched. A platform admin edits
        // those defaults (the global rows every shop falls back to).
        // The panic flag inside ai.autonomy is NOT part of this form -
        // it stays platform-global (MessagesController::panic).
        $data = $request->validate([
            'facebook' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'whatsapp' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'instagram' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'comment' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'working_hours' => ['nullable', 'string', 'max:200'],
        ]);
        $levels = [
            'facebook' => $data['facebook'], 'whatsapp' => $data['whatsapp'],
            'instagram' => $data['instagram'], 'comment' => $data['comment'],
        ];
        if ((bool) ($request->user()?->is_platform_admin)) {
            $auto = $this->settings->get('ai.autonomy', []);
            $auto = array_merge(is_array($auto) ? $auto : [], $levels);
            $this->setLogged($request, 'ai.autonomy', $auto);
            $this->setLogged($request, 'ai.working_hours', (string) ($data['working_hours'] ?? ''));
        } else {
            $biz = $this->shopBiz($request);
            $current = (array) $this->settings->bizGet($biz, "ai.{$biz}.autonomy", 'ai.autonomy', []);
            $merged = array_merge($current, $levels);
            unset($merged['panic']); // panic never lives in a shop row - global only
            $this->setLoggedBiz($request, $biz, "ai.{$biz}.autonomy", 'ai.autonomy', $merged);
            $this->setLoggedBiz($request, $biz, "ai.{$biz}.working_hours", 'ai.working_hours', (string) ($data['working_hours'] ?? ''));
        }

        return back()->with('success', __('AI Reply settings saved'));
    }

    public function updateAiTalker(Request $request): RedirectResponse
    {
        // ai.talker holds the platform's own Gemini/Claude API keys.
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'normal_provider' => ['required', 'string', 'in:gemini,claude'],
            'normal_model' => ['required', 'string', 'max:80'],
            'complex_provider' => ['required', 'string', 'in:gemini,claude'],
            'complex_model' => ['required', 'string', 'max:80'],
            'route_angry' => ['required', 'boolean'],
            'route_long_bargain' => ['required', 'boolean'],
            'gemini_key' => ['nullable', 'string', 'max:300'],
            'claude_key' => ['nullable', 'string', 'max:300'],
        ]);
        $cfg = $this->settings->get('ai.talker', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $cfg = array_merge($cfg, [
            'enabled' => (bool) $data['enabled'],
            'normal_provider' => $data['normal_provider'],
            'normal_model' => trim($data['normal_model']),
            'complex_provider' => $data['complex_provider'],
            'complex_model' => trim($data['complex_model']),
            'route_angry' => (bool) $data['route_angry'],
            'route_long_bargain' => (bool) $data['route_long_bargain'],
        ]);
        // Blank key field = keep the saved key, exactly like channel tokens.
        foreach (['gemini', 'claude'] as $provider) {
            $plain = trim((string) ($data[$provider . '_key'] ?? ''));
            if ($plain !== '') {
                $cfg[$provider . '_key_enc'] = \Illuminate\Support\Facades\Crypt::encryptString($plain);
            }
        }
        $this->setLogged($request, 'ai.talker', $cfg);

        return back()->with('success', __('AI Model settings saved'));
    }

    public function testAiTalker(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:gemini,claude'],
            'key' => ['nullable', 'string', 'max:300'],
        ]);
        // The check changes nothing and never exposes the saved key: it
        // runs one minimal call ("Reply with exactly: OK") and reports
        // ok/failed. A key typed into the form is tested as typed, but
        // only for platform admins - the provider form (and its keys)
        // is the platform's; a seller's check always tests the saved
        // platform key, so a seller can confirm the AI model is
        // reachable without ever touching the keys.
        $typed = (bool) ($request->user()?->is_platform_admin) ? ($data['key'] ?? null) : null;
        $result = app(\App\Services\AiTalkerService::class)->testConnection($data['provider'], $typed);

        return response()->json($result);
    }

    public function updateAiNegotiation(Request $request): RedirectResponse
    {
        // Each shop's own discount ladder: shop users save under
        // ai.{biz}.negotiation; a platform admin edits the defaults.
        $data = $request->validate([
            'max_discount' => ['required', 'integer', 'min:0', 'max:90'],
            'step' => ['required', 'integer', 'min:1', 'max:50'],
            'free_delivery_min' => ['required', 'numeric', 'min:0'],
        ]);
        if ((bool) ($request->user()?->is_platform_admin)) {
            $this->setLogged($request, 'ai.negotiation', $data);
        } else {
            $biz = $this->shopBiz($request);
            $this->setLoggedBiz($request, $biz, "ai.{$biz}.negotiation", 'ai.negotiation', $data);
        }

        return back()->with('success', __('Negotiation settings saved'));
    }

    public function updateAiBehavior(Request $request): RedirectResponse
    {
        // Each shop's own AI behaviour (tone, holding line, its daily
        // model-call cap, burst wait): shop users save under
        // ai.{biz}.behavior; a platform admin edits the defaults.
        $data = $request->validate([
            'angry_holding' => ['required', 'boolean'],
            'honorific' => ['required', 'string', 'in:auto,apu,bhaiya,none'],
            'daily_llm_cap' => ['required', 'integer', 'min:0', 'max:100000'],
            'debounce_seconds' => ['required', 'integer', 'min:0', 'max:60'],
        ]);
        if ((bool) ($request->user()?->is_platform_admin)) {
            $this->setLogged($request, 'ai.behavior', $data);
        } else {
            $biz = $this->shopBiz($request);
            $this->setLoggedBiz($request, $biz, "ai.{$biz}.behavior", 'ai.behavior', $data);
        }

        return back()->with('success', __('AI behaviour settings saved'));
    }

    public function storeKnowledge(Request $request): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000']]);
        \App\Models\KnowledgeEntry::create($data + ['business_id' => $biz]);

        return back()->with('success', __('Knowledge added'));
    }

    public function updateKnowledge(Request $request, \App\Models\KnowledgeEntry $entry): RedirectResponse
    {
        abort_unless((int) $entry->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000']]);
        $entry->update($data);

        return back()->with('success', __('Knowledge saved'));
    }

    public function deleteKnowledge(Request $request, \App\Models\KnowledgeEntry $entry): RedirectResponse
    {
        abort_unless((int) $entry->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $entry->delete();

        return back()->with('success', __('Knowledge deleted'));
    }

    public function approveProposal(Request $request, \App\Models\AiRuleProposal $proposal): RedirectResponse
    {
        $biz = $this->shopBiz($request);
        abort_unless((int) $proposal->business_id === $biz, 403);
        if ($proposal->status !== 'pending') {
            // State discipline (Hunt Y2-6): a decided proposal is
            // finished — approving again must never duplicate a rule
            // or append a template sentence twice.
            return back()->with('error', __('This proposal was already decided.'));
        }
        $data = $proposal->rule_data ?? [];
        $actionable = ! empty($data['new_rule']) || ! empty($data['edit_rule_match']);
        if (! empty($data['new_rule'])) {
            \App\Models\AiRule::create($data['new_rule'] + ['business_id' => $biz, 'active' => true]);
        }
        if (! empty($data['edit_rule_match'])) {
            $rule = \App\Models\AiRule::where('business_id', $biz)->where('match_text', $data['edit_rule_match'])->first();
            if ($rule && ! empty($data['append_template'])) {
                $rule->reply_template = rtrim((string) $rule->reply_template) . $data['append_template'];
                $rule->save();
            }
        }
        $proposal->status = 'approved';
        $proposal->save();

        if (! $actionable) {
            // A staff-edit proposal carries no rule change (Hunt
            // Y2-7): approving it acknowledges the record, it does
            // not teach the AI anything — and the flash must not
            // claim a rule changed.
            return back()->with('success', __('Marked as noted - this proposal only records a staff edit, so no rule changed.'));
        }

        return back()->with('success', __('Proposal approved'));
    }

    public function rejectProposal(Request $request, \App\Models\AiRuleProposal $proposal): RedirectResponse
    {
        abort_unless((int) $proposal->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        if ($proposal->status !== 'pending') {
            // Rejecting an already-approved proposal must not rewrite
            // its record while its rule stays live (Hunt Y2-6).
            return back()->with('error', __('This proposal was already decided.'));
        }
        $proposal->status = 'rejected';
        $proposal->save();

        return back()->with('success', __('Proposal rejected'));
    }

    // ---------- Messaging Channels (Meta connection) ----------

    /** The channels prop shape for non-platform users: same keys, no values. */
    private function emptyChannelView(array $extraKeys): array
    {
        $view = ['account_id' => '', 'token_set' => false];
        foreach ($extraKeys as $k) {
            $view[$k] = '';
        }

        return $view;
    }

    private function channelView(string $channel, array $extraKeys): array
    {
        $cfg = (array) $this->settings->get("channels.{$channel}", []);
        $view = [
            'account_id' => (string) ($cfg['account_id'] ?? ''),
            'token_set' => ! empty($cfg['token_enc']),
        ];
        foreach ($extraKeys as $k) {
            $view[$k] = (string) ($cfg[$k] ?? '');
        }

        return $view;
    }

    public function updateChannels(Request $request): RedirectResponse
    {
        // meta.* and channels.* are the PLATFORM's single Meta connection
        // (one app, one set of channel tokens for every shop). A seller
        // saving this tab would replace the credentials for the whole
        // platform, so it is platform admin only.
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $data = $request->validate([
            'app_id' => ['nullable', 'string', 'max:40'],
            'app_secret' => ['nullable', 'string', 'max:200'],
            'facebook_account_id' => ['nullable', 'string', 'max:40'],
            'facebook_page_name' => ['nullable', 'string', 'max:120'],
            'facebook_token' => ['nullable', 'string', 'max:2000'],
            'whatsapp_account_id' => ['nullable', 'string', 'max:40'],
            'whatsapp_waba_id' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone_number' => ['nullable', 'string', 'max:40'],
            'whatsapp_token' => ['nullable', 'string', 'max:2000'],
            'instagram_account_id' => ['nullable', 'string', 'max:40'],
            'instagram_username' => ['nullable', 'string', 'max:120'],
            'instagram_token' => ['nullable', 'string', 'max:2000'],
        ]);
        // All saves go through setLogged so the Change History shows
        // them like every other settings save (secret values are
        // masked at display time by maskHistoryValue).
        $this->setLogged($request, 'meta.app_id', trim((string) ($data['app_id'] ?? '')));
        if (! empty($data['app_secret'])) {
            $this->setLogged($request, 'meta.app_secret_enc', \App\Services\MetaGraphService::encrypt($data['app_secret']));
        }
        if ((string) $this->settings->get('meta.verify_token', '') === '') {
            $this->setLogged($request, 'meta.verify_token', 'skaleup_' . \Illuminate\Support\Str::random(24));
        }
        $this->setLogged($request, 'channels.business_id', (int) ($request->user()?->business_id ?? 0) ?: (int) $this->settings->get('channels.business_id', 1));
        $map = [
            'facebook' => ['account_id' => 'facebook_account_id', 'page_name' => 'facebook_page_name', 'token' => 'facebook_token'],
            'whatsapp' => ['account_id' => 'whatsapp_account_id', 'waba_id' => 'whatsapp_waba_id', 'phone_number' => 'whatsapp_phone_number', 'token' => 'whatsapp_token'],
            'instagram' => ['account_id' => 'instagram_account_id', 'username' => 'instagram_username', 'token' => 'instagram_token'],
        ];
        foreach ($map as $channel => $fields) {
            $cfg = (array) $this->settings->get("channels.{$channel}", []);
            foreach ($fields as $key => $input) {
                if ($key === 'token') {
                    if (! empty($data[$input])) {
                        $cfg['token_enc'] = \App\Services\MetaGraphService::encrypt($data[$input]);
                    }
                } else {
                    $cfg[$key] = trim((string) ($data[$input] ?? ''));
                }
            }
            $this->setLogged($request, "channels.{$channel}", $cfg);
        }

        // ধাপ ৫: keep the platform shop's connection ROW in sync with
        // this global form (creates it on first save, refreshes the
        // token on rotation), so per-seller routing and the platform
        // form never disagree about the platform's own connection.
        app(\App\Services\ChannelConnectionService::class)->migrateLegacyGlobal();

        return back()->with('success', __('Messaging channels saved'));
    }

    public function checkChannel(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $channel = (string) $request->input('channel', '');
        abort_unless(in_array($channel, ['facebook', 'whatsapp', 'instagram'], true), 422);

        return response()->json(app(\App\Services\MetaGraphService::class)->check($channel));
    }

    // ---------- Seller Packages → Point Packs (M19; platform admin only) ----------

    private function packData(Request $request): array
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price_bdt' => ['required', 'numeric', 'min:0'],
            'credits' => ['required', 'integer', 'min:1', 'max:100000000'],
            'validity_type' => ['required', \Illuminate\Validation\Rule::in(['monthly', 'yearly_wallet'])],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $data = $this->packData($request);
        \App\Models\PointPack::create([
            'name' => $data['name'], 'price_bdt' => $data['price_bdt'], 'credits' => $data['credits'],
            'validity_type' => $data['validity_type'],
            'is_active' => true, 'sort' => (int) (\App\Models\PointPack::max('sort') ?? 0) + 1,
        ]);

        return back()->with('success', __('Pack created'));
    }

    public function updatePackage(Request $request, \App\Models\PointPack $package): RedirectResponse
    {
        $data = $this->packData($request);
        $package->update([
            'name' => $data['name'], 'price_bdt' => $data['price_bdt'], 'credits' => $data['credits'],
            'validity_type' => $data['validity_type'],
            'is_active' => (bool) ($data['active'] ?? $package->is_active),
        ]);

        return back()->with('success', __('Pack saved'));
    }

    public function deletePackage(Request $request, \App\Models\PointPack $package): RedirectResponse
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        if (\App\Models\BusinessWallet::where('pack_id', $package->id)->exists()) {
            return back()->with('error', __('A seller is on this pack - deactivate it instead of deleting'));
        }
        $package->delete();

        return back()->with('success', __('Pack deleted'));
    }

    /** M19 pricing switches — everything about points is set here. */
    public function updatePricing(Request $request): RedirectResponse
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $data = $request->validate([
            'points_per_order' => ['required', 'integer', 'min:1', 'max:100'],
            'onboarding_fee_bdt' => ['required', 'numeric', 'min:0'],
            'low_balance_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
            'enforcement_enabled' => ['required', 'boolean'],
        ]);
        $this->setLogged($request, 'pricing.points_per_order', (int) $data['points_per_order']);
        $this->setLogged($request, 'pricing.onboarding_fee_bdt', (float) $data['onboarding_fee_bdt']);
        $this->setLogged($request, 'pricing.low_balance_threshold', (int) $data['low_balance_threshold']);
        $this->setLogged($request, 'pricing.payment_instructions', (string) ($data['payment_instructions'] ?? ''));
        $this->setLogged($request, 'pricing.enforcement_enabled', (bool) $data['enforcement_enabled']);

        return back()->with('success', __('Pricing settings saved'));
    }

    /** Registration verification switches — platform admin only. */
    public function updateRegistration(Request $request): RedirectResponse
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $data = $request->validate([
            'verify_enabled' => ['required', 'boolean'],
            // Stored as a plain string — never let it become a number.
            'verify_channel' => ['required', 'string', \Illuminate\Validation\Rule::in(['sms', 'email', 'both'])],
        ]);
        $this->setLogged($request, 'registration.verify_enabled', (bool) $data['verify_enabled']);
        $this->setLogged($request, 'registration.verify_channel', (string) $data['verify_channel']);

        return back()->with('success', __('Registration verification settings saved'));
    }
}

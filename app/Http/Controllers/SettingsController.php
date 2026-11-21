<?php

namespace App\Http\Controllers;

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
        $biz = (int) ($request->user()?->business_id ?? 0);

        return Inertia::render('Settings/Index', [
            'group' => $request->query('group', 'home'),
            'couriers' => [
                'methods' => \App\Models\CourierMethod::where('business_id', $biz)->with('pickupStores')->orderBy('name')->get()
                    ->map(fn ($m) => \App\Http\Controllers\CourierSettingsController::methodView($m))->all(),
                'rules' => \App\Models\CourierAssignmentRule::where('business_id', $biz)->with('method')->orderBy('priority')->get()
                    ->map(fn ($r) => ['id' => $r->id, 'scope' => $r->scope, 'product_id' => $r->product_id, 'category' => $r->category, 'district' => $r->district, 'area' => $r->area, 'method_id' => $r->courier_method_id, 'method_label' => $r->method?->label(), 'priority' => $r->priority])->all(),
                'products' => \App\Models\Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku'])->all(),
            ],
            'ai' => [
                'rules' => \App\Models\AiRule::where('business_id', $biz)->orderBy('id')->get()
                    ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'trigger_type' => $r->trigger_type, 'match_text' => $r->match_text, 'action' => $r->action, 'reply_template' => $r->reply_template, 'channel' => $r->channel, 'active' => (bool) $r->active])->all(),
                'autonomy' => app(\App\Services\RuleBrainService::class)->autonomy(),
                'talker' => app(\App\Services\AiTalkerService::class)->config(),
                'behavior' => (array) $this->settings->get('ai.behavior', []),
                'working_hours' => (string) $this->settings->get('ai.working_hours', 'সকাল 9টা — রাত 11টা'),
                'negotiation' => app(\App\Services\NegotiationService::class)->config(),
                'knowledge' => \App\Models\KnowledgeEntry::where('business_id', $biz)->orderBy('id')->get(['id', 'title', 'body'])->all(),
                'proposals' => \App\Models\AiRuleProposal::where('business_id', $biz)->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->get()
                    ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'detail' => $p->detail, 'status' => $p->status])->all(),
            ],
            'totpEnabled' => $this->totp->enabled(),
            'totpEnrolled' => (bool) $request->user()?->hasTotpEnabled(),
            'isPlatform' => (bool) ($request->user()?->is_platform_admin),
            // Payment Gateway: seller scope for the caller's own shop;
            // platform scope only for platform admins. Same form, both.
            'paymentGateway' => $biz ? app(\App\Services\PaymentGatewayService::class)->view($biz) : null,
            'platformPaymentGateway' => ($request->user()?->is_platform_admin)
                ? app(\App\Services\PaymentGatewayService::class)->view(null) : null,
            'channels' => [
                'webhook_url' => url('/api/webhooks/meta'),
                'verify_token' => (string) $this->settings->get('meta.verify_token', ''),
                'app_id' => (string) $this->settings->get('meta.app_id', ''),
                'app_secret_set' => ! empty($this->settings->get('meta.app_secret_enc')),
                'facebook' => $this->channelView('facebook', ['page_name']),
                'whatsapp' => $this->channelView('whatsapp', ['waba_id', 'phone_number']),
                'instagram' => $this->channelView('instagram', ['username']),
            ],
            'packagesData' => ($request->user()?->is_platform_admin) ? [
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
            ] : null,
            'm2' => [
                'business' => [
                    'name' => (string) $this->settings->get('business.name', 'Demo Shop'),
                    'mobile' => (string) $this->settings->get('business.mobile', ''),
                    'address' => (string) $this->settings->get('business.address', ''),
                    'invoice_slug' => (string) $this->settings->get('business.invoice_slug', 'OB'),
                    'default_delivery_cost' => (float) $this->settings->get('business.default_delivery_cost', 60),
                ],
                'sources' => OrderSource::where('business_id', $biz)->orderBy('sort')
                    ->get(['id', 'code', 'label', 'is_visible']),
                'templates' => [
                    'reminder' => MessageTemplate::where('business_id', $biz)->where('key', 'reminder')->value('body') ?? '',
                    'advance' => MessageTemplate::where('business_id', $biz)->where('key', 'advance')->value('body') ?? '',
                    'invoice_template' => (string) $this->settings->get('invoice.template', 'a4'),
                ],
                'inventory' => [
                    'branch_stock_enabled' => (bool) $this->settings->get('inventory.branch_stock_enabled', false),
                    'warehouse_cartons' => (bool) $this->settings->get('inventory.warehouse_cartons', false),
                    'stock_management_disabled' => (bool) $this->settings->get('inventory.stock_management_disabled', false),
                    'valuation' => (string) $this->settings->get('inventory.valuation', 'average_cost'),
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
                    'has_key' => (bool) ($this->settings->get('marketing.' . $biz . '.sms_api_key_enc')
                        || $this->settings->get('marketing.gateway_api_key_enc')),
                    'has_webhook_secret' => (bool) $this->settings->get('marketing.' . $biz . '.sms_webhook_secret_enc'),
                ],
                'toggles' => [
                    'show_email' => (bool) $this->settings->get('orders.show_email', true),
                    'show_alt_number' => (bool) $this->settings->get('orders.show_alt_number', false),
                    'mandatory_source' => (bool) $this->settings->get('orders.mandatory_source', true),
                    'mandatory_shipping_note' => (bool) $this->settings->get('orders.mandatory_shipping_note', false),
                    'mandatory_advance_txn' => (bool) $this->settings->get('orders.mandatory_advance_txn', true),
                    'cross_sale' => (bool) $this->settings->get('orders.cross_sale', true),
                ],
                'deletionLogs' => DeletionLog::where('business_id', $biz)->latest('created_at')->limit(50)->get()
                    ->map(fn ($l) => ['ref' => $l->order_ref, 'by' => $l->deleted_by, 'reason' => $l->reason,
                        'when' => $l->created_at?->format('d M Y, h:i A')]),
                'changeHistory' => DB::table('settings_changes')->latest('created_at')->limit(30)->get()
                    ->map(fn ($c) => ['key' => $c->key, 'old' => $c->old_value, 'new' => $c->new_value,
                        'by' => $c->changed_by, 'when' => \Illuminate\Support\Carbon::parse($c->created_at)->format('d M Y, h:i A')]),
            ],
        ]);
    }

    private function logChange(Request $request, string $key, mixed $old, mixed $new): void
    {
        DB::table('settings_changes')->insert([
            'key' => $key,
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

    /** Master switch for two-factor authentication (Settings → Security & Records). */
    public function updateTotp(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);

        $this->settings->set('security.totp_enabled', $request->boolean('enabled'), $request->user()?->id);

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
        ]);
        $this->setLogged($request, 'business.name', $data['name']);
        $this->setLogged($request, 'business.mobile', $data['mobile'] ?? '');
        $this->setLogged($request, 'business.address', $data['address'] ?? '');
        $this->setLogged($request, 'business.invoice_slug', strtoupper($data['invoice_slug']));
        $this->setLogged($request, 'business.default_delivery_cost', (float) $data['default_delivery_cost']);

        return back()->with('success', 'Business settings saved');
    }

    public function updateSources(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sources' => ['required', 'array'],
            'sources.*.id' => ['required', 'integer'],
            'sources.*.label' => ['required', 'string', 'max:60'],
            'sources.*.is_visible' => ['required', 'boolean'],
        ]);
        $biz = (int) $request->user()->business_id;
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

        return back()->with('success', 'Order sources saved');
    }

    public function resetSources(Request $request): RedirectResponse
    {
        $biz = (int) $request->user()->business_id;
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

        return back()->with('success', 'Order sources reset to default');
    }

    public function updateTemplates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminder' => ['required', 'string', 'max:1000'],
            'advance' => ['required', 'string', 'max:1000'],
            'invoice_template' => ['required', 'string', 'max:20'],
        ]);
        $biz = (int) $request->user()->business_id;
        MessageTemplate::updateOrCreate(['business_id' => $biz, 'key' => 'reminder'], ['name' => 'Order Reminder SMS', 'body' => $data['reminder']]);
        MessageTemplate::updateOrCreate(['business_id' => $biz, 'key' => 'advance'], ['name' => 'Advance Payment SMS', 'body' => $data['advance']]);
        $this->setLogged($request, 'invoice.template', $data['invoice_template']);

        return back()->with('success', 'Templates saved');
    }

    public function updateInventory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_stock_enabled' => ['required', 'boolean'],
            'warehouse_cartons' => ['required', 'boolean'],
            'stock_management_disabled' => ['required', 'boolean'],
            'valuation' => ['required', 'string', 'in:average_cost'],
        ]);
        $this->setLogged($request, 'inventory.branch_stock_enabled', (bool) $data['branch_stock_enabled']);
        $this->setLogged($request, 'inventory.warehouse_cartons', (bool) $data['warehouse_cartons']);
        $this->setLogged($request, 'inventory.stock_management_disabled', (bool) $data['stock_management_disabled']);
        $this->setLogged($request, 'inventory.valuation', $data['valuation']);

        return back()->with('success', 'Inventory settings saved');
    }

    public function updateFinance(Request $request): RedirectResponse
    {
        $biz = (int) $request->user()->business_id;
        $data = $request->validate([
            'daily_closing' => ['required', 'boolean'],
            'advance_account_id' => ['nullable', 'integer'],
        ]);
        $this->setLogged($request, 'finance.' . $biz . '.daily_closing', (bool) $data['daily_closing']);
        $this->setLogged($request, 'finance.' . $biz . '.advance_account_id', (int) ($data['advance_account_id'] ?? 0));

        return back()->with('success', 'Finance settings saved');
    }

    public function updateMarketingSms(Request $request): RedirectResponse
    {
        $biz = (int) $request->user()->business_id;
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
        $sms->setSetting($biz, 'sms_rate', (float) $data['rate']);
        $sms->setSetting($biz, 'cap_day', (int) $data['cap_day']);
        $sms->setSetting($biz, 'cap_week', (int) $data['cap_week']);
        if (! empty($data['add_balance'])) {
            $sms->setSetting($biz, 'sms_balance', round($sms->balance($biz) + (float) $data['add_balance'], 2));
        }
        // The provider account is the seller's own: every field is saved
        // per business (marketing.{biz}.sms_*), never platform-global.
        $sms->setSetting($biz, 'sms_provider', $data['provider']);
        $sms->setSetting($biz, 'sms_mode', $data['mode']);
        $sms->setSetting($biz, 'sms_sender_id', $data['sender_id'] ?? '');
        if (! empty($data['api_key'])) {
            $sms->setSetting($biz, 'sms_api_key_enc', \Illuminate\Support\Facades\Crypt::encryptString($data['api_key']));
            // Plain marker so views can tell a key exists without decrypting it.
            $sms->setSetting($biz, 'sms_api_key', 'set');
        }
        if (! empty($data['webhook_secret'])) {
            $sms->setSetting($biz, 'sms_webhook_secret_enc', \Illuminate\Support\Facades\Crypt::encryptString($data['webhook_secret']));
            $sms->setSetting($biz, 'sms_webhook_secret', 'set');
        }

        return back()->with('success', 'SMS settings saved');
    }

    /** Settings → Marketing SMS "Check connection": provider balance as JSON. */
    public function checkSmsConnection(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $data = $request->validate(['key' => ['nullable', 'string', 'max:255']]);
        // A key typed into the form is tested as typed; blank tests the saved one.
        $result = app(\App\Services\SmsService::class)->testConnection($biz, $data['key'] ?? null);

        return response()->json($result);
    }

    /** Settings → Marketing SMS "Send test SMS": one send in the current mode. */
    public function sendTestSms(Request $request): RedirectResponse
    {
        $biz = (int) $request->user()->business_id;
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $body = trim((string) ($data['message'] ?? '')) ?: 'SkaleUp test SMS';
        $log = app(\App\Services\SmsService::class)->sendTest($biz, $data['phone'], $body);
        if ($log->status === 'sent') {
            return back()->with('success', 'Test SMS sent');
        }

        return back()->with('error', 'Test SMS not sent — ' . ($log->error ?: 'unknown error'));
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
        foreach ($data as $key => $value) {
            $this->setLogged($request, "orders.{$key}", (bool) $value);
        }

        return back()->with('success', 'Order settings saved');
    }

    public function addCourierMethod(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $methods = $this->settings->get('couriers.methods', []);
        $exists = collect($methods)->contains(fn ($m) => strtolower((string) $m) === strtolower($data['name']));
        if (! $exists) {
            $methods[] = $data['name'];
            $this->setLogged($request, 'couriers.methods', array_values($methods));
        }

        return back()->with('success', 'Delivery method added');
    }

    // ---------- AI & Auto Reply (M6) ----------

    public function storeAiRule(Request $request): RedirectResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', 'string', 'in:intent,keyword'],
            'match_text' => ['nullable', 'string', 'max:200'],
            'action' => ['required', 'string', 'in:answer,draft,hide,handover'],
            'reply_template' => ['nullable', 'string', 'max:2000'],
        ]);
        \App\Models\AiRule::create($data + ['business_id' => $biz, 'active' => true]);

        return back()->with('success', 'Rule added');
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

        return back()->with('success', 'Rule saved');
    }

    public function deleteAiRule(Request $request, \App\Models\AiRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $rule->delete();

        return back()->with('success', 'Rule deleted');
    }

    public function testAiRule(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $brain = app(\App\Services\RuleBrainService::class);
        $rule = $brain->matchRule($biz, $data['text']);
        if (! $rule) {
            return response()->json(['matched' => false, 'note' => 'কোনো নিয়ম মেলেনি — AI product-এর বিবরণ পড়ে draft বানাবে, মানুষ দেখে পাঠাবে।']);
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
        $data = $request->validate([
            'facebook' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'whatsapp' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'instagram' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'comment' => ['required', 'string', 'in:off,draft,auto,full_auto'],
            'working_hours' => ['nullable', 'string', 'max:200'],
        ]);
        $auto = $this->settings->get('ai.autonomy', []);
        $auto = array_merge(is_array($auto) ? $auto : [], [
            'facebook' => $data['facebook'], 'whatsapp' => $data['whatsapp'],
            'instagram' => $data['instagram'], 'comment' => $data['comment'],
        ]);
        $this->setLogged($request, 'ai.autonomy', $auto);
        $this->setLogged($request, 'ai.working_hours', (string) ($data['working_hours'] ?? ''));

        return back()->with('success', 'AI Reply settings saved');
    }

    public function updateAiTalker(Request $request): RedirectResponse
    {
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

        return back()->with('success', 'AI Model settings saved');
    }

    public function testAiTalker(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:gemini,claude'],
            'key' => ['nullable', 'string', 'max:300'],
        ]);
        // A key typed into the form is tested as typed; blank tests the saved one.
        $result = app(\App\Services\AiTalkerService::class)->testConnection($data['provider'], $data['key'] ?? null);

        return response()->json($result);
    }

    public function updateAiNegotiation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_discount' => ['required', 'integer', 'min:0', 'max:90'],
            'step' => ['required', 'integer', 'min:1', 'max:50'],
            'free_delivery_min' => ['required', 'numeric', 'min:0'],
        ]);
        $this->setLogged($request, 'ai.negotiation', $data);

        return back()->with('success', 'Negotiation settings saved');
    }

    public function updateAiBehavior(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'angry_holding' => ['required', 'boolean'],
            'honorific' => ['required', 'string', 'in:auto,apu,bhaiya,none'],
            'daily_llm_cap' => ['required', 'integer', 'min:0', 'max:100000'],
            'debounce_seconds' => ['required', 'integer', 'min:0', 'max:60'],
        ]);
        $this->setLogged($request, 'ai.behavior', $data);

        return back()->with('success', 'AI behaviour settings saved');
    }

    public function storeKnowledge(Request $request): RedirectResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000']]);
        \App\Models\KnowledgeEntry::create($data + ['business_id' => $biz]);

        return back()->with('success', 'Knowledge added');
    }

    public function updateKnowledge(Request $request, \App\Models\KnowledgeEntry $entry): RedirectResponse
    {
        abort_unless((int) $entry->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000']]);
        $entry->update($data);

        return back()->with('success', 'Knowledge saved');
    }

    public function deleteKnowledge(Request $request, \App\Models\KnowledgeEntry $entry): RedirectResponse
    {
        abort_unless((int) $entry->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $entry->delete();

        return back()->with('success', 'Knowledge deleted');
    }

    public function approveProposal(Request $request, \App\Models\AiRuleProposal $proposal): RedirectResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        abort_unless((int) $proposal->business_id === $biz, 403);
        $data = $proposal->rule_data ?? [];
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

        return back()->with('success', 'Proposal approved');
    }

    public function rejectProposal(Request $request, \App\Models\AiRuleProposal $proposal): RedirectResponse
    {
        abort_unless((int) $proposal->business_id === (int) ($request->user()?->business_id ?? 0), 403);
        $proposal->status = 'rejected';
        $proposal->save();

        return back()->with('success', 'Proposal rejected');
    }

    // ---------- Messaging Channels (Meta connection) ----------

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
        $uid = $request->user()?->id;
        $this->settings->set('meta.app_id', trim((string) ($data['app_id'] ?? '')), $uid);
        if (! empty($data['app_secret'])) {
            $this->settings->set('meta.app_secret_enc', \App\Services\MetaGraphService::encrypt($data['app_secret']), $uid);
        }
        if ((string) $this->settings->get('meta.verify_token', '') === '') {
            $this->settings->set('meta.verify_token', 'skaleup_' . \Illuminate\Support\Str::random(24), $uid);
        }
        $this->settings->set('channels.business_id', (int) ($request->user()?->business_id ?? 0) ?: (int) $this->settings->get('channels.business_id', 1), $uid);
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
            $this->settings->set("channels.{$channel}", $cfg, $uid);
        }

        return back()->with('success', 'Messaging channels saved');
    }

    public function checkChannel(Request $request): \Illuminate\Http\JsonResponse
    {
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

        return back()->with('success', 'Pack created');
    }

    public function updatePackage(Request $request, \App\Models\PointPack $package): RedirectResponse
    {
        $data = $this->packData($request);
        $package->update([
            'name' => $data['name'], 'price_bdt' => $data['price_bdt'], 'credits' => $data['credits'],
            'validity_type' => $data['validity_type'],
            'is_active' => (bool) ($data['active'] ?? $package->is_active),
        ]);

        return back()->with('success', 'Pack saved');
    }

    public function deletePackage(Request $request, \App\Models\PointPack $package): RedirectResponse
    {
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        if (\App\Models\BusinessWallet::where('pack_id', $package->id)->exists()) {
            return back()->with('error', 'A seller is on this pack — deactivate it instead of deleting');
        }
        $package->delete();

        return back()->with('success', 'Pack deleted');
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
        $this->settings->set('pricing.points_per_order', (int) $data['points_per_order']);
        $this->settings->set('pricing.onboarding_fee_bdt', (float) $data['onboarding_fee_bdt']);
        $this->settings->set('pricing.low_balance_threshold', (int) $data['low_balance_threshold']);
        $this->settings->set('pricing.payment_instructions', (string) ($data['payment_instructions'] ?? ''));
        $this->settings->set('pricing.enforcement_enabled', (bool) $data['enforcement_enabled']);

        return back()->with('success', 'Pricing settings saved');
    }
}

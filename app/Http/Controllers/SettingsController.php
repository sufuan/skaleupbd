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
            'totpEnabled' => $this->totp->enabled(),
            'totpEnrolled' => (bool) $request->user()?->hasTotpEnabled(),
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
            'old_value' => is_bool($old) ? ($old ? 'On' : 'Off') : (string) ($old ?? ''),
            'new_value' => is_bool($new) ? ($new ? 'On' : 'Off') : (string) ($new ?? ''),
            'changed_by' => $request->user()?->name,
            'created_at' => now(),
        ]);
    }

    private function setLogged(Request $request, string $key, mixed $value): void
    {
        $old = $this->settings->get($key);
        $this->settings->set($key, $value, $request->user()?->id);
        if ((string) $old !== (string) $value) {
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
}

<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Services\AttributionService;
use App\Services\AutomationService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MarketingController extends Controller
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly AutomationService $auto,
        private readonly AttributionService $attr,
    ) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    // ---------- Automation + Templates ----------

    public function automation(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $cfg = $this->auto->config($biz);
        $flows = [];
        foreach (AutomationService::FLOWS as $key => $f) {
            $stats = $this->auto->flowStats($biz, $key);
            $flows[] = [
                'key' => $key, 'group' => $f['group'], 'title' => $f['title'], 'when' => $f['when'],
                'body' => $this->auto->body($biz, $key),
                'enabled' => $cfg[$key]['enabled'], 'timing' => $cfg[$key]['timing'],
                'sent_total' => $stats['sent_total'], 'sent_today' => $stats['sent_today'],
            ];
        }
        $templates = MessageTemplate::where('business_id', $biz)->orderBy('id')->get()
            ->map(fn ($t) => ['id' => $t->id, 'key' => $t->key, 'name' => $t->name, 'body' => $t->body,
                'standard' => in_array($t->key, AutomationService::TEMPLATE_KEYS, true) || in_array($t->key, ['offer'], true)]);

        // This page is about AUTOMATION — its strip must count automation
        // sends only. It counted every SMS (campaigns, order SMS, settings
        // tests), so the strip said "1 sent, ৳0" while every flow card
        // truthfully said "run 0 times".
        $month = collect($this->attr->report($biz, Carbon::now()->startOfMonth(), Carbon::now()->endOfDay()))
            ->where('type', 'automation');
        $sentMonth = SmsLog::where('business_id', $biz)->where('status', 'sent')->where('source_type', 'automation')
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()])->get();

        return Inertia::render('Marketing/Automation', [
            'flows' => $flows,
            'templates' => $templates,
            'strip' => [
                'sent' => $sentMonth->count(),
                'cost' => round($sentMonth->sum('cost'), 2),
                'orders' => $month->sum('orders'),
                'sales' => round($month->sum('sales'), 2),
            ],
        ]);
    }

    public function saveFlow(Request $request, string $key): RedirectResponse
    {
        abort_unless(isset(AutomationService::FLOWS[$key]), 404);
        $biz = $this->biz($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'timing' => ['nullable', 'array'],
            'timing.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        $cfg = $this->auto->config($biz);
        $cfg[$key]['enabled'] = $data['enabled'];
        foreach (AutomationService::FLOWS[$key]['timing'] as $tk => $default) {
            if (isset($data['timing'][$tk])) {
                $cfg[$key]['timing'][$tk] = (int) $data['timing'][$tk];
            }
        }
        $this->auto->saveConfig($biz, $cfg);

        return back()->with('success', 'Automation saved');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ]);
        MessageTemplate::create(['business_id' => $biz, 'key' => 'tpl-'.Str::lower(Str::random(8)),
            'name' => $data['name'], 'body' => $data['body']]);

        return back()->with('success', 'Template added');
    }

    public function updateTemplate(Request $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ]);
        $template->update($data);

        return back()->with('success', 'Template saved');
    }

    public function deleteTemplate(Request $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->business_id === $this->biz($request), 403);
        abort_if(in_array($template->key, AutomationService::TEMPLATE_KEYS, true), 422, 'Flow templates cannot be deleted');
        $template->delete();

        return back()->with('success', 'Template deleted');
    }

    // ---------- SMS Marketing ----------

    public function sms(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $audiences = [];
        foreach ($this->sms->audiences($biz) as $key => $a) {
            $sendable = collect($a['phones'])->reject(fn ($p) => $this->sms->onDoNotSend($biz, $p))->count();
            $audiences[] = ['key' => $key, 'label' => $a['label'], 'count' => count($a['phones']), 'sendable' => $sendable];
        }
        $templates = MessageTemplate::where('business_id', $biz)->orderBy('id')->get(['id', 'name', 'body']);

        $campaigns = SmsCampaign::where('business_id', $biz)->latest()->limit(20)->get();
        $attrRows = $campaigns->isEmpty() ? collect()
            : collect($this->attr->report($biz, $campaigns->min('created_at') ?? Carbon::now(), Carbon::now()->endOfDay()))
                ->where('type', 'campaign')->keyBy('key');
        $campaignRows = $campaigns->map(function ($c) use ($attrRows) {
            $a = $attrRows->get((string) $c->id);

            return ['id' => $c->id, 'name' => $c->name, 'audience_label' => $c->audience_label,
                'recipients' => $c->recipients, 'total_cost' => (float) $c->total_cost,
                'orders' => $a['orders'] ?? 0, 'sales' => $a['sales'] ?? 0.0, 'profit' => $a['profit'] ?? 0.0,
                'date' => $c->created_at->format('d M')];
        });

        // Success rate over messages that actually reached a gateway
        // (provider set). Blocked attempts — no balance, no gateway, caps,
        // DND — were never "sent", and counting them produced nonsense like
        // 6%. Settings test sends are not customer messaging either.
        $lastMonth = SmsLog::where('business_id', $biz)->where('created_at', '>=', Carbon::now()->subDays(30))
            ->whereNotNull('provider')->where('source_type', '!=', 'test')->get();
        $sentOk = $lastMonth->where('status', 'sent')->count();
        $attempts = $lastMonth->whereIn('status', ['sent', 'failed'])->count();

        return Inertia::render('Marketing/Sms', [
            'balance' => $this->sms->balance($biz),
            'rate' => $this->sms->rate($biz),
            'success_rate' => $attempts > 0 ? (int) round($sentOk / $attempts * 100) : null,
            'audiences' => $audiences,
            'dnd_count' => $this->sms->doNotSendCount($biz),
            'templates' => $templates,
            'campaigns' => $campaignRows,
        ]);
    }

    public function sendCampaign(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'audience_key' => ['required', 'string'],
            'message' => ['required', 'string', 'max:1000'],
            'coupon' => ['nullable', 'string', 'max:40'],
        ]);
        $audiences = $this->sms->audiences($biz);
        abort_unless(isset($audiences[$data['audience_key']]), 422);
        $audience = $audiences[$data['audience_key']];

        $body = $data['message'];
        if (! empty($data['coupon']) && ! str_contains($body, $data['coupon'])) {
            $body .= ' Code: '.$data['coupon'];
        }
        $name = Str::limit(trim(preg_replace('/\s+/', ' ', $data['message'])), 42, '…');

        $campaign = SmsCampaign::create([
            'business_id' => $biz, 'name' => $name, 'audience_key' => $data['audience_key'],
            'audience_label' => $audience['label'], 'message' => $body, 'coupon' => $data['coupon'] ?? null,
            'parts' => $this->sms->parts($body), 'status' => 'sending', 'sent_by' => $request->user()->id,
        ]);

        $sent = 0;
        $cost = 0.0;
        foreach ($audience['phones'] as $phone) {
            $log = $this->sms->send($biz, $phone, $body, [
                'type' => 'campaign', 'key' => (string) $campaign->id, 'label' => $name,
            ]);
            if ($log->status === 'sent') {
                $sent++;
                $cost += (float) $log->cost;
            }
        }
        $campaign->update(['recipients' => $sent, 'total_cost' => round($cost, 2), 'status' => 'sent']);

        return back()->with('success', "Campaign sent — {$sent} SMS delivered, cost ৳".number_format($cost, 2));
    }

    // ---------- SMS Report ----------

    public function report(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $preset = $request->query('preset', 'month');
        $to = Carbon::now()->endOfDay();
        $from = match ($preset) {
            'today' => Carbon::today(),
            '7d' => Carbon::now()->subDays(6)->startOfDay(),
            'custom' => $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::now()->startOfMonth(),
            default => Carbon::now()->startOfMonth(),
        };
        if ($preset === 'custom' && $request->query('to')) {
            $to = Carbon::parse($request->query('to'))->endOfDay();
        }
        $rows = $this->attr->report($biz, $from, $to);

        return Inertia::render('Marketing/Report', [
            'rows' => $rows,
            'totals' => [
                'sent' => collect($rows)->sum('sent'), 'orders' => collect($rows)->sum('orders'),
                'sales' => round(collect($rows)->sum('sales'), 2), 'sms_cost' => round(collect($rows)->sum('sms_cost'), 2),
                'profit' => round(collect($rows)->sum('profit'), 2),
            ],
            'window_days' => $this->attr->windowDays($biz),
            'preset' => $preset,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ]);
    }

    public function saveWindow(Request $request): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $this->sms->setSetting($this->biz($request), 'attribution_days', (int) $data['days']);

        return back()->with('success', 'Report rule saved');
    }
}

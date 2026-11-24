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
use App\Support\DhakaTime;

class MarketingController extends Controller
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly AutomationService $auto,
        private readonly AttributionService $attr,
    ) {}

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
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

        return back()->with('success', __('Automation saved'));
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

        return back()->with('success', __('Template added'));
    }

    public function updateTemplate(Request $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ]);
        $template->update($data);

        return back()->with('success', __('Template saved'));
    }

    public function deleteTemplate(Request $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->business_id === $this->biz($request), 403);
        abort_if(in_array($template->key, AutomationService::TEMPLATE_KEYS, true), 422, 'Flow templates cannot be deleted');
        $template->delete();

        return back()->with('success', __('Template deleted'));
    }

    // ---------- SMS Marketing ----------

    public function sms(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $audiences = [];
        foreach ($this->sms->audiences($biz) as $key => $a) {
            // Sendable = the phones a send would really reach right now:
            // the do-not-send list AND the frequency caps, through the
            // same check the sender itself applies (SmsService::
            // marketingSkipReason) - never a second, looser count.
            $sendable = collect($a['phones'])->reject(fn ($p) => $this->sms->marketingSkipReason($biz, $p) !== null)->count();
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
                'recipients' => $c->recipients, 'total_cost' => (float) $c->total_cost, 'status' => $c->status,
                'orders' => $a['orders'] ?? 0, 'sales' => $a['sales'] ?? 0.0, 'profit' => $a['profit'] ?? 0.0,
                'date' => DhakaTime::fmt($c->created_at, 'd M')];
        });

        // Success rate over the shop's real sends of the last month,
        // whatever mode they went out in. Demo sends are logged with no
        // provider but are simulated successes, so they count like any
        // other send - filtering on provider made the card permanently
        // "-" for exactly the shops still setting up. Blocked attempts
        // (caps, DND, no balance) were never "sent" and stay out of both
        // numbers; settings test sends are not customer messaging either.
        $lastMonth = SmsLog::where('business_id', $biz)->where('created_at', '>=', Carbon::now()->subDays(30))
            ->where('source_type', '!=', 'test')->get();
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
        if (! empty($data['coupon'])) {
            // Fill the template slots the campaign itself knows: the
            // coupon code, and the discount the compose form's coupon
            // select promises for it ("10% off" / "৳50 off"). A raw
            // {placeholder} must never reach a customer's phone (Hunt V2).
            $vars = ['coupon' => $data['coupon']];
            $discount = ['FEST10' => '10%', 'RETURN50' => '৳50'][$data['coupon']] ?? null;
            if ($discount !== null) {
                $vars['discount'] = $discount;
            }
            $body = $this->auto->fill($body, $vars);
        }
        if (! empty($data['coupon']) && ! str_contains($body, $data['coupon'])) {
            $body .= ' Code: '.$data['coupon'];
        }
        if (preg_match_all('/\{[a-z_]+\}/', $body, $mm) && $mm[0]) {
            return back()->with('error', __('The campaign was not sent - the message still has unfilled placeholders (:placeholders). Remove them or fill them in first.', ['placeholders' => implode(', ', array_unique($mm[0]))]));
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
        // A campaign nobody could be reached by is not "sent": it keeps
        // its honest 0 recipients / ৳0 and a status that says so, instead
        // of sitting in the list with a profit verdict for a send that
        // never happened.
        $campaign->update(['recipients' => $sent, 'total_cost' => round($cost, 2), 'status' => $sent > 0 ? 'sent' : 'not_sent']);

        if ($sent === 0) {
            return back()->with('error', __('No SMS could be sent - every recipient was stopped by the do-not-send list or the daily/weekly SMS limit.'));
        }

        return back()->with('success', __('Campaign sent - :sent SMS delivered, cost :cost', ['sent' => $sent, 'cost' => '৳' . number_format($cost, 2)]));
    }

    // ---------- SMS Report ----------

    public function report(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $preset = $request->query('preset', 'month');
        // Windows are Dhaka calendar days (the seller's days), converted
        // to UTC instants for the stored timestamps — the old code used
        // UTC days, so "today" was yesterday until 6 AM Dhaka time.
        $todayDhaka = Carbon::now('Asia/Dhaka');
        $toDate = $todayDhaka->toDateString();
        $fromDate = match ($preset) {
            'today' => $toDate,
            '7d' => $todayDhaka->copy()->subDays(6)->toDateString(),
            'custom' => $request->query('from') ?: $todayDhaka->copy()->startOfMonth()->toDateString(),
            default => $todayDhaka->copy()->startOfMonth()->toDateString(),
        };
        if ($preset === 'custom' && $request->query('to')) {
            $toDate = (string) $request->query('to');
        }
        [$from] = \App\Support\DhakaTime::dayBoundsUtc($fromDate);
        [, $to] = \App\Support\DhakaTime::dayBoundsUtc($toDate);
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
            'from' => $fromDate, 'to' => $toDate,
        ]);
    }

    public function saveWindow(Request $request): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $this->sms->setSetting($this->biz($request), 'attribution_days', (int) $data['days']);

        return back()->with('success', __('Report rule saved'));
    }
}

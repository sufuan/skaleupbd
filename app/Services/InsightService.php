<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationStage;
use App\Models\InsightAction;
use App\Models\InsightDaily;
use App\Models\InsightTask;
use App\Models\InsightTaskMember;
use App\Models\Order;
use App\Models\Product;
use App\Models\StageEvent;
use App\Support\DhakaTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M12 Sales Growth — reads and jobs over the stage layer.
 *
 * Accuracy rules (owner, 2026-11-22): every number comes from real
 * records through StageService's single definitions; the identity
 * কথা বলেছে = stuck0 + stuck1 + stuck2 + অর্ডার হয়েছে must hold or
 * the numbers are hidden behind "ডাটা যাচাই চলছে"; percentages need
 * a cohort of 100+; the আনুমানিক badge stays until the human audit
 * gate passes (settings growth.{biz}.audit_passed, default off).
 *
 * The reconciliation job is the independent checker: it re-derives
 * counts from chat_messages and orders WITHOUT trusting the stage
 * tables, and hides + logs any mismatch.
 */
class InsightService
{
    public const PERIODS = ['today' => 1, '7d' => 7, '30d' => 30];

    public function __construct(
        private readonly StageService $stages,
        private readonly SettingsService $settings,
        private readonly RuleBrainService $brain,
        private readonly AiTalkerService $talker,
        private readonly AiGuardService $guard,
    ) {}

    // ---------------- settings flags ----------------

    public function auditPassed(int $biz): bool
    {
        return (bool) $this->settings->get("growth.{$biz}.audit_passed", false);
    }

    public function reconFailed(int $biz): bool
    {
        $r = $this->settings->get("growth.{$biz}.recon", null);

        return is_array($r) && ($r['ok'] ?? true) === false;
    }

    // ---------------- summary ----------------

    /** [fromUtc, toUtc] covering the last $days Dhaka calendar days incl. today. */
    private function bounds(int $days, int $offsetDays = 0): array
    {
        $today = Carbon::parse(DhakaTime::today());
        $endDate = $today->copy()->subDays($offsetDays)->toDateString();
        $startDate = $today->copy()->subDays($offsetDays + $days - 1)->toDateString();
        [$from] = DhakaTime::dayBoundsUtc($startDate);
        [, $to] = DhakaTime::dayBoundsUtc($endDate);

        return [$from, $to];
    }

    /** Cohort base query: conversations whose first inbound falls in the window, spam excluded (stage-0 definition). */
    private function cohort(int $biz, Carbon $from, Carbon $to, ?string $channel = null)
    {
        return Conversation::query()
            ->join('conversation_stage', 'conversation_stage.conversation_id', '=', 'conversations.id')
            ->where('conversations.business_id', $biz)
            ->whereBetween('conversations.first_inbound_at', [$from, $to])
            ->when($channel !== null, fn ($q) => $q->where('conversations.channel', $channel))
            ->where(fn ($q) => $q->whereNull('conversation_stage.closed_reason')->orWhere('conversation_stage.closed_reason', '!=', 'spam'));
    }

    private function funnelFor(int $biz, Carbon $from, Carbon $to, ?string $channel = null): array
    {
        $base = $this->cohort($biz, $from, $to, $channel);
        $wrote = (clone $base)->count();
        $reached = [];
        foreach ([1, 2, 3] as $n) {
            $reached[$n] = (clone $base)->where('conversation_stage.stage_reached', '>=', $n)->count();
        }
        $stuck = [];
        foreach ([0, 1, 2] as $n) {
            $stuck[$n] = (clone $base)->where('conversation_stage.stage_current', $n)
                ->whereNull('conversation_stage.closed_reason')->count();
        }
        $ordered = $reached[3];

        return [
            'wrote' => $wrote,
            'reached' => [0 => $wrote, 1 => $reached[1], 2 => $reached[2], 3 => $ordered],
            'stuck' => $stuck,
            'ordered' => $ordered,
            'identity_ok' => $wrote === ($stuck[0] + $stuck[1] + $stuck[2] + $ordered),
            'monotonic_ok' => $wrote >= $reached[1] && $reached[1] >= $reached[2] && $reached[2] >= $ordered,
        ];
    }

    public function summary(int $biz, string $period): array
    {
        $days = self::PERIODS[$period] ?? 30;
        $cacheKey = "growth.summary.{$biz}.{$period}.".($this->auditPassed($biz) ? 'a' : 'e');

        return Cache::remember($cacheKey, 300, function () use ($biz, $period, $days) {
            [$from, $to] = $this->bounds($days);
            [$pFrom, $pTo] = $this->bounds($days, $days);
            $cur = $this->funnelFor($biz, $from, $to);
            $prev = $this->funnelFor($biz, $pFrom, $pTo);

            $corruptRows = InsightDaily::where('business_id', $biz)->where('reconciled', false)
                ->whereDate('cohort_date', '>=', $from->copy()->setTimezone(DhakaTime::TZ)->toDateString())
                ->whereDate('cohort_date', '<=', $to->copy()->setTimezone(DhakaTime::TZ)->toDateString())
                ->exists();
            $dataCheck = ! $cur['identity_ok'] || ! $cur['monotonic_ok'] || $this->reconFailed($biz) || $corruptRows;

            $pct = fn ($num, $den) => ($cur['wrote'] >= 100 && $den > 0) ? round($num / $den * 100, 1) : null;

            return [
                'period' => $period,
                'data_check' => $dataCheck,
                'estimated' => ! $this->auditPassed($biz),
                'low_data' => $cur['wrote'] < 100,
                'wrote' => $dataCheck ? null : $cur['wrote'],
                'ordered' => $dataCheck ? null : $cur['ordered'],
                'reached' => $dataCheck ? null : $cur['reached'],
                'stuck' => $dataCheck ? null : $cur['stuck'],
                'conversion_pct' => $dataCheck ? null : $pct($cur['ordered'], $cur['wrote']),
                'step_pct' => $dataCheck ? null : [
                    1 => $pct($cur['reached'][1], $cur['wrote']),
                    2 => $pct($cur['reached'][2], $cur['reached'][1]),
                    3 => $pct($cur['reached'][3], $cur['reached'][2]),
                ],
                'prev' => [
                    'wrote' => $prev['wrote'],
                    'ordered' => $prev['ordered'],
                    'conversion_pct' => ($prev['wrote'] >= 100 && $prev['wrote'] > 0) ? round($prev['ordered'] / $prev['wrote'] * 100, 1) : null,
                ],
            ];
        });
    }

    public function forgetSummary(int $biz): void
    {
        foreach (array_keys(self::PERIODS) as $p) {
            Cache::forget("growth.summary.{$biz}.{$p}.a");
            Cache::forget("growth.summary.{$biz}.{$p}.e");
        }
    }

    // ---------------- tasks ----------------

    /** Conversations with an unanswered complaint signal (angry / needs a human) older than 30 minutes. */
    public function complaintConversations(int $biz): array
    {
        $cutoff = now()->subMinutes(30);
        $angryIds = StageEvent::where('business_id', $biz)->where('reason_code', 'angry')
            ->distinct()->pluck('conversation_id')->all();
        $candidates = Conversation::where('business_id', $biz)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', $cutoff)
            ->where(fn ($q) => $q->where('needs_human', true)->orWhereIn('id', $angryIds ?: [0]))
            ->get();
        $out = [];
        foreach ($candidates as $c) {
            $stage = ConversationStage::where('conversation_id', $c->id)->first();
            if ($stage && $stage->closed_reason) {
                continue;
            }
            // Unanswered = no outbound message after the last inbound one.
            $lastIn = ChatMessage::where('conversation_id', $c->id)->where('direction', 'in')->max('id');
            $lastOut = ChatMessage::where('conversation_id', $c->id)->where('direction', 'out')->max('id');
            if ($lastIn && ($lastOut === null || $lastOut < $lastIn)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    public function generateTasks(int $biz): array
    {
        $today = DhakaTime::today();
        InsightTask::where('business_id', $biz)->where('status', 'open')
            ->where('expires_at', '<', now())->update(['status' => 'expired']);

        $actedToday = InsightAction::where('business_id', $biz)
            ->whereBetween('created_at', DhakaTime::dayBoundsUtc($today))
            ->distinct()->pluck('conversation_id')->all();
        $complaints = $this->complaintConversations($biz);
        $complaintIds = collect($complaints)->pluck('id')->all();

        $segment = function (int $stage) use ($biz, $actedToday, $complaintIds) {
            return Conversation::query()
                ->join('conversation_stage', 'conversation_stage.conversation_id', '=', 'conversations.id')
                ->where('conversations.business_id', $biz)
                ->where('conversation_stage.stage_current', $stage)
                ->whereNull('conversation_stage.closed_reason')
                ->where('conversations.last_inbound_at', '>=', now()->subDays(14))
                ->whereNotIn('conversations.id', $actedToday ?: [0])
                ->whereNotIn('conversations.id', $complaintIds ?: [0])
                ->select('conversations.*')
                ->get()->all();
        };

        $defs = [
            ['kind' => 'complaint', 'priority' => 1, 'rows' => $complaints],
            ['kind' => 'ready', 'priority' => 2, 'rows' => $segment(2)],
            ['kind' => 'interested', 'priority' => 3, 'rows' => $segment(1)],
        ];

        $aov = (float) (Order::where('business_id', $biz)->where('created_at', '>=', now()->subDays(90))->avg('grand_total') ?? 0);
        $made = [];
        $openCount = InsightTask::where('business_id', $biz)->whereDate('task_date', $today)->where('status', 'open')->count();
        foreach ($defs as $def) {
            $rows = $def['rows'];
            if ($rows === []) {
                continue;
            }
            $existing = InsightTask::where('business_id', $biz)->where('dedupe_key', $def['kind'])->whereDate('task_date', $today)->first();
            if ($existing && in_array($existing->status, ['done', 'snoozed'], true)) {
                continue; // the seller already handled / parked today's list
            }
            if (! $existing && $openCount >= 5) {
                continue; // at most 5 open tasks
            }
            $est = ($def['kind'] !== 'complaint' && $aov > 0)
                ? (int) (round(count($rows) * 0.1 * $aov / 10) * 10)
                : null;
            $task = $existing ?: new InsightTask(['business_id' => $biz, 'dedupe_key' => $def['kind'], 'task_date' => $today]);
            $task->kind = $def['kind'];
            $task->count = count($rows);
            $task->est_value = $est;
            $task->priority = $def['priority'];
            $task->status = 'open';
            $task->generated_at = now();
            $task->expires_at = now()->addDay();
            $task->save();
            InsightTaskMember::where('task_id', $task->id)->delete();
            foreach ($rows as $c) {
                InsightTaskMember::create(['business_id' => $biz, 'task_id' => $task->id, 'conversation_id' => $c->id]);
            }
            if (! $existing) {
                $openCount++;
            }
            $made[] = $task;
        }
        $this->forgetSummary($biz);

        return $made;
    }

    public function tasksFor(int $biz, bool $canSeeRevenue): array
    {
        $today = DhakaTime::today();
        if (! InsightTask::where('business_id', $biz)->whereDate('task_date', $today)->exists()) {
            $this->generateTasks($biz);
        }

        return InsightTask::where('business_id', $biz)->whereDate('task_date', $today)
            ->whereIn('status', ['open', 'done', 'snoozed'])
            ->orderBy('priority')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'kind' => $t->kind,
                'count' => (int) $t->count,
                'est_value' => $canSeeRevenue ? ($t->est_value !== null ? (float) $t->est_value : null) : null,
                'status' => $t->status,
                'priority' => (int) $t->priority,
            ])->all();
    }

    public function taskCustomers(InsightTask $task): array
    {
        $today = DhakaTime::today();
        // "Drafted" means a draft was actually created for this
        // customer today — it only gates the Draft button (no
        // duplicate drafts). A logged call must NOT mark the
        // customer as drafted, and nothing here may gate the Call
        // button: calling is always available.
        $drafted = InsightAction::where('business_id', $task->business_id)
            ->where('type', 'draft_created')
            ->whereBetween('created_at', DhakaTime::dayBoundsUtc($today))
            ->distinct()->pluck('conversation_id')->all();

        return InsightTaskMember::where('task_id', $task->id)->with('conversation')->get()
            ->map(function ($m) use ($drafted) {
                $c = $m->conversation;
                if (! $c) {
                    return null;
                }
                $last = ChatMessage::where('conversation_id', $c->id)->where('direction', 'in')
                    ->orderByDesc('id')->first();
                $windowOpen = $c->window_expires_at && now()->lt($c->window_expires_at);

                return [
                    'conversation_id' => $c->id,
                    'name' => $c->customer_name,
                    'channel' => $c->channel,
                    'phone' => $c->customer_phone,
                    'last_message' => $last?->body,
                    'hours_since' => $c->last_inbound_at ? (int) $c->last_inbound_at->diffInHours(now()) : null,
                    'window_open' => (bool) $windowOpen,
                    'window_expires_at' => $c->window_expires_at?->toIso8601String(),
                    'drafted' => in_array($c->id, $drafted, true),
                ];
            })->filter()->values()->all();
    }

    // ---------------- actions ----------------

    /**
     * Create ONE Inbox draft for a task customer. The draft text is a
     * fixed slot-built follow-up (figures only from the product row);
     * the AI talker may rephrase it, and the result must still pass
     * the same money-figure + claims checks the M6 brain applies —
     * a failing draft is stored flagged, never clean. Nothing is ever
     * sent from here; the seller reviews and sends in the Inbox.
     */
    public function createDraft(InsightTask $task, Conversation $conversation, int $userId): ?ChatMessage
    {
        $biz = (int) $task->business_id;
        if (! $conversation->window_expires_at || now()->gte($conversation->window_expires_at)) {
            return null; // outside the 24h window — no free-form draft
        }
        $product = $conversation->product_id
            ? Product::where('business_id', $biz)->find($conversation->product_id)
            : null;

        $name = trim((string) $conversation->customer_name);
        $base = 'আসসালামু আলাইকুম'.($name !== '' ? ' '.$name : '').', ';
        if ($product) {
            $price = $product->effectivePrice();
            $base .= 'আপনি "'.$product->name.'" সম্পর্কে জানতে চেয়েছিলেন। দাম '.number_format((float) $price).' টাকা। ';
        }
        $base .= 'অর্ডার করতে চাইলে আপনার ফোন নম্বর আর ঠিকানাটা পাঠিয়ে দিন, আমরা অর্ডারটা করে দিচ্ছি। কোনো প্রশ্ন থাকলে জানাবেন।';

        $body = $base;
        $lastIn = ChatMessage::where('conversation_id', $conversation->id)->where('direction', 'in')->orderByDesc('id')->first();
        $phrased = $this->talker->phrase([
            'business_id' => $biz,
            'channel' => $conversation->channel,
            'incoming' => (string) ($lastIn?->body ?? ''),
            'intent' => 'follow_up',
            'draft' => $base,
        ]);
        if ($phrased && trim((string) $phrased['text']) !== '') {
            $candidate = $this->brain->renderSlots((string) $phrased['text'], $product, $biz);
            if ($this->brain->moneyCheck($biz, $candidate, $product)
                && $this->guard->claimViolations($biz, $base, $candidate, []) === []) {
                $body = $candidate;
            }
        }
        $status = $this->brain->moneyCheck($biz, $body, $product) ? 'draft' : 'flagged';

        $msg = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $body,
            'status' => $status,
            'meta' => ['kind' => 'growth_followup', 'task_id' => $task->id],
            'user_id' => $userId,
        ]);
        InsightAction::create([
            'business_id' => $biz, 'task_id' => $task->id, 'conversation_id' => $conversation->id,
            'type' => 'draft_created', 'channel' => $conversation->channel, 'user_id' => $userId,
            'outcome' => $status,
        ]);

        return $msg;
    }

    public function createDraftsForTask(InsightTask $task, int $userId): array
    {
        $created = 0;
        $skipped = 0;
        foreach ($this->taskCustomers($task) as $row) {
            if ($row['drafted'] || ! $row['window_open']) {
                $skipped++;
                continue;
            }
            $conv = Conversation::find($row['conversation_id']);
            if ($conv && $this->createDraft($task, $conv, $userId)) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function logCall(InsightTask $task, Conversation $conversation, int $userId, ?string $outcome = null): InsightAction
    {
        return InsightAction::create([
            'business_id' => (int) $task->business_id, 'task_id' => $task->id,
            'conversation_id' => $conversation->id, 'type' => 'call_logged',
            'channel' => $conversation->channel, 'user_id' => $userId, 'outcome' => $outcome,
        ]);
    }

    /** "কেন এই ধাপে?" — the evidence trail for one conversation. */
    public function why(Conversation $conversation): array
    {
        $stage = ConversationStage::where('conversation_id', $conversation->id)->first();
        $events = StageEvent::where('conversation_id', $conversation->id)->orderBy('id')->get()
            ->map(fn ($e) => [
                'reason' => $e->reason_code,
                'from_stage' => $e->from_stage,
                'to_stage' => $e->to_stage,
                'source' => $e->source,
                'evidence' => $e->evidence,
                'at' => $e->created_at?->toIso8601String(),
            ])->all();

        return [
            'stage_reached' => $stage ? (int) $stage->stage_reached : 0,
            'stage_current' => $stage ? (int) $stage->stage_current : 0,
            'closed_reason' => $stage?->closed_reason,
            'events' => $events,
        ];
    }

    // ---------------- rollup + reconciliation ----------------

    public function rollup(int $biz, int $days = 35): void
    {
        $today = Carbon::parse(DhakaTime::today());
        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->subDays($i)->toDateString();
            [$from, $to] = DhakaTime::dayBoundsUtc($date);
            $f = $this->funnelFor($biz, $from, $to);
            $channels = $this->cohort($biz, $from, $to)
                ->select('conversations.channel')
                ->distinct()->pluck('conversations.channel');
            $complaints = $this->cohort($biz, $from, $to)
                ->join('stage_events', 'stage_events.conversation_id', '=', 'conversations.id')
                ->where('stage_events.reason_code', 'angry')
                ->distinct()->count('conversations.id');
            foreach ($channels as $channel) {
                $cf = $this->funnelFor($biz, $from, $to, (string) $channel);
                $this->upsertDaily($biz, $date, (string) $channel, [
                    'wrote' => $cf['wrote'], 'interested' => $cf['reached'][1], 'ready' => $cf['reached'][2],
                    'ordered' => $cf['ordered'], 'delivered' => 0, 'complaints' => 0,
                    'metric_version' => StageService::METRIC_VERSION,
                    'reconciled' => $cf['identity_ok'] && $cf['monotonic_ok'],
                ]);
            }
            $this->upsertDaily($biz, $date, 'all', [
                'wrote' => $f['wrote'], 'interested' => $f['reached'][1], 'ready' => $f['reached'][2],
                'ordered' => $f['ordered'], 'delivered' => 0, 'complaints' => $complaints,
                'metric_version' => StageService::METRIC_VERSION,
                'reconciled' => $f['identity_ok'] && $f['monotonic_ok'],
            ]);
        }
    }

    /** updateOrCreate cannot match SQLite 'date'-cast values — look up with whereDate. */
    private function upsertDaily(int $biz, string $date, string $channel, array $values): void
    {
        $row = InsightDaily::where('business_id', $biz)->whereDate('cohort_date', $date)
            ->where('channel', $channel)->first()
            ?? new InsightDaily(['business_id' => $biz, 'cohort_date' => $date, 'channel' => $channel]);
        $row->fill($values)->save();
    }

    /**
     * The independent checker. Re-derives wrote/ordered from
     * chat_messages + orders (never from conversation_stage), asserts
     * the identity on the stage tables, and on any mismatch marks the
     * rollup rows unreconciled, raises the recon flag (the page then
     * hides the numbers) and logs an admin alert.
     */
    public function reconcile(int $biz): array
    {
        $problems = [];
        $today = Carbon::parse(DhakaTime::today());

        // Raw derivation, independent of the stage tables.
        $firstInbound = ChatMessage::where('business_id', $biz)->where('direction', 'in')
            ->select('conversation_id', DB::raw('MIN(id) as first_id'), DB::raw('MIN(created_at) as first_at'))
            ->groupBy('conversation_id')->get();
        $spamFirst = [];
        foreach ($firstInbound as $row) {
            $msg = ChatMessage::find($row->first_id);
            if ($msg && ! empty(($msg->meta ?? [])['spam'])) {
                $spamFirst[$row->conversation_id] = true;
            }
        }
        $orderPhones = Order::where('business_id', $biz)->pluck('customer_phone')
            ->map(fn ($p) => PhoneNormalizer::normalize((string) $p))->filter()->unique()->all();
        $convPhones = Conversation::where('business_id', $biz)->pluck('customer_phone', 'id')
            ->map(fn ($p) => PhoneNormalizer::normalize((string) $p))->all();

        for ($i = 0; $i < 35; $i++) {
            $date = $today->copy()->subDays($i)->toDateString();
            [$from, $to] = DhakaTime::dayBoundsUtc($date);

            $rawWrote = 0;
            $rawOrdered = 0;
            foreach ($firstInbound as $row) {
                $at = Carbon::parse($row->first_at);
                if ($at->lt($from) || $at->gt($to) || isset($spamFirst[$row->conversation_id])) {
                    continue;
                }
                $rawWrote++;
                $phone = $convPhones[$row->conversation_id] ?? '';
                if ($phone !== '' && in_array($phone, $orderPhones, true)) {
                    $rawOrdered++;
                }
            }

            $f = $this->funnelFor($biz, $from, $to);
            $bad = ! $f['identity_ok'] || ! $f['monotonic_ok']
                || $f['wrote'] !== $rawWrote || $f['ordered'] !== $rawOrdered;
            InsightDaily::where('business_id', $biz)->whereDate('cohort_date', $date)
                ->update(['reconciled' => ! $bad]);
            if ($bad) {
                $problems[] = ['date' => $date, 'stage' => $f, 'raw_wrote' => $rawWrote, 'raw_ordered' => $rawOrdered];
            }
        }

        $ok = $problems === [];
        $this->settings->set("growth.{$biz}.recon", ['ok' => $ok, 'at' => now()->toIso8601String(), 'problems' => $ok ? [] : $problems]);
        if (! $ok) {
            Log::warning('Sales Growth reconciliation FAILED', ['business_id' => $biz, 'problems' => $problems]);
        }
        $this->forgetSummary($biz);

        return ['ok' => $ok, 'problems' => $problems];
    }

    // ---------------- backfill ----------------

    /**
     * One-time derivation for conversations that predate the stage
     * layer. Deterministic signals only (keyword intents re-run over
     * stored messages, captured contact, phone-matched orders) and
     * fully idempotent — every advance carries a backfill dedupe key.
     * Rows keep classifier_version 'backfill-1' and the page badge
     * stays আনুমানিক until the human audit passes.
     */
    public function backfill(int $biz): array
    {
        $stats = ['conversations' => 0, 'staged' => 0];
        Conversation::where('business_id', $biz)->orderBy('id')
            ->chunkById(200, function ($convs) use ($biz, &$stats) {
                foreach ($convs as $conv) {
                    $stats['conversations']++;
                    $inbound = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'in')
                        ->orderBy('id')->get();
                    if ($inbound->isEmpty() && ! $conv->customer_phone) {
                        continue;
                    }
                    $row = $this->stages->stageRow($conv);
                    if (! $row->classifier_version) {
                        $row->classifier_version = 'backfill-1';
                        $row->save();
                    }
                    // Repair pass: conversations from before the
                    // universal contact capture may hold a phone (and
                    // address) inside a message body that was never
                    // copied onto the record. Fill what is missing so
                    // the derivation below — and the order linking
                    // after it — can match by phone. Idempotent: the
                    // capture only ever sets empty fields.
                    if (empty($conv->customer_phone) || empty($conv->customer_address)) {
                        foreach ($inbound as $m) {
                            $this->brain->captureContactFromText($conv, (string) $m->body);
                            if (! empty($conv->customer_phone) && ! empty($conv->customer_address)) {
                                break;
                            }
                        }
                    }
                    foreach ($inbound as $m) {
                        $this->stages->noteInbound($conv, $m);
                        $intent = $this->brain->detectIntent((string) $m->body, $conv);
                        if ($intent === 'spam') {
                            $this->stages->close($conv, 'spam', ['message_id' => $m->id, 'intent' => 'spam'], 'rule', 'backfill:msg:'.$m->id);
                            break;
                        }
                        if ($intent === 'angry') {
                            $this->stages->noteSignal($conv, 'angry', ['message_id' => $m->id, 'intent' => 'angry'], 'rule', 'backfill:msg:'.$m->id);
                        } elseif ($intent === 'order_intent') {
                            $this->stages->advance($conv, 2, 'order_intent', ['message_id' => $m->id, 'intent' => 'order_intent'], 'rule', 'backfill:msg:'.$m->id);
                        } elseif (in_array($intent, StageService::INTEREST_INTENTS, true)) {
                            $this->stages->advance($conv, 1, 'intent_'.$intent, ['message_id' => $m->id, 'intent' => $intent], 'rule', 'backfill:msg:'.$m->id);
                        }
                    }
                    $lastOut = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')->orderByDesc('id')->first();
                    if ($lastOut) {
                        $conv->last_outbound_at = $lastOut->created_at;
                        $conv->save();
                    }
                    $this->stages->noteContactShared($conv);
                    $stats['staged']++;
                }
            });
        // Orders last, so phone matches close staged conversations.
        Order::where('business_id', $biz)->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    $this->stages->noteOrderCreated($order);
                }
            });
        $this->forgetSummary($biz);

        return $stats;
    }
}

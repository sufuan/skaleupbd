<?php

namespace App\Http\Controllers;

use App\Models\AiActivity;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\NegotiationService;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DhakaTime;

class MessagesController extends Controller
{
    public function __construct(
        private RuleBrainService $brain,
        private NegotiationService $negotiation,
        private SettingsService $settings,
    ) {
    }

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    public function inbox(Request $request): Response
    {
        $biz = $this->biz($request);
        // The list is capped at the 100 most recent conversations: a shop's
        // full history made every inbox open ship (and probe) every
        // conversation it ever had. Report counts below stay global.
        $conversations = Conversation::where('business_id', $biz)
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();

        // One thread, one list entry (Round 2 finding 6): the same
        // channel thread can exist as two conversation rows (an older
        // row and a re-created one sharing the channel's external id),
        // which showed the customer twice in the list under two names.
        // The list is freshest-first, so the first row per
        // channel+external_id wins and later duplicates are dropped
        // from the LIST only — no rows are merged or deleted.
        $seenThreads = [];
        $conversations = $conversations->filter(function ($c) use (&$seenThreads) {
            if (empty($c->external_id)) {
                return true;
            }
            $key = $c->channel . '|' . $c->external_id;
            if (isset($seenThreads[$key])) {
                return false;
            }
            $seenThreads[$key] = true;

            return true;
        })->values();

        $selected = null;
        $selId = (int) $request->query('c', 0);
        $sel = $selId
            ? ($conversations->firstWhere('id', $selId) ?? Conversation::where('business_id', $biz)->find($selId))
            : null;
        $sel ??= $conversations->first();
        if ($sel) {
            if ((int) $sel->unread_count > 0) {
                $sel->unread_count = 0;
                $sel->save();
            }
            // Latest 300 messages of the thread, oldest first — a
            // long-lived thread no longer ships its whole history per open.
            $msgs = ChatMessage::where('conversation_id', $sel->id)->orderByDesc('id')->limit(300)->get()->sortBy('id')->values();
            // Meta's 24h reply window, computed from the customer's last
            // inbound message (StageService keeps window_expires_at =
            // last inbound + 24h; fall back to deriving it when the
            // stored expiry is missing) - the header pill must never
            // claim Open for a window that has closed (Hunt V4).
            $expiresAt = $sel->window_expires_at;
            if (! $expiresAt) {
                $lastInAt = $sel->last_inbound_at ?? $msgs->where('direction', 'in')->last()?->created_at;
                $expiresAt = $lastInAt?->copy()->addHours(24);
            }
            $windowOpen = $expiresAt !== null && now()->lt($expiresAt);
            $sent = $msgs->whereIn('status', ['sent']);
            $drafts = $msgs->whereIn('status', ['draft', 'flagged']);
            $product = $sel->product_id ? Product::where('business_id', $biz)->find($sel->product_id) : null;

            $orders = collect();
            $stats = ['orders' => 0, 'success' => null, 'ltv' => 0];
            if ($sel->customer_phone) {
                // Orders store the canonical 01... form - the same
                // PhoneNormalizer order intake uses. Conversation phones
                // arrive raw ("+880 1712-345678" on WhatsApp), so match
                // on the normalised form or those customers look new.
                $phone = PhoneNormalizer::normalize($sel->customer_phone);
                $orders = Order::where('business_id', $biz)->where('customer_phone', $phone)
                    ->latest('id')->limit(5)->get(['id', 'invoice_no', 'status', 'grand_total']);
                $all = Order::where('business_id', $biz)->where('customer_phone', $phone)->get();
                $stats['orders'] = $all->count();
                // Lifetime value matches the Customers page definition
                // (Customer::lifetimeValue): delivered orders only.
                $stats['ltv'] = (float) $all->where('status', 'Delivered')->sum('grand_total');
                $done = $all->whereIn('status', ['Delivered', 'Returned'])->count();
                $stats['success'] = $done > 0 ? (int) round($all->where('status', 'Delivered')->count() / $done * 100) : null;
            }

            $selected = [
                'id' => $sel->id,
                'customer_name' => $sel->customer_name,
                'channel' => $sel->channel,
                'customer_phone' => $sel->customer_phone,
                'customer_address' => $sel->customer_address,
                'ai_paused' => (bool) $sel->ai_paused,
                'needs_human' => (bool) $sel->needs_human,
                'window_open' => $windowOpen,
                // The 300-message cap must be visible, not silent
                // (Hunt Y2-13): the thread shows a note when older
                // messages exist beyond the ones shipped.
                'thread_truncated' => ChatMessage::where('conversation_id', $sel->id)->count() > $msgs->count(),
                'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'price' => (float) $product->price] : null,
                'ladder' => $product ? $this->negotiation->ladder($product) : [],
                'neg_offer' => $sel->neg_offer !== null ? (float) $sel->neg_offer : null,
                'floor' => $product ? $this->negotiation->floor($product) : null,
                'stats' => $stats,
                'orders' => $orders->map(fn ($o) => ['invoice' => $o->invoice_no, 'status' => $o->status, 'total' => (float) $o->grand_total])->all(),
                'messages' => $sent->map(fn ($m) => [
                    'id' => $m->id, 'direction' => $m->direction, 'sender' => $m->sender, 'body' => $m->body,
                    'attachment_type' => $m->attachment_type,
                    'attachment_path' => $m->attachment_path,
                    'transcript' => $m->meta['transcript'] ?? null,
                    // A send Meta refused must be visible as failed in
                    // the thread, not presented as delivered (Y2-17).
                    'send_status' => $m->meta['send_status'] ?? null,
                    'rule' => $m->ai_rule_id ? \App\Models\AiRule::find($m->ai_rule_id)?->name : null,
                    'time' => DhakaTime::fmt($m->created_at, 'h:i A'),
                ])->values()->all(),
                'drafts' => $drafts->map(fn ($m) => [
                    'id' => $m->id, 'body' => $m->body, 'status' => $m->status,
                    'rule' => $m->ai_rule_id ? \App\Models\AiRule::find($m->ai_rule_id)?->name : null,
                    'kind' => $m->meta['kind'] ?? null,
                    'no_rule' => (bool) ($m->meta['no_rule'] ?? false),
                    'ladder_step' => $m->meta['ladder_step'] ?? null,
                    // Every draft card carries its creation time (Round
                    // 2 finding 1): without it a stale card in the stack
                    // reads as the newest one.
                    'time' => DhakaTime::fmt($m->created_at, 'd M, h:i A'),
                ])->values()->all(),
            ];
        }

        // List aggregates in bulk — one query each for the whole page of
        // conversations, never one probe per conversation (the old map ran
        // three extra queries per row, so cost grew with shop history).
        $listIds = $conversations->pluck('id')->all();
        $lastByConv = $listIds === [] ? collect() : ChatMessage::whereIn('id', function ($q) use ($listIds) {
            $q->selectRaw('MAX(id)')->from('chat_messages')
                ->whereIn('conversation_id', $listIds)->where('status', 'sent')->groupBy('conversation_id');
        })->get()->keyBy('conversation_id');
        $draftConvs = $listIds === [] ? [] : ChatMessage::whereIn('conversation_id', $listIds)
            ->whereIn('status', ['draft', 'flagged'])->distinct()->pluck('conversation_id')->flip()->all();
        $aiConvs = $listIds === [] ? [] : ChatMessage::whereIn('conversation_id', $listIds)
            ->where('sender', 'ai')->where('status', 'sent')->distinct()->pluck('conversation_id')->flip()->all();

        $list = $conversations->map(function ($c) use ($lastByConv, $draftConvs, $aiConvs) {
            $last = $lastByConv->get($c->id);
            $hasDraft = isset($draftConvs[$c->id]);
            $aiReplied = isset($aiConvs[$c->id]);

            return [
                'id' => $c->id, 'customer_name' => $c->customer_name, 'channel' => $c->channel,
                'phone' => $c->customer_phone,
                'snippet' => $last ? mb_strimwidth($last->body, 0, 60, '…') : '',
                'time' => $c->last_message_at?->diffForHumans(null, true) ?? '',
                'unread' => (int) $c->unread_count, 'has_draft' => $hasDraft, 'ai_replied' => $aiReplied && !$hasDraft,
                'needs_human' => (bool) $c->needs_human,
            ];
        })->all();

        $report = [
            'conversations' => Conversation::where('business_id', $biz)->count(),
            'ai_replies' => ChatMessage::where('business_id', $biz)->where('sender', 'ai')->where('status', 'sent')->count(),
            'ai_approved' => AiActivity::where('business_id', $biz)->whereIn('action', ['draft_created', 'edited_sent'])->count(),
            'drafts_waiting' => ChatMessage::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])->count(),
            'orders_from_chat' => Order::where('business_id', $biz)->whereIn('source', ['FB', 'WA', 'IG'])->count(),
            // Revenue is Delivered-only, the same definition as the
            // customer pane's LTV above and every other revenue surface.
            'orders_revenue' => (float) Order::where('business_id', $biz)->whereIn('source', ['FB', 'WA', 'IG'])->where('status', 'Delivered')->sum('grand_total'),
            'rule_stats' => \App\Models\AiRule::where('business_id', $biz)->get()->map(function ($r) use ($biz) {
                $acts = AiActivity::where('business_id', $biz)->where('ai_rule_id', $r->id);

                return ['name' => $r->name, 'count' => $acts->count(), 'last' => $acts->latest('id')->first()?->created_at?->diffForHumans(null, true) ?? __('Never')];
            })->all(),
        ];

        $auto = $this->brain->autonomy($biz > 0 ? $biz : null);
        $modeLabel = $auto['panic'] ? 'paused' : ($auto['facebook'] ?? 'draft');

        return Inertia::render('Messages/Inbox', [
            'conversations' => $list,
            // The 100-conversation cap is visible too (Hunt Y2-13):
            // the report count is global, the list is not.
            'listTruncated' => $report['conversations'] > count($list),
            'selected' => $selected,
            'report' => $report,
            'aiMode' => $modeLabel,
            'aiPanic' => (bool) $auto['panic'],
        ]);
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless((int) $conversation->business_id === $this->biz($request), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $msg = ChatMessage::create([
            'business_id' => $conversation->business_id, 'conversation_id' => $conversation->id,
            'direction' => 'out', 'sender' => 'staff', 'body' => $data['body'], 'status' => 'sent',
            'user_id' => $request->user()?->id,
        ]);
        [$sentOk, $sendErr] = app(\App\Services\MetaGraphService::class)->dispatch($msg);
        $conversation->last_message_at = now();
        $conversation->last_outbound_at = now();
        $conversation->needs_human = false;
        $conversation->save();

        return $sentOk ? back()->with('success', __('Message sent')) : back()->with('error', __('Saved, but Meta did not accept it: :error', ['error' => $sendErr]));
    }

    /** The conversation's customer is on the shop's blocklist (Customers page flag). */
    private function chatCustomerBlocklisted(ChatMessage $message): bool
    {
        $phone = PhoneNormalizer::normalize($message->conversation?->customer_phone ?? '');
        if ($phone === '') {
            return false;
        }

        return (bool) \App\Models\Customer::where('business_id', (int) $message->business_id)
            ->where('phone', $phone)->value('is_blacklisted');
    }

    public function approveDraft(Request $request, ChatMessage $message, OrderService $orders): RedirectResponse
    {
        abort_unless((int) $message->business_id === $this->biz($request), 403);
        if (! in_array($message->status, ['draft', 'flagged'], true)) {
            // State discipline (Hunt Y2-1/Y2-2/Y2-4): a draft that was
            // already approved or rejected is finished. A second
            // approve (double-click, second staff member, stale tab)
            // is an honest refusal - never a second send, a second
            // order or a second point.
            return back()->with('error', __('This draft was already handled - it cannot be approved or rejected again.'));
        }
        $edited = $request->input('body');
        $kind = $message->meta['kind'] ?? null;
        if ($kind === 'order_summary' && $this->chatCustomerBlocklisted($message)) {
            // The blocklist binds at the till too (Hunt Y2-16): no
            // order, no point for a customer the shop has blocked.
            return back()->with('error', __('This customer is on your blocklist - a chat order cannot be created for them.'));
        }
        $handled = $this->brain->approveDraft($message, $edited, $request->user()?->id);
        if ($handled === null) {
            // Lost the race (Hunt Z1-8): another approver's locked
            // guard committed first, so this copy was stale - the
            // draft is already handled and NOTHING here may dispatch
            // or create an order for it a second time.
            return back()->with('error', __('This draft was already handled - it cannot be approved or rejected again.'));
        }
        $message->refresh();
        if ($message->status !== 'sent') {
            // Held at approval (Round 6, P5): the figures no longer match
            // live data, so nothing is dispatched and no chat order is
            // created - the approver sees why and edits first.
            return back()->with('error', $message->meta['hold_reason'] ?? __('This reply was held because its figures no longer match the live price or stock. Edit the reply, then approve again.'));
        }
        // The dispatch result is surfaced exactly like the staff-reply
        // path below handles it (Hunt Y2-17): a send Meta refused is
        // flashed as failed and stays marked failed in the thread via
        // meta.send_status - never reported as a clean send.
        [$sentOk, $sendErr] = app(\App\Services\MetaGraphService::class)->dispatch($message);

        if ($kind === 'order_summary') {
            $c = $message->conversation;
            $product = \App\Models\Product::where('business_id', $message->business_id)->find($message->meta['product_id'] ?? 0);
            if ($c && $product) {
                try {
                    // THE one chat order-creation path (brain fix
                    // batch 3): the Full-auto chat confirmation route
                    // builds the identical order through the same
                    // method — the payload, the points deduction and
                    // the details-consumed close-out live there now.
                    $order = $this->brain->createOrderFromSummary($c, $message, $request->user());
                } catch (\App\Exceptions\InsufficientTokensException $e) {
                    return back()->with('error', $e->getMessage());
                }

                if ($order) {
                    if (! $sentOk) {
                        return back()->with('error', __('Order created, but the confirmation message could not be sent - Meta did not accept it: :error', ['error' => $sendErr]));
                    }

                    return back()->with('success', __('Order created from chat'));
                }
            }
        }

        return $sentOk
            ? back()->with('success', __('Reply sent'))
            : back()->with('error', __('Saved, but Meta did not accept it: :error', ['error' => $sendErr]));
    }

    public function rejectDraft(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless((int) $message->business_id === $this->biz($request), 403);
        if (! in_array($message->status, ['draft', 'flagged'], true)) {
            // Only a pending draft can be rejected (Hunt Y2-3): a sent
            // message stays on the record as sent.
            return back()->with('error', __('This draft was already handled - it cannot be approved or rejected again.'));
        }
        if (! $this->brain->rejectDraft($message)) {
            // Already handled under the lock (Hunt Z1-8) - the stale
            // copy above passed the fast-path check, the row did not.
            return back()->with('error', __('This draft was already handled - it cannot be approved or rejected again.'));
        }

        return back()->with('success', __('Draft rejected'));
    }

    public function toggleAi(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless((int) $conversation->business_id === $this->biz($request), 403);
        $conversation->ai_paused = !$conversation->ai_paused;
        $conversation->save();

        return back();
    }

    public function panic(Request $request): RedirectResponse
    {
        // The panic flag lives in the platform-global ai.autonomy setting
        // (it pauses AI for EVERY shop, not just the caller's), so it
        // stays platform admin only even though the channel levels in
        // the same row are now namespaced per shop (ai.{biz}.autonomy).
        // Per-conversation AI pause (toggleAi) stays available to sellers.
        abort_unless((bool) ($request->user()?->is_platform_admin), 403);
        $auto = $this->settings->get('ai.autonomy', []);
        $auto['panic'] = !($auto['panic'] ?? false);
        $this->settings->set('ai.autonomy', $auto, $request->user()?->id);

        return back()->with('success', $auto['panic'] ? __('All AI paused') : __('AI resumed'));
    }

    public function activity(Request $request): Response
    {
        $biz = $this->biz($request);
        $drafts = ChatMessage::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])
            ->with('conversation')->orderBy('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'kind' => 'chat', 'customer' => $m->conversation?->customer_name, 'channel' => $m->conversation?->channel,
                'customer_text' => ChatMessage::where('conversation_id', $m->conversation_id)->where('direction', 'in')->latest('id')->first()?->body,
                'body' => $m->body, 'status' => $m->status, 'conversation_id' => $m->conversation_id,
                'rule' => $m->ai_rule_id ? \App\Models\AiRule::find($m->ai_rule_id)?->name : null,
                'summary_kind' => $m->meta['kind'] ?? null,
            ])->all();
        $commentDrafts = \App\Models\CommentReply::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])
            ->with('comment')->orderBy('id')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'kind' => 'comment', 'customer' => $r->comment?->author_name, 'channel' => $r->comment?->channel,
                'customer_text' => $r->comment?->body, 'body' => $r->body, 'status' => $r->status, 'comment_id' => $r->comment_id,
                'rule' => $r->ai_rule_id ? \App\Models\AiRule::find($r->ai_rule_id)?->name : null, 'summary_kind' => null,
            ])->all();

        $handed = Conversation::where('business_id', $biz)->where('needs_human', true)->get()
            ->map(function ($c) use ($biz) {
                // The reason comes from the handover record itself — guessing
                // it from the negotiation state blamed the wrong cause.
                $last = AiActivity::where('business_id', $biz)->where('conversation_id', $c->id)
                    ->whereIn('action', ['handed_human', 'human_takeover', 'image_rejected', 'image_no_match', 'voice_to_human'])
                    ->latest('id')->first();
                $reason = __('Customer needs a human reply');
                if ($last && $last->detail) {
                    $reason = trim((string) preg_replace('/^.*?—\s*/u', '', (string) $last->detail)) ?: $reason;
                }

                return [
                'id' => $c->id, 'customer' => $c->customer_name, 'channel' => $c->channel,
                'reason' => $reason,
                'last' => ChatMessage::where('conversation_id', $c->id)->where('direction', 'in')->latest('id')->first()?->body,
                ];
            })->all();

        // Last 7 days, from the activity log the brief implementation now
        // enriches with source + latency (brief §3.13).
        $since = now()->subDays(7);
        $acts = fn () => AiActivity::where('business_id', $biz)->where('created_at', '>=', $since);
        $autoSent = $acts()->where('action', 'auto_sent')->count();
        $draftMade = $acts()->where('action', 'draft_created')->count();
        $replyRows = $acts()->whereIn('action', ['auto_sent', 'draft_created'])->whereNotNull('source')->count();
        $talkerRows = $acts()->whereIn('action', ['auto_sent', 'draft_created'])->where('source', 'talker')->count();
        $avgLatency = $acts()->whereNotNull('latency_ms')->avg('latency_ms');
        $metrics = [
            'auto_sent' => $autoSent,
            'drafts' => $draftMade,
            'flagged' => ChatMessage::where('business_id', $biz)->where('sender', 'ai')->where('status', 'flagged')->where('created_at', '>=', $since)->count(),
            'handed' => $acts()->whereIn('action', ['handed_human', 'human_takeover'])->count(),
            'auto_rate' => ($autoSent + $draftMade) > 0 ? (int) round($autoSent / ($autoSent + $draftMade) * 100) : null,
            'talker_share' => $replyRows > 0 ? (int) round($talkerRows / $replyRows * 100) : null,
            'avg_latency_s' => $avgLatency ? round($avgLatency / 1000, 1) : null,
        ];

        return Inertia::render('Messages/AiActivity', [
            'metrics' => $metrics,
            'stats' => [
                'ai_replies_today' => ChatMessage::where('business_id', $biz)->where('sender', 'ai')->whereDate('created_at', today())->count()
                    + \App\Models\CommentReply::where('business_id', $biz)->where('sender', 'ai')->whereDate('created_at', today())->count(),
                // Same TODAY window as the card beside it (it was all-time,
                // which read as a contradiction next to today's count).
                'sent_by_ai' => ChatMessage::where('business_id', $biz)->where('sender', 'ai')->where('status', 'sent')->whereDate('created_at', today())->count()
                    + \App\Models\CommentReply::where('business_id', $biz)->where('sender', 'ai')->where('status', 'sent')->whereDate('created_at', today())->count(),
                'drafts_waiting' => count($drafts) + count($commentDrafts),
                'handed' => count($handed),
            ],
            'draftQueue' => array_merge($drafts, $commentDrafts),
            'handed' => $handed,
            'ruleStats' => \App\Models\AiRule::where('business_id', $biz)->get()->map(function ($r) use ($biz) {
                // The section heading says "today" — scope the counts to
                // today too (they were all-time, contradicting the cards).
                $acts = AiActivity::where('business_id', $biz)->where('ai_rule_id', $r->id)->whereDate('created_at', today());

                return ['name' => $r->name, 'count' => $acts->count(), 'last' => $acts->latest('id')->first()?->created_at?->diffForHumans(null, true) ?? __('Never')];
            })->all(),
            'feed' => AiActivity::where('business_id', $biz)->latest('id')->limit(30)->get()
                ->map(fn ($a) => ['action' => $a->action, 'detail' => $a->detail, 'when' => $a->created_at?->diffForHumans(null, true)])->all(),
        ]);
    }
}

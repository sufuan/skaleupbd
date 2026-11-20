<?php

namespace App\Http\Controllers;

use App\Models\AiActivity;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\NegotiationService;
use App\Services\OrderService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        return (int) ($request->user()?->business_id ?? 0);
    }

    public function inbox(Request $request): Response
    {
        $biz = $this->biz($request);
        $conversations = Conversation::where('business_id', $biz)
            ->orderByDesc('last_message_at')->orderByDesc('id')->get();

        $selected = null;
        $selId = (int) $request->query('c', 0);
        $sel = $conversations->firstWhere('id', $selId) ?? $conversations->first();
        if ($sel) {
            if ((int) $sel->unread_count > 0) {
                $sel->unread_count = 0;
                $sel->save();
            }
            $msgs = ChatMessage::where('conversation_id', $sel->id)->orderBy('id')->get();
            $sent = $msgs->whereIn('status', ['sent']);
            $drafts = $msgs->whereIn('status', ['draft', 'flagged']);
            $product = $sel->product_id ? Product::where('business_id', $biz)->find($sel->product_id) : null;

            $orders = collect();
            $stats = ['orders' => 0, 'success' => null, 'ltv' => 0];
            if ($sel->customer_phone) {
                $orders = Order::where('business_id', $biz)->where('customer_phone', preg_replace('/\D/', '', $sel->customer_phone) ? $sel->customer_phone : $sel->customer_phone)
                    ->latest('id')->limit(5)->get(['id', 'invoice_no', 'status', 'grand_total']);
                $all = Order::where('business_id', $biz)->where('customer_phone', $sel->customer_phone)->get();
                $stats['orders'] = $all->count();
                $stats['ltv'] = (float) $all->sum('grand_total');
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
                    'rule' => $m->ai_rule_id ? \App\Models\AiRule::find($m->ai_rule_id)?->name : null,
                    'time' => $m->created_at?->format('h:i A'),
                ])->values()->all(),
                'drafts' => $drafts->map(fn ($m) => [
                    'id' => $m->id, 'body' => $m->body, 'status' => $m->status,
                    'rule' => $m->ai_rule_id ? \App\Models\AiRule::find($m->ai_rule_id)?->name : null,
                    'kind' => $m->meta['kind'] ?? null,
                    'no_rule' => (bool) ($m->meta['no_rule'] ?? false),
                    'ladder_step' => $m->meta['ladder_step'] ?? null,
                ])->values()->all(),
            ];
        }

        $list = $conversations->map(function ($c) {
            $last = ChatMessage::where('conversation_id', $c->id)->where('status', 'sent')->latest('id')->first();
            $hasDraft = ChatMessage::where('conversation_id', $c->id)->whereIn('status', ['draft', 'flagged'])->exists();
            $aiReplied = ChatMessage::where('conversation_id', $c->id)->where('sender', 'ai')->where('status', 'sent')->exists();

            return [
                'id' => $c->id, 'customer_name' => $c->customer_name, 'channel' => $c->channel,
                'snippet' => $last ? mb_strimwidth($last->body, 0, 60, '…') : '',
                'time' => $c->last_message_at?->diffForHumans(null, true) ?? '',
                'unread' => (int) $c->unread_count, 'has_draft' => $hasDraft, 'ai_replied' => $aiReplied && !$hasDraft,
                'needs_human' => (bool) $c->needs_human,
            ];
        })->all();

        $report = [
            'conversations' => $conversations->count(),
            'ai_replies' => ChatMessage::where('business_id', $biz)->where('sender', 'ai')->where('status', 'sent')->count(),
            'ai_approved' => AiActivity::where('business_id', $biz)->whereIn('action', ['draft_created', 'edited_sent'])->count(),
            'drafts_waiting' => ChatMessage::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])->count(),
            'orders_from_chat' => Order::where('business_id', $biz)->whereIn('source', ['FB', 'WA', 'IG'])->count(),
            'orders_revenue' => (float) Order::where('business_id', $biz)->whereIn('source', ['FB', 'WA', 'IG'])->sum('grand_total'),
            'rule_stats' => \App\Models\AiRule::where('business_id', $biz)->get()->map(function ($r) use ($biz) {
                $acts = AiActivity::where('business_id', $biz)->where('ai_rule_id', $r->id);

                return ['name' => $r->name, 'count' => $acts->count(), 'last' => $acts->latest('id')->first()?->created_at?->diffForHumans(null, true) ?? '—'];
            })->all(),
        ];

        $auto = $this->brain->autonomy();
        $modeLabel = $auto['panic'] ? 'paused' : ($auto['facebook'] ?? 'draft');

        return Inertia::render('Messages/Inbox', [
            'conversations' => $list,
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
        $conversation->needs_human = false;
        $conversation->save();

        return $sentOk ? back()->with('success', 'Message sent') : back()->with('error', 'Saved, but Meta did not accept it: ' . $sendErr);
    }

    public function approveDraft(Request $request, ChatMessage $message, OrderService $orders): RedirectResponse
    {
        abort_unless((int) $message->business_id === $this->biz($request), 403);
        $edited = $request->input('body');
        $kind = $message->meta['kind'] ?? null;
        $this->brain->approveDraft($message, $edited, $request->user()?->id);
        $message->refresh();
        if ($message->status === 'sent') {
            app(\App\Services\MetaGraphService::class)->dispatch($message);
        }

        if ($kind === 'order_summary') {
            $c = $message->conversation;
            $product = \App\Models\Product::where('business_id', $message->business_id)->find($message->meta['product_id'] ?? 0);
            if ($c && $product) {
                $source = ['facebook' => 'FB', 'whatsapp' => 'WA', 'instagram' => 'IG'][$c->channel] ?? 'FB';
                $name = $c->customer_name;
                if (str_starts_with($name, '+')) {
                    $name = 'Karim Sheikh';
                }
                $orders->create((int) $message->business_id, [
                    'customer_name' => $name,
                    'customer_phone' => $c->customer_phone ?: '01712345678',
                    'customer_address' => $c->customer_address,
                    'source' => $source,
                    'status' => 'Pending',
                    'delivery_charge' => 60,
                    'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => isset($message->meta['unit_price']) ? (float) $message->meta['unit_price'] : null, 'variant_name' => $message->meta['variant_name'] ?? null]],
                ], $request->user(), 'Chat order intake');

                return back()->with('success', 'Order created from chat');
            }
        }

        return back()->with('success', 'Reply sent');
    }

    public function rejectDraft(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless((int) $message->business_id === $this->biz($request), 403);
        $this->brain->rejectDraft($message);

        return back()->with('success', 'Draft rejected');
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
        $auto = $this->settings->get('ai.autonomy', []);
        $auto['panic'] = !($auto['panic'] ?? false);
        $this->settings->set('ai.autonomy', $auto, $request->user()?->id);

        return back()->with('success', $auto['panic'] ? 'All AI paused' : 'AI resumed');
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
                $reason = 'Customer-এর উত্তরে মানুষ দরকার';
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
                'sent_by_ai' => AiActivity::where('business_id', $biz)->where('action', 'auto_sent')->count(),
                'drafts_waiting' => count($drafts) + count($commentDrafts),
                'handed' => count($handed),
            ],
            'draftQueue' => array_merge($drafts, $commentDrafts),
            'handed' => $handed,
            'ruleStats' => \App\Models\AiRule::where('business_id', $biz)->get()->map(function ($r) use ($biz) {
                $acts = AiActivity::where('business_id', $biz)->where('ai_rule_id', $r->id);

                return ['name' => $r->name, 'count' => $acts->count(), 'last' => $acts->latest('id')->first()?->created_at?->diffForHumans(null, true) ?? '—'];
            })->all(),
            'feed' => AiActivity::where('business_id', $biz)->latest('id')->limit(30)->get()
                ->map(fn ($a) => ['action' => $a->action, 'detail' => $a->detail, 'when' => $a->created_at?->diffForHumans(null, true)])->all(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\AiRuleProposal;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Conversation;
use App\Models\Product;

/**
 * Rule Brain — decides, the AI only phrases.
 *
 * A matched rule builds a reply from LIVE data (product price/stock, delivery
 * charges). Before anything is auto-sent, every money figure in the reply is
 * re-checked in code against real figures; a mismatch demotes the reply to a
 * flagged draft. Autonomy per channel comes from Settings → AI & Auto Reply.
 * Comments run one step more cautiously than chats on the same setting.
 */
class RuleBrainService
{
    /** Intent name => words that mean it (Bangla + English + common romanised). */
    public const INTENTS = [
        'price' => ['দাম', 'price', 'কত', 'koto', 'dam koto', 'দামটা'],
        'delivery' => ['delivery', 'ডেলিভারি', 'চার্জ', 'charge', 'shipping', 'পাঠাতে কত'],
        'stock' => ['স্টক', 'stock', 'আছে কি', 'available', 'ache ki', 'আছেনি'],
        'greeting' => ['আসসালামু', 'salam', 'hello', 'hi', 'হ্যালো', 'আদাব'],
        'bargain' => ['কমান', 'কমাতে', 'discount', 'কম হবে', 'কম রাখেন', 'dam kom', 'less hobe', 'শেষ দাম', 'কম দাম', 'কমে দেন', 'দিলে নিব', 'টাকায় দিলে'],
        'spam' => ['follower', 'ফলোয়ার', 'সস্তায় follower', 'cheap follower', 'like কিনুন', 'free money', 'click here', 'click link', 'scam', 'earn money', 'make money', 'casino', 'ক্যাসিনো', 'betting', 'বাজি', 'ফ্রি টাকা', 'টাকা ইনকাম', 'লিংকে ক্লিক', 'crypto', 'double investment', 'lottery', 'লটারি'],
        'angry' => ['পাইনি', 'পাই নাই', 'রেগে', 'complain', 'অভিযোগ', 'দুই দিন হলো', 'এখনো আসেনি'],
        'order_intent' => ['order confirm', 'অর্ডার কনফার্ম', 'confirm korlam', 'নিতে চাই', 'order korte chai'],
    ];

    public function __construct(
        private SettingsService $settings,
        private NegotiationService $negotiation,
        private AiTalkerService $talker,
    ) {
    }

    /** @return array<string,string> channel => off|draft|auto|full_auto, plus panic */
    public function autonomy(): array
    {
        $a = $this->settings->get('ai.autonomy', []);

        return [
            'facebook' => $a['facebook'] ?? 'draft',
            'whatsapp' => $a['whatsapp'] ?? 'draft',
            'instagram' => $a['instagram'] ?? 'draft',
            'comment' => $a['comment'] ?? 'draft',
            'panic' => (bool) ($a['panic'] ?? false),
        ];
    }

    private function cautious(string $mode): string
    {
        return match ($mode) {
            'full_auto' => 'auto',
            'auto' => 'draft',
            default => $mode,
        };
    }

    public function matchRule(int $businessId, string $text, ?string $channel = null, ?Conversation $conversation = null): ?AiRule
    {
        $rules = AiRule::where('business_id', $businessId)->where('active', true)->orderBy('id')->get();
        $hay = mb_strtolower($text);

        $findIntent = function (string $intent) use ($rules, $channel) {
            foreach ($rules as $r) {
                if ($r->trigger_type === 'intent' && $r->match_text === $intent && (!$channel || !$r->channel || $r->channel === $channel)) {
                    return $r;
                }
            }

            return null;
        };

        // Mid-bargaining continuation: once a ladder is running, a bare
        // amount or another push for less continues the SAME negotiation —
        // the customer will not repeat the product name every time.
        if ($conversation && (int) $conversation->neg_step >= 1) {
            $hasAmount = (bool) preg_match('/[0-9\x{09E6}-\x{09EF}]/u', $text);
            $pushes = false;
            foreach (['কমান', 'কম', 'দাম', 'দিলে', 'শেষ', 'পারবেন', 'হবে না', 'হয় না'] as $w) {
                if (str_contains($hay, $w)) {
                    $pushes = true;
                    break;
                }
            }
            if (($hasAmount || $pushes) && ($bargain = $findIntent('bargain'))) {
                return $bargain;
            }
        }

        // A delivery-charge question is a delivery question even though it
        // contains "কত" — unless the customer also asks a product's price.
        $deliveryFirst = false;
        foreach (['ডেলিভারি চার্জ', 'delivery charge', 'কুরিয়ার চার্জ', 'ডেলিভারি খরচ', 'shipping charge', 'চার্জ কত', 'ডেলিভারি কত'] as $p) {
            if (str_contains($hay, $p)) {
                $deliveryFirst = true;
                break;
            }
        }
        if ($deliveryFirst) {
            foreach (['দাম', 'price', 'dam '] as $p) {
                if (str_contains($hay, $p)) {
                    $deliveryFirst = false;
                    break;
                }
            }
        }
        if ($deliveryFirst && ($delivery = $findIntent('delivery'))) {
            return $delivery;
        }

        // Priority intents first — a bargaining or angry message also contains
        // price words, and it must not be answered as a plain price question.
        $priority = ['spam', 'angry', 'bargain', 'order_intent'];
        $rules = $rules->sortBy(fn ($r) => in_array($r->match_text, $priority, true) ? 0 : 1)->values();
        foreach ($rules as $rule) {
            if ($channel && $rule->channel && $rule->channel !== $channel) {
                continue;
            }
            if ($rule->trigger_type === 'keyword') {
                foreach (array_map('trim', explode(',', (string) $rule->match_text)) as $kw) {
                    if ($kw !== '' && str_contains($hay, mb_strtolower($kw))) {
                        return $rule;
                    }
                }
            } else {
                foreach (self::INTENTS[$rule->match_text] ?? [] as $kw) {
                    if (str_contains($hay, mb_strtolower($kw))) {
                        return $rule;
                    }
                }
            }
        }

        return null;
    }

    public function productFor(int $businessId, string $text, ?Conversation $conversation = null): ?Product
    {
        if ($conversation?->product_id) {
            $p = Product::where('business_id', $businessId)->find($conversation->product_id);
            if ($p) {
                return $p;
            }
        }
        $hay = mb_strtolower($text);
        foreach (Product::where('business_id', $businessId)->orderBy('id')->get() as $p) {
            foreach (preg_split('/\s+/', mb_strtolower($p->name)) as $word) {
                if (mb_strlen($word) >= 4 && str_contains($hay, $word)) {
                    return $p;
                }
            }
            if ($p->sku && str_contains($hay, mb_strtolower($p->sku))) {
                return $p;
            }
        }

        return null;
    }

    public function buildReply(AiRule $rule, ?Product $product, int $businessId): string
    {
        $tpl = (string) ($rule->reply_template ?? '');
        $inside = (float) $this->settings->get('business.default_delivery_cost', 60);
        $replace = [
            '{product}' => $product?->name ?? 'পণ্যটা',
            '{price}' => $product ? number_format((float) $product->price) : '',
            '{stock}' => $product ? (string) max(0, (int) $product->stock_qty - (int) $product->reserved_qty) : '',
            '{delivery_inside}' => number_format($inside),
            '{delivery_outside}' => number_format(120),
        ];

        return trim(strtr($tpl, $replace));
    }

    /** Every figure the business could honestly state right now. */
    public function allowedFigures(int $businessId, ?Product $product): array
    {
        $figures = [60.0, 120.0, (float) $this->settings->get('business.default_delivery_cost', 60), $this->negotiation->config()['free_delivery_min']];
        foreach (Product::where('business_id', $businessId)->get() as $p) {
            $figures[] = (float) $p->price;
            foreach ($this->negotiation->ladder($p) as $rung) {
                $figures[] = (float) $rung;
                $figures[] = (float) $rung + 60.0;
                $figures[] = (float) $rung + 120.0;
            }
        }
        if ($product) {
            $figures[] = (float) $product->price + 60.0;
            $figures[] = (float) $product->price + 120.0;
        }

        return $figures;
    }

    /** Pre-send verification: every ৳ figure in the text must be a real one. */
    public function moneyCheck(int $businessId, string $text, ?Product $product): bool
    {
        $clean = str_replace(',', '', $text);
        if (!preg_match_all('/৳\s*(\d+(?:\.\d+)?)/u', $clean, $m)) {
            return true;
        }
        $allowed = $this->allowedFigures($businessId, $product);
        foreach ($m[1] as $raw) {
            $amount = (float) $raw;
            $ok = false;
            foreach ($allowed as $f) {
                if (abs($f - $amount) < 1.0) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function log(int $businessId, string $action, string $detail, ?AiRule $rule = null, ?Conversation $c = null, ?Comment $cm = null): void
    {
        AiActivity::create([
            'business_id' => $businessId,
            'action' => $action,
            'detail' => $detail,
            'ai_rule_id' => $rule?->id,
            'conversation_id' => $c?->id,
            'comment_id' => $cm?->id,
        ]);
    }

    /**
     * The one entry point for an incoming chat message — the webhooks will
     * call this too. Returns the AI draft/sent message, or null.
     */
    public function handleIncoming(Conversation $conversation, string $text): ?ChatMessage
    {
        $biz = (int) $conversation->business_id;
        $incoming = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'sender' => 'customer',
            'body' => $text,
            'status' => 'sent',
        ]);
        $conversation->unread_count = (int) $conversation->unread_count + 1;
        $conversation->last_message_at = now();
        $conversation->save();

        $auto = $this->autonomy();
        if ($auto['panic'] || $conversation->ai_paused) {
            return null;
        }

        $rule = $this->matchRule($biz, $text, $conversation->channel, $conversation);
        $product = $this->productFor($biz, $text, $conversation);
        if ($product && (int) $conversation->product_id !== (int) $product->id) {
            // Remember the product for this conversation — follow-up
            // messages ("আরেকটু কমান", "স্টকে আছে?") rarely repeat its name.
            $conversation->product_id = $product->id;
            $conversation->save();
        }

        if ($rule && $rule->action === 'handover') {
            $conversation->needs_human = true;
            $conversation->save();
            $this->log($biz, 'handed_human', $conversation->customer_name . ' — ' . $rule->name, $rule, $conversation);

            return null;
        }

        $mode = $auto[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }

        $kind = null;
        if ($rule && $rule->match_text === 'bargain') {
            if (!$product) {
                return null;
            }
            $offer = $this->negotiation->nextOffer($conversation, $product);
            if ($offer === null) {
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', $rule, $conversation);

                return null;
            }
            $body = 'বুঝতে পারছি আপু। আপনার জন্য ৳' . number_format($offer) . '-এ দিতে পারব। অর্ডার করবেন?';
            $meta = ['ladder_step' => (int) $conversation->neg_step, 'offer' => $offer];
        } elseif ($rule && $rule->match_text === 'order_intent') {
            if (!$product) {
                return null;
            }
            $delivery = 60.0;
            // A price agreed in bargaining is the price the customer pays —
            // the summary must not jump back to the full price.
            $unit = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : (float) $product->price;
            $total = $unit + $delivery;
            $body = $conversation->customer_name . ' · ' . ($conversation->customer_address ?: 'ঠিকানা বাকি') . "\n"
                . $product->name . ' ×1 — ৳' . number_format($unit) . ' + delivery ৳' . number_format($delivery) . ' = মোট ৳' . number_format($total) . ' (Cash on Delivery)';
            $kind = 'order_summary';
            $meta = ['product_id' => $product->id, 'total' => $total, 'unit_price' => $unit];
        } elseif ($rule) {
            // A price/stock answer needs a product. A one-product shop can
            // only mean that product; with several, ask which one instead
            // of sending a template with the price left blank.
            if (!$product && in_array($rule->match_text, ['price', 'stock'], true)) {
                $all = Product::where('business_id', $biz)->get();
                if ($all->count() === 1) {
                    $product = $all->first();
                }
            }
            $body = $this->buildReply($rule, $product, $biz);
            if ($body === '') {
                return null;
            }
            if (preg_match('/৳\s*([।,]|$)/u', $body)) {
                $body = 'আপু, কোন পণ্যটার কথা বলছেন? পণ্যের নামটা বললে দাম আর স্টক জানিয়ে দিচ্ছি।';
                $meta = ['clarify' => true];
            } else {
                $meta = [];
            }
        } else {
            // No rule matched — the AI drafts from the product description and
            // a human always checks it before it goes out.
            if (!$product) {
                return null;
            }
            $desc = trim((string) ($product->short_description ?? ''));
            $body = $desc !== ''
                ? 'আপু, ' . $desc
                : 'আপু, ' . $product->name . '-এর দাম ৳' . number_format((float) $product->price) . '। আর কিছু জানতে চাইলে বলুন।';
            $meta = ['no_rule' => true];
        }

        // The talker only phrases what was decided above — the order
        // summary keeps its exact factual format. moneyCheck() below still
        // re-verifies every figure in the phrased text before any send.
        $talkerMeta = [];
        if ($kind !== 'order_summary') {
            $phrased = $this->talker->phrase([
                'business_id' => $biz,
                'channel' => $conversation->channel,
                'incoming' => $text,
                'intent' => $rule?->match_text,
                'neg_step' => (int) $conversation->neg_step,
                'draft' => $body,
            ]);
            if ($phrased) {
                $body = $phrased['text'];
                $talkerMeta = ['talker' => $phrased['provider'], 'talker_model' => $phrased['model'], 'talker_tier' => $phrased['tier']];
            }
        }

        $verified = $this->moneyCheck($biz, $body, $product);
        if (!$verified) {
            $status = 'flagged';
        } elseif ($mode === 'auto' || $mode === 'full_auto') {
            $status = 'sent';
        } else {
            $status = 'draft';
        }

        $msg = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $body,
            'ai_rule_id' => $rule?->id,
            'status' => $status,
            'meta' => array_merge($meta ?? [], $kind ? ['kind' => $kind] : [], $talkerMeta),
        ]);

        $action = $status === 'sent' ? 'auto_sent' : ($status === 'flagged' ? 'flagged' : 'draft_created');
        $this->log($biz, $action, $conversation->customer_name . ' — ' . ($rule?->name ?? 'No rule matched'), $rule, $conversation);
        if ($status === 'sent') {
            $conversation->last_message_at = now();
            $conversation->save();
        }

        return $msg;
    }

    /** A staff member approves a draft (optionally after editing it). */
    public function approveDraft(ChatMessage $msg, ?string $editedBody, ?int $userId): ChatMessage
    {
        $edited = $editedBody !== null && trim($editedBody) !== '' && trim($editedBody) !== $msg->body;
        if ($edited) {
            // Learning loop: the edit becomes a proposal — a human still has
            // to approve it in Settings before any rule changes.
            AiRuleProposal::create([
                'business_id' => $msg->business_id,
                'title' => 'Staff edit থেকে শেখা — reply বদলানো হয়েছে',
                'detail' => 'আগের draft: "' . $msg->body . '" → staff পাঠিয়েছে: "' . trim($editedBody) . '"',
                'rule_data' => ['source_message_id' => $msg->id, 'edited_body' => trim($editedBody)],
                'status' => 'pending',
            ]);
            $this->log((int) $msg->business_id, 'proposal_created', 'Staff edit থেকে নতুন প্রস্তাব এসেছে', $msg->rule, $msg->conversation);
            $msg->body = trim($editedBody);
        }
        $msg->status = 'sent';
        $msg->sender = 'ai';
        $msg->user_id = $userId;
        $msg->save();
        $this->log((int) $msg->business_id, $edited ? 'edited_sent' : 'draft_created', ($msg->conversation?->customer_name ?? '') . ' — draft পাঠানো হয়েছে', $msg->rule, $msg->conversation);

        return $msg;
    }

    public function rejectDraft(ChatMessage $msg): void
    {
        $msg->status = 'rejected';
        $msg->save();
    }

    /** Incoming comment: spam hides itself; questions get a drafted reply. */
    public function handleComment(Comment $comment): ?CommentReply
    {
        $biz = (int) $comment->business_id;
        $auto = $this->autonomy();
        if ($auto['panic']) {
            return null;
        }
        $rule = $this->matchRule($biz, $comment->body, 'comment');
        if (!$rule) {
            return null;
        }
        if ($rule->action === 'hide') {
            $comment->status = 'hidden';
            $comment->hidden_by_ai = true;
            $comment->ai_rule_id = $rule->id;
            $comment->save();
            $this->log($biz, 'comment_hidden', $comment->author_name . ' — spam pattern মিলেছে', $rule, null, $comment);

            return null;
        }
        $product = $comment->product_id ? Product::where('business_id', $biz)->find($comment->product_id) : $this->productFor($biz, $comment->body);
        $body = $this->buildReply($rule, $product, $biz);
        if ($body === '' || preg_match('/৳\s*([।,]|$)/u', $body)) {
            // Never post a public reply with a blank figure in it.
            return null;
        }
        $phrased = $this->talker->phrase([
            'business_id' => $biz,
            'channel' => 'comment',
            'incoming' => $comment->body,
            'intent' => $rule->match_text,
            'neg_step' => 0,
            'draft' => $body,
        ]);
        if ($phrased) {
            $body = $phrased['text'];
        }
        $mode = $this->cautious($auto['comment'] ?? 'draft');
        $verified = $this->moneyCheck($biz, $body, $product);
        $status = !$verified ? 'flagged' : ($mode === 'auto' || $mode === 'full_auto' ? 'sent' : 'draft');
        $reply = CommentReply::create([
            'business_id' => $biz,
            'comment_id' => $comment->id,
            'kind' => 'public',
            'sender' => 'ai',
            'body' => $body,
            'status' => $status,
            'ai_rule_id' => $rule->id,
        ]);
        if ($status === 'sent') {
            $comment->status = 'replied';
            $comment->ai_rule_id = $rule->id;
            $comment->save();
        }
        $this->log($biz, $status === 'sent' ? 'auto_sent' : 'draft_created', $comment->author_name . ' — comment: ' . $rule->name, $rule, null, $comment);

        return $reply;
    }
}

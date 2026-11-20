<?php

namespace App\Services;

use App\Models\AiActivity;
use App\Models\AiRule;
use App\Models\AiRuleProposal;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Conversation;
use App\Models\KnowledgeEntry;
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
        'angry' => ['পাইনি', 'পাই নাই', 'রেগে', 'complain', 'অভিযোগ', 'দুই দিন হলো', 'এখনো আসেনি', 'প্রতারক', 'প্রতারণা', 'টাকা মেরে', 'ভুয়া', 'মামলা', 'পুলিশ', 'কোর্ট', 'ভোক্তা অধিকার', 'ঠকিয়ে'],
        'order_intent' => ['order confirm', 'অর্ডার কনফার্ম', 'confirm korlam', 'নিতে চাই', 'order korte chai', 'কনফার্ম করেন', 'কনফার্ম করুন', 'কনফার্ম করে দেন', 'অর্ডার করলাম', 'অর্ডার দিলাম'],
    ];

    public function __construct(
        private SettingsService $settings,
        private NegotiationService $negotiation,
        private AiTalkerService $talker,
        private AiGuardService $guard,
        private AiClassifierService $classifier,
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

    /** Does the text carry this intent's keywords? Short Latin words need word boundaries — "hi" must not fire inside "this". */
    private function intentMatches(string $intent, string $hay): bool
    {
        foreach (self::INTENTS[$intent] ?? [] as $kw) {
            $kw = mb_strtolower($kw);
            if (strlen($kw) <= 3 && preg_match('/^[a-z ]+$/', $kw)) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $hay)) {
                    return true;
                }
            } elseif (str_contains($hay, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify the message's intent ONCE, with real-world priority — the
     * rule that answers is then the rule for that intent, never an accident
     * of the order the rules were created in.
     */
    public function detectIntent(string $text, ?Conversation $conversation = null): ?string
    {
        $hay = mb_strtolower($text);
        if ($this->intentMatches('spam', $hay)) {
            return 'spam';
        }
        if ($this->intentMatches('angry', $hay)) {
            return 'angry';
        }
        if ($conversation && (int) $conversation->neg_step >= 1) {
            // Mid-bargaining: an amount or another push continues the ladder.
            if (preg_match('/[0-9\x{09E6}-\x{09EF}]/u', $text) || $this->intentMatches('bargain', $hay)
                || str_contains($hay, 'দাম') || str_contains($hay, 'কম')) {
                return 'bargain';
            }
        }
        if ($this->intentMatches('bargain', $hay)) {
            return 'bargain';
        }
        if ($this->intentMatches('order_intent', $hay)) {
            return 'order_intent';
        }
        foreach (['অর্ডার কোথায়', 'অর্ডারটা কোথায়', 'কোথায় আছে', 'পৌঁছেছে', 'ট্র্যাকিং', 'tracking', 'স্ট্যাটাস', 'কবে পাব', 'কবে পৌঁছাবে'] as $w) {
            if (str_contains($hay, $w)) {
                return 'order_status';
            }
        }
        // A delivery-charge question is a delivery question even though it
        // contains "কত" — unless the customer also asks a product's price.
        $deliveryCharge = false;
        foreach (['ডেলিভারি চার্জ', 'delivery charge', 'কুরিয়ার চার্জ', 'ডেলিভারি খরচ', 'shipping charge', 'চার্জ কত', 'ডেলিভারি কত', 'পাঠাতে কত'] as $p) {
            if (str_contains($hay, $p)) {
                $deliveryCharge = true;
                break;
            }
        }
        $asksPrice = str_contains($hay, 'দাম') || str_contains($hay, 'price') || str_contains($hay, 'dam ');
        if ($deliveryCharge && !$asksPrice) {
            return 'delivery';
        }
        if ($this->intentMatches('price', $hay)) {
            return 'price';
        }
        if ($this->intentMatches('stock', $hay)) {
            return 'stock';
        }
        if ($this->intentMatches('delivery', $hay)) {
            return 'delivery';
        }
        if ($this->intentMatches('greeting', $hay)) {
            return 'greeting';
        }

        return null;
    }

    public function matchRule(int $businessId, string $text, ?string $channel = null, ?Conversation $conversation = null): ?AiRule
    {
        $rules = AiRule::where('business_id', $businessId)->where('active', true)->orderBy('id')->get();
        $matchesChannel = fn (AiRule $r) => !$channel || !$r->channel || $r->channel === $channel;

        $intent = $this->detectIntent($text, $conversation);
        if ($intent !== null) {
            foreach ($rules as $rule) {
                if ($rule->trigger_type === 'intent' && $rule->match_text === $intent && $matchesChannel($rule)) {
                    return $rule;
                }
            }
        }

        // Fallback: seller keyword rules, then any intent rule whose words
        // appear at all (priority intents first, as before).
        $hay = mb_strtolower($text);
        $priority = ['spam', 'angry', 'bargain', 'order_intent'];
        $sorted = $rules->sortBy(fn ($r) => in_array($r->match_text, $priority, true) ? 0 : 1)->values();
        foreach ($sorted as $rule) {
            if (!$matchesChannel($rule)) {
                continue;
            }
            if ($rule->trigger_type === 'keyword') {
                foreach (array_map('trim', explode(',', (string) $rule->match_text)) as $kw) {
                    if ($kw !== '' && str_contains($hay, mb_strtolower($kw))) {
                        return $rule;
                    }
                }
            } elseif ($this->intentMatches($rule->match_text, $hay)) {
                return $rule;
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
            foreach (array_map('trim', explode(',', (string) ($p->aliases ?? ''))) as $alias) {
                if ($alias !== '' && str_contains($hay, mb_strtolower($alias))) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * The variant in focus for this conversation: the one already chosen,
     * or the one this message names ("L সাইজ", "লালটা"). A new choice is
     * remembered on the conversation.
     */
    public function variantFor(Conversation $conversation, Product $product, string $text): ?\App\Models\ProductVariant
    {
        $variants = $product->activeVariants();
        if ($variants->isEmpty()) {
            return null;
        }
        $state = (array) ($conversation->state_data ?? []);
        if (!empty($state['variant_id'])) {
            $chosen = $variants->firstWhere('id', (int) $state['variant_id']);
            if ($chosen) {
                return $chosen;
            }
        }
        $hay = mb_strtolower($text);
        foreach ($variants as $v) {
            foreach (preg_split('/[\s:\/,]+/u', mb_strtolower($v->name)) ?: [] as $tok) {
                $tok = trim($tok);
                if ($tok === '' || in_array($tok, ['size', 'color', 'colour', 'সাইজ', 'রঙ'], true)) {
                    continue;
                }
                if (preg_match('/(?<![a-z0-9\x{0980}-\x{09FF}])' . preg_quote($tok, '/') . '(?![a-z0-9\x{0980}-\x{09FF}])/iu', $hay)) {
                    $state['variant_id'] = $v->id;
                    $conversation->state_data = $state;
                    $conversation->save();

                    return $v;
                }
            }
        }

        return null;
    }

    public function buildReply(AiRule $rule, ?Product $product, int $businessId, bool $slots = false): string
    {
        $tpl = (string) ($rule->reply_template ?? '');
        $inside = (float) $this->settings->get('business.default_delivery_cost', 60);
        // With $slots, every figure stays a ⟦token⟧ until AFTER the talker
        // has phrased the text — the model never sees or writes a number,
        // and code renders the real values from the database at the end.
        $replace = $slots ? [
            '{product}' => $product?->name ?? 'পণ্যটা',
            '{price}' => '⟦price⟧',
            '{stock}' => '⟦stock⟧',
            '{delivery_inside}' => '⟦delivery_inside⟧',
            '{delivery_outside}' => '⟦delivery_outside⟧',
        ] : [
            '{product}' => $product?->name ?? 'পণ্যটা',
            '{price}' => $product ? number_format($product->effectivePrice()) : '',
            '{stock}' => $product ? (string) max(0, (int) $product->stock_qty - (int) $product->reserved_qty) : '',
            '{delivery_inside}' => number_format($inside),
            '{delivery_outside}' => number_format(120),
        ];

        return trim(strtr($tpl, $replace));
    }

    /** Fill ⟦slot⟧ tokens with the real figures from the database. */
    public function renderSlots(string $text, ?Product $product, int $businessId, array $extra = []): string
    {
        $inside = (float) $this->settings->get('business.default_delivery_cost', 60);
        $map = [
            '⟦price⟧' => $product ? number_format($product->effectivePrice()) : '',
            '⟦stock⟧' => $product ? (string) max(0, (int) $product->stock_qty - (int) $product->reserved_qty) : '',
            '⟦delivery_inside⟧' => number_format($inside),
            '⟦delivery_outside⟧' => number_format(120),
        ];
        foreach ($extra as $k => $v) {
            $map['⟦' . $k . '⟧'] = is_numeric($v) ? number_format((float) $v) : (string) $v;
        }

        return strtr($text, $map);
    }

    /** Truth context for the claim guard, from live data. */
    private function claimCtx(int $businessId, ?Product $product, array $slotValues = []): array
    {
        $available = $product ? max(0, (int) $product->stock_qty - (int) $product->reserved_qty) : null;
        $price = isset($slotValues['offer']) ? (float) $slotValues['offer'] : ($product ? $product->effectivePrice() : 0.0);

        return [
            'in_stock' => $available === null || $available > 0,
            'free_delivery_ok' => $product !== null && $price >= $this->negotiation->config()['free_delivery_min'],
            'shop_phone' => Business::find($businessId)?->phone,
        ];
    }

    /** How code-written replies address the customer (Settings: apu | bhaiya | none). Default keeps today's আপু. */
    private function honorific(int $businessId): string
    {
        $behavior = $this->settings->get('ai.behavior', []);
        $h = is_array($behavior) ? (string) ($behavior['honorific'] ?? 'apu') : 'apu';

        return match ($h) {
            'bhaiya' => 'ভাইয়া',
            'none' => '',
            default => 'আপু',
        };
    }

    private function honorificPrefix(int $businessId): string
    {
        $h = $this->honorific($businessId);

        return $h !== '' ? $h . ', ' : '';
    }

    /** The price the customer named in this message, if they named one. */
    private function namedAmount(string $text): ?float
    {
        $figures = $this->guard->extractFigures($text);
        if ($figures !== []) {
            return max($figures);
        }
        $t = $this->guard->normalizeDigits($text);
        if (preg_match('/(\d{2,7}(?:,\d{3})*(?:\.\d+)?)\s*(?:টাকায়|টাকা|দিলে|tk\b|taka\b)/iu', $t, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /**
     * One calm holding line for an angry customer, if the shop allows it —
     * at most once a day per conversation. Silence makes anger worse; an
     * argument would be worse still.
     */
    private function holdingLine(Conversation $conversation, array $auto): ?ChatMessage
    {
        $behavior = $this->settings->get('ai.behavior', []);
        if (!is_array($behavior) || !($behavior['angry_holding'] ?? false)) {
            return null;
        }
        $mode = $auto[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }
        $recent = ChatMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->contains(fn ($m) => ($m->meta['kind'] ?? null) === 'holding');
        if ($recent) {
            return null;
        }
        $msg = ChatMessage::create([
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => 'আপনার বিষয়টা আমাদের অ্যাডমিনকে জানানো হয়েছে — কিছুক্ষণের মধ্যে উত্তর পাবেন।',
            'status' => ($mode === 'auto' || $mode === 'full_auto') ? 'sent' : 'draft',
            'meta' => ['kind' => 'holding'],
        ]);
        $this->log((int) $conversation->business_id, 'holding_line', $conversation->customer_name . ' — holding line', null, $conversation);

        return $msg;
    }

    /**
     * Stage-2 fallback: ask the classifier, then resolve its suggestion to
     * this shop's own rule and product. Every call is logged — the log is
     * where new keywords are mined from, so misses stop repeating.
     */
    private function classifyFallback(int $businessId, Conversation $conversation, string $text): ?array
    {
        $result = $this->classifier->classify($businessId, $text);
        if ($result === null) {
            return null;
        }
        $this->log($businessId, 'stage2_classified', $conversation->customer_name . ' — ' . $result['intent'], null, $conversation);
        $rule = AiRule::where('business_id', $businessId)->where('active', true)
            ->where('trigger_type', 'intent')->where('match_text', $result['intent'])
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
            ->first();
        $product = !empty($result['product_id'])
            ? Product::where('business_id', $businessId)->find($result['product_id'])
            : null;

        return ['rule' => $rule, 'product' => $product, 'intent' => $result['intent']];
    }

    /** The shop's Knowledge entry this message is asking about, if any. */
    private function knowledgeFor(int $businessId, string $text): ?KnowledgeEntry
    {
        $hay = mb_strtolower($text);
        $best = null;
        $bestScore = 0;
        foreach (KnowledgeEntry::where('business_id', $businessId)->get() as $entry) {
            $score = 0;
            foreach (preg_split('/\s+/u', mb_strtolower($entry->title)) ?: [] as $word) {
                $word = trim($word, ' ,।?');
                if (mb_strlen($word) >= 3 && str_contains($hay, $word)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return $bestScore >= 1 ? $best : null;
    }

    /** The order summary text + meta, shared by the direct and guided-collection paths. */
    private function buildSummary(Conversation $conversation, Product $product, ?\App\Models\ProductVariant $variant): array
    {
        $delivery = 60.0;
        // A price agreed in bargaining is the price the customer pays —
        // the summary must not jump back to the full price.
        $unit = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : ($variant ? $variant->effectivePrice() : $product->effectivePrice());
        $total = $unit + $delivery;
        $itemName = $product->name . ($variant ? ' (' . $variant->name . ')' : '');
        $body = $conversation->customer_name . ' · ' . ($conversation->customer_address ?: 'ঠিকানা বাকি') . "\n"
            . $itemName . ' ×1 — ৳' . number_format($unit) . ' + delivery ৳' . number_format($delivery) . ' = মোট ৳' . number_format($total) . ' (Cash on Delivery)';
        // Cart snapshot: what this conversation is currently buying.
        $state = (array) ($conversation->state_data ?? []);
        $state['cart'] = [['product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'qty' => 1, 'agreed_price' => $unit]];
        $conversation->state_data = $state;
        $conversation->save();

        return ['body' => $body, 'meta' => ['product_id' => $product->id, 'total' => $total, 'unit_price' => $unit, 'variant_name' => $variant?->name]];
    }

    private function collectQuestion(int $businessId, string $step): string
    {
        $prefix = $this->honorificPrefix($businessId);

        return $step === 'phone'
            ? $prefix . 'অর্ডারের জন্য আপনার মোবাইল নম্বরটা দিন — যে নম্বরে কুরিয়ার কল করবে।'
            : $prefix . 'ডেলিভারির ঠিকানাটা দিন — বাসা/হোল্ডিং, রোড, এলাকা, জেলা।';
    }

    /** A valid Bangladeshi mobile number from free text, normalised to 01XXXXXXXXX. */
    private function extractPhone(string $text): ?string
    {
        $t = preg_replace('/[\s\-]+/', '', $this->guard->normalizeDigits($text));
        if (preg_match('/(?:\+?880|0)(1[3-9]\d{8})/', (string) $t, $m)) {
            return '0' . $m[1];
        }

        return null;
    }

    /** A short yes/no answer, or null when the message is neither. */
    private function yesNo(string $text): ?bool
    {
        $t = trim(mb_strtolower($text));
        if (mb_strlen($t) > 20) {
            return null;
        }
        foreach (['না', 'ভুল', 'এটা না', 'no', 'na'] as $w) {
            if (str_contains($t, $w)) {
                return false;
            }
        }
        foreach (['হ্যাঁ', 'হ্যা', 'হুম', 'এটাই', 'ঠিক', 'yes', 'ha'] as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }

        return null;
    }

    /** Public fixed-text reply (used by the media job for photo confirmation). */
    public function simpleReply(Conversation $conversation, string $body, string $kind): ?ChatMessage
    {
        $mode = $this->autonomy()[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }

        return $this->simpleOutgoing($conversation, $body, $kind, $mode);
    }

    /** A fixed-text AI outgoing message (collection questions, cancellations). */
    private function simpleOutgoing(Conversation $conversation, string $body, string $kind, string $mode, array $meta = []): ChatMessage
    {
        $msg = ChatMessage::create([
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $body,
            'status' => ($mode === 'auto' || $mode === 'full_auto') ? 'sent' : 'draft',
            'meta' => array_merge($meta, ['kind' => $kind]),
        ]);
        $this->log((int) $conversation->business_id, $msg->status === 'sent' ? 'auto_sent' : 'draft_created', $conversation->customer_name . ' — ' . $kind, null, $conversation);

        return $msg;
    }

    /**
     * Guided order-data collection. One question at a time; a message that
     * carries several fields at once fills them all; two failed tries at
     * one field hand the conversation to a human.
     */
    private function handleCollect(Conversation $conversation, string $text, string $mode): ?ChatMessage
    {
        $biz = (int) $conversation->business_id;
        $state = (array) ($conversation->state_data ?? []);
        $collect = (array) ($state['collect'] ?? []);
        $step = (string) ($collect['step'] ?? 'phone');

        $hay = mb_strtolower($text);
        foreach (['বাদ দেন', 'বাদ দিন', 'থামুন', 'cancel', 'ক্যান্সেল'] as $w) {
            if (str_contains($hay, $w)) {
                $conversation->state = null;
                $state['collect'] = null;
                $conversation->state_data = $state;
                $conversation->save();

                return $this->simpleOutgoing($conversation, $this->honorificPrefix($biz) . 'ঠিক আছে, অর্ডারটা রাখা হলো না।', 'collect_cancel', $mode);
            }
        }

        // A phone number anywhere in the message counts, whatever step we are on.
        $phone = $this->extractPhone($text);
        if ($phone !== null && empty($conversation->customer_phone)) {
            $conversation->customer_phone = $phone;
        }
        // Strip the number from the DIGIT-NORMALISED text: the customer may
        // have typed it in Bangla digits while $phone is Latin — replacing
        // in the raw text would leave the whole message standing as "rest"
        // and it would be misread as an address.
        $rest = trim(str_replace($phone ?? '~~~', '', $this->guard->normalizeDigits($text)));
        if (empty($conversation->customer_address) && ($step === 'address' || $phone !== null)) {
            $addr = trim((string) preg_replace('/^(ঠিকানা|address)\s*[:：]?\s*/iu', '', $rest));
            // When the address step was asked, any real answer counts. In a
            // blob message, the leftover text must LOOK like an address —
            // "আমার নম্বর" after the number is not one.
            $looksAddress = $step === 'address'
                || str_contains($addr, ',')
                || mb_strlen($addr) >= 15
                || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম/u', $addr);
            if ($looksAddress && mb_strlen($addr) >= 8) {
                $conversation->customer_address = $addr;
            }
        }
        $conversation->save();

        $missing = [];
        if (empty($conversation->customer_phone)) {
            $missing[] = 'phone';
        }
        if (empty($conversation->customer_address)) {
            $missing[] = 'address';
        }

        if ($missing === []) {
            $product = Product::where('business_id', $biz)->find($collect['product_id'] ?? $conversation->product_id);
            $conversation->state = null;
            $state['collect'] = null;
            $conversation->state_data = $state;
            $conversation->save();
            if (!$product) {
                return null;
            }
            $variant = !empty($collect['variant_id']) ? \App\Models\ProductVariant::find($collect['variant_id']) : null;
            $summary = $this->buildSummary($conversation, $product, $variant);
            $msg = ChatMessage::create([
                'business_id' => $biz,
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'sender' => 'ai',
                'body' => $summary['body'],
                'status' => ($mode === 'auto' || $mode === 'full_auto') ? 'sent' : 'draft',
                'meta' => array_merge($summary['meta'], ['kind' => 'order_summary']),
            ]);
            $this->log($biz, $msg->status === 'sent' ? 'auto_sent' : 'draft_created', $conversation->customer_name . ' — Order summary (collected)', null, $conversation);

            return $msg;
        }

        $next = $missing[0];
        if ($next === $step) {
            $collect['retries'] = (int) ($collect['retries'] ?? 0) + 1;
            if ($collect['retries'] > 2) {
                $conversation->state = null;
                $state['collect'] = null;
                $conversation->state_data = $state;
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — order data incomplete', null, $conversation);

                return null;
            }
        } else {
            $collect['retries'] = 0;
        }
        $collect['step'] = $next;
        $state['collect'] = $collect;
        $conversation->state_data = $state;
        $conversation->save();

        return $this->simpleOutgoing($conversation, $this->collectQuestion($biz, $next), 'collect_question', $mode, ['collect' => $next]);
    }

    /** Every figure the business could honestly state right now. */
    public function allowedFigures(int $businessId, ?Product $product): array
    {
        $figures = [60.0, 120.0, (float) $this->settings->get('business.default_delivery_cost', 60), $this->negotiation->config()['free_delivery_min']];
        foreach (Product::where('business_id', $businessId)->get() as $p) {
            $figures[] = (float) $p->price;
            $figures[] = $p->effectivePrice();
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

    /**
     * Pre-send verification: every money figure in the text must be a real
     * one. Figures are extracted in every written form (Bangla digits,
     * টাকা/tk suffixes, number words, ranges) via the guard, so changing
     * the script or wording cannot bypass the check.
     */
    public function moneyCheck(int $businessId, string $text, ?Product $product): bool
    {
        $figures = $this->guard->extractFigures($text);
        if ($figures === []) {
            return true;
        }
        $allowed = $this->allowedFigures($businessId, $product);
        foreach ($figures as $amount) {
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

    private function log(int $businessId, string $action, string $detail, ?AiRule $rule = null, ?Conversation $c = null, ?Comment $cm = null, ?array $extra = null): void
    {
        AiActivity::create(array_merge([
            'business_id' => $businessId,
            'action' => $action,
            'detail' => $detail,
            'ai_rule_id' => $rule?->id,
            'conversation_id' => $c?->id,
            'comment_id' => $cm?->id,
        ], $extra ?? []));
    }

    /**
     * The one entry point for an incoming chat message — the webhooks will
     * call this too. Returns the AI draft/sent message, or null.
     */
    /** Store one incoming message (no reply decision yet). */
    public function storeIncoming(Conversation $conversation, string $text, array $opts = []): ChatMessage
    {
        $attachment = (array) ($opts['attachment'] ?? []);
        $incoming = ChatMessage::create([
            'business_id' => (int) $conversation->business_id,
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'sender' => 'customer',
            'body' => $text,
            'status' => 'sent',
            'external_id' => $opts['external_id'] ?? null,
            'attachment_type' => $attachment['type'] ?? null,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_meta' => $attachment['meta'] ?? null,
            'meta' => array_filter([
                'referral' => $opts['referral'] ?? null,
            ]) ?: null,
        ]);
        $conversation->unread_count = (int) $conversation->unread_count + 1;
        $conversation->last_message_at = now();
        $conversation->save();

        return $incoming;
    }

    private function debounceSeconds(): int
    {
        $behavior = $this->settings->get('ai.behavior', []);

        return is_array($behavior) ? max(0, (int) ($behavior['debounce_seconds'] ?? 0)) : 0;
    }

    /**
     * Burst handling: customers often send three short messages in a row.
     * The webhook stores them and this sweep answers the whole burst once,
     * after a quiet window — never three separate replies.
     */
    public function processPending(Conversation $conversation): ?ChatMessage
    {
        $lastOutId = (int) (ChatMessage::where('conversation_id', $conversation->id)->where('direction', 'out')->max('id') ?? 0);
        $pending = ChatMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->where('id', '>', $lastOutId)
            ->orderBy('id')
            ->get()
            ->filter(fn ($m) => empty(($m->meta ?? [])['processed']) && empty(($m->meta ?? [])['spam']))
            ->values();
        if ($pending->isEmpty()) {
            return null;
        }
        $latest = $pending->last();
        if ($latest->created_at && $latest->created_at->gt(now()->subSeconds($this->debounceSeconds()))) {
            return null; // still inside the quiet window — a later run takes it
        }
        $text = $pending->pluck('body')->filter(fn ($b) => trim((string) $b) !== '')->implode("\n");
        $reply = trim($text) === '' ? null : $this->handleIncoming($conversation, $text, ['prestored_ids' => $pending->pluck('id')->all()]);
        foreach ($pending as $m) {
            $meta = (array) ($m->meta ?? []);
            $meta['processed'] = true;
            $m->meta = $meta;
            $m->save();
        }

        return $reply;
    }

    public function handleIncoming(Conversation $conversation, string $text, array $opts = []): ?ChatMessage
    {
        $startedAt = microtime(true);
        $biz = (int) $conversation->business_id;
        $attachment = (array) ($opts['attachment'] ?? []);
        if (!empty($opts['prestored_ids'])) {
            // The messages are already stored (burst intake) — work on the
            // newest stored row instead of storing the merged text again.
            $incoming = ChatMessage::whereIn('id', $opts['prestored_ids'])->orderByDesc('id')->first()
                ?? $this->storeIncoming($conversation, $text, $opts);
            $attachment = ['type' => $incoming->attachment_type] + (array) ($opts['attachment'] ?? []);
        } else {
            $incoming = $this->storeIncoming($conversation, $text, $opts);
        }

        if ($text === '' && ! empty($attachment)) {
            // Media-only message (photo, voice note, sticker). It is stored
            // and visible in the inbox; understanding it is a separate step
            // (image scan / transcription) — the brain never guesses.
            $this->log($biz, 'media_received', $conversation->customer_name . ' — ' . ($attachment['type'] ?? 'media'), null, $conversation);

            return null;
        }

        $auto = $this->autonomy();
        if ($auto['panic'] || $conversation->ai_paused) {
            return null;
        }

        $rule = $this->matchRule($biz, $text, $conversation->channel, $conversation);
        $intent = $this->detectIntent($text, $conversation);
        $product = $this->productFor($biz, $text, $conversation);
        if ($product && (int) $conversation->product_id !== (int) $product->id) {
            // Remember the product for this conversation — follow-up
            // messages ("আরেকটু কমান", "স্টকে আছে?") rarely repeat its name.
            $conversation->product_id = $product->id;
            $conversation->focus_at = now();
            $conversation->save();
        }

        $stage2 = null;
        if (!$rule) {
            // Stage 2: only when the rule system found nothing, a cheap
            // classifier may suggest the intent/product. Code still decides.
            $stage2 = $this->classifyFallback($biz, $conversation, $text);
            if ($stage2) {
                if ($stage2['rule']) {
                    $rule = $stage2['rule'];
                }
                if (!$product && $stage2['product']) {
                    $product = $stage2['product'];
                    $conversation->product_id = $product->id;
                    $conversation->focus_at = now();
                    $conversation->save();
                }
            }
        }

        // A pending variant question gets its answer first: "L" after
        // "কোন সাইজ?" resumes the question that was actually asked.
        $stateData = (array) ($conversation->state_data ?? []);
        if (!$rule && !empty($stateData['variant_pending'])) {
            $pendingProduct = Product::where('business_id', $biz)->find($stateData['variant_pending']['product_id'] ?? 0);
            if ($pendingProduct) {
                $resolved = $this->variantFor($conversation, $pendingProduct, $text);
                if ($resolved) {
                    $product = $pendingProduct;
                    $rule = AiRule::where('business_id', $biz)->where('active', true)
                        ->where('trigger_type', 'intent')
                        ->where('match_text', (string) ($stateData['variant_pending']['intent'] ?? ''))
                        ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
                        ->first();
                    $stateData['variant_pending'] = null;
                    $conversation->state_data = $stateData;
                    $conversation->product_id = $product->id;
                    $conversation->focus_at = now();
                    $conversation->save();
                }
            }
        }
        // A pending photo confirmation gets its answer first: "হ্যাঁ"
        // after "এই প্রোডাক্টটা?" confirms the match and answers price+stock.
        $imgState = (array) ($conversation->state_data ?? []);
        if (!empty($imgState['image_confirm'])) {
            $answer = $this->yesNo($text);
            if ($answer !== null) {
                $pendingImg = (array) $imgState['image_confirm'];
                $imgState['image_confirm'] = null;
                $conversation->state_data = $imgState;
                $conversation->save();
                if (!$answer) {
                    $conversation->needs_human = true;
                    $conversation->save();
                    $this->log($biz, 'image_rejected', $conversation->customer_name . ' — photo match denied', null, $conversation);

                    return null;
                }
                $confirmed = Product::where('business_id', $biz)->find($pendingImg['product_id'] ?? 0);
                if ($confirmed) {
                    $photoMsg = !empty($pendingImg['message_id']) ? ChatMessage::find($pendingImg['message_id']) : null;
                    if ($photoMsg && $photoMsg->attachment_path) {
                        app(ImageMatchService::class)->learn($biz, $confirmed->id, $photoMsg->attachment_path, 'customer');
                    }
                    $product = $confirmed;
                    $conversation->product_id = $confirmed->id;
                    $conversation->focus_at = now();
                    $conversation->save();
                    $text = 'এটার দাম কত? স্টকে আছে?';
                    $rule = AiRule::where('business_id', $biz)->where('active', true)
                        ->where('trigger_type', 'intent')->where('match_text', 'price')
                        ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
                        ->first() ?? $rule;
                }
            }
        }

        $variant = $product ? $this->variantFor($conversation, $product, $text) : null;
        $needsVariant = $product && $variant === null && $product->hasVariants()
            && $rule && in_array($rule->match_text, ['stock', 'order_intent'], true);

        if ($rule && $rule->match_text === 'spam') {
            // Ignored, but never silently lost: the message is marked and
            // the ignore is on the record, so staff can review and undo it.
            $incoming->meta = array_merge((array) ($incoming->meta ?? []), ['spam' => true]);
            $incoming->save();
            $this->log($biz, 'spam_ignored', $conversation->customer_name . ' — ' . mb_substr($text, 0, 60), $rule, $conversation);

            return null;
        }

        if ($rule && $rule->action === 'handover') {
            $conversation->needs_human = true;
            $conversation->save();
            $this->log($biz, 'handed_human', $conversation->customer_name . ' — ' . $rule->name, $rule, $conversation);
            if ($rule->match_text === 'angry') {
                return $this->holdingLine($conversation, $auto);
            }

            return null;
        }

        $mode = $auto[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }

        if ($conversation->state === 'collecting') {
            return $this->handleCollect($conversation, $text, $mode);
        }

        $kind = null;
        if ($needsVariant) {
            // Never quote stock or build an order for "the product" when
            // only some variants exist — ask ONE short question first.
            $names = $product->activeVariants()->pluck('name')->implode(', ');
            $slottedBody = $this->honorificPrefix($biz) . $product->name . '-এর কোনটা চাইছেন? আছে: ' . $names . '। কোনটা নেবেন, বলুন।';
            $kind = 'variant_question';
            $meta = ['variant_question' => true];
            $state = (array) ($conversation->state_data ?? []);
            $state['variant_pending'] = ['product_id' => $product->id, 'intent' => $rule->match_text];
            $conversation->state_data = $state;
            $conversation->save();
        } elseif ($rule && $rule->match_text === 'bargain') {
            if (!$product) {
                return null;
            }
            $quote = $this->negotiation->quote($conversation, $product, $this->namedAmount($text));
            if ($quote === null) {
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', $rule, $conversation);

                return null;
            }
            $offer = $quote['offer'];
            $total = $offer + (float) $this->settings->get('business.default_delivery_cost', 60);
            $h = $this->honorific($biz);
            $first = match ($quote['kind']) {
                'accept' => 'ঠিক আছে' . ($h !== '' ? ' ' . $h : '') . ', আপনার কথামতোই ৳⟦offer⟧-এ দিচ্ছি।',
                'floor_repeat' => ($h !== '' ? $h . ', ' : '') . '৳⟦offer⟧-ই শেষ দাম — এর কমে আর হচ্ছে না।',
                default => 'বুঝতে পারছি' . ($h !== '' ? ' ' . $h : '') . '। আপনার জন্য ৳⟦offer⟧-এ দিতে পারব।',
            };
            $slottedBody = $first . ' ডেলিভারি চার্জসহ মোট ৳⟦total⟧ (ঢাকার ভিতরে)। অর্ডার করবেন?';
            $slotValues = ['offer' => $offer, 'total' => $total];
            $meta = ['ladder_step' => (int) $conversation->neg_step, 'offer' => $offer, 'quote' => $quote['kind']];
        } elseif ($rule && $rule->match_text === 'order_intent') {
            if (!$product) {
                return null;
            }
            $missing = [];
            if (empty($conversation->customer_phone)) {
                $missing[] = 'phone';
            }
            if (empty($conversation->customer_address)) {
                $missing[] = 'address';
            }
            if ($missing !== []) {
                // Guided collection: ask only for what is missing, one
                // thing at a time, instead of a summary full of holes.
                $state = (array) ($conversation->state_data ?? []);
                $state['collect'] = ['step' => $missing[0], 'retries' => 0, 'product_id' => $product->id, 'variant_id' => $variant?->id];
                $conversation->state = 'collecting';
                $conversation->state_data = $state;
                $conversation->save();
                $slottedBody = $this->collectQuestion($biz, $missing[0]);
                $kind = 'collect_question';
                $meta = ['collect' => $missing[0]];
            } else {
                $summary = $this->buildSummary($conversation, $product, $variant);
                $body = $summary['body'];
                $kind = 'order_summary';
                $meta = $summary['meta'];
            }
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
            $slottedBody = $this->buildReply($rule, $product, $biz, true);
            if (trim($slottedBody) === '') {
                return null;
            }
            if (preg_match('/৳\s*([।,]|$)/u', $this->renderSlots($slottedBody, $product, $biz))) {
                $slottedBody = $this->honorificPrefix($biz) . 'কোন পণ্যটার কথা বলছেন? পণ্যের নামটা বললে দাম আর স্টক জানিয়ে দিচ্ছি।';
                $meta = ['clarify' => true];
            } else {
                $meta = [];
            }
        } elseif (!$rule && $intent === 'order_status') {
            // "আমার অর্ডারটা কোথায়?" — answered from the real order record.
            $order = null;
            if (!empty($conversation->customer_phone)) {
                $order = \App\Models\Order::where('business_id', $biz)
                    ->where('customer_phone', $conversation->customer_phone)
                    ->latest('id')->first();
            }
            if ($order) {
                $slottedBody = $this->honorificPrefix($biz) . 'আপনার অর্ডার (⟦invoice⟧) এখন ⟦status⟧ অবস্থায় আছে।';
                $slotValues = ['invoice' => $order->invoice_no, 'status' => (string) $order->status];
                $meta = ['order_status' => $order->id];
            } else {
                $slottedBody = $this->honorificPrefix($biz) . 'আপনার নামে কোনো অর্ডার খুঁজে পাইনি। অর্ডারের নম্বরটা দিলে দেখে দিচ্ছি।';
                $meta = ['order_status' => null];
            }
            $kind = 'order_status';
        } else {
            // No rule matched. First try the shop's own Knowledge — the AI
            // may answer policy questions only from what the shop wrote.
            $knowledge = $this->knowledgeFor($biz, $text);
            if ($knowledge !== null) {
                $slottedBody = (string) $knowledge->body;
                $meta = ['knowledge_id' => $knowledge->id];
            } elseif (!$product) {
                return null;
            } else {
                // Draft from the product description; a human always checks
                // a no-rule draft before it goes out (mode decides below).
                $desc = trim((string) ($product->short_description ?? ''));
                $slottedBody = $desc !== ''
                    ? $this->honorificPrefix($biz) . $desc
                    : $this->honorificPrefix($biz) . $product->name . '-এর দাম ৳⟦price⟧। আর কিছু জানতে চাইলে বলুন।';
                $meta = ['no_rule' => true];
            }
        }

        if ($variant && $rule && $rule->match_text === 'stock') {
            $slotValues['stock'] = $variant->available();
        }
        if ($variant && $rule && $rule->match_text === 'price') {
            $slotValues['price'] = $variant->effectivePrice();
        }

        // The talker only phrases what was decided above, working on the
        // slotted text — it never sees a real figure. Code renders the
        // slots afterwards, then re-verifies figures AND claims before
        // anything can be sent. The order summary is never phrased.
        $talkerMeta = [];
        $slotValues = $slotValues ?? [];
        if ($kind === 'order_summary') {
            $draftRendered = $body;
        } elseif ($kind === 'variant_question' || $kind === 'collect_question') {
            // Fixed questions must reach the customer word-for-word — no phrasing.
            $draftRendered = $this->renderSlots($slottedBody, $product, $biz, $slotValues);
            $body = $draftRendered;
        } else {
            $draftRendered = $this->renderSlots($slottedBody, $product, $biz, $slotValues);
            $phrased = $this->talker->phrase([
                'business_id' => $biz,
                'channel' => $conversation->channel,
                'incoming' => $text,
                'intent' => $rule?->match_text,
                'neg_step' => (int) $conversation->neg_step,
                'draft' => $slottedBody,
            ]);
            if ($phrased) {
                $body = $this->renderSlots($phrased['text'], $product, $biz, $slotValues);
                $talkerMeta = ['talker' => $phrased['provider'], 'talker_model' => $phrased['model'], 'talker_tier' => $phrased['tier']];
            } else {
                $body = $draftRendered;
            }
        }

        $verified = $this->moneyCheck($biz, $body, $product);
        $claimIssues = $this->guard->claimViolations($biz, $draftRendered, $body, $this->claimCtx($biz, $product, $slotValues));
        if (!$verified || $claimIssues !== []) {
            $status = 'flagged';
            if ($claimIssues !== []) {
                $meta = array_merge($meta ?? [], ['guard' => $claimIssues]);
            }
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
            'meta' => array_merge($meta ?? [], $kind ? ['kind' => $kind] : [], $talkerMeta, $stage2 ? ['stage2' => $stage2['intent']] : []),
        ]);

        $action = $status === 'sent' ? 'auto_sent' : ($status === 'flagged' ? 'flagged' : 'draft_created');
        $this->log($biz, $action, $conversation->customer_name . ' — ' . ($rule?->name ?? 'No rule matched'), $rule, $conversation, null, [
            'source' => $talkerMeta !== [] ? 'talker' : 'template',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
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
        $slottedBody = $this->buildReply($rule, $product, $biz, true);
        $draftRendered = $this->renderSlots($slottedBody, $product, $biz);
        if (trim($slottedBody) === '' || preg_match('/৳\s*([।,]|$)/u', $draftRendered)) {
            // Never post a public reply with a blank figure in it.
            return null;
        }
        $phrased = $this->talker->phrase([
            'business_id' => $biz,
            'channel' => 'comment',
            'incoming' => $comment->body,
            'intent' => $rule->match_text,
            'neg_step' => 0,
            'draft' => $slottedBody,
        ]);
        $body = $phrased ? $this->renderSlots($phrased['text'], $product, $biz) : $draftRendered;
        $mode = $this->cautious($auto['comment'] ?? 'draft');
        $verified = $this->moneyCheck($biz, $body, $product)
            && $this->guard->claimViolations($biz, $draftRendered, $body, $this->claimCtx($biz, $product)) === [];
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

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
use App\Models\Customer;
use App\Models\KnowledgeEntry;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        'order_intent' => ['order confirm', 'অর্ডার কনফার্ম', 'confirm korlam', 'নিতে চাই', 'order korte chai', 'অর্ডার করতে চাই', 'কনফার্ম করেন', 'কনফার্ম করুন', 'কনফার্ম করে দেন', 'অর্ডার করলাম', 'অর্ডার দিলাম'],
    ];

    public function __construct(
        private SettingsService $settings,
        private NegotiationService $negotiation,
        private AiTalkerService $talker,
        private AiGuardService $guard,
        private AiClassifierService $classifier,
        private HonorificService $honorifics,
    ) {
    }

    /**
     * Reply levels for one shop: the shop's own save (ai.{biz}.autonomy)
     * wins, the platform-global ai.autonomy is the fallback until the
     * shop saves its own. The panic flag is different - it pauses AI
     * for EVERY shop, so it is read from the global row only and stays
     * platform-admin controlled (MessagesController::panic).
     *
     * @return array<string,mixed> channel => off|draft|auto|full_auto, plus panic
     */
    public function autonomy(?int $businessId = null): array
    {
        $global = $this->settings->get('ai.autonomy', []);
        $global = is_array($global) ? $global : [];
        $a = $global;
        if ($businessId !== null && $businessId > 0) {
            $own = $this->settings->bizGet($businessId, "ai.{$businessId}.autonomy", 'ai.autonomy', []);
            $a = is_array($own) ? $own : [];
        }

        return [
            'facebook' => $a['facebook'] ?? 'draft',
            'whatsapp' => $a['whatsapp'] ?? 'draft',
            'instagram' => $a['instagram'] ?? 'draft',
            'comment' => $a['comment'] ?? 'draft',
            'panic' => (bool) ($global['panic'] ?? false),
        ];
    }

    /** The shop's effective AI behaviour (own save wins, global fallback). */
    private function behaviorFor(int $businessId): array
    {
        $b = $businessId > 0
            ? $this->settings->bizGet($businessId, "ai.{$businessId}.behavior", 'ai.behavior', [])
            : $this->settings->get('ai.behavior', []);

        return is_array($b) ? $b : [];
    }

    private function cautious(string $mode): string
    {
        return match ($mode) {
            'full_auto' => 'auto',
            'auto' => 'draft',
            default => $mode,
        };
    }

    /**
     * Is this conversation's customer on the shop's blocklist? The
     * Customers page flag (is_blacklisted) — the same record the
     * courier auto-approve guard reads (Hunt Y2-16).
     */
    private function customerBlocklisted(Conversation $conversation): bool
    {
        $phone = PhoneNormalizer::normalize($conversation->customer_phone ?? '');
        if ($phone === '') {
            return false;
        }

        return (bool) Customer::where('business_id', (int) $conversation->business_id)
            ->where('phone', $phone)->value('is_blacklisted');
    }

    /**
     * A blocklisted customer gets no AI reply at all: the conversation
     * is flagged for a human and the brain stays silent (Hunt Y2-16).
     * Returns true when the caller must stop here.
     */
    private function blocklistSilences(Conversation $conversation): bool
    {
        if (! $this->customerBlocklisted($conversation)) {
            return false;
        }
        if (! $conversation->needs_human) {
            $conversation->needs_human = true;
            $conversation->save();
        }
        $this->log((int) $conversation->business_id, 'blocklisted_handover', $conversation->customer_name . ' - blocklisted customer, AI stayed silent', null, $conversation);

        return true;
    }

    /**
     * The shop's Working Hours window as [startMinute, endMinute] of
     * the Dhaka day, or null when the field is empty or cannot be
     * understood (then no hour restriction applies — a free-text field
     * must never silently mute a shop). Accepts the forms the Settings
     * field itself shows: "9 AM - 11 PM", "09:00 - 18:00" and the
     * Bangla "সকাল ৯টা - রাত ১১টা".
     */
    private function workingHoursWindow(int $businessId): ?array
    {
        $raw = (string) $this->settings->bizGet($businessId, "ai.{$businessId}.working_hours", 'ai.working_hours', '');
        if (trim($raw) === '') {
            return null;
        }
        $s = strtr($raw, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $found = []; // [offset, minutes]
        if (preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $full) {
                $h = (int) $m[1][$i][0];
                $min = (int) ($m[2][$i][0] ?? 0);
                if (strtolower($m[3][$i][0]) === 'pm' && $h < 12) {
                    $h += 12;
                }
                if (strtolower($m[3][$i][0]) === 'am' && $h === 12) {
                    $h = 0;
                }
                $found[] = [$full[1], $h * 60 + $min];
            }
        }
        if (preg_match_all('/(সকাল|ভোর|দুপুর|বিকাল|বিকেল|সন্ধ্যা|রাত)\s*(\d{1,2})(?::(\d{2}))?\s*টা?/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $full) {
                $h = (int) $m[2][$i][0];
                $min = (int) ($m[3][$i][0] ?? 0);
                $h = match ($m[1][$i][0]) {
                    'সকাল', 'ভোর' => $h === 12 ? 0 : $h,
                    'দুপুর' => $h === 12 ? 12 : $h + 12,
                    'বিকাল', 'বিকেল', 'সন্ধ্যা' => $h + 12,
                    'রাত' => $h === 12 ? 0 : $h + 12,
                    default => $h,
                };
                $found[] = [$full[1], ($h % 24) * 60 + $min];
            }
        }
        if ($found === [] && preg_match_all('/\b(\d{1,2}):(\d{2})\b/', $s, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $full) {
                $found[] = [$full[1], ((int) $m[1][$i][0]) * 60 + (int) $m[2][$i][0]];
            }
        }
        if (count($found) < 2) {
            return null;
        }
        usort($found, fn ($a, $b) => $a[0] <=> $b[0]);

        return [$found[0][1], $found[1][1]];
    }

    private function withinWorkingHours(int $businessId): bool
    {
        $window = $this->workingHoursWindow($businessId);
        if ($window === null || $window[0] === $window[1]) {
            return true;
        }
        [$start, $end] = $window;
        $dhaka = \Carbon\Carbon::now(\App\Support\DhakaTime::TZ);
        $now = $dhaka->hour * 60 + $dhaka->minute;

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end); // overnight window, e.g. 8 PM - 2 AM
    }

    /**
     * The Settings promise, enforced (Hunt Y2-10): outside the shop's
     * Working Hours the AI only drafts — auto/full_auto step down to
     * draft, every other level is untouched.
     */
    private function applyWorkingHours(int $businessId, string $mode): string
    {
        if (($mode === 'auto' || $mode === 'full_auto') && ! $this->withinWorkingHours($businessId)) {
            return 'draft';
        }

        return $mode;
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
    /**
     * A message that is only emoji / a Like — 👍 ❤️ 😊 and friends, with no
     * words at all. The Messenger Like sticker arrives as the text '👍'
     * (the webhook translates it), so one check covers both.
     */
    public function isReaction(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 12) {
            return false;
        }
        $emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u';
        if (! preg_match($emoji, $t)) {
            return false;
        }
        $rest = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\s]/u', '', $t);

        return $rest === '';
    }

    public function detectIntent(string $text, ?Conversation $conversation = null): ?string
    {
        if ($this->isReaction($text)) {
            return 'reaction';
        }
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
        if (! $rules->contains(fn (AiRule $r) => $r->trigger_type === 'intent')) {
            // Safety net: a shop with zero active intent rules can only
            // ever get the generic fallback — it never bargains and never
            // takes an order. Install the defaults once and reload.
            // ensureDefaults is firstOrCreate-keyed, so a shop whose
            // seller deliberately deactivated every rule keeps them off:
            // the rows exist, they are just not reactivated.
            AiRule::ensureDefaults($businessId);
            $rules = AiRule::where('business_id', $businessId)->where('active', true)->orderBy('id')->get();
        }
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
        $products = Product::where('business_id', $businessId)->orderBy('id')->get();

        // Exact signals win outright, as before: a SKU or a seller-taught
        // alias names ONE product unambiguously.
        foreach ($products as $p) {
            if ($p->sku && str_contains($hay, mb_strtolower($p->sku))) {
                return $p;
            }
            foreach (array_map('trim', explode(',', (string) ($p->aliases ?? ''))) as $alias) {
                if ($alias !== '' && str_contains($hay, mb_strtolower($alias))) {
                    return $p;
                }
            }
        }

        // Name words are scored, never first-match (Round 6, P2): a single
        // shared generic word ("pack" in both "T-Shirt Pack" and "Gift
        // Combo Pack") must not hijack the match to the first product that
        // happens to contain it. A product wins on COVERAGE first (how
        // much of its name the customer actually said), then on how
        // DISTINCTIVE the matched words are (a word appearing in many
        // product names is weak evidence). A full-name mention is the
        // strongest signal of all. An exact tie keeps the legacy
        // first-by-id order - the reply always names the product it
        // matched, so the customer can correct it, and its figures are
        // that product's own live figures.
        $freq = [];
        $wordsOf = [];
        foreach ($products as $p) {
            $words = array_values(array_filter(
                preg_split('/\s+/', mb_strtolower($p->name)) ?: [],
                fn ($w) => mb_strlen($w) >= 4
            ));
            $wordsOf[$p->id] = $words;
            foreach (array_unique($words) as $w) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        $best = null;
        $bestCoverage = -1.0;
        $bestScore = -1.0;
        foreach ($products as $p) {
            $words = $wordsOf[$p->id];
            if ($words === []) {
                continue;
            }
            $matched = array_values(array_filter($words, fn ($w) => str_contains($hay, $w)));
            if ($matched === []) {
                continue;
            }
            $coverage = count($matched) / count($words);
            $score = 0.0;
            foreach ($matched as $w) {
                $score += 1.0 / max(1, $freq[$w] ?? 1);
            }
            if (str_contains($hay, mb_strtolower($p->name))) {
                $score += 100.0; // the customer said the whole name
            }
            if ($coverage > $bestCoverage + 1e-9
                || (abs($coverage - $bestCoverage) <= 1e-9 && $score > $bestScore + 1e-9)) {
                $best = $p;
                $bestCoverage = $coverage;
                $bestScore = $score;
            }
        }

        return $best;
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

    public function buildReply(AiRule $rule, ?Product $product, int $businessId, bool $slots = false, ?string $honorific = null): string
    {
        $tpl = (string) ($rule->reply_template ?? '');
        $inside = (float) $this->settings->bizGet($businessId, "business.{$businessId}.default_delivery_cost", 'business.default_delivery_cost', 60);
        // With $slots, every figure stays a ⟦token⟧ until AFTER the talker
        // has phrased the text — the model never sees or writes a number,
        // and code renders the real values from the database at the end.
        $replace = $slots ? [
            '{product}' => $product?->name ?? 'পণ্যটা',
            '{price}' => '⟦price⟧',
            '{stock}' => '⟦stock⟧',
            '{delivery_inside}' => '⟦delivery_inside⟧',
            '{delivery_outside}' => '⟦delivery_outside⟧',
            '{honorific}' => $honorific ?? '',
        ] : [
            '{product}' => $product?->name ?? 'পণ্যটা',
            '{price}' => $product ? number_format($product->effectivePrice()) : '',
            '{stock}' => $product ? (string) max(0, $product->available()) : '',
            '{delivery_inside}' => number_format($inside),
            '{delivery_outside}' => number_format(120),
            '{honorific}' => $honorific ?? '',
        ];

        $rendered = (string) preg_replace('/ {2,}/', ' ', strtr($tpl, $replace));
        if ($honorific === null || $honorific === '') {
            // No address word this time — tidy the gaps its placeholder
            // leaves behind so the sentence still reads naturally.
            $rendered = (string) preg_replace('/^, /', '', $rendered);
            $rendered = str_replace(' ,', ',', $rendered);
            $rendered = str_replace(' ।', '।', $rendered);
            $rendered = str_replace(',,', ',', $rendered);
            $rendered = (string) preg_replace('/ {2,}/', ' ', $rendered);
        }

        return trim($rendered);
    }

    /** Fill ⟦slot⟧ tokens with the real figures from the database. */
    public function renderSlots(string $text, ?Product $product, int $businessId, array $extra = []): string
    {
        $inside = (float) $this->settings->bizGet($businessId, "business.{$businessId}.default_delivery_cost", 'business.default_delivery_cost', 60);
        $map = [
            '⟦price⟧' => $product ? number_format($product->effectivePrice()) : '',
            '⟦stock⟧' => $product ? (string) max(0, $product->available()) : '',
            '⟦delivery_inside⟧' => number_format($inside),
            '⟦delivery_outside⟧' => number_format(120),
        ];
        foreach ($extra as $k => $v) {
            $map['⟦' . $k . '⟧'] = is_numeric($v) ? number_format((float) $v) : (string) $v;
        }

        return strtr($text, $map);
    }

    /**
     * A money slot that rendered EMPTY — the text says "দাম ৳।" or
     * "দাম টাকা।" with no figure in it. Such a reply must never be sent;
     * the caller falls back to a clarification question instead.
     */
    public function hasBrokenFigure(string $rendered): bool
    {
        if (preg_match('/৳\s*([।,]|$)/u', $rendered)) {
            return true;
        }
        // Remove every well-formed "1,250 টাকা" amount first; a টাকা still
        // left standing at a sentence end had no figure in front of it.
        $stripped = preg_replace('/[0-9\x{09E6}-\x{09EF}][0-9,\x{09E6}-\x{09EF}]*\s*টাকা/u', '', $rendered);

        return (bool) preg_match('/টাকা\s*([।,]|$)/u', (string) $stripped);
    }

    /** Truth context for the claim guard, from live data. */
    private function claimCtx(int $businessId, ?Product $product, array $slotValues = []): array
    {
        // The ONE availability definition (Round 6, P10): Product::available()
        // - a combo is in stock when its COMPONENTS can still make one, even
        // though its own stock row is 0. When a variant is the focus (its
        // stock was slotted into the reply), the variant's own pool decides.
        $available = $product ? max(0, $product->available()) : null;
        if (isset($slotValues['stock'])) {
            $available = max(0, (int) $slotValues['stock']);
        }
        $price = isset($slotValues['offer']) ? (float) $slotValues['offer'] : ($product ? $product->effectivePrice() : 0.0);

        return [
            'in_stock' => $available === null || $available > 0,
            'free_delivery_ok' => $product !== null && $price >= $this->negotiation->config($businessId)['free_delivery_min'],
            'shop_phone' => Business::find($businessId)?->phone,
        ];
    }

    /**
     * Sellable units of the conversation's focus: the variant's own pool
     * when a variant is in focus, else the product's available(). Null
     * when there is no focus product to judge.
     */
    private function focusAvailable(?Product $product, ?\App\Models\ProductVariant $variant): ?int
    {
        if ($variant) {
            return $variant->available();
        }

        return $product ? max(0, $product->available()) : null;
    }

    /**
     * The honest zero-stock answer (Round 6, P1): the ONLY reply a
     * 0-available focus gets - no order invitation, no collection, no
     * promises the shop has not made itself.
     */
    private function outOfStockBody(int $businessId, ?string $customerName, Product $product, ?\App\Models\ProductVariant $variant = null): string
    {
        $name = $product->name . ($variant ? ' (' . $variant->name . ')' : '');

        return $this->honorificPrefix($businessId, $customerName) . $name . ' এখন স্টকে নেই। অন্য কোনো পণ্য দেখতে চাইলে বলুন।';
    }

    /**
     * How code-written replies address this customer — আপু, ভাইয়া or no
     * word at all. The mode comes from Settings (auto | apu | bhaiya |
     * none); auto reads the customer's name (see HonorificService).
     */
    private function honorific(int $businessId, ?string $customerName = null): string
    {
        return $this->honorifics->forName($businessId, $customerName);
    }

    private function honorificPrefix(int $businessId, ?string $customerName = null): string
    {
        $h = $this->honorific($businessId, $customerName);

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
        $behavior = $this->behaviorFor((int) $conversation->business_id);
        if (!($behavior['angry_holding'] ?? false)) {
            return null;
        }
        $mode = $auto[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }
        $mode = $this->applyWorkingHours((int) $conversation->business_id, $mode);
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
            'body' => 'আপনার বিষয়টা আমাদের অ্যাডমিনকে জানানো হয়েছে - কিছুক্ষণের মধ্যে উত্তর পাবেন।',
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
            $title = mb_strtolower(trim((string) $entry->title));
            $score = 0;
            foreach (preg_split('/\s+/u', $title) ?: [] as $word) {
                $word = trim($word, ' ,।?');
                if (mb_strlen($word) >= 3 && str_contains($hay, $word)) {
                    $score++;
                }
            }
            // One shared generic word must not let an entry answer a
            // question it is not about (Hunt Y2-12: "policy" alone sent
            // the delivery policy to a warranty question). An entry
            // qualifies on TWO title words, or when the customer says
            // the entry's whole title.
            $qualifies = $score >= 2 || ($title !== '' && str_contains($hay, $title));
            if ($qualifies && $score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return $best;
    }

    /** The order summary text + meta, shared by the direct and guided-collection paths. */
    private function buildSummary(Conversation $conversation, Product $product, ?\App\Models\ProductVariant $variant): array
    {
        // The shop's own delivery charge, through the same bizGet fallback
        // chain the delivery answer uses (Round 6, P6) - never a flat 60
        // the shop never set.
        $biz = (int) $conversation->business_id;
        $delivery = (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60);
        // A price agreed in bargaining is the price the customer pays —
        // the summary must not jump back to the full price.
        $unit = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : ($variant ? $variant->effectivePrice() : $product->effectivePrice());
        $total = $unit + $delivery;
        $itemName = $product->name . ($variant ? ' (' . $variant->name . ')' : '');
        // The quantity sits in parentheses, never "×1 - price": the
        // money guard reads "1 - 3,400 টাকা" as a price RANGE and the
        // summary would flag itself (Hunt Y2-11 fix regression).
        $body = $conversation->customer_name . ' · ' . ($conversation->customer_address ?: 'ঠিকানা বাকি') . "\n"
            . $itemName . ' (×1) ' . number_format($unit) . ' টাকা + delivery ' . number_format($delivery) . ' টাকা = মোট ' . number_format($total) . ' টাকা (Cash on Delivery)';
        // Cart snapshot: what this conversation is currently buying.
        $state = (array) ($conversation->state_data ?? []);
        $state['cart'] = [['product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'qty' => 1, 'agreed_price' => $unit]];
        $conversation->state_data = $state;
        $conversation->save();

        return ['body' => $body, 'meta' => ['product_id' => $product->id, 'total' => $total, 'unit_price' => $unit, 'variant_name' => $variant?->name]];
    }

    private function collectQuestion(int $businessId, string $step, ?string $customerName = null): string
    {
        $prefix = $this->honorificPrefix($businessId, $customerName);

        return $step === 'phone'
            ? $prefix . 'অর্ডারের জন্য আপনার মোবাইল নম্বরটা দিন - যে নম্বরে কুরিয়ার কল করবে।'
            : $prefix . 'ডেলিভারির ঠিকানাটা দিন - বাসা/হোল্ডিং, রোড, এলাকা, জেলা।';
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

    /**
     * Universal contact capture: scan EVERY inbound customer message
     * for contact details and persist what the conversation is still
     * missing. Until now this only happened inside the guided
     * collection flow, so a customer who sent their phone (and
     * address) inside the very message that expressed order intent
     * was asked for the number again, the details were never saved,
     * and a later order with that phone could not be linked back to
     * the conversation. A number already on the record is never
     * overwritten. The address is read from the same message that
     * carries the number (the collection flow's blob rule): when the
     * message labels it ("ঠিকানা: ..."), only that part is kept.
     */
    public function captureContactFromText(Conversation $conversation, string $text): void
    {
        $phone = $this->extractPhone($text);
        if ($phone === null) {
            return;
        }
        $changed = false;
        if (empty($conversation->customer_phone)) {
            $conversation->customer_phone = $phone;
            $changed = true;
        }
        if (empty($conversation->customer_address)) {
            // Same digit-normalised strip as the collection flow: the
            // customer may have typed the number in Bangla digits.
            $rest = trim(str_replace($phone, '', $this->guard->normalizeDigits($text)));
            if (preg_match('/(?:ঠিকানা|address)\s*[:：]\s*(.+)$/isu', $rest, $m)) {
                $addr = trim($m[1]);
            } else {
                $addr = trim((string) preg_replace('/^(ঠিকানা|address)\s*[:：]?\s*/iu', '', $rest));
            }
            $looksAddress = str_contains($addr, ',')
                || mb_strlen($addr) >= 15
                || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম/u', $addr);
            if ($looksAddress && mb_strlen($addr) >= 8) {
                $conversation->customer_address = $addr;
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }
        $conversation->save();

        // Sales Growth: a captured phone/address is stage-2 evidence,
        // wherever in the conversation it arrived.
        try {
            app(StageService::class)->noteContactShared($conversation);
        } catch (\Throwable) {
        }
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
        $mode = $this->autonomy((int) $conversation->business_id)[$conversation->channel] ?? 'draft';
        if ($mode === 'off') {
            return null;
        }
        if ($this->blocklistSilences($conversation)) {
            return null;
        }
        $mode = $this->applyWorkingHours((int) $conversation->business_id, $mode);

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

                return $this->simpleOutgoing($conversation, $this->honorificPrefix($biz, $conversation->customer_name) . 'ঠিক আছে, অর্ডারটা রাখা হলো না।', 'collect_cancel', $mode);
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

        // Sales Growth: a captured phone/address is stage-2 evidence.
        try {
            app(StageService::class)->noteContactShared($conversation);
        } catch (\Throwable) {
        }

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
            // Stock 0 = NO order (owner addendum, Round 6 P1): if the item
            // sold out while the details were being collected, the sale
            // stops here with the honest answer - no summary, no order.
            if ($this->focusAvailable($product, $variant) <= 0) {
                return $this->simpleOutgoing($conversation, $this->outOfStockBody($biz, $conversation->customer_name, $product, $variant), 'out_of_stock', $mode, ['out_of_stock' => true]);
            }
            $summary = $this->buildSummary($conversation, $product, $variant);
            $msg = ChatMessage::create([
                'business_id' => $biz,
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'sender' => 'ai',
                'body' => $summary['body'],
                // An order summary commits the shop to a price and an
                // order: in plain Auto it stays a draft like every
                // other committing reply (Hunt Y2-8) - only Full auto
                // sends it.
                'status' => $mode === 'full_auto' ? 'sent' : 'draft',
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

        return $this->simpleOutgoing($conversation, $this->collectQuestion($biz, $next, $conversation->customer_name), 'collect_question', $mode, ['collect' => $next]);
    }

    /**
     * Every figure that could honestly be stated right now.
     *
     * With a focus product the set is PER-PRODUCT (Round 6, P4): the
     * product's own price and campaign price, its variants' prices
     * (Round 6, P3 - regular and effective), its negotiation ladder, the
     * shop's delivery charges, and totals honestly derived from those
     * (price + delivery). A figure that merely exists somewhere else in
     * the shop - another product's price quoted for this one - is NOT in
     * the set and fails the check. Without a focus product (comment
     * replies, settings previews) the legacy shop-wide set applies.
     */
    public function allowedFigures(int $businessId, ?Product $product): array
    {
        $inside = (float) $this->settings->bizGet($businessId, "business.{$businessId}.default_delivery_cost", 'business.default_delivery_cost', 60);
        $figures = [$inside, 120.0, $this->negotiation->config($businessId)['free_delivery_min']];
        if ($product) {
            $prices = [(float) $product->price, $product->effectivePrice()];
            foreach ($product->activeVariants() as $v) {
                if ($v->price !== null) {
                    $prices[] = (float) $v->price;
                }
                $prices[] = $v->effectivePrice();
            }
            foreach ($this->negotiation->ladder($product) as $rung) {
                $prices[] = (float) $rung;
            }
            foreach (array_unique($prices) as $p) {
                $figures[] = $p;
                $figures[] = $p + $inside;
                $figures[] = $p + 120.0;
            }

            return $figures;
        }
        $figures[] = 60.0;
        foreach (Product::where('business_id', $businessId)->get() as $p) {
            $figures[] = (float) $p->price;
            $figures[] = $p->effectivePrice();
            foreach ($p->activeVariants() as $v) {
                if ($v->price !== null) {
                    $figures[] = (float) $v->price;
                }
                $figures[] = $v->effectivePrice();
            }
            foreach ($this->negotiation->ladder($p) as $rung) {
                $figures[] = (float) $rung;
                $figures[] = (float) $rung + 60.0;
                $figures[] = (float) $rung + 120.0;
            }
        }

        return $figures;
    }

    /**
     * Pre-send verification: every money figure in the text must be a real
     * one. Figures are extracted in every written form (Bangla digits,
     * টাকা/tk suffixes, number words, ranges) via the guard, so changing
     * the script or wording cannot bypass the check. $extraFigures carries
     * figures that are honest for THIS conversation only (a bargained
     * offer the customer named and the shop accepted, and its totals).
     */
    public function moneyCheck(int $businessId, string $text, ?Product $product, array $extraFigures = []): bool
    {
        $figures = $this->guard->extractFigures($text);
        if ($figures === []) {
            return true;
        }
        $allowed = array_merge($this->allowedFigures($businessId, $product), $extraFigures);
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

        // Sales Growth stage layer: an inbound message is stage-0
        // evidence. Insights must never break message intake.
        try {
            app(StageService::class)->noteInbound($conversation, $incoming);
        } catch (\Throwable) {
        }

        return $incoming;
    }

    private function debounceSeconds(int $businessId): int
    {
        return max(0, (int) ($this->behaviorFor($businessId)['debounce_seconds'] ?? 0));
    }

    /**
     * Burst handling: customers often send three short messages in a row.
     * The webhook stores them and this sweep answers the whole burst once,
     * after a quiet window — never three separate replies.
     */
    public function processPending(Conversation $conversation): ?ChatMessage
    {
        // One runner at a time per conversation: the queued job and the
        // ai:process-pending sweep can overlap (both run every minute),
        // and each used to see the same unprocessed burst — the customer
        // got the same reply twice. Whoever holds the lock answers; the
        // other run finds nothing to do.
        $lock = Cache::store('file')->lock('ai-pending-' . $conversation->id, 60);
        if (! $lock->acquire()) {
            return null;
        }
        try {
            return $this->processPendingLocked($conversation);
        } finally {
            $lock->release();
        }
    }

    private function processPendingLocked(Conversation $conversation): ?ChatMessage
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
        if ($latest->created_at && $latest->created_at->gt(now()->subSeconds($this->debounceSeconds((int) $conversation->business_id)))) {
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

        // Contact details can arrive in ANY message — most importantly
        // in the same message that starts an order. Capture them onto
        // the record before anything branches on what is still
        // missing, so a collection flow that starts because of this
        // message consumes what the message already carried.
        if ($text !== '') {
            $this->captureContactFromText($conversation, $text);
        }

        if ($text === '' && ! empty($attachment)) {
            // Media-only message (photo, voice note, sticker). It is stored
            // and visible in the inbox; understanding it is a separate step
            // (image scan / transcription) — the brain never guesses.
            $this->log($biz, 'media_received', $conversation->customer_name . ' — ' . ($attachment['type'] ?? 'media'), null, $conversation);

            return null;
        }

        $auto = $this->autonomy($biz);
        if ($auto['panic'] || $conversation->ai_paused) {
            return null;
        }

        if ($this->blocklistSilences($conversation)) {
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

        // Sales Growth: record the FINAL intent for this message —
        // the deterministic keyword result first; the AI classifier's
        // fallback intent only when the keywords decided nothing.
        // When both exist and disagree, the rule result wins and the
        // disagreement is logged for the classifier audit sample.
        try {
            $stageSvc = app(StageService::class);
            $aiIntent = $stage2['intent'] ?? null;
            if ($intent !== null && $aiIntent !== null && $intent !== $aiIntent) {
                $stageSvc->noteDisagreement($conversation, $incoming, (string) $intent, (string) $aiIntent);
            }
            $finalIntent = $intent ?? $aiIntent;
            $finalSource = $intent !== null ? 'rule' : 'ai';
            if ($rule && $rule->match_text === 'spam') {
                $finalIntent = 'spam';
                $finalSource = $intent === 'spam' ? 'rule' : $finalSource;
            }
            if ($finalIntent !== null) {
                $stageSvc->noteIntent($conversation, $incoming, (string) $finalIntent, $finalSource);
            }
        } catch (\Throwable) {
            // Insights must never break the reply flow.
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

        $mode = $this->applyWorkingHours($biz, $auto[$conversation->channel] ?? 'draft');
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
            $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . $product->name . '-এর কোনটা চাইছেন? আছে: ' . $names . '। কোনটা নেবেন, বলুন।';
            $kind = 'variant_question';
            $meta = ['variant_question' => true];
            $state = (array) ($conversation->state_data ?? []);
            $state['variant_pending'] = ['product_id' => $product->id, 'intent' => $rule->match_text];
            $conversation->state_data = $state;
            $conversation->save();
        } elseif ($rule && in_array($rule->match_text, ['order_intent', 'bargain'], true) && $product
            && ($this->focusAvailable($product, $variant) ?? 1) <= 0) {
            // Stock 0 = NO order (owner addendum, Round 6 P1): the sale
            // does not progress at all - no bargain quote, no collection,
            // no summary. The honest out-of-stock answer is the whole reply.
            $slottedBody = $this->outOfStockBody($biz, $conversation->customer_name, $product, $variant);
            $kind = 'out_of_stock';
            $meta = ['out_of_stock' => true];
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
            $total = $offer + (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60);
            $h = $this->honorific($biz, $conversation->customer_name);
            $first = match ($quote['kind']) {
                'accept' => 'ঠিক আছে' . ($h !== '' ? ' ' . $h : '') . ', আপনার কথামতোই ⟦offer⟧ টাকায় দিচ্ছি।',
                'floor_repeat' => ($h !== '' ? $h . ', ' : '') . '⟦offer⟧ টাকাই শেষ দাম - এর কমে আর হচ্ছে না।',
                default => 'বুঝতে পারছি' . ($h !== '' ? ' ' . $h : '') . '। আপনার জন্য ⟦offer⟧ টাকায় দিতে পারব।',
            };
            $slottedBody = $first . ' ডেলিভারি চার্জসহ মোট ⟦total⟧ টাকা (ঢাকার ভিতরে)। অর্ডার করবেন?';
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
                $slottedBody = $this->collectQuestion($biz, $missing[0], $conversation->customer_name);
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
            $slottedBody = $this->buildReply($rule, $product, $biz, true, $this->honorific($biz, $conversation->customer_name));
            if (trim($slottedBody) === '') {
                return null;
            }
            if ($rule->match_text === 'stock' && $product && ($this->focusAvailable($product, $variant) ?? 1) <= 0) {
                // The stock template's zero branch (Round 6, P1): driven by
                // Product::available() (the variant's own pool when a
                // variant is in focus), the honest out-of-stock answer
                // replaces the "in stock ({stock}টা)" template outright.
                $slottedBody = $this->outOfStockBody($biz, $conversation->customer_name, $product, $variant);
                $kind = 'out_of_stock';
                $meta = ['out_of_stock' => true];
            } elseif ($this->hasBrokenFigure($this->renderSlots($slottedBody, $product, $biz))) {
                $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'কোন পণ্যটার কথা বলছেন? পণ্যের নামটা বললে দাম আর স্টক জানিয়ে দিচ্ছি।';
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
                $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'আপনার অর্ডার (⟦invoice⟧) এখন ⟦status⟧ অবস্থায় আছে।';
                $slotValues = ['invoice' => $order->invoice_no, 'status' => (string) $order->status];
                $meta = ['order_status' => $order->id];
            } else {
                $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'আপনার নামে কোনো অর্ডার খুঁজে পাইনি। অর্ডারের নম্বরটা দিলে দেখে দিচ্ছি।';
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
                    ? $this->honorificPrefix($biz, $conversation->customer_name) . $desc
                    : $this->honorificPrefix($biz, $conversation->customer_name) . $product->name . '-এর দাম ⟦price⟧ টাকা। আর কিছু জানতে চাইলে বলুন।';
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
        } elseif ($kind === 'variant_question' || $kind === 'collect_question' || $kind === 'out_of_stock') {
            // Fixed questions and the out-of-stock answer must reach the
            // customer word-for-word — no phrasing.
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

        // Figures honest for THIS conversation only: a bargained offer
        // (this message's, or the one already agreed on the conversation)
        // and its delivery-inclusive totals.
        $extraFigures = [];
        if (isset($slotValues['offer'])) {
            $extraFigures[] = (float) $slotValues['offer'];
        }
        if (isset($slotValues['total'])) {
            $extraFigures[] = (float) $slotValues['total'];
        }
        if ($conversation->neg_offer !== null) {
            $neg = (float) $conversation->neg_offer;
            $insideNow = (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60);
            $extraFigures = array_merge($extraFigures, [$neg, $neg + $insideNow, $neg + 120.0]);
        }
        // The guards run over EVERY auto-send, whatever produced the text
        // (template, keyword rule or talker) - claimViolations no longer
        // exempts text that merely equals the draft (Round 6, P1).
        $verified = $this->moneyCheck($biz, $body, $product, $extraFigures);
        $claimIssues = $this->guard->claimViolations($biz, $draftRendered, $body, $this->claimCtx($biz, $product, $slotValues));
        // Plain Auto is "simple answers only" (its Settings label):
        // a reply that commits a price - a bargaining offer or an
        // order summary - stays a draft for a human in Auto, and only
        // Full auto sends it (Hunt Y2-8).
        $committing = $kind === 'order_summary' || isset($meta['offer']);
        if (!$verified || $claimIssues !== []) {
            $status = 'flagged';
            if ($claimIssues !== []) {
                $meta = array_merge($meta ?? [], ['guard' => $claimIssues]);
            }
        } elseif ($mode === 'full_auto' || ($mode === 'auto' && ! $committing)) {
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
            // One message, one outcome: the AI just answered this
            // customer itself, so a needs-human flag left over from an
            // earlier message must not keep the thread flagged — the
            // inbox would show "AI replied" and "Needs a human" at once.
            $conversation->needs_human = false;
            $conversation->save();
        }

        return $msg;
    }

    /**
     * A staff member approves a draft (optionally after editing it).
     * Returns the message when THIS call handled it (sent, or held
     * flagged), and null when another approver already handled it -
     * the caller must read null as an honest "already handled"
     * refusal, never as a send.
     */
    public function approveDraft(ChatMessage $msg, ?string $editedBody, ?int $userId): ?ChatMessage
    {
        return DB::transaction(function () use ($msg, $editedBody, $userId) {
            // State discipline under concurrency (Hunt Y2-1/Y2-2/Y2-4,
            // closed for the concurrent case by Hunt Z1-8): the guard
            // used to test the status on each request's own in-memory
            // copy, so two staff approving the same draft at the same
            // moment both passed it and both ran the full send path.
            // The guard now runs on the row locked inside this
            // transaction: the second approver waits on the lock,
            // re-reads the winner's committed status, and gets null -
            // which the controller turns into the already-handled
            // refusal BEFORE any dispatch or order creation can run.
            // Approving a sent or rejected message must never re-run
            // the send path, re-log a send, or create a second order.
            $locked = ChatMessage::where('id', $msg->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, ['draft', 'flagged'], true)) {
                return null;
            }

            return $this->approveDraftLocked($locked, $editedBody, $userId);
        });
    }

    /** approveDraft()'s body, run on the locked row inside its transaction. */
    private function approveDraftLocked(ChatMessage $msg, ?string $editedBody, ?int $userId): ChatMessage
    {
        $edited = $editedBody !== null && trim($editedBody) !== '' && trim($editedBody) !== $msg->body;

        // Send-time re-verification (Round 6, P5): approval is a SEND, so
        // the money + availability guards run again, now, against LIVE
        // data. A draft written when the Saree cost 3,400 must not go out
        // still quoting 3,400 after the price moved to 3,600 - it is held
        // (flagged, with the reason on the record) for the approver to
        // edit, never sent as-is.
        $biz = (int) $msg->business_id;
        $candidate = $edited ? trim((string) $editedBody) : (string) $msg->body;
        $conv = $msg->conversation;
        $focus = null;
        $focusId = (int) ($conv?->product_id ?? 0) ?: (int) ($msg->meta['product_id'] ?? 0);
        if ($focusId > 0) {
            $focus = Product::where('business_id', $biz)->find($focusId);
        }
        $extras = [];
        if ($conv && $conv->neg_offer !== null) {
            $neg = (float) $conv->neg_offer;
            $insideNow = (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60);
            $extras = [$neg, $neg + $insideNow, $neg + 120.0];
        }
        if (!empty($msg->meta['unit_price'])) {
            $unit = (float) $msg->meta['unit_price'];
            $insideNow = (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60);
            $extras = array_merge($extras, [$unit, $unit + $insideNow, $unit + 120.0]);
        }
        $slotValues = [];
        if ($focus && !empty($msg->meta['variant_name'])) {
            $heldVariant = $focus->variants()->where('name', (string) $msg->meta['variant_name'])->first();
            if ($heldVariant) {
                $slotValues['stock'] = $heldVariant->available();
            }
        }
        $moneyOk = $this->moneyCheck($biz, $candidate, $focus, $extras);
        $claimIssues = $this->guard->claimViolations($biz, $candidate, $candidate, $this->claimCtx($biz, $focus, $slotValues));
        if (!$moneyOk || $claimIssues !== []) {
            $reasons = $claimIssues;
            if (!$moneyOk) {
                $reasons[] = 'money';
            }
            $msg->status = 'flagged';
            $msg->meta = array_merge((array) ($msg->meta ?? []), [
                'guard' => $reasons,
                'hold_reason' => __('This reply was held because its figures no longer match the live price or stock. Edit the reply, then approve again.'),
            ]);
            $msg->save();
            $this->log($biz, 'flagged', ($conv?->customer_name ?? '') . ' — draft held at approval: figures no longer match live data', $msg->rule, $conv);

            return $msg;
        }

        if ($edited) {
            // Learning loop: the edit becomes a proposal — a human still has
            // to approve it in Settings before any rule changes. The
            // proposal is a RECORD of the edit (note_only, Hunt Y2-7):
            // it carries no rule change, and Settings presents it that
            // way instead of claiming the AI learned from it.
            AiRuleProposal::create([
                'business_id' => $msg->business_id,
                'title' => 'Staff edit থেকে শেখা - reply বদলানো হয়েছে',
                'detail' => 'আগের draft: "' . $msg->body . '" → staff পাঠিয়েছে: "' . trim($editedBody) . '"',
                'rule_data' => ['source_message_id' => $msg->id, 'edited_body' => trim($editedBody), 'note_only' => true],
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

    /**
     * Reject a pending draft. Returns false when the draft was
     * already handled (the same locked guard as approveDraft, Z1-8),
     * so the caller can answer honestly instead of claiming a
     * rejection it did not perform.
     */
    public function rejectDraft(ChatMessage $msg): bool
    {
        return DB::transaction(function () use ($msg) {
            $locked = ChatMessage::where('id', $msg->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, ['draft', 'flagged'], true)) {
                // Only a pending draft can be rejected (Hunt Y2-3): a sent
                // message the customer received must never be rewritten
                // as "rejected" and hidden from the thread.
                return false;
            }
            $locked->status = 'rejected';
            $locked->save();
            // A rejection is a decision about an AI reply — it belongs in
            // the activity log like approvals, holds and edits (Y2-5).
            $this->log((int) $locked->business_id, 'draft_rejected', ($locked->conversation?->customer_name ?? '') . ' - draft rejected', $locked->rule, $locked->conversation);

            return true;
        });
    }

    /** Incoming comment: spam hides itself; questions get a drafted reply. */
    public function handleComment(Comment $comment): ?CommentReply
    {
        $biz = (int) $comment->business_id;
        $auto = $this->autonomy($biz);
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
        $slottedBody = $this->buildReply($rule, $product, $biz, true, $this->honorific($biz, $comment->author_name ?? null));
        if ($rule->match_text === 'stock' && $product && $product->available() <= 0) {
            // Same zero branch as the inbox (Round 6, P1): a public reply
            // never claims stock the shop does not have.
            $slottedBody = $this->outOfStockBody($biz, $comment->author_name ?? null, $product);
        }
        $draftRendered = $this->renderSlots($slottedBody, $product, $biz);
        if (trim($slottedBody) === '' || $this->hasBrokenFigure($draftRendered)) {
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
        $mode = $this->cautious($this->applyWorkingHours($biz, $auto['comment'] ?? 'draft'));
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

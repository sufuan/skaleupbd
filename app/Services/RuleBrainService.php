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
        'bargain' => ['কমান', 'কমাতে', 'discount', 'কম হবে', 'কম রাখেন', 'dam kom', 'less hobe', 'kom hobe', 'ektu kom', 'komano', 'শেষ দাম', 'কম দাম', 'কমে দেন', 'দিলে নিব', 'টাকায় দিলে'],
        'spam' => ['follower', 'ফলোয়ার', 'সস্তায় follower', 'cheap follower', 'like কিনুন', 'free money', 'click here', 'click link', 'scam', 'earn money', 'make money', 'casino', 'ক্যাসিনো', 'betting', 'বাজি', 'ফ্রি টাকা', 'টাকা ইনকাম', 'লিংকে ক্লিক', 'crypto', 'double investment', 'lottery', 'লটারি'],
        'angry' => ['পাইনি', 'পাই নাই', 'রেগে', 'complain', 'অভিযোগ', 'দুই দিন হলো', 'এখনো আসেনি', 'প্রতারক', 'প্রতারণা', 'টাকা মেরে', 'ভুয়া', 'মামলা', 'পুলিশ', 'কোর্ট', 'ভোক্তা অধিকার', 'ঠকিয়ে'],
        'order_intent' => ['order confirm', 'অর্ডার কনফার্ম', 'কনফার্ম', 'confirm korlam', 'confirm korun', 'confirm koren', 'confirm korchi', 'নিতে চাই', 'order korte chai', 'অর্ডার করতে চাই', 'কনফার্ম করেন', 'কনফার্ম করুন', 'কনফার্ম করে দেন', 'অর্ডার করলাম', 'অর্ডার দিলাম'],
        // Fine-grained intents (Master Library): each exists because the
        // coarse intents above would otherwise swallow the case — a
        // delivery-charge negotiation contains the delivery AND bargain
        // words, a COD question contains the delivery word, "এখনো পাইনি"
        // contains the angry word. detectIntent checks these BEFORE the
        // coarse intent they shadow. The keyword lists are the library's
        // own, kept beside the coarse ones so there is one place to mine.
        'legal_threat' => ['মামলা', 'আইনি ব্যবস্থা', 'আইনগত', 'ভোক্তা অধিকার', 'কনজিউমার', 'থানায়', 'পুলিশ', 'সাইবার', 'কোর্ট', 'রিপোর্ট করব', 'mamla', 'legal action', 'aini babostha', 'consumer', 'thanay', 'police', 'cyber', 'report korbo', 'i will report', 'sue'],
        'payment_proof' => ['টাকা পাঠিয়েছি', 'টাকা পাঠালাম', 'পেমেন্ট করেছি', 'পেমেন্ট করলাম', 'ট্রানজেকশন', 'ট্রান্সফার করেছি', 'স্ক্রিনশট', 'TrxID', 'taka pathiyechi', 'taka pathalam', 'payment korechi', 'payment done', 'paid', 'sent money', 'transaction id', 'trx id', 'screenshot'],
        'payment_problem' => ['ভুল নম্বরে পাঠিয়েছি', 'ভুল নাম্বারে', 'টাকা কেটে নিয়েছে', 'টাকা কেটেছে কিন্তু', 'পেমেন্ট হয়নি', 'পেমেন্ট ফেইল', 'vul number e pathiyechi', 'taka kete niyeche', 'payment hoyni', 'payment failed', 'money deducted', 'wrong number'],
        'cancel_order' => ['অর্ডার ক্যান্সেল', 'অর্ডার বাতিল', 'অর্ডারটা বাতিল', 'ক্যান্সেল করে দিন', 'বাতিল করে দিন', 'order cancel', 'cancel my order', 'cancel korun', 'cancel koren', 'cancel the order'],
        'late_delivery' => ['এখনো পাইনি', 'এখনও পাইনি', 'এখনো আসেনি', 'পাচ্ছি না', 'অনেক দেরি', 'কতদিন আর', 'দেরি হচ্ছে', 'কুরিয়ার দিচ্ছে না', 'ekhono paini', 'ekhono ashe nai', 'pacchi na', 'onek deri', 'deri hocche', 'not received yet', 'still not received', 'delayed', 'courier not delivering'],
        'wholesale' => ['পাইকারি', 'পাইকারী', 'হোলসেল', 'রিসেল', 'রিসেলার', 'ডিলার', 'ডিস্ট্রিবিউটর', 'বাল্ক', 'অনেক পিস', 'কার্টন', 'ডজন', 'বেশি পরিমাণে', 'paikari', 'wholesale', 'whole sale', 'reseller', 'resell', 'dealer', 'distributor', 'bulk', 'bulk order', 'dozen', 'carton'],
        'wants_human' => ['অ্যাডমিন কই', 'এডমিন কই', 'এডমিন কে দেন', 'কেউ আছেন', 'মানুষ কেউ', 'রিয়েল মানুষ', 'কথা বলতে চাই', 'কল দিন', 'কল করুন', 'ফোন করুন', 'ফোন নম্বর দিন', 'কল করব', 'হোয়াটসঅ্যাপ নম্বর', 'admin kothay', 'admin ke den', 'keu achen', 'manush keu', 'kotha bolte chai', 'call din', 'call korun', 'phone number din', 'call me', 'speak to someone', 'real person', 'talk to human'],
        'are_you_bot' => ['আপনি কি রোবট', 'আপনি কি বট', 'এআই নাকি মানুষ', 'মেশিন নাকি', 'রোবট নাকি', 'robot naki', 'bot naki', 'ai naki manush', 'are you a bot', 'are you ai', 'are you human', 'is this a bot'],
        'change_order' => ['অর্ডার চেঞ্জ', 'অর্ডার বদলাতে', 'অর্ডারে পরিবর্তন', 'ঠিকানা বদলাতে', 'ঠিকানা পরিবর্তন', 'ঠিকানা ভুল দিয়েছি', 'নাম্বার বদলাতে', 'আরেকটা যোগ', 'পরিমাণ বদলাতে', 'order change', 'order e poriborton', 'address change', 'thikana bodlate', 'thikana vul', 'change address', 'change quantity', 'add one more'],
        'open_box' => ['খুলে দেখে', 'খুলে দেখতে পারব', 'দেখে নিতে পারব', 'চেক করে নিব', 'ওপেন ডেলিভারি', 'khule dekhte parbo', 'dekhe nite parbo', 'open delivery', 'open box', 'check before payment', 'can i check before paying'],
        'competitor_price' => ['অন্য পেজে', 'অন্য জায়গায় কম', 'অন্য দোকানে কম', 'ওরা কম দিচ্ছে', 'বাজারে কম', 'onno page e', 'onno dokane kom', 'ora kom dicche', 'cheaper elsewhere', 'found cheaper', 'competitor'],
        'price_mismatch' => ['পোস্টে কম ছিল', 'বিজ্ঞাপনে কম দেখেছিলাম', 'এডে কম ছিল', 'আগে কম ছিল', 'দাম বাড়িয়েছেন', 'দাম বেড়ে গেছে', 'post e kom chilo', 'ad e kom chilo', 'age kom chilo', 'dam barayechen', 'price increased', 'was cheaper'],
        'delivery_negotiation' => ['ডেলিভারি চার্জ কমান', 'ডেলিভারি চার্জ কম', 'চার্জ কমানো যায়', 'ডেলিভারি ফ্রি', 'ফ্রি ডেলিভারি', 'চার্জ ফ্রি', 'delivery charge komano', 'charge kom', 'delivery free', 'free delivery', 'reduce delivery charge'],
        'restock' => ['আবার কবে আসবে', 'স্টকে কবে আসবে', 'আবার স্টক', 'রিস্টক', 'আসলে জানাবেন', 'abar kobe asbe', 'stock e kobe asbe', 'restock', 'back in stock', 'when will it come back', 'available again'],
        'advance_payment' => ['অ্যাডভান্স', 'এডভান্স', 'অগ্রিম', 'আগে টাকা', 'কিছু টাকা আগে', 'অর্ধেক টাকা', 'আংশিক', 'advance', 'advanced', 'partial payment', 'half payment', 'agey taka', 'advance dite hobe'],
        'cod' => ['ক্যাশ অন ডেলিভারি', 'ক্যাশ অন', 'ক্যাশঅন', 'হাতে পেয়ে টাকা', 'ডেলিভারির সময় টাকা', 'cod ache', 'cod', 'cash on delivery', 'cash on', 'pay on delivery', 'hate peye taka'],
        'delivery_time' => ['কতদিনে পাব', 'কত দিন লাগবে', 'কয়দিন লাগবে', 'ডেলিভারি টাইম', 'কতক্ষণ লাগবে', 'কবে নাগাদ', 'kobe pabo', 'koto dine pabo', 'koydin lagbe', 'delivery time', 'how long', 'how many days', 'when will i get'],
        'delivery_coverage' => ['আমার এলাকায় ডেলিভারি', 'সব জায়গায় ডেলিভারি', 'সারা দেশে', 'গ্রামে ডেলিভারি', 'উপজেলায় ডেলিভারি', 'কোন কুরিয়ারে', 'কুরিয়ারে পাঠান', 'সুন্দরবন', 'home delivery hoy', 'amar elakay delivery', 'shara deshe', 'courier e pathan', 'sundarban courier', 'which courier', 'do you deliver to'],
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

    /**
     * Does this message ACCEPT an offer that is already on the table?
     * Only consulted mid-negotiation (an offer exists), where "confirm",
     * "রাজি", "ঠিক আছে" can only mean one thing: the deal is agreed.
     * Conditional bargain language ("দিলে নিব") is deliberately NOT here —
     * that is a counter-offer, and NegotiationService::quote judges it
     * against the floor.
     */
    private function isAcceptance(string $hay): bool
    {
        foreach (['confirm', 'কনফার্ম', 'রাজি', 'ঠিক আছে', 'হ্যাঁ', 'হ্যা', 'ওকে', 'ok', 'okay', 'নিচ্ছি', 'নিয়ে নিন', 'পাঠিয়ে দিন', 'অর্ডার করুন', 'order korun'] as $w) {
            if (str_contains($hay, $w)) {
                return true;
            }
        }

        return false;
    }

    public function detectIntent(string $text, ?Conversation $conversation = null): ?string
    {
        if ($this->isReaction($text)) {
            return 'reaction';
        }
        $hay = mb_strtolower($text);
        // Fine-grained intents first, in the library's priority order:
        // each of these contains words a coarser intent would claim
        // (legal words sit inside the angry list, "এখনো পাইনি" is an
        // angry word, a COD question is full of delivery words), so the
        // specific case must be judged before the coarse one.
        foreach (['legal_threat', 'spam', 'payment_proof', 'payment_problem', 'cancel_order', 'late_delivery', 'angry', 'wholesale', 'wants_human', 'are_you_bot', 'change_order', 'open_box', 'competitor_price', 'price_mismatch', 'delivery_negotiation', 'restock', 'advance_payment'] as $fine) {
            if ($this->intentMatches($fine, $hay)) {
                return $fine;
            }
        }
        if ($conversation && (int) $conversation->neg_step >= 1) {
            // Mid-bargaining. A confirmation or an order signal ENDS the
            // bargaining here — it is never another ladder round, even
            // when the message carries digits (a phone number does).
            // Only a fresh push for less continues the ladder.
            if ($this->intentMatches('order_intent', $hay) || $this->isAcceptance($hay)) {
                return 'order_intent';
            }
            // A price QUESTION is not a ladder push (brain fix batch 2):
            // "dam koto?" mid-bargaining asks what the price IS — it
            // must be answered as a price question, never routed into
            // the bargain branch where a floor-reached ladder would
            // hand the customer to a human for asking (live 2026-11-25
            // 15:00). A real push for less carries a bargain word
            // ("কমান", "শেষ দাম", "দিলে নিব") and stays a bargain.
            $asksPrice = (str_contains($hay, 'দাম') || str_contains($hay, 'price') || (bool) preg_match('/\bdam\b/', $hay))
                && (str_contains($hay, 'কত') || str_contains($hay, 'koto') || str_contains($hay, '?'));
            if ($asksPrice && ! $this->intentMatches('bargain', $hay)) {
                return 'price';
            }
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
        // Delivery sub-questions (COD? how long? do you deliver here?)
        // before the coarse delivery intent swallows them — they are
        // different questions with different answers.
        foreach (['cod', 'delivery_time', 'delivery_coverage'] as $fine) {
            if ($this->intentMatches($fine, $hay)) {
                return $fine;
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
        // appear at all (priority intents first, as before). Keyword
        // rules compete on SPECIFICITY — the longest matched keyword
        // wins, ties keep install order — because a rule learned from
        // staff edits keys on the customer's exact phrasing (long and
        // specific) and must beat a generic one-word catalog keyword,
        // or the learning loop could never fire.
        $hay = mb_strtolower($text);
        $priority = ['spam', 'angry', 'bargain', 'order_intent'];
        $sorted = $rules->sortBy(fn ($r) => in_array($r->match_text, $priority, true) ? 0 : 1)->values();
        $bestKeyword = null;
        $bestLen = -1;
        foreach ($sorted as $rule) {
            if (!$matchesChannel($rule)) {
                continue;
            }
            if ($rule->trigger_type === 'keyword') {
                foreach (array_map('trim', explode(',', (string) $rule->match_text)) as $kw) {
                    if ($kw !== '' && str_contains($hay, mb_strtolower($kw))) {
                        $len = mb_strlen($kw);
                        if ($len > $bestLen) {
                            $bestLen = $len;
                            $bestKeyword = $rule;
                        }
                    }
                }
            } elseif ($this->intentMatches($rule->match_text, $hay)) {
                return $bestKeyword ?? $rule;
            }
        }

        return $bestKeyword;
    }

    /**
     * The product this message EXPLICITLY names, if any — judged on
     * strong signals only: a SKU, a seller-taught alias, the product's
     * full name, or every word of its name. Weaker evidence (a shared
     * generic word, a partial name) must never yank the conversation
     * off the product it is about, so it is not enough here — the
     * scored matching in productFor() handles those messages. When two
     * products qualify equally (names differing only by a digit the
     * word split drops), the mention is ambiguous and null wins.
     */
    public function explicitProductFor(int $businessId, string $text): ?Product
    {
        $hay = mb_strtolower($text);
        $products = Product::where('business_id', $businessId)->orderBy('id')->get();

        // SKU, then alias, then full name — in each pass the LONGEST
        // match wins, so "CRUD-102" beats a shorter nested code and
        // "CRUD Test Panjabi 2" beats its own prefix "CRUD Test Panjabi".
        foreach (['sku', 'aliases'] as $field) {
            $best = null;
            $bestLen = -1;
            foreach ($products as $p) {
                $needles = $field === 'sku'
                    ? [(string) ($p->sku ?? '')]
                    : array_map('trim', explode(',', (string) ($p->aliases ?? '')));
                foreach ($needles as $needle) {
                    $needle = mb_strtolower($needle);
                    if ($needle !== '' && str_contains($hay, $needle) && mb_strlen($needle) > $bestLen) {
                        $best = $p;
                        $bestLen = mb_strlen($needle);
                    }
                }
            }
            if ($best) {
                return $best;
            }
        }
        $best = null;
        $bestLen = -1;
        foreach ($products as $p) {
            $name = mb_strtolower($p->name);
            if ($name !== '' && str_contains($hay, $name) && mb_strlen($name) > $bestLen) {
                $best = $p;
                $bestLen = mb_strlen($name);
            }
        }
        if ($best) {
            return $best;
        }
        $qualifiers = [];
        foreach ($products as $p) {
            $words = array_values(array_filter(
                preg_split('/\s+/', mb_strtolower($p->name)) ?: [],
                fn ($w) => mb_strlen($w) >= 4
            ));
            if ($words === []) {
                continue;
            }
            $all = true;
            foreach ($words as $w) {
                if (! str_contains($hay, $w)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $qualifiers[] = $p;
            }
        }

        return count($qualifiers) === 1 ? $qualifiers[0] : null;
    }

    /**
     * Product switching (brain fix batch 2): when a message explicitly
     * names a DIFFERENT product, the conversation changes product —
     * and the old product's negotiation dies with the switch. Live
     * 2026-11-25: a days-old thread that had bargained CRUD-101 to its
     * floor answered "CRUD-102 er dam koto?" with CRUD-101's floor
     * line, because productFor() trusted the stored focus and
     * detectIntent read the stale neg_step. A new product means a
     * fresh ladder (no step, no standing offer, no floor-repeat
     * memory), no half-finished collection, no cart and no variant
     * carried over from the old product.
     */
    private function switchProductIfNamed(Conversation $conversation, string $text): void
    {
        $named = $this->explicitProductFor((int) $conversation->business_id, $text);
        if ($named === null || (int) $conversation->product_id === (int) $named->id) {
            return;
        }
        $conversation->product_id = $named->id;
        $conversation->focus_at = now();
        $conversation->neg_step = 0;
        $conversation->neg_offer = null;
        if ($conversation->state === 'collecting') {
            $conversation->state = null;
        }
        $state = (array) ($conversation->state_data ?? []);
        foreach (['collect', 'cart', 'variant_pending', 'variant_id', 'floor_repeats', 'pending_summary', 'last_order', 'product_clarify'] as $key) {
            unset($state[$key]);
        }
        // Details are PER-FLOW (brain fix batch 3): the values a
        // previous product's flow collected stay on the record (the
        // order-status lookup and the channel identity read them) but
        // they stop COUNTING for the new product — its summary may
        // only be built from details the customer states in THIS
        // flow. Live 2026-11-25 15:52: an acceptance for a switched
        // product summarised instantly with the phone and address a
        // shipped order from days earlier had left behind, because
        // the switch reset the negotiation but not the details flag.
        // A detail stated in the switch message itself ("CRUD-101
        // order korte chai, phone 01..., address ...") belongs to the
        // NEW flow, so it is marked fresh as it is consumed.
        $state['details_consumed'] = true;
        $fresh = ['name' => false, 'phone' => false, 'address' => false];
        if ($this->extractName($this->guard->normalizeDigits($text)) !== null) {
            $fresh['name'] = true;
        }
        $switchPhone = $this->extractPhone($text);
        if ($switchPhone !== null) {
            $fresh['phone'] = true;
        }
        if ($this->extractLabelledAddress($this->guard->normalizeDigits($text), $switchPhone) !== null) {
            $fresh['address'] = true;
        }
        $state['details_fresh'] = $fresh;
        $conversation->state_data = $state;
        $conversation->save();
        $this->log((int) $conversation->business_id, 'product_switched', $conversation->customer_name . ' — customer named a different product: ' . $named->name, null, $conversation);
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
        // that product's own live figures. (Callers that must never
        // guess — the price/bargain flow — check ambiguousProductsFor()
        // first: on an exact tie there is no winner, only candidates.)
        $best = null;
        $bestCoverage = -1.0;
        $bestScore = -1.0;
        foreach ($this->wordMatchRows($businessId, $text) as $row) {
            if ($row['coverage'] > $bestCoverage + 1e-9
                || (abs($row['coverage'] - $bestCoverage) <= 1e-9 && $row['score'] > $bestScore + 1e-9)) {
                $best = $row['product'];
                $bestCoverage = $row['coverage'];
                $bestScore = $row['score'];
            }
        }

        return $best;
    }

    /**
     * Word-match scores for every product whose name shares a word
     * (>= 4 chars) with the text, in product-id order. Coverage is how
     * much of the product's name the customer actually said; the score
     * rewards DISTINCTIVE words (a word appearing in many product
     * names is weak evidence) and a full-name mention (+100).
     *
     * @return array<int, array{product: Product, coverage: float, score: float}>
     */
    private function wordMatchRows(int $businessId, string $text): array
    {
        $hay = mb_strtolower($text);
        $products = Product::where('business_id', $businessId)->orderBy('id')->get();
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
        $rows = [];
        foreach ($products as $p) {
            $words = $wordsOf[$p->id];
            if ($words === []) {
                continue;
            }
            $matched = array_values(array_filter($words, fn ($w) => str_contains($hay, $w)));
            if ($matched === []) {
                continue;
            }
            $score = 0.0;
            foreach ($matched as $w) {
                $score += 1.0 / max(1, $freq[$w] ?? 1);
            }
            if (str_contains($hay, mb_strtolower($p->name))) {
                $score += 100.0; // the customer said the whole name
            }
            $rows[] = ['product' => $p, 'coverage' => count($matched) / count($words), 'score' => $score];
        }

        return $rows;
    }

    /**
     * The products a message AMBIGUOUSLY points at (brain fix batch 8):
     * two or more products tied at the top of the word scoring — the
     * customer said a word several products share ("Panjabi" when the
     * shop sells "CRUD Test Panjabi" AND "CRUD Test Panjabi 2") and
     * nothing in the message separates them. productFor() would break
     * the tie by product id and silently quote one product's price;
     * this returns the tied candidates instead so the caller can ask.
     * An explicit mention (SKU / alias / full name / every name word)
     * is never ambiguous, and neither is a clear scoring winner (the
     * customer said more of one product's name than of any other) or
     * a tie among products that all share one price.
     *
     * @return Product[]|null the tied candidates (id order), or null
     */
    public function ambiguousProductsFor(int $businessId, string $text): ?array
    {
        if ($this->explicitProductFor($businessId, $text) !== null) {
            return null;
        }
        $rows = $this->wordMatchRows($businessId, $text);
        if (count($rows) < 2) {
            return null;
        }
        $best = null;
        foreach ($rows as $row) {
            if ($best === null || $row['coverage'] > $best['coverage'] + 1e-9
                || (abs($row['coverage'] - $best['coverage']) <= 1e-9 && $row['score'] > $best['score'] + 1e-9)) {
                $best = $row;
            }
        }
        $tied = array_values(array_filter(
            $rows,
            fn ($r) => abs($r['coverage'] - $best['coverage']) <= 1e-9 && abs($r['score'] - $best['score']) <= 1e-9
        ));
        if (count($tied) < 2) {
            return null;
        }
        // A tie among products that all carry the SAME price cannot
        // misquote: the figure is identical whichever one the customer
        // meant, so the legacy scored answer stands (the golden suite
        // pins this for the Demo Shop's colour pair, "Cotton Panjabi -
        // White" / "- Blue", both 1,250). The guess the owner banned
        // is a tie across DIFFERENT prices — quoting one product's
        // 1,300 when the customer may mean the other's 850.
        $prices = array_unique(array_map(fn ($r) => (float) $r['product']->price, $tied));
        if (count($prices) === 1) {
            return null;
        }

        return array_map(fn ($r) => $r['product'], $tied);
    }

    /**
     * Resolve a customer's answer to a product clarification against
     * the candidates that were listed: an explicit mention of one of
     * them, an ordinal ("2", "২", "দ্বিতীয়টা", "second"), or a word
     * that appears in exactly ONE candidate's name (the token that
     * separates "CRUD Test Panjabi 2" from "CRUD Test Panjabi").
     * Anything else resolves nothing — the message is then judged on
     * its own by the normal flow.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $candidates
     */
    private function resolveClarifiedPick(int $businessId, string $text, $candidates): ?Product
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        $explicit = $this->explicitProductFor($businessId, $text);
        if ($explicit !== null && $candidates->contains('id', (int) $explicit->id)) {
            return $explicit;
        }
        $hay = mb_strtolower($this->guard->normalizeDigits($text));
        $ordinals = [
            1 => ['প্রথম', 'first', '1st', 'prothom'],
            2 => ['দ্বিতীয়', 'second', '2nd', 'ditiyo', 'ditiya'],
            3 => ['তৃতীয়', 'third', '3rd', 'tritiyo', 'tritiya'],
            4 => ['চতুর্থ', 'fourth', '4th', 'choturtho'],
            5 => ['পঞ্চম', 'fifth', '5th', 'ponchom'],
        ];
        foreach ($ordinals as $n => $words) {
            if ($n > $candidates->count()) {
                break;
            }
            $hit = (bool) preg_match('/(?<!\d)' . $n . '(?!\d)/u', $hay);
            if (! $hit) {
                foreach ($words as $w) {
                    if (str_contains($hay, $w)) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                return $candidates->values()[$n - 1];
            }
        }
        // Distinguishing tokens: a name token exactly one candidate has.
        $tokenSets = [];
        $freq = [];
        foreach ($candidates as $p) {
            $tokens = array_values(array_unique(preg_split('/\s+/', mb_strtolower($p->name)) ?: []));
            $tokenSets[$p->id] = $tokens;
            foreach ($tokens as $t) {
                $freq[$t] = ($freq[$t] ?? 0) + 1;
            }
        }
        $hayTokens = array_values(array_unique(preg_split('/[\s,\x{0964}]+/u', $hay) ?: []));
        $hits = [];
        foreach ($candidates as $p) {
            foreach ($tokenSets[$p->id] as $t) {
                if (($freq[$t] ?? 0) === 1 && in_array($t, $hayTokens, true)) {
                    $hits[] = $p;
                    break;
                }
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
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
        // The product NAME is a slot too (brain fix batch 3): as literal
        // text the talker treated it as rewritable prose and paraphrased
        // the item noun — a Panjabi became "এই প্যান্টটি" live — so the
        // name now travels as ⟦product⟧ and code renders it verbatim.
        $replace = $slots ? [
            '{product}' => '⟦product⟧',
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
            '⟦product⟧' => $product ? $product->name : 'পণ্যটা',
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
     * The context package: what the AI layers (stage-2 classifier and
     * the talker) are allowed to SEE so they judge and phrase the
     * message IN CONTEXT instead of blind. The last ~10 messages in
     * order (both sides), plus the conversation state code has already
     * decided: the focus product, the bargaining step and the standing
     * offer, what contact details are already collected, and whether an
     * order summary was already shown. Context informs understanding
     * and wording only — figures and state transitions stay in code.
     */
    public function conversationContext(Conversation $conversation, ?int $beforeMessageId = null): array
    {
        $msgs = ChatMessage::where('conversation_id', $conversation->id)
            ->when($beforeMessageId, fn ($q) => $q->where('id', '<', $beforeMessageId))
            ->orderByDesc('id')->limit(10)->get()->reverse()->values();
        $history = [];
        $previousReply = null;
        foreach ($msgs as $m) {
            $history[] = [
                'who' => $m->direction === 'in' ? 'customer' : 'shop',
                'text' => mb_substr((string) $m->body, 0, 300),
            ];
            if ($m->direction === 'out') {
                $previousReply = (string) $m->body;
            }
        }
        $summaryShown = ChatMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'out')->get()
            ->contains(fn ($m) => ($m->meta['kind'] ?? null) === 'order_summary');
        $product = $conversation->product_id
            ? Product::where('business_id', (int) $conversation->business_id)->find($conversation->product_id)
            : null;

        return [
            'history' => $history,
            'previous_reply' => $previousReply,
            'state' => [
                'product' => $product?->name,
                'neg_step' => (int) $conversation->neg_step,
                'offer' => $conversation->neg_offer !== null ? (float) $conversation->neg_offer : null,
                'customer_name' => $conversation->customer_name,
                'phone_collected' => ! empty($conversation->customer_phone),
                'address_collected' => ! empty($conversation->customer_address),
                'summary_shown' => $summaryShown,
            ],
        ];
    }

    /**
     * Stage-2 fallback: ask the classifier, then resolve its suggestion to
     * this shop's own rule and product. Every call is logged — the log is
     * where new keywords are mined from, so misses stop repeating.
     */
    private function classifyFallback(int $businessId, Conversation $conversation, string $text, array $context = []): ?array
    {
        $this->classifier->setContext($context);
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
        // Layout (owner spec): who and where on their own lines, the
        // product price on its line, the delivery charge on ITS OWN
        // line — it is never folded into the (possibly bargained)
        // product price — and the total last.
        $body = $conversation->customer_name . "\n"
            . 'ফোন: ' . ($conversation->customer_phone ?: 'ফোন বাকি') . "\n"
            . 'ঠিকানা: ' . ($conversation->customer_address ?: 'ঠিকানা বাকি') . "\n"
            . $itemName . ' (×1) ' . number_format($unit) . ' টাকা' . "\n"
            . 'ডেলিভারি চার্জ: ' . number_format($delivery) . ' টাকা' . "\n"
            . 'মোট: ' . number_format($total) . ' টাকা (Cash on Delivery)';
        // Cart snapshot: what this conversation is currently buying.
        $state = (array) ($conversation->state_data ?? []);
        $state['cart'] = [['product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'qty' => 1, 'agreed_price' => $unit]];
        $conversation->state_data = $state;
        $conversation->save();

        return ['body' => $body, 'meta' => ['product_id' => $product->id, 'total' => $total, 'unit_price' => $unit, 'variant_name' => $variant?->name]];
    }

    /**
     * The collect question, word-for-word by design (never phrased).
     * It asks for exactly the pieces this order flow still needs, in
     * ONE message: name, mobile number and delivery address together
     * when all three are outstanding (owner spec, 2026-11-25 — the
     * stepwise phone-first ask was the batch-3 live deviation), and
     * only the still-missing pieces after a partial answer. A single
     * outstanding piece keeps its long-standing one-piece wording.
     *
     * @param array<int, string> $pieces any of 'name', 'phone', 'address'
     */
    private function collectQuestion(int $businessId, array $pieces, ?string $customerName = null): string
    {
        $prefix = $this->honorificPrefix($businessId, $customerName);
        if ($pieces === ['phone']) {
            return $prefix . 'অর্ডারের জন্য আপনার মোবাইল নম্বরটা দিন - যে নম্বরে কুরিয়ার কল করবে।';
        }
        if ($pieces === ['address']) {
            return $prefix . 'ডেলিভারির ঠিকানাটা দিন - বাসা/হোল্ডিং, রোড, এলাকা, জেলা।';
        }
        $labels = ['name' => 'নাম', 'phone' => 'মোবাইল নম্বর', 'address' => 'ডেলিভারির ঠিকানা'];
        $parts = [];
        foreach (['name', 'phone', 'address'] as $piece) {
            if (in_array($piece, $pieces, true)) {
                $parts[] = $labels[$piece];
            }
        }
        $last = (string) array_pop($parts);
        $list = ($parts !== [] ? implode(', ', $parts) . ' আর ' : '') . $last;
        $body = $prefix . 'অর্ডারের জন্য আপনার ' . $list . 'টা দিন';
        if (in_array('address', $pieces, true)) {
            $body .= ' - বাসা/হোল্ডিং, রোড, এলাকা, জেলা';
        }

        return $body . '।';
    }

    /**
     * The contact pieces the collect question must still ask for: the
     * name, when the customer has not stated one in this flow, plus
     * whichever of phone/address does not yet count as collected.
     * (The summary GATE stays phone+address — the profile name on the
     * record serves until the customer states their own — but the
     * ASK names every piece the flow does not yet hold.)
     *
     * @return array<int, string>
     */
    private function collectMissing(Conversation $conversation): array
    {
        $missing = [];
        if (! $this->nameStatedInFlow($conversation)) {
            $missing[] = 'name';
        }
        if (! $this->detailCollected($conversation, 'phone')) {
            $missing[] = 'phone';
        }
        if (! $this->detailCollected($conversation, 'address')) {
            $missing[] = 'address';
        }

        return $missing;
    }

    /** Has the customer stated a name in the CURRENT order flow? */
    private function nameStatedInFlow(Conversation $conversation): bool
    {
        $state = (array) ($conversation->state_data ?? []);

        return ! empty(($state['details_fresh'] ?? [])['name']);
    }

    /**
     * Order taking, shared by the order_intent branch and by a bargain
     * that just ended in agreement: ask only for what is still missing
     * (guided collection), or show the summary when name, phone and
     * address are all on the record. $agreed carries the price a
     * negotiation just settled at, when this call follows an acceptance:
     * the collect question is then prefixed with a one-line
     * acknowledgement of the agreed figure (never a fresh offer, never
     * "অর্ডার করবেন?") and the acceptance is recorded in the meta.
     *
     * @return array{slottedBody: ?string, kind: ?string, meta: array, body: ?string}
     */
    private function orderTaking(Conversation $conversation, Product $product, ?\App\Models\ProductVariant $variant, int $biz, ?float $agreed = null): array
    {
        // Details an earlier, completed order already consumed do NOT
        // count (detailCollected): a new order flow asks again instead
        // of silently reusing the previous order's phone and address.
        $missing = [];
        if (! $this->detailCollected($conversation, 'phone')) {
            $missing[] = 'phone';
        }
        if (! $this->detailCollected($conversation, 'address')) {
            $missing[] = 'address';
        }
        if ($missing !== []) {
            $state = (array) ($conversation->state_data ?? []);
            $state['collect'] = ['step' => $missing[0], 'retries' => 0, 'product_id' => $product->id, 'variant_id' => $variant?->id];
            // A new collection supersedes any summary an earlier flow
            // left pending: its data is no longer what this flow is
            // confirming, so it must not stay confirmable (batch 3).
            unset($state['pending_summary']);
            $conversation->state = 'collecting';
            $conversation->state_data = $state;
            $conversation->save();
            $body = $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name);
            $meta = ['collect' => $missing[0]];
            if ($agreed !== null) {
                $body = 'ঠিক আছে, ⟦offer⟧ টাকায় ফাইনাল। ' . $body;
                $meta = array_merge($meta, ['quote' => 'accept', 'offer' => $agreed]);
            }

            return ['slottedBody' => $body, 'kind' => 'collect_question', 'meta' => $meta, 'body' => null];
        }
        $summary = $this->buildSummary($conversation, $product, $variant);
        $meta = $summary['meta'];
        if ($agreed !== null) {
            $meta = array_merge($meta, ['quote' => 'accept', 'offer' => $agreed]);
        }

        return ['slottedBody' => null, 'kind' => 'order_summary', 'meta' => $meta, 'body' => $summary['body']];
    }

    /** Item nouns a customer names when asking for a kind of product. */
    private const ITEM_NOUNS = ['জামা', 'শাড়ি', 'শাড়ী', 'প্যান্ট', 'শার্ট', 'টি-শার্ট', 'টিশার্ট', 'কুর্তি', 'বোরকা', 'হিজাব', 'জুতা', 'স্যান্ডেল', 'ঘড়ি', 'ব্যাগ', 'পাঞ্জাবি', 'পাঞ্জাবী', 'থ্রি-পিস', 'থ্রিপিস', 'ওড়না', 'কামিজ', 'গেঞ্জি', 'জ্যাকেট', 'সোয়েটার', 'শাল', 'টুপি', 'বেল্ট', 'সানগ্লাস', 'মোবাইল', 'হেডফোন', 'চার্জার', 'লিপস্টিক', 'পারফিউম', 'jama', 'sharee', 'saree', 'pant', 'shirt', 'shoe', 'watch', 'bag', 'kurti', 'hijab', 'burqa', 'borka', 'panjabi'];

    /**
     * Item-noun equivalence groups for the product-name guard (batch
     * 3): the Bangla and romanised/English forms of ONE kind of item.
     * The মোবাইল/mobile group is deliberately absent — in a reply it
     * almost always means the customer's phone number, not a product.
     */
    private const ITEM_NOUN_GROUPS = [
        ['জামা', 'jama'], ['শাড়ি', 'শাড়ী', 'sharee', 'saree'], ['প্যান্ট', 'pant', 'pants'],
        ['শার্ট', 'shirt'], ['টি-শার্ট', 'টিশার্ট', 't-shirt', 'tshirt'], ['কুর্তি', 'kurti'],
        ['বোরকা', 'burqa', 'borka'], ['হিজাব', 'hijab'], ['জুতা', 'shoe', 'shoes'],
        ['স্যান্ডেল', 'sandal'], ['ঘড়ি', 'watch'], ['ব্যাগ', 'bag'],
        ['পাঞ্জাবি', 'পাঞ্জাবী', 'panjabi'], ['থ্রি-পিস', 'থ্রিপিস', 'three piece', 'three-piece'],
        ['ওড়না', 'orna'], ['কামিজ', 'kameez'], ['গেঞ্জি', 'genji'], ['জ্যাকেট', 'jacket'],
        ['সোয়েটার', 'sweater'], ['শাল', 'shawl'], ['টুপি', 'cap'], ['বেল্ট', 'belt'],
        ['সানগ্লাস', 'sunglass', 'sunglasses'], ['হেডফোন', 'headphone'],
        ['চার্জার', 'charger'], ['লিপস্টিক', 'lipstick'], ['পারফিউম', 'perfume'],
    ];

    /** Does $text use an item noun from this group? Latin forms need word boundaries; Bangla forms match as substrings (the টা/টি suffix rides on the stem). */
    private function nounGroupInText(array $group, string $text): bool
    {
        $hay = mb_strtolower($text);
        foreach ($group as $form) {
            if (preg_match('/^[a-z0-9\- ]+$/', $form)) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($form, '/') . '(?![a-z0-9])/u', $hay)) {
                    return true;
                }
            } elseif (str_contains($hay, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item noun a phrased reply RENAMED the focus product with, or
     * null (brain fix batch 3). A noun is a rename when it names a
     * DIFFERENT kind of item than the product — its group appears
     * neither in the product's name/aliases nor in the brain's own
     * draft — so the talker invented it: live, a bargain reply for a
     * Panjabi said "এই প্যান্টটি" (this pant). The product's own noun
     * in the customer's script (পাঞ্জাবি for a "Panjabi") is the
     * product's own group and always passes. The caller discards the
     * phrasing and sends the code-written draft instead.
     */
    private function productNounViolation(?Product $product, string $draftRendered, string $body): ?string
    {
        if (! $product) {
            return null;
        }
        $catalogue = $product->name . ' ' . (string) ($product->aliases ?? '');
        foreach (self::ITEM_NOUN_GROUPS as $group) {
            if (! $this->nounGroupInText($group, $body)) {
                continue;
            }
            if ($this->nounGroupInText($group, $catalogue) || $this->nounGroupInText($group, $draftRendered)) {
                continue;
            }

            return $group[0];
        }

        return null;
    }

    /**
     * Is this message a request for an item the shop does not sell?
     * Conservative on purpose — ALL of these must hold: the text names
     * no catalogue product at all (productFor without the conversation
     * finds nothing), it names an item noun that appears in NO product
     * name or alias of this shop, it carries a request marker, and it
     * does not point back at the conversation's own product ("এটা",
     * "আরেকটা", ...). Only then may the brain say "not available"
     * instead of quoting the context product's price at the customer.
     */
    /**
     * The message names an item noun (জামা, শাড়ি, panjabi, ...) that
     * appears in NO product name or alias of this shop — the customer
     * is asking about something the shop does not sell. Such a message
     * is not a bare question, so the product-listing clarification
     * (batch 8) must not fire for it; it keeps the existing path.
     * The noun test mirrors unmatchedItemRequest(): Latin nouns match
     * on word boundaries, Bangla nouns by containment.
     */
    private function namesUnstockedItem(int $businessId, string $text): bool
    {
        $hay = mb_strtolower($text);
        $catalogueWords = Product::where('business_id', $businessId)->get(['name', 'aliases'])
            ->map(fn ($p) => mb_strtolower($p->name . ' ' . (string) $p->aliases))->implode(' ');
        foreach (self::ITEM_NOUNS as $noun) {
            $hit = preg_match('/^[a-z]+$/', $noun)
                ? (bool) preg_match('/\b' . preg_quote($noun, '/') . '\b/u', $hay)
                : str_contains($hay, $noun);
            if (! $hit || str_contains($catalogueWords, $noun)) {
                continue;
            }
            // Script does not decide stock: a Bangla noun whose
            // romanised group-mate names a catalogue product
            // (পাঞ্জাবি vs "Panjabi") is a stocked item asked about
            // in the other script, not an unstocked one.
            $stocked = false;
            foreach (self::ITEM_NOUN_GROUPS as $group) {
                if (in_array($noun, $group, true)) {
                    foreach ($group as $mate) {
                        if ($mate !== $noun && str_contains($catalogueWords, $mate)) {
                            $stocked = true;
                            break 2;
                        }
                    }
                }
            }
            if (! $stocked) {
                return true;
            }
        }

        return false;
    }

    private function unmatchedItemRequest(int $businessId, string $text, Conversation $conversation): bool
    {
        if (! $conversation->product_id) {
            return false; // nothing in focus — nothing to wrongly quote
        }
        if ($this->productFor($businessId, $text, null) !== null) {
            return false; // the text itself names a catalogue product
        }
        $hay = mb_strtolower($text);
        foreach (['এটা', 'ওটা', 'সেটা', 'এইটা', 'আগেরটা', 'আরেকটা', 'আরেকটি', 'eta', 'ota'] as $ref) {
            if (str_contains($hay, $ref)) {
                return false; // pointing at the product in focus
            }
        }
        $marker = false;
        foreach (['লাগবে', 'lagbe', 'চাই', 'chai', 'আছে', 'ache', 'খুঁজছি', 'khujchi', 'need', 'want'] as $w) {
            if (str_contains($hay, $w)) {
                $marker = true;
                break;
            }
        }
        if (! $marker) {
            return false;
        }
        $catalogueWords = Product::where('business_id', $businessId)->get(['name', 'aliases'])
            ->map(fn ($p) => mb_strtolower($p->name . ' ' . (string) $p->aliases))->implode(' ');
        foreach (self::ITEM_NOUNS as $noun) {
            $hit = preg_match('/^[a-z]+$/', $noun)
                ? (bool) preg_match('/\b' . preg_quote($noun, '/') . '\b/u', $hay)
                : str_contains($hay, $noun);
            if ($hit && ! str_contains($catalogueWords, $noun)) {
                return true;
            }
        }

        return false;
    }

    /** The honest answer to a request for an item the shop does not sell. */
    private function notAvailableBody(int $businessId, ?string $customerName): string
    {
        return $this->honorificPrefix($businessId, $customerName) . 'দুঃখিত, আপনি যে পণ্যটা চাইছেন সেটা আমাদের কাছে নেই। আমাদের অন্য কোনো পণ্য দেখতে চাইলে বলুন।';
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
     * The name the customer states in THIS message, if they label it —
     * "আমার নাম X", "নাম: X", "Amar nam X", "my name is X". Only a
     * labelled name is read: an unlabelled guess would rewrite the
     * record with whatever words happen to follow "আমি".
     */
    private function extractName(string $text): ?string
    {
        $name = null;
        if (preg_match('/আমার\s+নাম\s*[:ঃ]?\s*([^,।\n]+)/u', $text, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/নাম\s*[:ঃ]\s*([^,।\n]+)/u', $text, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/\b(?:amar\s+nam|amr\s+nam|amar\s+name|my\s+name(?:\s+is)?)\s*[:.]?\s+([a-z][a-z0-9\s.\'-]{1,58}?)(?=\s*(?:,|$|\bphone\b|\bmobile\b|\baddress\b|ঠিকানা|ফোন))/iu', $text, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/\bname\s*[:]\s*([a-z][a-z0-9\s.\'-]{1,58}?)(?=\s*(?:,|$|\bphone\b|\bmobile\b|\baddress\b|ঠিকানা|ফোন))/iu', $text, $m)) {
            $name = trim($m[1]);
        }
        if ($name === null) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/', ' ', $name), " \t.");
        // A name is letters, short, and never a number or an intent
        // phrase. The fragment guards matter: "আমার নামটা বদলাতে চাই"
        // (change my name) and "amar nam ta change korte chai" are
        // requests ABOUT a name — their tails are not names.
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || ! preg_match('/[a-z\x{0980}-\x{09FF}]/iu', $name)) {
            return null;
        }
        if (str_starts_with($name, 'টা') || preg_match('/^(ta|amar|ami|eta|oita|phone|mobile|address|confirm|order|the)\b/iu', $name)) {
            return null;
        }
        foreach (['বদলাতে', 'ভুল', 'পরিবর্তন', 'korte chai', 'change', 'wrong'] as $w) {
            if (str_contains(mb_strtolower($name), $w)) {
                return null;
            }
        }

        return $name;
    }

    /**
     * The address the customer labels in THIS message — "ঠিকানা: ..."
     * or "address ..." (the colon is theirs to skip; live customers do).
     * The capture stops at the end of the sentence, and the candidate
     * must still LOOK like an address (a comma, or an address word like
     * বাসা/রোড/road/house) — "address change korte chai" is a request
     * ABOUT an address, not one.
     */
    private function extractLabelledAddress(string $text, ?string $phone): ?string
    {
        $t = $phone !== null ? str_replace($phone, '', $text) : $text;
        if (! preg_match('/(?:ঠিকানা|address)\s*[:：]?\s*(.+?)(?=\.\s|\n|$)/isu', $t, $m)) {
            return null;
        }
        $addr = trim($m[1], " \t.");
        // The address segment ends where the NEXT labelled field
        // begins (batch 6): "address House 10, Dhaka, phone 01…,
        // amar nam Karim" — the phone digits are already stripped
        // above, but without this cut the trailing segments' words
        // ("phone , amar nam Karim") stayed inside the address. The
        // label must follow a separator, so a word that merely
        // CONTAINS a label fragment (সুনামগঞ্জ) never cuts.
        if (preg_match('/[,;\n]\s*(?:আমার\s+নাম|নাম|ফোন|মোবাইল|amar\s+nam|amr\s+nam|amar\s+name|my\s+name|\bname\b|\bphone\b|\bmobile\b)\s*[:ঃ]?/iu', $addr, $mm, PREG_OFFSET_CAPTURE)) {
            $addr = trim(substr($addr, 0, $mm[0][1]), " \t.");
        }
        $looksAddress = str_contains($addr, ',')
            || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম|\broad\b|\bhouse\b|\bsector\b|\bblock\b|\bdistrict\b/iu', $addr);
        if (! $looksAddress || mb_strlen($addr) < 8) {
            return null;
        }

        return $addr;
    }

    /**
     * Clean a leftover-text address candidate (batch 6): the text
     * that remains of a message once the phone digits are removed
     * can still carry the OTHER fields' segments — the labelled
     * name ("Amar nam Karim, …") and the bare label words ("phone ,")
     * — and storing it whole was the batch-6 defect. Remove the
     * stated name's value and every field label, tidy the separators
     * the removals leave behind, and return null when nothing that
     * could be an address remains. Label words are removed only in
     * their label forms (a bare নাম is left alone — সুনামগঞ্জ is a
     * district, not a label), so a genuine unlabelled address passes
     * through untouched.
     */
    private function cleanAddressCandidate(string $candidate, ?string $name): ?string
    {
        $addr = $candidate;
        if ($name !== null && $name !== '') {
            $addr = str_replace($name, '', $addr);
        }
        $addr = (string) preg_replace('/আমার\s+নাম\s*[:ঃ]?\s*|নাম\s*[:ঃ]\s*|ঠিকানা\s*[:：]?\s*|ফোন\s*[:ঃ]?\s*|মোবাইল\s*[:ঃ]?\s*|নম্বর\s*[:ঃ]\s*/u', '', $addr);
        $addr = (string) preg_replace('/\b(?:amar\s+nam|amr\s+nam|amar\s+name|my\s+name(?:\s+is)?|name|phone|mobile|address)\b\s*[:：]?\s*|\bnumber\s*[:：]\s*/iu', '', $addr);
        $addr = trim((string) preg_replace('/\s+/', ' ', $addr));
        $addr = trim((string) preg_replace('/,(\s*,)+/', ',', $addr), " \t.");
        $addr = trim($addr, ',');

        return $addr === '' ? null : trim($addr, " \t.");
    }

    /**
     * Is this contact field usable for a NEW order flow? A field counts
     * when it is on the record — UNLESS an order already consumed it
     * (see consumeCollectedDetails): details a completed order used up
     * are never silently reused for the next order; they count again
     * only when the customer states them in a later message, which
     * captureContactFromText marks fresh. The values themselves stay
     * on the record (the order-status lookup and the WhatsApp channel
     * identity both read the phone), they just stop being a silent
     * answer to "do we have the details?".
     */
    private function detailCollected(Conversation $conversation, string $field): bool
    {
        $value = $field === 'phone' ? $conversation->customer_phone : $conversation->customer_address;
        if (empty($value)) {
            return false;
        }
        $state = (array) ($conversation->state_data ?? []);
        if (! empty($state['details_consumed'])) {
            return (bool) (($state['details_fresh'] ?? [])[$field] ?? false);
        }

        return true;
    }

    /** Mark a field the customer just (re)stated as fresh for the next order flow. */
    private function markDetailFresh(Conversation $conversation, string $field): bool
    {
        $state = (array) ($conversation->state_data ?? []);
        if (empty($state['details_consumed'])) {
            return false;
        }
        $fresh = (array) ($state['details_fresh'] ?? []);
        if (! empty($fresh[$field])) {
            return false;
        }
        $fresh[$field] = true;
        $state['details_fresh'] = $fresh;
        $conversation->state_data = $state;

        return true;
    }

    /**
     * Universal contact capture: scan EVERY inbound customer message
     * for contact details and persist them. Until batch 1 this only
     * happened inside the guided collection flow; batch 2 fixes the
     * PRECEDENCE: a detail stated explicitly in the CURRENT message
     * always wins over what the record already holds. Live 2026-11-25:
     * a confirm message carrying a new name, phone and address was
     * summarised with the phone and address a DIFFERENT order had left
     * on the days-old thread, because capture only ever filled empty
     * fields and never read a name at all. Labelled details (নাম /
     * Amar nam, ঠিকানা / address) are read from the labels; the blob
     * rule (the rest of a message that carries the phone) still fills
     * an address gap when nothing is labelled.
     */
    public function captureContactFromText(Conversation $conversation, string $text): void
    {
        $norm = $this->guard->normalizeDigits($text);
        $changed = false;

        $name = $this->extractName($norm);
        if ($name !== null) {
            if ($name !== (string) $conversation->customer_name) {
                $conversation->customer_name = $name;
                $changed = true;
            }
            // A name stated in THIS flow: the collect question stops
            // asking for it (brain fix batch 4). The flag lives in the
            // same per-flow freshness map as phone/address and is
            // reset by the same closers (order created, product
            // switch), so a later flow asks again.
            $st = (array) ($conversation->state_data ?? []);
            if (empty(($st['details_fresh'] ?? [])['name'])) {
                $fresh = (array) ($st['details_fresh'] ?? []);
                $fresh['name'] = true;
                $st['details_fresh'] = $fresh;
                $conversation->state_data = $st;
                $changed = true;
            }
        }

        $phone = $this->extractPhone($text);
        if ($phone !== null) {
            if ($phone !== (string) $conversation->customer_phone) {
                $conversation->customer_phone = $phone;
                $changed = true;
            }
            if ($this->markDetailFresh($conversation, 'phone')) {
                $changed = true;
            }
        }

        $labelled = $this->extractLabelledAddress($norm, $phone);
        if ($labelled !== null) {
            if ($labelled !== (string) $conversation->customer_address) {
                $conversation->customer_address = $labelled;
                $changed = true;
            }
            if ($this->markDetailFresh($conversation, 'address')) {
                $changed = true;
            }
        } elseif (empty($conversation->customer_address) && $phone !== null) {
            // Blob rule: the address rides in the same message as the
            // number, unlabelled — the leftover text after the number
            // is the address when it looks like one. The leftover is
            // cleaned of the other fields' segments first (batch 6):
            // a name+phone message with NO address in it must leave
            // the address empty, not store "Amar nam , phone ," as
            // one.
            $rest = trim(str_replace($phone, '', $norm));
            $addr = $this->cleanAddressCandidate($rest, $name);
            $looksAddress = $addr !== null && (str_contains($addr, ',')
                || mb_strlen($addr) >= 15
                || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম/u', $addr));
            if ($addr !== null && $looksAddress && mb_strlen($addr) >= 8) {
                $conversation->customer_address = $addr;
                $changed = true;
                if ($this->markDetailFresh($conversation, 'address')) {
                    $changed = true;
                }
            }
        }
        if ($phone !== null || $labelled !== null) {
            // (Re)stating contact details begins a NEW order flow: the
            // previous order's "already confirmed" memory (batch 3)
            // must not answer this customer's next confirmation as if
            // it were about that old order.
            $st = (array) ($conversation->state_data ?? []);
            if (isset($st['last_order'])) {
                unset($st['last_order']);
                $conversation->state_data = $st;
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

    /**
     * An order was just created from this conversation's summary
     * (MessagesController::approveDraft is the one creation path):
     * close the flow out. The negotiation is over (no standing offer),
     * the cart and any half-finished collection are cleared, and the
     * collected name/phone/address are marked CONSUMED — they stay on
     * the record for the order-status lookup and the channel identity,
     * but a later order flow in this thread must ask again (or the
     * customer re-states them) instead of silently reusing the
     * previous order's details.
     */
    public function consumeCollectedDetails(Conversation $conversation, ?int $orderId = null): void
    {
        $state = (array) ($conversation->state_data ?? []);
        $state['details_consumed'] = true;
        $state['details_fresh'] = ['name' => false, 'phone' => false, 'address' => false];
        if ($orderId !== null) {
            $state['last_order_id'] = $orderId;
            // The placed order's public facts, snapshotted for the
            // already-confirmed reply (batch 3): a repeat "confirm"
            // after this point is answered with THIS order's number
            // and total, never with a new summary or a second order.
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                $state['last_order'] = [
                    'id' => (int) $order->id,
                    'invoice' => (string) $order->invoice_no,
                    'total' => (float) $order->grand_total,
                ];
            }
        }
        unset($state['collect'], $state['cart'], $state['floor_repeats'], $state['pending_summary']);
        $conversation->state_data = $state;
        $conversation->state = null;
        $conversation->neg_step = 0;
        $conversation->neg_offer = null;
        $conversation->save();
    }

    /**
     * Record the summary that was just shown as PENDING CONFIRMATION
     * (brain fix batch 3): its message, product, variant, figures and
     * the exact contact details it was built from. A later customer
     * confirmation creates the order from THIS snapshot — and only
     * while the record still matches it; details edited since then
     * re-summarise instead of ordering stale data.
     */
    private function markSummaryPending(Conversation $conversation, ChatMessage $msg, ?\App\Models\ProductVariant $variant): void
    {
        $meta = (array) ($msg->meta ?? []);
        $unit = (float) ($meta['unit_price'] ?? 0);
        $total = (float) ($meta['total'] ?? 0);
        $state = (array) ($conversation->state_data ?? []);
        $state['pending_summary'] = [
            'message_id' => (int) $msg->id,
            'product_id' => (int) ($meta['product_id'] ?? 0),
            'variant_id' => $variant?->id,
            'variant_name' => $meta['variant_name'] ?? null,
            'unit_price' => $unit,
            'delivery' => $total - $unit,
            'total' => $total,
            'name' => (string) ($conversation->customer_name ?? ''),
            'phone' => (string) ($conversation->customer_phone ?? ''),
            'address' => (string) ($conversation->customer_address ?? ''),
            'customer_confirmed' => false,
        ];
        $conversation->state_data = $state;
        $conversation->save();
    }

    /**
     * THE one chat order-creation path (brain fix batch 3): build the
     * order an order summary stands for, through OrderService — the
     * points gate and the exactly-one deduction live inside its
     * transaction — then close the conversation's flow out via
     * consumeCollectedDetails. Used by BOTH creation routes: the
     * seller approval route (MessagesController::approveDraft, a
     * human approving the summary draft) and the Full-auto chat route
     * (summaryConfirmation below, the customer confirming a summary
     * the AI already sent). Before batch 3 only the approval route
     * existed, so a customer confirming a sent summary in Full-auto
     * chat just got the summary re-rendered — four times live — and
     * no order was ever created.
     *
     * Returns null when the summarised product no longer exists.
     *
     * @throws \App\Exceptions\InsufficientTokensException
     */
    public function createOrderFromSummary(Conversation $conversation, ChatMessage $summary, ?\App\Models\User $actor = null): ?\App\Models\Order
    {
        $biz = (int) $conversation->business_id;
        $product = Product::where('business_id', $biz)->find($summary->meta['product_id'] ?? 0);
        if (! $product) {
            return null;
        }
        $source = ['facebook' => 'FB', 'whatsapp' => 'WA', 'instagram' => 'IG'][$conversation->channel] ?? 'FB';
        $name = (string) ($conversation->customer_name ?? '');
        if (str_starts_with($name, '+')) {
            $name = 'Karim Sheikh';
        }
        $order = app(OrderService::class)->create($biz, [
            'customer_name' => $name,
            'customer_phone' => $conversation->customer_phone ?: '01712345678',
            'customer_address' => $conversation->customer_address,
            'source' => $source,
            'status' => 'Pending',
            // The delivery charge the summary quoted: the shop's
            // configured cost, read the same way buildSummary reads
            // it — never a hardcoded 60 the shop may not charge
            // (Hunt V1).
            'delivery_charge' => (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => isset($summary->meta['unit_price']) ? (float) $summary->meta['unit_price'] : null, 'variant_name' => $summary->meta['variant_name'] ?? null]],
        ], $actor, 'Chat order intake');

        // The order consumed the collected details (batch 2): the
        // negotiation closes and the name/phone/address stop counting
        // for the NEXT order flow in this thread.
        $this->consumeCollectedDetails($conversation, (int) $order->id);

        return $order;
    }

    /**
     * The summary-confirmation step (brain fix batch 3): the customer
     * is answering a summary that is already on the table. Returns
     * null when this message is not a summary confirmation (the reply
     * chain decides it), ['message' => ChatMessage] when a reply was
     * produced, and ['stop' => true] when the step was handled with a
     * logged handover only. A confirmed summary NEVER just re-sends
     * the summary:
     *  - summary SENT (Full auto): the order is created now, through
     *    the one creation path, and the reply carries its number.
     *  - summary still a DRAFT (Draft-only / plain Auto, or held
     *    flagged): the customer never saw it, so nothing is created
     *    from chat — the confirmation is recorded on the summary
     *    draft (the existing order draft the seller approves) and a
     *    draft acknowledgement answers the customer.
     *  - no summary pending but an order was just placed here: a bare
     *    repeat confirmation gets that order's status.
     */
    private function summaryConfirmation(Conversation $conversation, ?Product $product, int $biz, string $mode, ?AiRule $rule, ?string $intent, string $text): ?array
    {
        if ($rule && $rule->action === 'handover') {
            return null; // the shop wants a human on this intent — their rule wins
        }
        $hay = mb_strtolower($text);
        if ($intent !== 'order_intent' && ! $this->isAcceptance($hay)) {
            return null;
        }
        $state = (array) ($conversation->state_data ?? []);
        $pending = (array) ($state['pending_summary'] ?? []);

        if ($pending !== []) {
            if (! $product || (int) ($pending['product_id'] ?? 0) !== (int) $product->id) {
                return null;
            }
            // A figure that is NOT the summarised price is a counter,
            // not a confirmation of THIS summary — the negotiation
            // chain re-settles and re-summarises it (the 13:59 case:
            // a summary at 1,170, the customer settling at the 1,105
            // floor he named).
            $named = $this->namedAmount($text);
            if ($named !== null && abs($named - (float) ($pending['unit_price'] ?? 0)) >= 1.0) {
                return null;
            }
            // The order is created from EXACTLY the summarised data:
            // if the record's details moved since the summary (the
            // customer corrected them in this very message — capture
            // already ran), that summary is stale; fall through and
            // let order taking re-summarise with the new data.
            if (trim((string) ($conversation->customer_name ?? '')) !== trim((string) ($pending['name'] ?? ''))
                || trim((string) ($conversation->customer_phone ?? '')) !== trim((string) ($pending['phone'] ?? ''))
                || trim((string) ($conversation->customer_address ?? '')) !== trim((string) ($pending['address'] ?? ''))) {
                return null;
            }
            if (! $this->detailCollected($conversation, 'phone') || ! $this->detailCollected($conversation, 'address')) {
                return null;
            }
            $summaryMsg = ! empty($pending['message_id']) ? ChatMessage::find((int) $pending['message_id']) : null;
            if (! $summaryMsg) {
                return null;
            }
            if ($summaryMsg->status === 'rejected') {
                // The seller killed this summary: the flow is dead and
                // a fresh order intent starts over through the chain.
                unset($state['pending_summary']);
                $conversation->state_data = $state;
                $conversation->save();

                return null;
            }
            $pVariant = ! empty($pending['variant_id']) ? \App\Models\ProductVariant::find((int) $pending['variant_id']) : null;
            if (($this->focusAvailable($product, $pVariant) ?? 1) <= 0) {
                // Sold out after the summary was shown: no order, the
                // honest answer instead (owner rule: stock 0 = NO order).
                unset($state['pending_summary']);
                $conversation->state_data = $state;
                $conversation->save();
                $msg = $this->simpleOutgoing($conversation, $this->outOfStockBody($biz, $conversation->customer_name, $product, $pVariant), 'out_of_stock', $mode, ['out_of_stock' => true]);

                return ['message' => $msg];
            }
            if ($summaryMsg->status === 'sent') {
                // The customer HAS this summary (Full auto sent it):
                // their confirmation creates the order now — exactly
                // one order, exactly one point, details consumed,
                // flow reset (all inside the creation path).
                try {
                    $order = $this->createOrderFromSummary($conversation, $summaryMsg);
                } catch (\App\Exceptions\InsufficientTokensException $e) {
                    $conversation->needs_human = true;
                    $conversation->save();
                    $this->log($biz, 'handed_human', $conversation->customer_name . ' — confirmed order could not be created: ' . $e->getMessage(), null, $conversation);

                    return ['stop' => true];
                }
                if ($order === null) {
                    $conversation->needs_human = true;
                    $conversation->save();
                    $this->log($biz, 'handed_human', $conversation->customer_name . ' — confirmed summary product no longer exists', null, $conversation);

                    return ['stop' => true];
                }
                $body = $this->honorificPrefix($biz, $conversation->customer_name)
                    . 'আপনার অর্ডারটি কনফার্ম হয়েছে। অর্ডার নম্বর: ' . $order->invoice_no
                    . '। মোট: ' . number_format((float) $order->grand_total) . ' টাকা (Cash on Delivery)। ধন্যবাদ!';
                $msg = $this->simpleOutgoing($conversation, $body, 'order_confirmed', $mode, [
                    'order_id' => (int) $order->id, 'invoice' => (string) $order->invoice_no, 'total' => (float) $order->grand_total,
                ]);
                $conversation->last_message_at = now();
                $conversation->needs_human = false;
                $conversation->save();

                return ['message' => $msg];
            }

            // The summary is still a draft (Draft-only / plain Auto)
            // or held flagged: the summary draft IS the order draft.
            // Record the customer's confirmation on it for the seller,
            // answer with a draft acknowledgement — never a second
            // summary, never a direct order.
            if (empty($pending['customer_confirmed'])) {
                $meta = (array) ($summaryMsg->meta ?? []);
                $meta['customer_confirmed'] = true;
                $summaryMsg->meta = $meta;
                $summaryMsg->save();
                $pending['customer_confirmed'] = true;
                $state['pending_summary'] = $pending;
                $conversation->state_data = $state;
                $conversation->save();
                $msg = ChatMessage::create([
                    'business_id' => $biz,
                    'conversation_id' => $conversation->id,
                    'direction' => 'out',
                    'sender' => 'ai',
                    'body' => $this->honorificPrefix($biz, $conversation->customer_name) . 'ধন্যবাদ! আপনার কনফার্মেশন পেয়েছি — অর্ডারটি প্রসেস করা হচ্ছে।',
                    'status' => 'draft',
                    'meta' => ['kind' => 'order_confirm_ack', 'summary_message_id' => (int) $summaryMsg->id],
                ]);
                $this->log($biz, 'draft_created', $conversation->customer_name . ' — customer confirmed the pending summary draft', null, $conversation);

                return ['message' => $msg];
            }
            $this->log($biz, 'order_confirm_repeat', $conversation->customer_name . ' — customer confirmed again; the summary draft is already marked confirmed', null, $conversation);

            return ['stop' => true];
        }

        // No summary pending: a bare repeat confirmation about the
        // order this conversation JUST placed gets that order's
        // status — never a new summary, never a second order. A
        // message that names a product, names a figure, or (re)states
        // contact details is a NEW flow starting, not a repeat: the
        // chain handles those.
        $last = (array) ($state['last_order'] ?? []);
        if ($last !== []
            && $this->namedAmount($text) === null
            && $this->extractPhone($text) === null
            && $this->extractLabelledAddress($this->guard->normalizeDigits($text), null) === null
            && $this->explicitProductFor($biz, $text) === null) {
            $body = $this->honorificPrefix($biz, $conversation->customer_name)
                . 'আপনার অর্ডারটি ইতিমধ্যে কনফার্ম হয়েছে। অর্ডার নম্বর: ' . ($last['invoice'] ?? '')
                . '। মোট: ' . number_format((float) ($last['total'] ?? 0)) . ' টাকা (Cash on Delivery)।';
            $msg = $this->simpleOutgoing($conversation, $body, 'order_already_confirmed', $mode, [
                'order_id' => (int) ($last['id'] ?? 0), 'invoice' => (string) ($last['invoice'] ?? ''),
            ]);

            return ['message' => $msg];
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
     * A cancellation of the PENDING order flow (brain fix batch 7),
     * honoured in every pre-order state — collecting, a summary on
     * the table awaiting confirmation, mid-bargain. Until this batch
     * the word list lived only inside handleCollect, so a cancel
     * typed while a summary awaited confirmation fell through the
     * whole reply chain (the no-rule fallback answered it with an
     * unrelated price line), the summary stayed confirmable, and a
     * later "order korte chai" silently placed the very order the
     * customer had just dropped (live 2026-11-25 18:31: OB300 was
     * created after "বাদ দিন").
     *
     * The guards are batch 5's, unchanged: a message carrying
     * contact details is collection input, never an exit; one that
     * asks a price or pushes the ladder is a negotiation, not an
     * exit; one naming a figure is a counter, not an exit.
     * Acceptance words are deliberately NOT in the list — a genuine
     * confirmation of a live summary must still create the order
     * (batch 3), and this check runs before that hook only for
     * words that can never mean acceptance.
     *
     * Clearing is the full flow close-out: no pending summary, no
     * collection, no cart, no ladder, no standing offer — and the
     * details this flow collected are marked consumed (they stay on
     * the record for the order-status lookup and the channel
     * identity; they stop counting), so the next "order korte chai"
     * asks again instead of resurrecting what was cancelled. A
     * summary still sitting as a seller draft is rejected with it:
     * the customer killed this summary, so the approval route must
     * not be able to place the order either.
     */
    private function flowCancellation(Conversation $conversation, string $text, string $mode): ?ChatMessage
    {
        $state = (array) ($conversation->state_data ?? []);
        $flowActive = $conversation->state === 'collecting'
            || ! empty($state['pending_summary'])
            || (int) $conversation->neg_step >= 1
            || $conversation->neg_offer !== null;
        if (! $flowActive) {
            return null;
        }

        $norm = $this->guard->normalizeDigits($text);
        $phoneInMsg = $this->extractPhone($text);
        $carriesDetails = $phoneInMsg !== null
            || $this->extractLabelledAddress($norm, $phoneInMsg) !== null
            || $this->extractName($norm) !== null;
        if ($carriesDetails || $this->namedAmount($text) !== null) {
            return null;
        }
        $intent = $this->detectIntent($text, $conversation);
        if ($intent === 'price' || $intent === 'bargain') {
            return null;
        }

        $hay = mb_strtolower($text);
        foreach (['বাদ দেন', 'বাদ দিন', 'থামুন', 'cancel', 'ক্যান্সেল', 'লাগবে না', 'নিব না'] as $w) {
            if (! str_contains($hay, $w)) {
                continue;
            }
            $pending = (array) ($state['pending_summary'] ?? []);
            if (! empty($pending['message_id'])) {
                $summaryMsg = ChatMessage::find((int) $pending['message_id']);
                if ($summaryMsg) {
                    // No-op for a summary the customer already has
                    // (sent); closes the approval route for a draft.
                    $this->rejectDraft($summaryMsg);
                }
            }
            unset($state['collect'], $state['cart'], $state['floor_repeats'], $state['pending_summary']);
            $state['details_consumed'] = true;
            $state['details_fresh'] = ['name' => false, 'phone' => false, 'address' => false];
            $conversation->state_data = $state;
            $conversation->state = null;
            $conversation->neg_step = 0;
            $conversation->neg_offer = null;
            $conversation->save();

            return $this->simpleOutgoing($conversation, $this->honorificPrefix((int) $conversation->business_id, $conversation->customer_name) . 'ঠিক আছে, অর্ডারটা রাখা হলো না।', 'collect_cancel', $mode);
        }

        return null;
    }

    /**
     * Guided order-data collection. One question at a time; a message that
     * carries several fields at once fills them all; two failed tries at
     * one field hand the conversation to a human. A message carrying no
     * details can still be a SIDE QUESTION about the product being
     * collected (its price, or a push for less) — that is answered with
     * the collect reminder appended and the collection left exactly as
     * it was (brain fix batch 5) — or a cancellation, which exits.
     */
    private function handleCollect(Conversation $conversation, string $text, string $mode): ?ChatMessage
    {
        $biz = (int) $conversation->business_id;
        $state = (array) ($conversation->state_data ?? []);
        $collect = (array) ($state['collect'] ?? []);
        $step = (string) ($collect['step'] ?? 'phone');

        // What does THIS message itself carry? (Pure reads of the text —
        // the capture further down, and captureContactFromText before
        // this call, persist whatever is here regardless of routing.)
        // A message carrying details is collection input, full stop: it
        // never triggers the cancel exit and never the side-question
        // path (brain fix batch 5).
        $norm = $this->guard->normalizeDigits($text);
        $phoneInMsg = $this->extractPhone($text);
        $carriesDetails = $phoneInMsg !== null
            || $this->extractLabelledAddress($norm, $phoneInMsg) !== null
            || $this->extractName($norm) !== null;

        // A side question about the product being collected (its price,
        // or a push for less) is answered, not swallowed (batch 5):
        // until this fix, handleCollect never classified the message at
        // all, so "dam koto?" mid-collection got the identical collect
        // prompt back and the question vanished. Only price/bargain
        // route sideways — everything else keeps the routing it had.
        $sideIntent = null;
        if (! $carriesDetails) {
            $intent = $this->detectIntent($text, $conversation);
            if ($intent === 'price' || $intent === 'bargain') {
                $sideIntent = $intent;
            }
        }

        // Cancellation exits the flow (batch 5; shared since batch 7
        // with the summary-pending and mid-bargain states — one word
        // list, one guard set, one state clearing, in
        // flowCancellation()). handleIncoming already ran it before
        // dispatching here; this call keeps the contract if
        // collection is ever entered another way.
        $cancel = $this->flowCancellation($conversation, $text, $mode);
        if ($cancel !== null) {
            return $cancel;
        }

        // A phone number anywhere in the message counts, whatever step
        // we are on — and it OVERRIDES the record: the number a customer
        // types while ordering is this order's number, even when an
        // older one is still on the conversation (batch 2 precedence).
        $phone = $this->extractPhone($text);
        if ($phone !== null) {
            $conversation->customer_phone = $phone;
            $this->markDetailFresh($conversation, 'phone');
        }
        // Strip the number from the DIGIT-NORMALISED text: the customer may
        // have typed it in Bangla digits while $phone is Latin — replacing
        // in the raw text would leave the whole message standing as "rest"
        // and it would be misread as an address.
        $rest = trim(str_replace($phone ?? '~~~', '', $this->guard->normalizeDigits($text)));
        if ($sideIntent === null && ($step === 'address' || ($phone !== null && ! $this->detailCollected($conversation, 'address')))) {
            // A LABELLED address in this message is the address —
            // exactly its own segment (batch 6). This capture used to
            // store the raw leftover text instead, overwriting the
            // clean value captureContactFromText had just stored: a
            // one-message name+phone+address answer went onto the
            // record (and into the summary, and the order) as the
            // whole message with the phone digits blanked.
            $addr = $this->extractLabelledAddress($norm, $phone);
            if ($addr === null) {
                $addr = $this->cleanAddressCandidate($rest, $this->extractName($norm));
                // When the address step was asked, any real answer
                // counts — and it overrides a stale stored address
                // for the same precedence reason. In a blob message,
                // the leftover text must LOOK like an address —
                // "আমার নম্বর" after the number is not one.
                $looksAddress = $addr !== null && ($step === 'address'
                    || str_contains($addr, ',')
                    || mb_strlen($addr) >= 15
                    || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম/u', $addr));
                if (! $looksAddress) {
                    $addr = null;
                }
            }
            if ($addr !== null && mb_strlen($addr) >= 8) {
                $conversation->customer_address = $addr;
                $this->markDetailFresh($conversation, 'address');
            }
        }
        $conversation->save();

        // Sales Growth: a captured phone/address is stage-2 evidence.
        try {
            app(StageService::class)->noteContactShared($conversation);
        } catch (\Throwable) {
        }

        $missing = [];
        if (! $this->detailCollected($conversation, 'phone')) {
            $missing[] = 'phone';
        }
        if (! $this->detailCollected($conversation, 'address')) {
            $missing[] = 'address';
        }

        if ($missing === []) {
            $product = Product::where('business_id', $biz)->find($collect['product_id'] ?? $conversation->product_id);
            $conversation->state = null;
            $state['collect'] = null;
            $conversation->state_data = $state;
            $conversation->save();
            if (!$product) {
                // The product vanished mid-collection: never a silent
                // drop (batch 2's no-silence invariant) — a human takes
                // the thread and the handover is on the record.
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — collection product no longer exists', null, $conversation);

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
            $this->markSummaryPending($conversation, $msg, $variant);

            return $msg;
        }

        if ($sideIntent !== null) {
            // The question is answered and the reminder appended; the
            // collection itself is untouched — same step, same retries,
            // still waiting for the same pieces.
            return $this->collectSideAnswer($conversation, $collect, $sideIntent, $text, $mode);
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

        return $this->simpleOutgoing($conversation, $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name), 'collect_question', $mode, ['collect' => $next]);
    }

    /**
     * A side question asked mid-collection (brain fix batch 5): the
     * customer is part-way through giving order details and asks about
     * the very product being collected — its price, or a push for
     * less. The question is answered through the same machinery the
     * main chain uses (the shop's price template; NegotiationService
     * for the ladder, so a rung agreed here is the rung the eventual
     * summary charges — buildSummary reads neg_offer), the collect
     * reminder for the still-missing pieces is appended, and the
     * collection state is left untouched. Collect-mode replies are
     * word-for-word by design, so the answer is code-rendered from
     * the same slotted sentences, never phrased.
     */
    private function collectSideAnswer(Conversation $conversation, array $collect, string $sideIntent, string $text, string $mode): ?ChatMessage
    {
        $biz = (int) $conversation->business_id;
        $product = Product::where('business_id', $biz)->find($collect['product_id'] ?? $conversation->product_id);
        $reminder = $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name);
        if (! $product) {
            // Nothing to answer about — the plain reminder, never silence.
            return $this->simpleOutgoing($conversation, $reminder, 'collect_question', $mode, ['collect' => ($collect['step'] ?? 'phone')]);
        }
        $variant = ! empty($collect['variant_id']) ? \App\Models\ProductVariant::find($collect['variant_id']) : null;

        if ($sideIntent === 'bargain') {
            // The main chain's stock guard, mirrored: no ladder is
            // quoted for an item that sold out while collecting.
            if (($this->focusAvailable($product, $variant) ?? 1) <= 0) {
                return $this->simpleOutgoing($conversation, $this->outOfStockBody($biz, $conversation->customer_name, $product, $variant) . ' ' . $reminder, 'collect_side_answer', $mode, ['side' => 'bargain', 'out_of_stock' => true]);
            }
            $quote = $this->negotiation->quote($conversation, $product, $this->namedAmount($text));
            if ($quote === null) {
                // The floor already had its last word: the main chain
                // hands over at exactly this point too — logged, and
                // the thread stays flagged for the human.
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', null, $conversation);

                return null;
            }
            $h = $this->honorific($biz, $conversation->customer_name);
            $sentence = match ($quote['kind']) {
                // Already ordering: an acceptance is acknowledged at
                // the settled figure — never a fresh offer, and never
                // "অর্ডার করবেন?" (the main chain appends that question
                // to a standing offer; here the order is under way).
                'accept' => 'ঠিক আছে, ⟦offer⟧ টাকায় ফাইনাল।',
                'floor_repeat' => ($h !== '' ? $h . ', ' : '') . '⟦product⟧ ⟦offer⟧ টাকাই শেষ দাম - এর কমে আর হচ্ছে না।',
                default => 'বুঝতে পারছি' . ($h !== '' ? ' ' . $h : '') . '। ⟦product⟧ আপনার জন্য ⟦offer⟧ টাকায় দিতে পারব।',
            };
            $answer = $this->renderSlots($sentence, $product, $biz, ['offer' => $quote['offer']]);

            return $this->simpleOutgoing($conversation, $answer . ' ' . $reminder, 'collect_side_answer', $mode, ['side' => 'bargain', 'offer' => $quote['offer'], 'quote' => $quote['kind']]);
        }

        // A price question: the shop's own price answer for the
        // product being collected (its intent rule's template, the
        // variant's price when a variant is being collected), with
        // the reminder appended.
        $rule = AiRule::where('business_id', $biz)->where('active', true)
            ->where('trigger_type', 'intent')->where('match_text', 'price')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
            ->first();
        $slotted = $rule
            ? $this->buildReply($rule, $product, $biz, true, $this->honorific($biz, $conversation->customer_name))
            : $this->honorificPrefix($biz, $conversation->customer_name) . '⟦product⟧-এর দাম ⟦price⟧ টাকা।';
        $answer = $this->renderSlots($slotted, $product, $biz, $variant ? ['price' => $variant->effectivePrice()] : []);
        if ($this->hasBrokenFigure($answer)) {
            $answer = $this->honorificPrefix($biz, $conversation->customer_name) . $product->name . '-এর দাম ' . number_format($variant ? $variant->effectivePrice() : $product->effectivePrice()) . ' টাকা।';
        }

        return $this->simpleOutgoing($conversation, $answer . ' ' . $reminder, 'collect_side_answer', $mode, ['side' => 'price']);
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
        // other run finds nothing to do. The TTL covers a slow model
        // call end to end, and processPendingLocked additionally CLAIMS
        // the burst before doing the slow work, so even a lock that
        // expires mid-flight cannot produce a second reply.
        $lock = Cache::store('file')->lock('ai-pending-' . $conversation->id, 120);
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
        if (trim($text) === '') {
            // Media-only burst: the media/voice jobs own those messages;
            // claiming them here would starve that pipeline.
            return null;
        }
        // Claim BEFORE the slow work (claim-first idempotency, the same
        // pattern the courier webhooks use): the processed flag used to
        // be written only after handleIncoming returned, so a second
        // runner — the delayed job and the per-minute sweep — whose
        // lock had expired during a slow model call re-read the same
        // burst as unprocessed and answered it a second time. Claimed
        // first, any twin run finds nothing pending: one customer
        // message, exactly one reply.
        foreach ($pending as $m) {
            $meta = (array) ($m->meta ?? []);
            $meta['processed'] = true;
            $m->meta = $meta;
            $m->save();
        }

        // The no-silence net (brain fix batch 2): the burst is now
        // CLAIMED, so if handling produces neither an outgoing message
        // nor a single activity row, the customer's message would
        // vanish without a trace — claimed, unanswered and unlogged
        // (the live 15:04 bargain push). Whatever path stayed quiet,
        // the miss lands on the record here so staff can see and mine
        // it. Paths that deliberately stay silent (spam, handovers,
        // media) all log their own row and never trip this net.
        $activityBefore = (int) (AiActivity::where('conversation_id', $conversation->id)->max('id') ?? 0);
        $result = $this->handleIncoming($conversation, $text, ['prestored_ids' => $pending->pluck('id')->all()]);
        if ($result === null) {
            $answered = ChatMessage::where('conversation_id', $conversation->id)
                ->where('direction', 'out')->where('id', '>', $lastOutId)->exists();
            $logged = AiActivity::where('conversation_id', $conversation->id)
                ->where('id', '>', $activityBefore)->exists();
            if (! $answered && ! $logged) {
                $this->log((int) $conversation->business_id, 'unanswered', $conversation->customer_name . ' — message produced no reply, draft or logged handover', null, $conversation);
            }
        }

        return $result;
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

        // A message that explicitly names another product switches the
        // conversation to it (with the negotiation reset) BEFORE any
        // intent or rule reads the old product's state.
        $this->switchProductIfNamed($conversation, $text);

        $rule = $this->matchRule($biz, $text, $conversation->channel, $conversation);
        $intent = $this->detectIntent($text, $conversation);

        // A pending product clarification gets its answer first
        // (batch 8): the previous reply listed candidate products and
        // asked which one — this message may be the pick (a name, a
        // SKU, an ordinal, or the one word that separates the
        // candidates). The pending question is consumed either way;
        // an answer that resolves nothing is judged on its own below.
        $pendingPick = null;
        $clarifyState = (array) ($conversation->state_data ?? []);
        if (! empty($clarifyState['product_clarify'])) {
            $pendingClarify = (array) $clarifyState['product_clarify'];
            $candidates = Product::where('business_id', $biz)
                ->whereIn('id', (array) ($pendingClarify['product_ids'] ?? []))
                ->orderBy('id')->get();
            $picked = $this->resolveClarifiedPick($biz, $text, $candidates);
            $clarifyState['product_clarify'] = null;
            $conversation->state_data = $clarifyState;
            $conversation->save();
            if ($picked !== null) {
                $pendingPick = $picked;
                if (! $rule && ! empty($pendingClarify['intent'])) {
                    // The pick itself carries no intent ("2", "ওটা") —
                    // resume the question that was actually asked.
                    $rule = AiRule::where('business_id', $biz)->where('active', true)
                        ->where('trigger_type', 'intent')
                        ->where('match_text', (string) $pendingClarify['intent'])
                        ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
                        ->first() ?? $rule;
                    $intent = $intent ?? (string) $pendingClarify['intent'];
                }
            }
        }

        // Product ambiguity (batch 8): with no product in focus and a
        // product-dependent question (price / stock / bargain / order),
        // never let the word scoring silently guess. When several
        // products tie for the words the customer used — or the
        // message points at no product at all in a multi-product shop —
        // the reply lists the candidates and asks which one (the
        // clarification branch in the reply chain below); no product
        // is put in focus until the customer picks.
        $clarifyWith = null;
        if ($pendingPick !== null) {
            $product = $pendingPick;
        } else {
            if (! $conversation->product_id && $rule && $rule->trigger_type === 'intent'
                && in_array($rule->match_text, ['price', 'stock', 'bargain', 'order_intent'], true)) {
                $tied = $this->ambiguousProductsFor($biz, $text);
                if ($tied !== null) {
                    $clarifyWith = array_slice($tied, 0, 5);
                } elseif ($this->productFor($biz, $text, null) === null && ! $this->namesUnstockedItem($biz, $text)) {
                    // Bare question ("দাম কত?") — the message names
                    // nothing at all. (A message naming an item the
                    // shop does not sell keeps the existing path: the
                    // generic clarify, or the not-available answer
                    // when a product is in focus.)
                    $all = Product::where('business_id', $biz)->orderBy('id')->get();
                    if ($all->count() >= 2) {
                        $clarifyWith = $all->take(5)->all();
                    }
                }
            }
            $product = $clarifyWith !== null ? null : $this->productFor($biz, $text, $conversation);
        }
        if ($product && (int) $conversation->product_id !== (int) $product->id) {
            // Remember the product for this conversation — follow-up
            // messages ("আরেকটু কমান", "স্টকে আছে?") rarely repeat its name.
            $conversation->product_id = $product->id;
            $conversation->focus_at = now();
            $conversation->save();
        }

        // The context package for the AI layers (stage-2 classifier and
        // the talker): recent history + the state code has decided, so
        // neither of them has to judge or phrase this message blind.
        $context = $this->conversationContext($conversation, (int) $incoming->id);

        $stage2 = null;
        if (!$rule) {
            // Stage 2: only when the rule system found nothing, a cheap
            // classifier may suggest the intent/product. Code still decides.
            $stage2 = $this->classifyFallback($biz, $conversation, $text, $context);
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

        // A confirmation that names a figure at/above the floor settles
        // the negotiation at THAT figure before order taking reads
        // neg_offer (the accept-at-counter semantics of
        // NegotiationService::quote). A standing offer confirmed as-is
        // is likewise an agreement, recorded for the reply's meta.
        $agreedNow = null;
        if ($product && $rule && $rule->match_text === 'order_intent' && $conversation->neg_offer !== null) {
            $named = $this->namedAmount($text);
            if ($named !== null && $named >= $this->negotiation->floor($product) && $named < (float) $conversation->neg_offer) {
                $conversation->neg_offer = round($named, 2);
                $conversation->save();
            }
            $agreedNow = (float) $conversation->neg_offer;
        }
        $unmatchedItem = $this->unmatchedItemRequest($biz, $text, $conversation);

        if ($rule && ($rule->match_text === 'spam' || $rule->action === 'hide')) {
            // Ignored, but never silently lost: the message is marked and
            // the ignore is on the record, so staff can review and undo it.
            $incoming->meta = array_merge((array) ($incoming->meta ?? []), ['spam' => true]);
            $incoming->save();
            $this->log($biz, 'spam_ignored', $conversation->customer_name . ' — ' . mb_substr($text, 0, 60), $rule, $conversation);

            return null;
        }

        $handoverAck = false;
        if ($rule && $rule->action === 'handover') {
            $conversation->needs_human = true;
            $conversation->save();
            $this->log($biz, 'handed_human', $conversation->customer_name . ' — ' . $rule->name, $rule, $conversation);
            if ($rule->match_text === 'angry') {
                return $this->holdingLine($conversation, $auto);
            }
            // A handover rule carrying a real acknowledgement template
            // (payment proof received, a cancel request) still sends
            // that one line through the normal guarded path below —
            // silence after a customer sends money proof reads as
            // broken — and the conversation stays flagged for the
            // human either way. A template-less handover stays silent.
            if (mb_strlen(trim((string) $rule->reply_template)) < 10) {
                return null;
            }
            $handoverAck = true;
        }

        $mode = $this->applyWorkingHours($biz, $auto[$conversation->channel] ?? 'draft');
        if ($mode === 'off') {
            return null;
        }

        // A cancellation ends the pending order flow in EVERY
        // pre-order state (batch 7) — checked here, before the
        // collecting dispatch and before the summary-confirmation
        // hook, so a summary the customer just dropped can never be
        // confirmed by the next message.
        $cancel = $this->flowCancellation($conversation, $text, $mode);
        if ($cancel !== null) {
            return $cancel;
        }

        if ($conversation->state === 'collecting') {
            return $this->handleCollect($conversation, $text, $mode);
        }

        // A confirmation of a summary already on the table is handled
        // before the reply chain (batch 3): it creates the order (Full
        // auto), marks the order draft (Draft modes), or answers with
        // the placed order's status — it never re-renders the summary.
        // Live, a confirmed summary looped four times and no order was
        // ever created, because orderTaking could only re-summarise.
        $confirmation = $this->summaryConfirmation($conversation, $product, $biz, $mode, $rule, $intent, $text);
        if ($confirmation !== null) {
            return $confirmation['message'] ?? null;
        }

        // A summary is on the table and this message matched NOTHING
        // the chain knows — no rule, no recognised question. Point at
        // the pending summary instead of falling through to the
        // no-rule fallback, which answers with an unrelated price
        // line about the focused product (batch 7: that fallback is
        // what answered a cancellation while a summary awaited its
        // confirmation). The summary stays exactly as confirmable
        // as it was; the customer is simply told where it stands.
        $pendingState = (array) ($conversation->state_data ?? []);
        if (! empty($pendingState['pending_summary']) && $rule === null && $intent !== 'order_status' && ! $unmatchedItem) {
            $pending = (array) $pendingState['pending_summary'];
            $body = $this->honorificPrefix($biz, $conversation->customer_name)
                . 'আপনার অর্ডারের সারাংশটা উপরে দেওয়া আছে — মোট ' . number_format((float) ($pending['total'] ?? 0)) . ' টাকা। '
                . 'কনফার্ম করতে চাইলে "কনফার্ম" লিখুন, আর অর্ডারটা না নিলে "বাদ দিন" লিখুন।';

            return $this->simpleOutgoing($conversation, $body, 'summary_reminder', $mode, ['summary_message_id' => (int) ($pending['message_id'] ?? 0)]);
        }

        $kind = null;
        if ($clarifyWith !== null) {
            // Never guess a product (batch 8): list the candidates by
            // name — names only, rendered by code from live data, no
            // figures the talker could garble — and ask which one.
            // Nothing is put in focus and no order state is created;
            // the candidates are remembered so the customer's pick
            // ("2", the separating word, the name) resumes the flow.
            $names = collect($clarifyWith)->map(fn (Product $p) => $p->name)->implode(', ');
            $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name)
                . 'কোন পণ্যটার কথা বলছেন? আমাদের পণ্য: ' . $names . '। কোনটা, সেটা বলুন — তাহলেই দাম জানিয়ে দিচ্ছি।';
            $kind = 'product_clarify';
            $meta = ['clarify' => true, 'candidates' => collect($clarifyWith)->map(fn (Product $p) => (int) $p->id)->all()];
            $state = (array) ($conversation->state_data ?? []);
            $state['product_clarify'] = ['product_ids' => $meta['candidates'], 'intent' => $rule->match_text];
            $conversation->state_data = $state;
            $conversation->save();
            $this->log($biz, 'product_clarify', $conversation->customer_name . ' — ambiguous product, asked which one: ' . $names, $rule, $conversation);
        } elseif ($needsVariant) {
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
                // Never silent (batch 2): the burst was already claimed,
                // so an unlogged return would lose the message for good.
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — bargain message with no product in focus', $rule, $conversation);

                return null;
            }
            $quote = $this->negotiation->quote($conversation, $product, $this->namedAmount($text));
            if ($quote === null) {
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', $rule, $conversation);

                return null;
            }
            if ($quote['kind'] === 'accept') {
                // AGREED — the negotiation is over. The offer is never
                // repeated and "অর্ডার করবেন?" is never asked again:
                // the flow moves straight to order taking at the
                // agreed figure (already settled in neg_offer).
                $o = $this->orderTaking($conversation, $product, $variant, $biz, (float) $quote['offer']);
                $slottedBody = $o['slottedBody'];
                $kind = $o['kind'];
                $meta = $o['meta'];
                $body = $o['body'];
                if (isset($o['meta']['offer'])) {
                    $slotValues = ['offer' => $o['meta']['offer']];
                }
            } else {
                $offer = $quote['offer'];
                $h = $this->honorific($biz, $conversation->customer_name);
                $first = match ($quote['kind']) {
                    'floor_repeat' => ($h !== '' ? $h . ', ' : '') . '⟦product⟧ ⟦offer⟧ টাকাই শেষ দাম - এর কমে আর হচ্ছে না।',
                    default => 'বুঝতে পারছি' . ($h !== '' ? ' ' . $h : '') . '। ⟦product⟧ আপনার জন্য ⟦offer⟧ টাকায় দিতে পারব।',
                };
                // The bargained figure is the PRODUCT price, only. The
                // delivery charge is never folded into it, never cut by
                // the ladder, and appears exactly once in the whole
                // flow: as its own line in the order summary.
                $slottedBody = $first . ' অর্ডার করবেন?';
                $slotValues = ['offer' => $offer];
                $meta = ['ladder_step' => (int) $conversation->neg_step, 'offer' => $offer, 'quote' => $quote['kind']];
            }
        } elseif ($rule && $rule->match_text === 'order_intent') {
            if (!$product) {
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — order message with no product in focus', $rule, $conversation);

                return null;
            }
            $o = $this->orderTaking($conversation, $product, $variant, $biz, $agreedNow);
            $slottedBody = $o['slottedBody'];
            $kind = $o['kind'];
            $meta = $o['meta'];
            $body = $o['body'];
            if (isset($o['meta']['offer'])) {
                $slotValues = ['offer' => $o['meta']['offer']];
            }
        } elseif ($unmatchedItem && $intent !== 'order_status'
            && ($rule === null || in_array($rule->match_text, ['price', 'stock'], true))) {
            // A clear request for an item the shop does not sell: say
            // exactly that. Never quote the context product's price at
            // them, never invite an order or an address for it.
            $slottedBody = $this->notAvailableBody($biz, $conversation->customer_name);
            $kind = 'out_of_stock';
            $meta = ['out_of_stock' => true, 'not_available' => true];
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
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — matched rule has an empty reply template', $rule, $conversation);

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
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — no rule matched and no product in focus', null, $conversation);

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
        } elseif ($kind === 'variant_question' || $kind === 'collect_question' || $kind === 'out_of_stock' || $kind === 'product_clarify') {
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
                'previous_reply' => $context['previous_reply'],
                'history' => $context['history'],
                'state' => $context['state'],
            ]);
            if ($phrased) {
                $body = $this->renderSlots($phrased['text'], $product, $biz, $slotValues);
                $renamed = $this->productNounViolation($product, $draftRendered, $body);
                if ($renamed !== null) {
                    // The talker renamed the product (batch 3): the
                    // phrasing is discarded and the code-written draft
                    // goes out instead — the product's name is
                    // code-rendered from the ⟦product⟧ slot, never
                    // paraphrased, and a noun the draft never used
                    // cannot be invented for it. Logged like every
                    // talker failure, so the degradation is visible.
                    $this->log($biz, 'talker_failed', $conversation->customer_name . ' — talker renamed the product (' . $renamed . ' for ' . ($product?->name ?? '') . '); the unphrased template was used instead', $rule, $conversation, null, ['source' => 'talker']);
                    $body = $draftRendered;
                } else {
                    $talkerMeta = ['talker' => $phrased['provider'], 'talker_model' => $phrased['model'], 'talker_tier' => $phrased['tier']];
                }
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
        if ($kind === 'order_summary') {
            // The summary is now on the table: a customer confirmation
            // of it creates the order (batch 3), so remember exactly
            // what was summarised.
            $this->markSummaryPending($conversation, $msg, $variant);
        }
        if ($status === 'sent') {
            $conversation->last_message_at = now();
            // One message, one outcome: the AI just answered this
            // customer itself, so a needs-human flag left over from an
            // earlier message must not keep the thread flagged — the
            // inbox would show "AI replied" and "Needs a human" at once.
            // A handover acknowledgement is the exception: the human is
            // still being asked for, so the flag stays.
            if (! $handoverAck) {
                $conversation->needs_human = false;
            }
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
            // Learning loop (owner addendum, 2026-11-25): the proposal
            // records the edit WITH its context — the customer message,
            // the recent history and the conversation state (the same
            // context package the classifier and the talker receive) —
            // plus both texts, so what is learned is context-accurate,
            // not a bare string swap. A real correction (more than
            // whitespace or case) carries the learned-rule shape, so the
            // seller can approve it as a rule in Settings; and the SAME
            // correction made 3 times for the same case auto-creates
            // that shop rule (below). A change that normalises to the
            // same text is not a correction at all: it stays a note,
            // never a rule (Hunt Y2-7).
            $aiBody = (string) $msg->body;
            $humanBody = trim($editedBody);
            $normalize = fn (string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
            $realCorrection = $normalize($aiBody) !== $normalize($humanBody);
            $incoming = $conv ? ChatMessage::where('conversation_id', $conv->id)->where('direction', 'in')->where('id', '<', $msg->id)->orderByDesc('id')->first() : null;
            $context = $conv ? $this->conversationContext($conv, $incoming ? (int) $incoming->id : (int) $msg->id) : ['history' => [], 'previous_reply' => null, 'state' => []];
            $matchText = $msg->rule?->match_text;
            $learnedRule = null;
            $patternKey = null;
            if ($realCorrection) {
                $keyword = mb_substr($normalize((string) ($incoming?->body ?? '')), 0, 120);
                $patternKey = sha1(($matchText ?? '') . '|' . $keyword . '|' . $normalize($humanBody));
                if ($keyword !== '') {
                    $learnedRule = [
                        'name' => 'Learned: ' . ($msg->rule?->name ?? 'Staff correction'),
                        'trigger_type' => 'keyword',
                        'match_text' => $keyword,
                        'action' => in_array($msg->rule?->action, ['answer', 'draft'], true) ? $msg->rule->action : 'draft',
                        'reply_template' => $humanBody,
                        'channel' => null,
                    ];
                }
            }
            $proposal = AiRuleProposal::create([
                'business_id' => $msg->business_id,
                'title' => 'Staff edit থেকে শেখা - reply বদলানো হয়েছে',
                'detail' => 'আগের draft: "' . $aiBody . '" → staff পাঠিয়েছে: "' . $humanBody . '"',
                'rule_data' => [
                    'source_message_id' => $msg->id,
                    'ai_body' => $aiBody,
                    'edited_body' => $humanBody,
                    'intent' => $matchText,
                    'customer_message' => $incoming?->body,
                    'context' => $context,
                    'pattern_key' => $patternKey,
                    'note_only' => ! $realCorrection,
                    ...($learnedRule ? ['new_rule' => $learnedRule] : []),
                ],
                'status' => 'pending',
            ]);
            $this->log((int) $msg->business_id, 'proposal_created', 'Staff edit থেকে নতুন প্রস্তাব এসেছে', $msg->rule, $msg->conversation);

            // Repetition is the signal: the same correction for the
            // same case, made 3 times, becomes the shop's own rule —
            // active at once, and visible and reversible in the
            // seller's Rule Brain settings like any other rule.
            if ($realCorrection && $learnedRule) {
                $repeats = AiRuleProposal::where('business_id', $biz)->where('status', '!=', 'rejected')->get()
                    ->filter(fn ($p) => ($p->rule_data['pattern_key'] ?? null) === $patternKey)->count();
                $exists = AiRule::where('business_id', $biz)->where('trigger_type', 'keyword')->where('match_text', $learnedRule['match_text'])->exists();
                if ($repeats >= 3 && ! $exists) {
                    $rule = AiRule::create($learnedRule + ['business_id' => $biz, 'active' => true]);
                    $data = (array) $proposal->rule_data;
                    $data['learned_rule_id'] = $rule->id;
                    $proposal->rule_data = $data;
                    $proposal->save();
                    $this->log($biz, 'rule_learned', 'একই staff correction বারবার হওয়ায় নতুন rule তৈরি হয়েছে: ' . $rule->name, $rule, $conv);
                }
            }
            $msg->body = $humanBody;
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
            'previous_reply' => null,
            'history' => [],
            'state' => [],
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

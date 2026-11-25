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
        'price' => ['দাম', 'price', 'কত', 'koto', 'dam koto', 'দামটা', 'dam ki', 'dam bolen', 'koto taka', 'কত টাকা', 'মূল্য', 'রেট'],
        'delivery' => ['delivery', 'ডেলিভারি', 'চার্জ', 'charge', 'shipping', 'পাঠাতে কত'],
        'stock' => ['স্টক', 'stock', 'আছে কি', 'available', 'ache ki', 'আছেনি'],
        'greeting' => ['আসসালামু', 'salam', 'hello', 'hi', 'হ্যালো', 'আদাব'],
        'bargain' => ['কমান', 'কমাতে', 'কম হবে', 'কম রাখেন', 'dam kom', 'less hobe', 'kom hobe', 'ektu kom', 'komano', 'শেষ দাম', 'কম দাম', 'কমে দেন', 'দিলে নিব', 'টাকায় দিলে', 'discount hobe', 'discount হবে', 'ডিসকাউন্ট হবে'],
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
        // Profanity only (batch 15): the one list that separates ABUSE
        // from an angry complaint. A complaint ("এখনো পাইনি", "অভিযোগ")
        // carries none of these and keeps the angry path; a message
        // carrying one of these gets silence + the human flag.
        'abuse' => ['হারামজাদা', 'হারামি', 'খানকি', 'বাইনচোদ', 'মাগির পোলা', 'কুত্তার বাচ্চা', 'fuck', 'motherfucker', 'asshole', 'bastard'],
        'payment_proof' => ['টাকা পাঠিয়েছি', 'টাকা পাঠালাম', 'পেমেন্ট করেছি', 'পেমেন্ট করলাম', 'ট্রানজেকশন', 'ট্রান্সফার করেছি', 'স্ক্রিনশট', 'TrxID', 'taka pathiyechi', 'taka pathalam', 'payment korechi', 'payment done', 'paid', 'sent money', 'transaction id', 'trx id', 'screenshot'],
        'payment_problem' => ['ভুল নম্বরে পাঠিয়েছি', 'ভুল নাম্বারে', 'টাকা কেটে নিয়েছে', 'টাকা কেটেছে কিন্তু', 'পেমেন্ট হয়নি', 'পেমেন্ট ফেইল', 'vul number e pathiyechi', 'taka kete niyeche', 'payment hoyni', 'payment failed', 'money deducted', 'wrong number'],
        'cancel_order' => ['অর্ডার ক্যান্সেল', 'অর্ডার বাতিল', 'অর্ডারটা বাতিল', 'ক্যান্সেল করে দিন', 'বাতিল করে দিন', 'order cancel', 'cancel my order', 'cancel korun', 'cancel koren', 'cancel the order'],
        'late_delivery' => ['এখনো পাইনি', 'এখনও পাইনি', 'এখনো আসেনি', 'পাচ্ছি না', 'অনেক দেরি', 'কতদিন আর', 'দেরি হচ্ছে', 'কুরিয়ার দিচ্ছে না', 'ekhono paini', 'ekhono ashe nai', 'pacchi na', 'onek deri', 'deri hocche', 'not received yet', 'still not received', 'delayed', 'courier not delivering'],
        // A missed delivery the customer wants sent AGAIN (batch 15):
        // distinct from a late-delivery complaint — the ask is a
        // rebooking, which only a human can make with the courier.
        'redelivery' => ['আবার ডেলিভারি', 'ডেলিভারি আবার', 'রিডেলিভারি', 'redelivery', 're-delivery', 'deliver again', 'missed delivery', 'missed the delivery', 'কুরিয়ার এসে ফিরে গেছে', 'ডেলিভারি মিস'],
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
        'delivery_time' => ['কতদিনে পাব', 'কত দিন লাগবে', 'কতদিন লাগবে', 'কতদিনে লাগবে', 'কয়দিন লাগবে', 'ডেলিভারি টাইম', 'কতক্ষণ লাগবে', 'কবে নাগাদ', 'kobe pabo', 'koto dine pabo', 'koydin lagbe', 'delivery time', 'how long', 'how many days', 'when will i get'],
        'delivery_coverage' => ['আমার এলাকায় ডেলিভারি', 'সব জায়গায় ডেলিভারি', 'সারা দেশে', 'গ্রামে ডেলিভারি', 'উপজেলায় ডেলিভারি', 'কোন কুরিয়ারে', 'কুরিয়ারে পাঠান', 'সুন্দরবন', 'home delivery hoy', 'amar elakay delivery', 'shara deshe', 'courier e pathan', 'sundarban courier', 'which courier', 'do you deliver to'],
    ];

    public function __construct(
        private SettingsService $settings,
        private NegotiationService $negotiation,
        private AiTalkerService $talker,
        private AiGuardService $guard,
        private AiClassifierService $classifier,
        private HonorificService $honorifics,
        private MetaGraphService $graph,
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

    /**
     * The off-hours stretch a moment belongs to, identified by the
     * Dhaka date on which the stretch BEGAN (batch 15, finding #23):
     * for a daytime window (9 AM - 11 PM) the stretch starts at the
     * window's end — this evening, or yesterday evening when the
     * moment is before the window opens; for an overnight window
     * (8 PM - 2 AM) it starts at the window's end that same morning.
     * One identifier per continuous closed period, so "once per
     * stretch" survives midnight.
     */
    private function offHoursStretchKey(int $businessId): ?string
    {
        $window = $this->workingHoursWindow($businessId);
        if ($window === null || $window[0] === $window[1]) {
            return null;
        }
        [$start, $end] = $window;
        $now = \Carbon\Carbon::now(\App\Support\DhakaTime::TZ);
        $mins = $now->hour * 60 + $now->minute;
        $stretch = $now->copy()->setTime(intdiv($end, 60), $end % 60, 0);
        if ($start < $end && $mins < $start) {
            $stretch->subDay();
        }

        return $stretch->toDateString();
    }

    /**
     * The one customer-facing off-hours line (batch 15, finding
     * #23): when the hours step-down has just turned an auto mode
     * into a draft, the customer is told the shop is closed and when
     * it opens — SENT, once per conversation per off-hours stretch,
     * never repeatedly. It fires only on a real step-down: a shop
     * that chose Draft itself, or a channel that is off, gets no
     * line (nothing was promised to that customer in the first
     * place). The shop's own hours text from Settings is quoted when
     * it is saved; a generic line stands in when it is not.
     */
    private function offHoursLineOnce(Conversation $conversation, string $rawMode): void
    {
        if ($rawMode !== 'auto' && $rawMode !== 'full_auto') {
            return;
        }
        $biz = (int) $conversation->business_id;
        if ($this->withinWorkingHours($biz)) {
            return;
        }
        $stretch = $this->offHoursStretchKey($biz);
        if ($stretch === null) {
            return;
        }
        $state = (array) ($conversation->state_data ?? []);
        if (($state['offhours_line'] ?? null) === $stretch) {
            return;
        }
        $state['offhours_line'] = $stretch;
        $conversation->state_data = $state;
        $conversation->save();
        $hours = trim((string) $this->settings->bizGet($biz, "ai.{$biz}.working_hours", 'ai.working_hours', ''));
        $body = $this->honorificPrefix($biz, $conversation->customer_name)
            . ($hours !== ''
                ? 'এখন আমাদের দোকান বন্ধ আছে। আমাদের সময়: ' . $hours . '। আপনার মেসেজ পেয়েছি — দোকান খুললেই উত্তর পাবেন।'
                : 'এখন আমাদের দোকান বন্ধ আছে। আপনার মেসেজ পেয়েছি — দোকান খুললেই উত্তর পাবেন।');
        $line = $this->simpleOutgoing($conversation, $body, 'off_hours', 'full_auto');
        // The line must actually be SENT (batch 16, live Part F):
        // every entry point dispatches only the message
        // handleIncoming RETURNS, and this line is a side message —
        // it was created 'sent' but no Graph call ever happened for
        // it, no send outcome was recorded, and the customer never
        // received it. Dispatch it here, through the same path as
        // every other send, so its meta carries send_status /
        // send_error (with Meta's code fields) exactly like theirs.
        // dispatch() itself no-ops on conversations with no live
        // channel identity, so demo/test threads are untouched.
        $this->graph->dispatch($line);
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

    /**
     * Weak order words (batch 12, review finding #19): everyday verbs
     * that mean "I'll take it" — 'নিব', 'nibo', 'লাগবে', 'কিনব'. They
     * are deliberately NOT in INTENTS['order_intent']: matchRule's
     * keyword fallback re-checks that list, and a bare 'নিব' there
     * would outrank the specific keyword rules these words live
     * inside ("পরে নিব" = decide later, "আজকেই লাগবে" = urgent
     * delivery). detectIntent is the only reader, and it applies the
     * guards below before believing them.
     */
    private const WEAK_ORDER_WORDS = ['নিব', 'নিবো', 'nibo', 'লাগবে', 'lagbe', 'কিনব', 'kinbo'];

    /**
     * Phrases that disarm the weak order words (batch 12): a decline
     * ("লাগবে না", "নিব না"), a deferral ("পরে নিব") or an urgency
     * ask ("আজকেই লাগবে") contains an order verb but is not an order.
     * When one is present, the weak words are ignored and the
     * message falls through to the keyword rules written for it.
     */
    private const ORDER_GUARD_PHRASES = [
        'নিব না', 'নিবো না', 'নেব না', 'লাগবে না', 'কিনব না', 'nibo na', 'lagbe na', 'kinbo na',
        'পরে নিব', 'পরে নিবো', 'পরে জানাব', 'পরে অর্ডার', 'পরে কিনব', 'ভেবে দেখি', 'ভেবে জানাব', 'pore nibo', 'pore janabo',
        'আজকেই লাগবে', 'আজকের মধ্যে', 'কালকের মধ্যে', 'কালকেই লাগবে', 'ajkei lagbe',
        // "কত লাগবে" asks what something will COST ("পাঠাতে কত লাগবে?"
        // is the delivery-charge question), it does not place an order.
        'কত লাগবে', 'koto lagbe',
        // "কতদিন লাগবে" asks how long DELIVERY takes (batch 15): the
        // weak verb inside it is not an order either — guarded, the
        // message reaches the delivery_time check below.
        'কতদিন লাগবে', 'কত দিন লাগবে', 'কতদিনে', 'কয়দিন লাগবে',
        // An invoice/memo request rides on the same weak verb ("মেমো
        // লাগবে") but asks for a document, not a product (batch 15):
        // guarded, it falls through to the Invoice or memo rule.
        'মেমো লাগবে', 'ইনভয়েস লাগবে', 'ইনভয়েস দেন', 'মেমো দেন', 'রিসিপ্ট লাগবে',
    ];

    /**
     * Is this message asking ABOUT a product attribute — its weight,
     * size, material, warranty, colour — rather than its price?
     * (Batch 12, finding #8.) 'কত' is a price word, so "ওজন কত?"
     * used to get a price answer even though the catalog carries
     * attribute rules for exactly these questions. The boundary is
     * the price signal itself: a message that names the price
     * ("সাইজ L এর দাম কত?") IS a price question about a variant and
     * stays one; an attribute word in question shape with no price
     * signal is the attribute's own question. The romanised forms
     * (ojon, wajan, map — batch 16, live C1: "CRUD-101 er ojon
     * koto?" got the price) count the same as their Bengali twins.
     */
    private function isAttributeQuestion(string $hay): bool
    {
        if (str_contains($hay, 'দাম') || preg_match('/\bprice\b/', $hay) || preg_match('/\bdam\b/', $hay)) {
            return false;
        }
        $attribute = false;
        foreach (['ওজন', 'weight', 'সাইজ', 'size', 'মাপ', 'ম্যাটেরিয়াল', 'material', 'ওয়ারেন্টি', 'warranty', 'গ্যারান্টি', 'guarantee', 'কালার', 'colour', 'color', 'কাপড়', 'fabric', 'ojon', 'wajan'] as $w) {
            if (str_contains($hay, $w)) {
                $attribute = true;
                break;
            }
        }
        // The romanised word for মাপ is 'map' (batch 16) — also the
        // English word for a map, so it counts only as a whole word,
        // and the question shape required below does the rest.
        if (! $attribute && preg_match('/\bmap\b/', $hay)) {
            $attribute = true;
        }
        if (! $attribute) {
            return false;
        }

        return str_contains($hay, 'কত') || str_contains($hay, '?')
            || str_contains($hay, 'কী') || str_contains($hay, 'কি')
            || str_contains($hay, 'কেমন') || str_contains($hay, 'আছে')
            || (bool) preg_match('/\bkoto\b/', $hay)
            || (bool) preg_match('/\bache\b/', $hay)
            || (bool) preg_match('/\bki\b/', $hay);
    }

    /**
     * Coupon/offer vocabulary (batch 12, finding #9): the customer is
     * asking about a code, coupon or running offer — the catalog's
     * Offer rule's case, never a bargain push. Bare 'discount' used
     * to sit in the bargain word list, so "discount code ache?"
     * opened the price ladder instead of reaching that rule.
     */
    private function hasOfferVocabulary(string $hay): bool
    {
        foreach (['কোড', 'কুপন', 'প্রোমো', 'ভাউচার', 'অফার', 'কম্বো', 'coupon', 'promo', 'voucher', 'offer', 'combo', 'discount code'] as $w) {
            if (str_contains($hay, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "I ordered but the confirmation never came" (batch 15): the
     * message names a message/confirmation AND says it did not
     * arrive. Both halves are required — a bare "মেসেজ দিয়েন" (send
     * me a message) names no absence, and a bare "পাইনি" is the
     * late-delivery complaint it has always been.
     */
    private function confirmationMissing(string $hay): bool
    {
        $namesMessage = false;
        foreach (['মেসেজ', 'এসএমএস', 'sms', 'কনফার্মেশন', 'confirmation'] as $w) {
            if (str_contains($hay, $w)) {
                $namesMessage = true;
                break;
            }
        }
        if (! $namesMessage) {
            return false;
        }
        foreach (['পাইনি', 'পাই নাই', 'পাইনাই', 'আসেনি', 'আসে নাই', 'পেলাম না', 'painai', 'ashe nai', 'ase nai', 'not received', 'did not get', "didn't get", 'no confirmation'] as $w) {
            if (str_contains($hay, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "The price is too high" (batch 15): the excess words around a
     * price word — অনেক দাম, দাম বেশি, too expensive. Judged on
     * phrases, never on 'দাম' alone, so the plain price question
     * ("দাম কত?") and the delivery-charge complaint ("ডেলিভারি
     * চার্জ বেশি" — no price word) both stay what they are.
     */
    private function isPriceTooHigh(string $hay): bool
    {
        foreach (['অনেক দাম', 'দাম বেশি', 'দামটা বেশি', 'দাম অনেক বেশি', 'দাম অনেক', 'বেশি দাম', 'দাম বহুত', 'onek dam', 'dam beshi', 'dam onek', 'beshi dam', 'dam ta beshi', 'too expensive', 'very expensive', 'price is too high', 'dam beshi hoye gelo'] as $p) {
            if (str_contains($hay, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this message CORRECTING a detail rather than stating one
     * (batch 15)? "নম্বরটা ভুল, সঠিকটা 017…" corrects the phone; the
     * leftover text after the number is stripped is correction talk,
     * not an address — yet both blob-address rules read it as one
     * (it contains a comma) and a collection "completed" with ঠিকানা:
     * "নম্বরটা ভুল, সঠিকটা". A correction marker anywhere in the
     * message disarms the blob rules; LABELLED parts are unaffected
     * (a labelled address in a correction message is an address
     * correction and lands through the labelled path as before).
     */
    private function isCorrectionText(string $hay): bool
    {
        // 'বদল' alone is deliberately NOT a marker: বদলগাছী is a
        // place name that can stand inside a real address.
        foreach (['ভুল', 'সঠিক', 'বদলে', 'change', 'correct', 'wrong'] as $w) {
            if (str_contains($hay, $w)) {
                return true;
            }
        }

        return false;
    }

    /** Does this conversation's customer have an order with the shop? */
    private function conversationHasOrder(?Conversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }
        $state = (array) ($conversation->state_data ?? []);
        if (! empty($state['last_order'])) {
            return true;
        }
        $phone = trim((string) ($conversation->customer_phone ?? ''));

        return $phone !== '' && \App\Models\Order::where('business_id', (int) $conversation->business_id)
            ->where('customer_phone', $phone)->exists();
    }

    /**
     * The invoice/order number a message quotes, if any (batch 12,
     * finding #20): a letter prefix plus digits — "OB299", "OB 299",
     * "OB-299", Bengali digits included — compacted to the stored
     * shape (prefix + number, upper-cased). A bare figure (a price,
     * a phone) is not an invoice mention: the prefix is required.
     */
    public function invoiceMention(string $text): ?string
    {
        $t = $this->guard->normalizeDigits($text);
        if (preg_match('/([A-Za-z]{1,8})[-\s]?(\d{2,})/', $t, $m)) {
            return strtoupper($m[1] . $m[2]);
        }

        return null;
    }

    public function detectIntent(string $text, ?Conversation $conversation = null): ?string
    {
        if ($this->isReaction($text)) {
            return 'reaction';
        }
        $hay = mb_strtolower($text);
        // "Ordered but no confirmation message came" (batch 15): an
        // order-status question wearing complaint words — 'পাইনি' is
        // an angry word and 'মেসেজ পাইনি' used to hand the customer
        // to a human as if they were complaining. It is judged before
        // the fine loop for exactly that reason; the status branch
        // answers it from the order record by phone.
        if ($this->confirmationMissing($hay)) {
            return 'order_status';
        }
        // Fine-grained intents first, in the library's priority order:
        // each of these contains words a coarser intent would claim
        // (legal words sit inside the angry list, "এখনো পাইনি" is an
        // angry word, a COD question is full of delivery words), so the
        // specific case must be judged before the coarse one.
        foreach (['legal_threat', 'abuse', 'spam', 'payment_proof', 'payment_problem', 'cancel_order', 'late_delivery', 'redelivery', 'angry', 'wholesale', 'wants_human', 'are_you_bot', 'change_order', 'open_box', 'competitor_price', 'price_mismatch', 'delivery_negotiation', 'restock', 'advance_payment'] as $fine) {
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
            // A message carrying the customer's own details is order
            // input, never another ladder round (brain fix batch 9):
            // the bot asked "অর্ডার করবেন?" and the customer answered
            // with his name, phone and address — but the digit branch
            // below read the phone's digits as a counter-offer, and
            // the ladder stepped down with no summary (live
            // 2026-11-25 18:56: 1,235 -> 1,170). Batch 1 covered
            // confirmations carrying digits and batch 5 covered
            // collect mode; bargain mode outside collect was the
            // state left uncovered.
            if ($this->carriesDetails($text)) {
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
        // Attribute questions beat price (batch 12, finding #8): "ওজন
        // কত?" carries the price word 'কত' but asks about the product,
        // not its price — the catalog's attribute rules answer it.
        // Returning null hands the message to matchRule's keyword
        // competition, where those rules live; a message that names
        // the price ("সাইজ L এর দাম কত?") never reaches here as an
        // attribute question (isAttributeQuestion excludes it).
        if ($this->isAttributeQuestion($hay)) {
            return null;
        }
        // Coupon/offer vocabulary is the Offer rule's case, never a
        // bargain push (batch 12, finding #9). No intent rule carries
        // the name 'offer', so matchRule falls through to its keyword
        // competition and the Offer rule wins there on its own words.
        if ($this->hasOfferVocabulary($hay)) {
            return 'offer';
        }
        if ($this->intentMatches('bargain', $hay)) {
            return 'bargain';
        }
        if ($this->intentMatches('order_intent', $hay)) {
            return 'order_intent';
        }
        // Weak order words (batch 12, finding #19): "নিব", "লাগবে",
        // "কিনব" mean the customer wants the product — unless a guard
        // phrase makes them a decline, a deferral or an urgency ask,
        // in which case the keyword rules written for those cases
        // decide instead.
        $guarded = false;
        foreach (self::ORDER_GUARD_PHRASES as $g) {
            if (str_contains($hay, $g)) {
                $guarded = true;
                break;
            }
        }
        if (! $guarded) {
            foreach (self::WEAK_ORDER_WORDS as $w) {
                if (str_contains($hay, $w)) {
                    // A request naming an item the shop does not sell
                    // is not an order for whatever is in focus (batch
                    // 1's not-available doctrine): "লাল জামা XL লাগবে"
                    // in a panjabi shop must reach the unmatched-item
                    // branch below, not order taking — the weak verb
                    // alone cannot override it.
                    if ($conversation !== null && $this->namesUnstockedItem((int) $conversation->business_id, $text)) {
                        break;
                    }

                    return 'order_intent';
                }
            }
        }
        // Order status. The delivery-time phrasings inside the old
        // word list ("কবে পাব?") are only a status question when
        // there IS an order to track (batch 12, finding #21): asked
        // before ordering, they are the delivery_time question the
        // catalog already carries a rule for.
        foreach (['অর্ডার কোথায়', 'অর্ডারটা কোথায়', 'কোথায় আছে', 'পৌঁছেছে', 'ট্র্যাকিং', 'tracking', 'স্ট্যাটাস', 'kothay ache', 'kothay ase', 'order kothay', 'ki obostha'] as $w) {
            if (str_contains($hay, $w)) {
                return 'order_status';
            }
        }
        foreach (['কবে পাব', 'কবে পৌঁছাবে'] as $w) {
            if (str_contains($hay, $w)) {
                return $this->conversationHasOrder($conversation) ? 'order_status' : 'delivery_time';
            }
        }
        // A quoted invoice number with a where-is-it question is a
        // status question even when the number splits the usual
        // phrases ("OB299 কোথায়?") — the number is looked up in
        // handleIncoming's status branch (batch 12, finding #20).
        // The where-words count in their romanised forms too
        // (batch 16, live C3: "OB299 kothay ache?" never reached
        // the lookup — the gate only knew Bengali script).
        if ($this->invoiceMention($text) !== null
            && (str_contains($hay, 'কোথায়') || str_contains($hay, 'কই') || str_contains($hay, 'স্ট্যাটাস') || str_contains($hay, 'status')
                || str_contains($hay, 'kothay') || str_contains($hay, 'obostha') || (bool) preg_match('/\bkoi\b/', $hay))) {
            return 'order_status';
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
        // "The price is too high" (batch 15): a reaction to a price
        // already seen — an opening push, not a question. It used to
        // land on the price intent ('দাম' is a price word) and get
        // the same figure quoted back, a dead end. As a bargain it
        // enters the ladder like any other push. A plain price
        // QUESTION ("দাম কত?") names no excess and never matches.
        if ($this->isPriceTooHigh($hay)) {
            return 'bargain';
        }
        // Price, plus the two vocabulary additions that cannot live
        // in the word list (batch 12, finding #19): 'rate' only as a
        // whole English word (never inside 'separate' or 'generate'),
        // and 'দর' only as a standalone Bengali word — 'দরকার' (need)
        // and 'দরজা' (door) carry a Bengali letter right after it, so
        // the separator-bounded pattern never fires on them.
        if ($this->intentMatches('price', $hay)
            || preg_match('/\brate\b/', $hay)
            || preg_match('/(?:^|[\s,।?!])দর(?:[\s,।?!]|$)/u', $hay)) {
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
                // A missing-confirmation message (batch 15) carries
                // two misleading words — the angry word 'পাইনি' and
                // 'কনফার্ম' inside 'কনফার্মেশন' — but IS an
                // order-status question: detectIntent above has
                // already said so. The word fallback must not
                // resurrect the angry handover or an order intent
                // for it.
                if (in_array($rule->match_text, ['angry', 'order_intent'], true) && $intent === 'order_status' && $this->confirmationMissing($hay)) {
                    continue;
                }

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
        // Does this switch tear down a live flow? Read BEFORE the
        // clearing below (batch 16): when it does, the close-out
        // arms the next message for flowCancellation — the switch
        // leaves no collect, ladder or summary behind for its
        // flow-active test to find, but a "বাদ দিন" that follows is
        // still the customer closing THIS conversation's business.
        $hadFlow = $conversation->state === 'collecting'
            || (int) $conversation->neg_step >= 1
            || $conversation->neg_offer !== null
            || ! empty(((array) ($conversation->state_data ?? []))['pending_summary']);
        $conversation->product_id = $named->id;
        $conversation->focus_at = now();
        $conversation->neg_step = 0;
        $conversation->neg_offer = null;
        if ($conversation->state === 'collecting') {
            $conversation->state = null;
        }
        $state = (array) ($conversation->state_data ?? []);
        foreach (['collect', 'cart', 'variant_pending', 'variant_id', 'floor_repeats', 'pending_summary', 'last_order', 'product_clarify', 'qty', 'qty_offer'] as $key) {
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
        if ($hadFlow) {
            $state['flow_just_closed'] = true;
        }
        $conversation->state_data = $state;
        $conversation->save();
        $this->log((int) $conversation->business_id, 'product_switched', $conversation->customer_name . ' — customer named a different product: ' . $named->name, null, $conversation);
    }

    /**
     * The product in focus, when the focus is still FRESH (batch 11,
     * finding #1). focus_at was written on every focus change and
     * read nowhere, so a product named days ago answered today's
     * bare questions as if it had just been mentioned. A focus older
     * than six hours counts as no focus at all. A missing timestamp
     * (rows from before it was tracked, or a focus a test/seller set
     * directly) cannot be proven stale, so it stays in force.
     * Every reader of the stored focus goes through here — the
     * resolution step, productFor(), the talker's context package
     * and the unstocked-item guard — so staleness means the same
     * thing everywhere the focus is consulted.
     */
    private function freshFocusProduct(Conversation $conversation): ?Product
    {
        if (! $conversation->product_id) {
            return null;
        }
        if ($conversation->focus_at !== null && $conversation->focus_at->lt(now()->subHours(6))) {
            return null;
        }

        return Product::where('business_id', (int) $conversation->business_id)->find($conversation->product_id);
    }

    public function productFor(int $businessId, string $text, ?Conversation $conversation = null): ?Product
    {
        if ($conversation !== null) {
            $focused = $this->freshFocusProduct($conversation);
            if ($focused) {
                return $focused;
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
        // Category words count across scripts (batch 11, finding
        // #10): the customer writes "পাঞ্জাবি", the shop's products
        // are named "Panjabi" — without help the word scoring saw no
        // shared word at all and the category matched nothing. When
        // any form of a noun-equivalence group (batch 3) appears in
        // the message, every form of that group counts as said, so
        // product-name words match against the expanded text. The
        // full-name bonus below still reads the raw text — saying
        // the whole name is a stronger signal than a category word.
        $matchHay = $hay;
        foreach (self::ITEM_NOUN_GROUPS as $group) {
            if ($this->nounGroupInText($group, $hay)) {
                $matchHay .= ' ' . implode(' ', $group);
            }
        }
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
            $matched = array_values(array_filter($words, fn ($w) => str_contains($matchHay, $w)));
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
     * customer said more of one product's name than of any other).
     * A tie is a tie even when the tied products share one price
     * (batch 11, finding #11): the figure cannot be misquoted then,
     * but the products still differ — colour, design, photo — so
     * silently naming one is still a guess about WHICH product the
     * customer means. Batch 8 exempted same-price ties; the external
     * review reversed that and the owner accepted the reversal.
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

        return array_map(fn ($r) => $r['product'], $tied);
    }

    /**
     * THE product resolution step (brain fix batch 11 — Step 1 of
     * the external review, findings #1, #2, #12): before the reply
     * chain reads a product, the message is resolved to exactly one
     * outcome.
     *
     *  - EXPLICIT: the message names a product outright (SKU, alias,
     *    full name, every name word) — that product. (When it
     *    differs from the focus, switchProductIfNamed() has already
     *    moved the focus and reset the old product's negotiation.)
     *  - CATEGORY_MULTI: a category/partial word ties two or more
     *    products — the caller asks which one, listing ONLY the
     *    matched products. A focus product no longer suppresses the
     *    question: batch 8 asked only with no focus, so a stale
     *    focus silently won over the category word (live
     *    2026-11-25 19:20: with CRUD-101 in focus, "Panjabi tar dam
     *    koto?" quoted its 1,300 while CRUD-102 matched the word
     *    just as well). The bare-question listing (a message naming
     *    no product at all, nothing fresh in focus) is returned
     *    through this same outcome, as batch 8 built it.
     *  - FOCUS_REF: the message names no product — a demonstrative
     *    ("এটা", "ওটা", "এইটা", "eta", "or dam") or a bare
     *    follow-up ("আরেকটু কমান") — and the focus is fresh: the
     *    focus product. A stale focus (over six hours) is no focus.
     *  - NONE: nothing matched and no fresh focus. A message naming
     *    an item the shop does not sell lands here too, so the
     *    existing not-available path answers it — never the listing.
     *
     * @return array{type: string, product: ?Product, candidates: ?array}
     */
    private function resolveProduct(Conversation $conversation, string $text, ?AiRule $rule): array
    {
        $biz = (int) $conversation->business_id;
        $productDependent = $rule !== null && $rule->trigger_type === 'intent'
            && in_array($rule->match_text, ['price', 'stock', 'bargain', 'order_intent'], true);

        $explicit = $this->explicitProductFor($biz, $text);
        if ($explicit !== null) {
            return ['type' => 'explicit', 'product' => $explicit, 'candidates' => null];
        }

        if ($productDependent) {
            $tied = $this->ambiguousProductsFor($biz, $text);
            if ($tied !== null) {
                return ['type' => 'category_multi', 'product' => null, 'candidates' => array_slice($tied, 0, 5)];
            }
        }

        $focus = $this->freshFocusProduct($conversation);
        if ($focus !== null) {
            return ['type' => 'focus_ref', 'product' => $focus, 'candidates' => null];
        }

        if ($productDependent && $this->productFor($biz, $text, null) === null && ! $this->namesUnstockedItem($biz, $text)) {
            // Bare question ("দাম কত?") with nothing fresh in
            // focus: the message names nothing at all, so the reply
            // lists what the shop sells and asks (batch 8).
            $all = Product::where('business_id', $biz)->orderBy('id')->get();
            if ($all->count() >= 2) {
                return ['type' => 'category_multi', 'product' => null, 'candidates' => $all->take(5)->all()];
            }
        }

        $scored = $this->productFor($biz, $text, null);
        if ($scored !== null) {
            return ['type' => 'scored', 'product' => $scored, 'candidates' => null];
        }

        return ['type' => 'none', 'product' => null, 'candidates' => null];
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
     * The variant in focus for this conversation: the one this message
     * names ("L সাইজ", "লালটা"), else the one already chosen. A new
     * choice is remembered on the conversation.
     */
    public function variantFor(Conversation $conversation, Product $product, string $text): ?\App\Models\ProductVariant
    {
        $variants = $product->activeVariants();
        if ($variants->isEmpty()) {
            return null;
        }
        // A variant NAMED in this message wins — over the remembered
        // choice too (batch 14, finding #13): the remembered choice
        // used to be returned before the text was ever read, so once
        // any variant was picked, naming a different one ("M ta den")
        // switched nothing and the first choice priced, stocked and
        // summarised the whole flow.
        // The haystack is normalised for the Bengali definiteness
        // suffixes first (batch 14, finding #14): "লালটা" / "লালটার"
        // are the variant লাল wearing its suffix, and the boundary
        // pattern below reads any Bengali letter after the token as
        // "still inside a word", so the suffixed form could never
        // match. Stripping the suffix from haystack words lets the
        // plain stem match; Latin text is untouched (the suffixes
        // are Bengali words themselves).
        $hay = (string) preg_replace('/(টাকে|টার|টা|টি)(?=[\s,\x{0964}।?!]|$)/u', '', mb_strtolower($text));
        foreach ($variants as $v) {
            foreach (preg_split('/[\s:\/,]+/u', mb_strtolower($v->name)) ?: [] as $tok) {
                $tok = trim($tok);
                if ($tok === '' || in_array($tok, ['size', 'color', 'colour', 'সাইজ', 'রঙ'], true)) {
                    continue;
                }
                if (preg_match('/(?<![a-z0-9\x{0980}-\x{09FF}])' . preg_quote($tok, '/') . '(?![a-z0-9\x{0980}-\x{09FF}])/iu', $hay)) {
                    $state = (array) ($conversation->state_data ?? []);
                    if ((int) ($state['variant_id'] ?? 0) !== (int) $v->id) {
                        $state['variant_id'] = $v->id;
                        $conversation->state_data = $state;
                        $conversation->save();
                    }

                    return $v;
                }
            }
        }
        // Nothing named: the choice already on the conversation stands.
        $state = (array) ($conversation->state_data ?? []);
        if (!empty($state['variant_id'])) {
            $chosen = $variants->firstWhere('id', (int) $state['variant_id']);
            if ($chosen) {
                return $chosen;
            }
        }

        return null;
    }

    /** Do this product's variants disagree on price? (batch 14, #15) */
    private function variantPricesDiffer(Product $product): bool
    {
        return $product->activeVariants()
            ->map(fn (\App\Models\ProductVariant $v) => $v->effectivePrice())
            ->unique()
            ->count() > 1;
    }

    /** The shop's inside-Dhaka delivery charge (Business Profile). */
    private function insideDeliveryCost(int $businessId): float
    {
        return (float) $this->settings->bizGet($businessId, "business.{$businessId}.default_delivery_cost", 'business.default_delivery_cost', 60);
    }

    /**
     * The shop's outside-Dhaka delivery charge (brain fix batch 13,
     * finding #7): a Business Profile setting of its own since this
     * batch — before it, the figure the rules quote and the guard
     * allows was a hardcoded 120 in the brain that no shop could
     * change. The default stays 120, so a shop that never sets it
     * sees no change.
     */
    private function outsideDeliveryCost(int $businessId): float
    {
        return (float) $this->settings->bizGet($businessId, "business.{$businessId}.default_delivery_cost_outside", 'business.default_delivery_cost_outside', 120);
    }

    public function buildReply(AiRule $rule, ?Product $product, int $businessId, bool $slots = false, ?string $honorific = null): string
    {
        $tpl = (string) ($rule->reply_template ?? '');
        $inside = $this->insideDeliveryCost($businessId);
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
            '{delivery_outside}' => number_format($this->outsideDeliveryCost($businessId)),
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
        $inside = $this->insideDeliveryCost($businessId);
        $map = [
            '⟦product⟧' => $product ? $product->name : 'পণ্যটা',
            '⟦price⟧' => $product ? number_format($product->effectivePrice()) : '',
            '⟦stock⟧' => $product ? (string) max(0, $product->available()) : '',
            '⟦delivery_inside⟧' => number_format($inside),
            '⟦delivery_outside⟧' => number_format($this->outsideDeliveryCost($businessId)),
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
     * One holding line where a flow hands over to a human and the
     * customer would otherwise hear NOTHING (brain fix batch 10): the
     * bargain floor, collection giving up on incomplete order data, a
     * handover rule with no acknowledgement template. The line goes
     * through simpleOutgoing like every fixed text, so the channel
     * mode decides sent vs draft exactly as elsewhere. Throttled like
     * holdingLine() — at most one 'holding' message per conversation
     * per 24 hours, a budget shared with the angry-customer line
     * (same meta kind), so a thread can never be spammed with them.
     */
    private function holdingReply(Conversation $conversation, string $mode): ?ChatMessage
    {
        $recent = ChatMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->contains(fn ($m) => ($m->meta['kind'] ?? null) === 'holding');
        if ($recent) {
            return null;
        }

        return $this->simpleOutgoing($conversation, $this->honorificPrefix((int) $conversation->business_id, $conversation->customer_name) . 'একটু অপেক্ষা করুন, আমাদের প্রতিনিধি দেখে জানাচ্ছেন।', 'holding', $mode);
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
        $product = $this->freshFocusProduct($conversation);

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

    /**
     * The quantity a message states, if it states one (brain fix
     * batch 13, finding #6): a number tied to a piece word — "২টা",
     * "2 ta", "৩ পিস", "3 pcs", "২টাই" — or a written-out Bengali
     * number ("তিনটা", "dui ta"). The unit word is what makes it a
     * quantity: a bare figure is a price, a phone or an address
     * number, and "টাকায়" must never read as "টা" (the টা may not
     * be followed by another Bengali letter, so টাকা/টাকায় fail,
     * while the emphatic টাই is a unit of its own).
     */
    public function extractQuantity(string $text): ?int
    {
        $t = $this->guard->normalizeDigits($text);
        $words = ['এক' => 1, 'দুই' => 2, 'তিন' => 3, 'চার' => 4, 'পাঁচ' => 5, 'ছয়' => 6, 'সাত' => 7, 'আট' => 8, 'নয়' => 9, 'দশ' => 10];
        foreach ($words as $word => $n) {
            if (preg_match('/' . $word . '\s*(?:টাই|টা|টি|পিস)(?![\x{0980}-\x{09FF}])/u', $t)) {
                return $n;
            }
        }
        $roman = ['ek' => 1, 'dui' => 2, 'tin' => 3, 'char' => 4, 'pach' => 5, 'chhoy' => 6, 'sat' => 7, 'at' => 8, 'noy' => 9, 'dosh' => 10];
        if (preg_match('/\b(' . implode('|', array_keys($roman)) . ')\s+(?:ta|ti|pcs?|pieces?)\b/iu', $t, $m)) {
            return $roman[mb_strtolower($m[1])];
        }
        // The digits must be a STANDALONE number: digits glued to a
        // product code are the code, not a quantity — "CRUD-102 ta"
        // is "that CRUD-102" (the demonstrative), and reading it as
        // 102 pieces would poison the flow (found by BrainFix3Test).
        if (preg_match('/(?<![\dA-Za-z\-_\x{0980}-\x{09FF}])(\d{1,3})(?!\d)\s*(?:টাই|টা|টি|পিস)(?![\x{0980}-\x{09FF}])/u', $t, $m)) {
            $n = (int) $m[1];
            if ($n >= 1) {
                return $n;
            }
        }
        if (preg_match('/(?<![\dA-Za-z\-_])(\d{1,3})(?!\d)\s*(?:ta|ti|pcs?|pieces?)\b/iu', $t, $m)) {
            $n = (int) $m[1];
            if ($n >= 1) {
                return $n;
            }
        }

        return null;
    }

    /**
     * A quantity the customer states rides the order flow (batch
     * 13): it is remembered on the conversation's state, so the
     * collection, the summary and the eventual order all read the
     * same figure, and a later message can change it before
     * confirmation. It is cleared with the rest of the flow's state
     * when the flow closes (an order is placed, a cancellation, a
     * product switch) — never silently inherited by the next order.
     */
    private function captureQuantityFromText(Conversation $conversation, string $text): void
    {
        $qty = $this->extractQuantity($text);
        if ($qty === null) {
            return;
        }
        $state = (array) ($conversation->state_data ?? []);
        if ((int) ($state['qty'] ?? 0) === $qty) {
            return;
        }
        $state['qty'] = $qty;
        $conversation->state_data = $state;
        $conversation->save();
    }

    /** The quantity the current flow is buying (default x1). */
    private function flowQty(Conversation $conversation): int
    {
        $qty = (int) (($conversation->state_data ?? [])['qty'] ?? 1);

        return $qty >= 1 ? min($qty, 999) : 1;
    }

    /**
     * Remember the reduced-quantity offer the brain is about to make
     * (batch 14, finding #18): the insufficient-stock reply ends
     * "Xটা নিলে বলুন, অর্ডার করে দিচ্ছি" — an explicit yes/no
     * question — but until this batch nothing recorded that an offer
     * was standing, so the customer's bare "হ্যাঁ" fell into the
     * no-rule fallback and the offered order never started. The
     * offer is product + variant + the available count, and
     * handleIncoming's yes/no hook answers it. It dies with its
     * flow: a product switch, a cancellation, a placed order, or a
     * summary superseding it all clear it.
     */
    private function noteQtyOffer(Conversation $conversation, Product $product, ?\App\Models\ProductVariant $variant, int $avail): void
    {
        $state = (array) ($conversation->state_data ?? []);
        $state['qty_offer'] = ['product_id' => (int) $product->id, 'variant_id' => $variant?->id, 'qty' => max(1, $avail)];
        $conversation->state_data = $state;
        $conversation->save();
    }

    /**
     * The honest answer when the flow asks for more pieces than the
     * shelf holds (batch 13): how many ARE available, and an
     * invitation to take those — never a summary, never a silently
     * shortened order. Counts are not money figures, so the guard
     * passes the text as written.
     */
    private function insufficientQtyBody(int $businessId, ?string $customerName, Product $product, ?\App\Models\ProductVariant $variant, int $want, int $avail): string
    {
        return $this->honorificPrefix($businessId, $customerName)
            . $product->name . ($variant ? ' (' . $variant->name . ')' : '')
            . ' এখন স্টকে ' . $avail . 'টা আছে। ' . $want . 'টা দেওয়া যাচ্ছে না। '
            . $avail . 'টা নিলে বলুন, অর্ডার করে দিচ্ছি।';
    }

    /** Bengali district names a customer writes, to their canonical English form. */
    private const DISTRICTS_BN = [
        'চট্টগ্রাম' => 'Chattogram', 'ময়মনসিংহ' => 'Mymensingh', 'নারায়ণগঞ্জ' => 'Narayanganj',
        'কক্সবাজার' => "Cox's Bazar", 'ব্রাহ্মণবাড়িয়া' => 'Brahmanbaria', 'কিশোরগঞ্জ' => 'Kishoreganj',
        'মুন্সীগঞ্জ' => 'Munshiganj', 'লক্ষ্মীপুর' => 'Lakshmipur', 'খাগড়াছড়ি' => 'Khagrachari',
        'লালমনিরহাট' => 'Lalmonirhat', 'পটুয়াখালী' => 'Patuakhali', 'নীলফামারী' => 'Nilphamari',
        'ঠাকুরগাঁও' => 'Thakurgaon', 'সিরাজগঞ্জ' => 'Sirajganj', 'মৌলভীবাজার' => 'Moulvibazar',
        'নেত্রকোনা' => 'Netrokona', 'গোপালগঞ্জ' => 'Gopalganj', 'মাদারীপুর' => 'Madaripur',
        'শরীয়তপুর' => 'Shariatpur', 'ঝালকাঠি' => 'Jhalokati', 'কুড়িগ্রাম' => 'Kurigram',
        'গাইবান্ধা' => 'Gaibandha', 'জয়পুরহাট' => 'Joypurhat', 'দিনাজপুর' => 'Dinajpur',
        'রাজশাহী' => 'Rajshahi', 'মানিকগঞ্জ' => 'Manikganj', 'নরসিংদী' => 'Narsingdi',
        'সুনামগঞ্জ' => 'Sunamganj', 'জামালপুর' => 'Jamalpur', 'টাঙ্গাইল' => 'Tangail',
        'ফরিদপুর' => 'Faridpur', 'নোয়াখালী' => 'Noakhali', 'কুষ্টিয়া' => 'Kushtia',
        'ঝিনাইদহ' => 'Jhenaidah', 'বান্দরবান' => 'Bandarban', 'পঞ্চগড়' => 'Panchagarh',
        'রাঙামাটি' => 'Rangamati', 'সাতক্ষীরা' => 'Satkhira', 'চুয়াডাঙ্গা' => 'Chuadanga',
        'মেহেরপুর' => 'Meherpur', 'গাজীপুর' => 'Gazipur', 'কুমিল্লা' => 'Cumilla',
        'বরিশাল' => 'Barishal', 'খুলনা' => 'Khulna', 'সিলেট' => 'Sylhet', 'রংপুর' => 'Rangpur',
        'বগুড়া' => 'Bogura', 'যশোর' => 'Jashore', 'পাবনা' => 'Pabna', 'নাটোর' => 'Natore',
        'নওগাঁ' => 'Naogaon', 'মাগুরা' => 'Magura', 'রাজবাড়ী' => 'Rajbari', 'নড়াইল' => 'Narail',
        'বাগেরহাট' => 'Bagerhat', 'বরগুনা' => 'Barguna', 'ভোলা' => 'Bhola', 'ফেনী' => 'Feni',
        'চাঁদপুর' => 'Chandpur', 'হবিগঞ্জ' => 'Habiganj', 'শেরপুর' => 'Sherpur', 'ঢাকা' => 'Dhaka',
    ];

    /** English district names (the same canonical list OrderService derives order districts from). */
    private const DISTRICTS_EN = [
        'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura', 'Brahmanbaria', 'Chandpur', 'Chattogram',
        'Chuadanga', 'Comilla', "Cox's Bazar", 'Dhaka', 'Dinajpur', 'Faridpur', 'Feni', 'Gaibandha',
        'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore', 'Jhalokati', 'Jhenaidah', 'Joypurhat',
        'Khagrachari', 'Khulna', 'Kishoreganj', 'Kurigram', 'Kushtia', 'Lakshmipur', 'Lalmonirhat',
        'Madaripur', 'Magura', 'Manikganj', 'Meherpur', 'Moulvibazar', 'Munshiganj', 'Mymensingh',
        'Naogaon', 'Narail', 'Narayanganj', 'Narsingdi', 'Natore', 'Netrokona', 'Nilphamari', 'Noakhali',
        'Pabna', 'Panchagarh', 'Patuakhali', 'Pirojpur', 'Rajbari', 'Rajshahi', 'Rangamati', 'Rangpur',
        'Satkhira', 'Shariatpur', 'Sherpur', 'Sirajganj', 'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon',
        'Chapai Nawabganj', 'Bagerhat', 'Chittagong', 'Jessore', 'Nawabganj',
    ];

    /**
     * The district a text names, if it names one (batch 13): the
     * canonical name, whether it is inside Dhaka, and the display
     * form the customer used. "ঢাকার বাইরে" (outside Dhaka) is a
     * named area too — the outside one. Longest names match first,
     * so "Chapai Nawabganj" never reads as "Nawabganj".
     *
     * @return array{name: string, inside: bool, display: string}|null
     */
    private function districtMention(?string $text): ?array
    {
        $t = trim((string) $text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/ঢাকার বাইরে|ঢাকার বাহিরে|outside\s+dhaka/iu', $t)) {
            return ['name' => 'Outside Dhaka', 'inside' => false, 'display' => 'ঢাকার বাইরে'];
        }
        $bn = self::DISTRICTS_BN;
        uksort($bn, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($bn as $name => $canonical) {
            if (str_contains($t, $name)) {
                return ['name' => $canonical, 'inside' => $canonical === 'Dhaka', 'display' => $name];
            }
        }
        $en = self::DISTRICTS_EN;
        usort($en, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($en as $name) {
            if (preg_match('/(?<![A-Za-z])' . preg_quote($name, '/') . '(?![A-Za-z])/i', $t)) {
                $canonical = $name === 'Chittagong' ? 'Chattogram' : ($name === 'Jessore' ? 'Jashore' : ($name === 'Comilla' ? 'Cumilla' : $name));

                return ['name' => $canonical, 'inside' => $canonical === 'Dhaka', 'display' => $name];
            }
        }

        return null;
    }

    /**
     * The delivery charge a summary quotes for this conversation
     * (batch 13, finding #7): the zone test is the platform's one
     * definition — CourierMethod::isInsideDhaka() over the district
     * the customer's stated address names — and each side charges
     * its own Business Profile figure. An address that names no
     * district at all is not inside Dhaka by that test, so it
     * charges the outside figure, exactly as the courier charge for
     * an order with no district already does.
     */
    private function summaryDeliveryCharge(int $businessId, ?string $address): float
    {
        $mention = $this->districtMention($address);
        $district = $mention['name'] ?? null;

        return \App\Models\CourierMethod::isInsideDhaka($district)
            ? $this->insideDeliveryCost($businessId)
            : $this->outsideDeliveryCost($businessId);
    }

    /**
     * A delivery-charge question that names its area is answered
     * with THAT area's charge (batch 13 — the reviewer's catalog
     * 'Area charge' case, implemented here once): the slotted body
     * for the delivery rule, or null when the message names no area
     * and the rule's own both-charges template should answer.
     */
    private function areaChargeBody(int $businessId, string $text, ?string $customerName): ?string
    {
        $mention = $this->districtMention($text);
        if ($mention === null) {
            return null;
        }
        $prefix = $this->honorificPrefix($businessId, $customerName);
        if ($mention['name'] === 'Outside Dhaka') {
            return $prefix . 'ঢাকার বাইরে ডেলিভারি চার্জ ⟦delivery_outside⟧ টাকা।';
        }
        $slot = $mention['inside'] ? '⟦delivery_inside⟧' : '⟦delivery_outside⟧';

        return $prefix . $mention['display'] . ' এলাকায় ডেলিভারি চার্জ ' . $slot . ' টাকা।';
    }

    /** The English twin of areaChargeBody (batch 15, finding #24). */
    private function areaChargeBodyEn(int $businessId, string $text, ?string $customerName): ?string
    {
        $mention = $this->districtMention($text);
        if ($mention === null) {
            return null;
        }
        $prefix = $this->honorificPrefix($businessId, $customerName);
        if ($mention['name'] === 'Outside Dhaka') {
            return $prefix . 'Delivery charge outside Dhaka is ⟦delivery_outside⟧ taka.';
        }
        $slot = $mention['inside'] ? '⟦delivery_inside⟧' : '⟦delivery_outside⟧';

        return $prefix . 'Delivery charge for ' . $mention['display'] . ' is ' . $slot . ' taka.';
    }

    /**
     * English templates for the four core informational intents
     * (batch 15, finding #24): the lightest honest version of
     * language matching — an English-dominant message is answered
     * from these, everything else keeps the Bangla catalog. The
     * placeholders are the same slots the Bangla templates carry, so
     * figures are rendered and verified identically.
     */
    private const ENGLISH_TEMPLATES = [
        'price' => 'The price of {product} is {price} taka. To order, please send your name, address and phone number.',
        'delivery' => 'Delivery charge is {delivery_inside} taka inside Dhaka and {delivery_outside} taka outside Dhaka.',
        'stock' => '{product} is in stock: {stock} available. To order, please send your name, address and phone number.',
        'greeting' => 'Hello! Welcome. Which product would you like to know about?',
    ];

    /**
     * Is this message English-dominant (batch 15, finding #24)?
     * Romanised Bangla is NOT English: it is written in Latin
     * letters, so a bare script test would send "dam koto?" down the
     * English path. The test therefore requires Latin letters to
     * dominate AND no Bengali script AND no romanised-Bangla marker
     * word — a single marker ("koto", "ache", "lagbe") keeps the
     * message on the Bangla path it has always taken.
     */
    private function isEnglishDominant(string $text): bool
    {
        if (preg_match('/[\x{0980}-\x{09FF}]/u', $text)) {
            return false;
        }
        $latin = preg_match_all('/[a-zA-Z]/', $text);
        if ($latin < 3) {
            return false;
        }
        foreach (['koto', 'kobe', 'ache', 'achen', 'lagbe', 'lagbena', 'chai', 'chaichen', 'kivabe', 'keno', 'kothay', 'bolen', 'bolun', 'den', 'diben', 'hobe', 'hobena', 'nibo', 'nebo', 'korbo', 'koren', 'korun', 'pathabo', 'pathan', 'jabe', 'kinbo', 'kinte', 'dam', 'ektu', 'onek', 'bhalo', 'valo', 'dhonnobad', 'apnar', 'amar', 'eta', 'oita', 'ekta', 'kintu', 'jodi', 'tahole', 'ekhon', 'pore', 'age', 'bhai', 'apu', 'vai'] as $w) {
            if (preg_match('/\b' . $w . '\b/', mb_strtolower($text))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Multi-intent joining (batch 15, Step 7 / finding #17): when
     * ONE message clearly carries two or three INDEPENDENT
     * informational questions, produce each part through its own
     * existing path and join them into one slotted reply. The join
     * is deliberately narrow:
     *  - the whole message's own rule must be one of the
     *    informational intents (price, delivery, stock, delivery
     *    time, delivery coverage, greeting) with an 'answer'-style
     *    outcome — a handover, draft or no-rule message never joins;
     *  - no summary may be pending (a pending summary's messages
     *    belong to that flow);
     *  - every segment must classify as one of those intents — a
     *    flow signal (bargain, order, cancel, acceptance) or an
     *    unrecognised segment aborts the join, and flow intents keep
     *    owning the message alone exactly as today;
     *  - more than three distinct parts aborts;
     *  - a price/stock part needs the resolved product, an
     *    out-of-stock part aborts (the honest zero-stock answer owns
     *    the message alone), and a price part with differing variant
     *    prices and no variant chosen aborts (the variant question
     *    owns it);
     *  - an English-dominant message joins only when every part has
     *    an English template (finding #24's scope).
     * The caller runs the joined body through the same talker,
     * money and claim verification as a single answer.
     *
     * @return array{body: string, intents: array<int, string>, slotValues: array<string, mixed>}|null
     */
    private function multiIntentParts(Conversation $conversation, string $text, AiRule $rule, ?Product $product, ?\App\Models\ProductVariant $variant, int $biz): ?array
    {
        // The whole message's own outcome must itself be one of the
        // informational answers — a handover (COD, payment proof)
        // or a keyword rule's case never joins.
        if ($rule->trigger_type !== 'intent' || $rule->action === 'handover'
            || ! in_array($rule->match_text, ['price', 'delivery', 'stock', 'delivery_time', 'delivery_coverage', 'greeting'], true)) {
            return null;
        }
        $state = (array) ($conversation->state_data ?? []);
        if (! empty($state['pending_summary'])) {
            return null;
        }
        $segments = preg_split('/[?।!.\n,;]+|\s+(?:আর|এবং|and)\s+/u', $text) ?: [];
        $intents = [];
        foreach ($segments as $segment) {
            $intent = $this->segmentIntent(trim((string) $segment));
            if ($intent === null) {
                continue;
            }
            if ($intent === 'flow' || $intent === 'other') {
                return null;
            }
            if (! in_array($intent, $intents, true)) {
                $intents[] = $intent;
            }
        }
        if (count($intents) < 2 || count($intents) > 3) {
            return null;
        }
        $english = $this->isEnglishDominant($text);
        if ($english) {
            foreach ($intents as $intent) {
                if (! isset(self::ENGLISH_TEMPLATES[$intent])) {
                    return null;
                }
            }
        }
        $honorific = $this->honorific($biz, $conversation->customer_name);
        $parts = [];
        $slotValues = [];
        foreach ($intents as $i => $intent) {
            $h = $i === 0 ? $honorific : null;
            if ($intent === 'greeting') {
                $rule = $this->intentRuleFor($biz, 'greeting', $conversation->channel);
                if ($rule === null) {
                    return null;
                }
                $parts[] = $english
                    ? $this->buildReply($this->englishRule($rule), $product, $biz, true, $h)
                    : $this->buildReply($rule, $product, $biz, true, $h);
                continue;
            }
            if ($intent === 'price' || $intent === 'stock') {
                if ($product === null) {
                    return null;
                }
                if (($this->focusAvailable($product, $variant) ?? 1) <= 0) {
                    return null;
                }
                if ($intent === 'price' && $variant === null && $product->hasVariants() && $this->variantPricesDiffer($product)) {
                    return null;
                }
                $rule = $this->intentRuleFor($biz, $intent, $conversation->channel);
                if ($rule === null) {
                    return null;
                }
                $parts[] = $english
                    ? $this->buildReply($this->englishRule($rule), $product, $biz, true, $h)
                    : $this->buildReply($rule, $product, $biz, true, $h);
                if ($variant !== null) {
                    $slotValues['price'] = $variant->effectivePrice();
                    $slotValues['stock'] = $variant->available();
                } elseif ($intent === 'price' && $product->hasVariants()) {
                    // Every variant shares one price (differing
                    // prices aborted above): the shared figure is
                    // the honest quote, as in the single path.
                    $slotValues['price'] = $product->activeVariants()->first()->effectivePrice();
                }
                continue;
            }
            // delivery, delivery_time, delivery_coverage
            $rule = $this->intentRuleFor($biz, $intent, $conversation->channel);
            if ($rule === null || $rule->action === 'handover') {
                return null;
            }
            if ($intent === 'delivery') {
                $area = $english
                    ? $this->areaChargeBodyEn($biz, $text, $i === 0 ? $conversation->customer_name : null)
                    : $this->areaChargeBody($biz, $text, $i === 0 ? $conversation->customer_name : null);
                if ($area !== null) {
                    $parts[] = $area;
                    continue;
                }
            }
            $parts[] = $english && isset(self::ENGLISH_TEMPLATES[$intent])
                ? $this->buildReply($this->englishRule($rule), $product, $biz, true, $h)
                : $this->buildReply($rule, $product, $biz, true, $h);
        }

        return ['body' => implode("\n", $parts), 'intents' => $intents, 'slotValues' => $slotValues];
    }

    /** A rule clone carrying the English template for its intent (batch 15, #24). */
    private function englishRule(AiRule $rule): AiRule
    {
        $clone = clone $rule;
        $clone->reply_template = self::ENGLISH_TEMPLATES[$rule->match_text] ?? $rule->reply_template;

        return $clone;
    }

    /** The shop's active intent rule for a match_text, channel-scoped like matchRule. */
    private function intentRuleFor(int $biz, string $matchText, ?string $channel): ?AiRule
    {
        return AiRule::where('business_id', $biz)->where('active', true)
            ->where('trigger_type', 'intent')->where('match_text', $matchText)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $channel))
            ->first();
    }

    /**
     * One segment of a multi-question message, classified on its own
     * (batch 15): an informational intent name, 'flow' for a
     * state-changing signal (bargain push, order verb, cancellation,
     * acceptance), 'other' for anything unrecognised, or null for an
     * empty segment. Deliberately narrower than detectIntent: a
     * segment earns a joined answer only when its question is
     * unmistakable.
     */
    private function segmentIntent(string $segment): ?string
    {
        $hay = mb_strtolower(trim($segment));
        if ($hay === '') {
            return null;
        }
        if ($this->intentMatches('bargain', $hay) || $this->intentMatches('order_intent', $hay)
            || $this->isAcceptance($hay)
            || str_contains($hay, 'বাদ দিন') || str_contains($hay, 'বাদ দেন') || str_contains($hay, 'cancel')) {
            return 'flow';
        }
        if ($this->intentMatches('delivery_time', $hay)
            || str_contains($hay, 'কতদিন লাগবে') || str_contains($hay, 'কতদিনে') || str_contains($hay, 'কয়দিন')) {
            return 'delivery_time';
        }
        if ($this->intentMatches('delivery_coverage', $hay)) {
            return 'delivery_coverage';
        }
        foreach (['ডেলিভারি চার্জ', 'delivery charge', 'কুরিয়ার চার্জ', 'ডেলিভারি খরচ', 'shipping charge', 'চার্জ কত', 'ডেলিভারি কত', 'পাঠাতে কত'] as $p) {
            if (str_contains($hay, $p)) {
                return 'delivery';
            }
        }
        if ($this->intentMatches('stock', $hay)) {
            return 'stock';
        }
        if ($this->intentMatches('price', $hay) || preg_match('/\brate\b/', $hay)) {
            return 'price';
        }
        if ($this->intentMatches('delivery', $hay)) {
            return 'delivery';
        }
        if ($this->intentMatches('greeting', $hay)) {
            return 'greeting';
        }

        return 'other';
    }

    /**
     * Is this message asking for the LIST of a product's colours or
     * sizes (batch 15)? Returns 'colour', 'size' or 'any' (a generic
     * "কি কি আছে?" list ask), or null. Judged on phrases, not single
     * words, so "সাইজ L এর দাম কত?" (a price question about one
     * variant) never matches.
     */
    private function variantListAsk(string $text): ?string
    {
        $hay = mb_strtolower($text);
        $colour = false;
        foreach (['কি কি কালার', 'কী কী কালার', 'কি কি রঙ', 'কোন কোন কালার', 'কোন কোন রঙ', 'কালার কি কি', 'রঙ কি কি', 'ki ki color', 'ki ki colour', 'kon kon color', 'what colors', 'what colours', 'which colors', 'which colours', 'colors available', 'colours available'] as $p) {
            if (str_contains($hay, $p)) {
                $colour = true;
                break;
            }
        }
        $size = false;
        foreach (['সাইজ কি কি', 'কি কি সাইজ', 'কোন কোন সাইজ', 'সাইজগুলো কি', 'ki ki size', 'kon kon size', 'what sizes', 'which sizes', 'sizes available'] as $p) {
            if (str_contains($hay, $p)) {
                $size = true;
                break;
            }
        }
        if ($colour && $size) {
            return 'any';
        }
        if ($colour) {
            return 'colour';
        }
        if ($size) {
            return 'size';
        }
        foreach (['কি কি আছে', 'কী কী আছে', 'কি কি অপশন', 'অপশন কি কি', 'ki ki ache', 'ki ki option'] as $p) {
            if (str_contains($hay, $p)) {
                return 'any';
            }
        }

        return null;
    }

    /**
     * The variant-list reply (batch 15): the product's ACTUAL variant
     * names from its data, filtered to the family asked about when
     * the variants carry family markers (a colour ask lists the
     * colour variants, not the sizes) — code-rendered, word-for-word.
     */
    private function variantListBody(int $businessId, ?string $customerName, Product $product, string $listKind): string
    {
        $names = $product->activeVariants()->pluck('name')->map(fn ($n) => (string) $n)->all();
        if ($listKind === 'colour' || $listKind === 'size') {
            $markers = $listKind === 'colour' ? ['color', 'colour', 'রঙ', 'কালার'] : ['size', 'সাইজ'];
            $filtered = array_values(array_filter($names, function ($n) use ($markers) {
                foreach ($markers as $m) {
                    if (str_contains(mb_strtolower($n), $m)) {
                        return true;
                    }
                }

                return false;
            }));
            if ($filtered !== []) {
                $names = $filtered;
            }
        }

        return $this->honorificPrefix($businessId, $customerName) . $product->name . '-এর আছে: ' . implode(', ', $names) . '। কোনটা নেবেন, বলুন।';
    }

    /**
     * The two bare short messages (batch 15): an acknowledgement
     * ("ওকে", "আচ্ছা", "ঠিক আছে", "ok") and a presence ping
     * ("আছেন?", "hello?"). Matched EXACTLY (the whole message, bar
     * punctuation) — a longer message that merely contains these
     * words is judged by the rest of the chain as always. Returns
     * the word-for-word body + kind, or null.
     *
     * @return array{body: string, kind: string}|null
     */
    private function shortReply(Conversation $conversation, string $text): ?array
    {
        $t = trim(mb_strtolower($text));
        $t = trim((string) preg_replace('/[?।!.,\s]+/u', ' ', $t));
        $biz = (int) $conversation->business_id;
        $prefix = $this->honorificPrefix($biz, $conversation->customer_name);
        if (in_array($t, ['ওকে', 'ok', 'okay', 'ঠিক আছে', 'ঠিকাছে', 'আচ্ছা', 'আচ্ছা ঠিক আছে', 'ওকে ভাই', 'ওকে আপু', 'জি', 'হুম'], true)) {
            return ['body' => $prefix . 'ঠিক আছে। আর কিছু জানার থাকলে নির্দ্বিধায় বলুন।', 'kind' => 'acknowledgement'];
        }
        if (in_array($t, ['আছেন', 'আছো', 'কেউ আছো', 'আছেন কি', 'আছো কি'], true)) {
            return ['body' => $prefix . 'জি, আছি। বলুন, কী জানতে চান?', 'kind' => 'presence'];
        }

        return null;
    }

    /**
     * The address a previous DELIVERED order left on this record
     * (batch 15, finding #25), or null. The candidate exists only
     * when the record's details were consumed by a completed order
     * (the batch-3 doctrine) AND an order in this shop, for this
     * phone, marked Delivered, carries exactly the record's current
     * address — provenance from the order history itself, never a
     * guess. The address is only ever OFFERED back as a question;
     * nothing here marks it collected.
     */
    private function previousDeliveredAddress(Conversation $conversation): ?string
    {
        $state = (array) ($conversation->state_data ?? []);
        if (empty($state['details_consumed'])) {
            return null;
        }
        $address = trim((string) ($conversation->customer_address ?? ''));
        $phone = trim((string) ($conversation->customer_phone ?? ''));
        if ($address === '' || $phone === '') {
            return null;
        }
        $exists = \App\Models\Order::where('business_id', (int) $conversation->business_id)
            ->where('customer_phone', $phone)
            ->where('status', 'Delivered')
            ->where('customer_address', $address)
            ->exists();

        return $exists ? $address : null;
    }

    /** The returning-customer address question (batch 15, #25), word-for-word. */
    private function addressConfirmQuestion(int $businessId, ?string $customerName, string $address): string
    {
        return $this->honorificPrefix($businessId, $customerName)
            . 'আগের ঠিকানায় পাঠাব? ঠিকানা: ' . $address . '। হ্যাঁ হলে "হ্যাঁ" বলুন, নতুন ঠিকানা হলে লিখে দিন।';
    }

    /** The order summary text + meta, shared by the direct and guided-collection paths. */
    private function buildSummary(Conversation $conversation, Product $product, ?\App\Models\ProductVariant $variant): array
    {
        $biz = (int) $conversation->business_id;
        // The delivery charge follows the customer's area (batch 13,
        // finding #7): the zone is read from the stated address with
        // the platform's one zone test, and each side charges the
        // shop's own Business Profile figure — before this batch the
        // inside figure was charged to every district.
        $delivery = $this->summaryDeliveryCharge($biz, $conversation->customer_address);
        // A price agreed in bargaining is the price the customer pays —
        // the summary must not jump back to the full price.
        $unit = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : ($variant ? $variant->effectivePrice() : $product->effectivePrice());
        // The quantity the flow is buying (batch 13, finding #6):
        // bargaining settles the PIECE price; the line total is that
        // price times the quantity, and the delivery charge is added
        // once for the parcel, never per piece.
        $qty = $this->flowQty($conversation);
        $line = $unit * $qty;
        $total = $line + $delivery;
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
            . $itemName . ' (×' . $qty . ') ' . number_format($line) . ' টাকা' . "\n"
            . 'ডেলিভারি চার্জ: ' . number_format($delivery) . ' টাকা' . "\n"
            . 'মোট: ' . number_format($total) . ' টাকা (Cash on Delivery)';
        // Cart snapshot: what this conversation is currently buying.
        $state = (array) ($conversation->state_data ?? []);
        $state['cart'] = [['product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'qty' => $qty, 'agreed_price' => $unit]];
        $conversation->state_data = $state;
        $conversation->save();

        return ['body' => $body, 'meta' => ['product_id' => $product->id, 'total' => $total, 'unit_price' => $unit, 'variant_name' => $variant?->name, 'qty' => $qty, 'delivery' => $delivery]];
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
            $collect = ['step' => $missing[0], 'retries' => 0, 'product_id' => $product->id, 'variant_id' => $variant?->id];
            // A returning customer whose previous DELIVERED order's
            // address is still on the record (batch 15, finding
            // #25): when the address is the ONLY piece missing, the
            // ask is not the plain question but the confirmation
            // question — the previous address is OFFERED, never
            // silently reused. handleCollect reads the answer.
            $previousAddress = $missing === ['address'] ? $this->previousDeliveredAddress($conversation) : null;
            if ($previousAddress !== null) {
                $collect['address_confirm'] = 'pending';
            }
            $state['collect'] = $collect;
            // A new collection supersedes any summary an earlier flow
            // left pending: its data is no longer what this flow is
            // confirming, so it must not stay confirmable (batch 3).
            // A standing quantity offer dies with it for the same
            // reason (batch 14).
            unset($state['pending_summary'], $state['qty_offer']);
            $conversation->state = 'collecting';
            $conversation->state_data = $state;
            $conversation->save();
            $body = $previousAddress !== null
                ? $this->addressConfirmQuestion($biz, $conversation->customer_name, $previousAddress)
                : $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name);
            $meta = ['collect' => $missing[0]];
            if ($previousAddress !== null) {
                $meta['address_confirm'] = true;
            }
            if ($agreed !== null) {
                $body = 'ঠিক আছে, ⟦offer⟧ টাকায় ফাইনাল। ' . $body;
                $meta = array_merge($meta, ['quote' => 'accept', 'offer' => $agreed]);
            }

            return ['slottedBody' => $body, 'kind' => 'collect_question', 'meta' => $meta, 'body' => null];
        }
        // More pieces wanted than the shelf holds (batch 13): the
        // honest count is the whole reply — no summary quoting a
        // quantity the shop cannot ship, and no short order later.
        $wantQty = $this->flowQty($conversation);
        $availQty = $this->focusAvailable($product, $variant);
        if ($wantQty > 1 && $availQty !== null && $availQty < $wantQty) {
            $this->noteQtyOffer($conversation, $product, $variant, (int) $availQty);

            return ['slottedBody' => $this->insufficientQtyBody($biz, $conversation->customer_name, $product, $variant, $wantQty, (int) $availQty), 'kind' => 'insufficient_stock', 'meta' => ['insufficient_stock' => true, 'qty' => $wantQty, 'available' => (int) $availQty], 'body' => null];
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
        if ($this->freshFocusProduct($conversation) === null) {
            return false; // nothing (fresh) in focus — nothing to wrongly quote
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
        } elseif (empty($conversation->customer_address) && $phone !== null && ! $this->isCorrectionText(mb_strtolower($text))) {
            // Blob rule: the address rides in the same message as the
            // number, unlabelled — the leftover text after the number
            // is the address when it looks like one. The leftover is
            // cleaned of the other fields' segments first (batch 6):
            // a name+phone message with NO address in it must leave
            // the address empty, not store "Amar nam , phone ," as
            // one. A CORRECTION message is disarmed (batch 15): its
            // leftover is correction talk, never an address.
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
        unset($state['collect'], $state['cart'], $state['floor_repeats'], $state['pending_summary'], $state['qty'], $state['qty_offer']);
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
        $qty = (int) ($meta['qty'] ?? 1);
        $state = (array) ($conversation->state_data ?? []);
        // A summary on the table supersedes a standing reduced-
        // quantity offer (batch 14): the summary is now the thing
        // a bare "হ্যাঁ" confirms.
        unset($state['qty_offer']);
        $state['pending_summary'] = [
            'message_id' => (int) $msg->id,
            'product_id' => (int) ($meta['product_id'] ?? 0),
            'variant_id' => $variant?->id,
            'variant_name' => $meta['variant_name'] ?? null,
            'unit_price' => $unit,
            // The charge the summary quoted for the customer's area
            // (batch 13): with a quantity the delivery is no longer
            // total minus ONE unit, so it is snapshotted from the
            // summary itself, never re-derived.
            'delivery' => isset($meta['delivery']) ? (float) $meta['delivery'] : $total - $unit * $qty,
            'qty' => $qty,
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
        // The delivery charge the summary quoted (batch 13): the
        // snapshot on the summary itself — the area's figure at the
        // rate in force when the customer saw it — so the order's
        // total equals the summary's exactly. Summaries written
        // before the snapshot existed fall back to the pending
        // state's own record of the quote, and only then to the
        // shop's configured inside cost, as before (Hunt V1).
        $pendingNow = (array) (($conversation->state_data ?? [])['pending_summary'] ?? []);
        $quotedDelivery = isset($summary->meta['delivery'])
            ? (float) $summary->meta['delivery']
            : (isset($pendingNow['delivery']) ? (float) $pendingNow['delivery'] : $this->insideDeliveryCost($biz));
        $order = app(OrderService::class)->create($biz, [
            'customer_name' => $name,
            'customer_phone' => $conversation->customer_phone ?: '01712345678',
            'customer_address' => $conversation->customer_address,
            'source' => $source,
            'status' => 'Pending',
            'delivery_charge' => $quotedDelivery,
            // The quantity the summary sold (batch 13): the line is
            // qty pieces at the summarised per-piece price.
            'items' => [['product_id' => $product->id, 'qty' => (int) ($summary->meta['qty'] ?? $pendingNow['qty'] ?? 1), 'price' => isset($summary->meta['unit_price']) ? (float) $summary->meta['unit_price'] : null, 'variant_name' => $summary->meta['variant_name'] ?? null]],
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
    /**
     * A variant change while a summary is on the table (batch 14,
     * finding #13): the customer named a DIFFERENT variant of the
     * summarised product, so the old summary is retired (a draft is
     * rejected, closing the seller approval route to the stale
     * variant) and a fresh summary at the new variant takes its
     * place — or the honest stock answer when the new variant
     * cannot cover the order. The twin of qtyChangeRerender (batch
     * 13): a message amending the order is never a confirmation of
     * the summary it amends. Runs before the confirmation hook.
     */
    private function variantChangeRerender(Conversation $conversation, string $text, string $mode): ?ChatMessage
    {
        $state = (array) ($conversation->state_data ?? []);
        $pending = (array) ($state['pending_summary'] ?? []);
        if ($pending === [] || empty($pending['message_id'])) {
            return null;
        }
        $biz = (int) $conversation->business_id;
        $product = Product::where('business_id', $biz)->find((int) ($pending['product_id'] ?? 0));
        if ($product === null || ! $product->hasVariants()) {
            return null;
        }
        $named = $this->variantFor($conversation, $product, $text);
        if ($named === null || (int) ($pending['variant_id'] ?? 0) === (int) $named->id) {
            return null;
        }
        $oldMsg = ChatMessage::find((int) $pending['message_id']);
        if ($oldMsg !== null) {
            $this->rejectDraft($oldMsg);
        }
        unset($state['pending_summary']);
        $conversation->state_data = $state;
        $conversation->save();
        $avail = $this->focusAvailable($product, $named);
        if (($avail ?? 1) <= 0) {
            return $this->simpleOutgoing($conversation, $this->outOfStockBody($biz, $conversation->customer_name, $product, $named), 'out_of_stock', $mode, ['out_of_stock' => true]);
        }
        $want = $this->flowQty($conversation);
        if ($avail !== null && $avail < $want) {
            $this->noteQtyOffer($conversation, $product, $named, (int) $avail);

            return $this->simpleOutgoing($conversation, $this->insufficientQtyBody($biz, $conversation->customer_name, $product, $named, $want, (int) $avail), 'insufficient_stock', $mode, ['insufficient_stock' => true, 'qty' => $want, 'available' => (int) $avail]);
        }
        $summary = $this->buildSummary($conversation, $product, $named);
        $msg = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $summary['body'],
            'status' => $mode === 'full_auto' ? 'sent' : 'draft',
            'meta' => array_merge($summary['meta'], ['kind' => 'order_summary', 'variant_changed' => true]),
        ]);
        $this->markSummaryPending($conversation, $msg, $named);
        $this->log($biz, $msg->status === 'sent' ? 'auto_sent' : 'draft_created', $conversation->customer_name . ' — Order summary (variant changed to ' . $named->name . ')', null, $conversation);

        return $msg;
    }

    /**
     * A quantity change while a summary is on the table (batch 13):
     * the customer said how many they want and it differs from the
     * summarised quantity, so the old summary is retired (a draft is
     * rejected, closing the seller approval route to a stale figure)
     * and a fresh summary at the new quantity takes its place — or
     * the honest count when the shelf cannot cover the new quantity.
     * Runs before the confirmation hook: a message carrying a new
     * quantity is a correction to the order, never a confirmation
     * of the old one.
     */
    private function qtyChangeRerender(Conversation $conversation, string $mode): ?ChatMessage
    {
        $state = (array) ($conversation->state_data ?? []);
        $pending = (array) ($state['pending_summary'] ?? []);
        if ($pending === [] || empty($pending['message_id'])) {
            return null;
        }
        $want = $this->flowQty($conversation);
        if ($want === (int) ($pending['qty'] ?? 1)) {
            return null;
        }
        $biz = (int) $conversation->business_id;
        $product = Product::where('business_id', $biz)->find((int) ($pending['product_id'] ?? 0));
        $oldMsg = ChatMessage::find((int) $pending['message_id']);
        if ($product === null) {
            // The product is gone: leave the pending summary to the
            // confirmation hook's own handling of that case.
            return null;
        }
        $variant = ! empty($pending['variant_id']) ? \App\Models\ProductVariant::find((int) $pending['variant_id']) : null;
        if ($oldMsg !== null) {
            $this->rejectDraft($oldMsg);
        }
        unset($state['pending_summary']);
        $conversation->state_data = $state;
        $conversation->save();
        $avail = $this->focusAvailable($product, $variant);
        if ($avail !== null && $avail < $want) {
            $this->noteQtyOffer($conversation, $product, $variant, (int) $avail);

            return $this->simpleOutgoing($conversation, $this->insufficientQtyBody($biz, $conversation->customer_name, $product, $variant, $want, (int) $avail), 'insufficient_stock', $mode, ['insufficient_stock' => true, 'qty' => $want, 'available' => (int) $avail]);
        }
        $summary = $this->buildSummary($conversation, $product, $variant);
        $msg = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $summary['body'],
            'status' => $mode === 'full_auto' ? 'sent' : 'draft',
            'meta' => array_merge($summary['meta'], ['kind' => 'order_summary', 'qty_changed' => true]),
        ]);
        $this->markSummaryPending($conversation, $msg, $variant);
        $this->log($biz, $msg->status === 'sent' ? 'auto_sent' : 'draft_created', $conversation->customer_name . ' — Order summary (quantity changed to ' . $want . ')', null, $conversation);

        return $msg;
    }

    /**
     * A details correction while a summary is on the table (batch
     * 15, the second half of the correction case): captureContact-
     * FromText has already put the corrected name/phone/address on
     * the record (batch 2 precedence), so the pending summary's
     * snapshot no longer matches it. Until this batch the flow only
     * noticed at the NEXT confirmation — the confirmation hook fell
     * through on the stale snapshot and the chain pointed at the old
     * summary ("summary_reminder") or re-collected. The summary is
     * now re-rendered at once from the corrected record, exactly
     * like the quantity and variant rerenders: the old summary is
     * retired, the fresh one takes its place as the pending one.
     * Only a message that CARRIES details can be a correction — a
     * plain chat message never re-renders anything.
     */
    private function detailsChangeRerender(Conversation $conversation, string $text, string $mode): ?ChatMessage
    {
        $state = (array) ($conversation->state_data ?? []);
        $pending = (array) ($state['pending_summary'] ?? []);
        if ($pending === [] || empty($pending['message_id'])) {
            return null;
        }
        if (! $this->carriesDetails($text)) {
            return null;
        }
        if (trim((string) ($conversation->customer_name ?? '')) === trim((string) ($pending['name'] ?? ''))
            && trim((string) ($conversation->customer_phone ?? '')) === trim((string) ($pending['phone'] ?? ''))
            && trim((string) ($conversation->customer_address ?? '')) === trim((string) ($pending['address'] ?? ''))) {
            return null; // the details restated are the summarised ones — nothing corrected
        }
        if (! $this->detailCollected($conversation, 'phone') || ! $this->detailCollected($conversation, 'address')) {
            // A partial correction (the phone changed, the address
            // is still outstanding): the summary cannot be rebuilt
            // yet — the confirmation hook's stale-snapshot fall-
            // through routes the message into collection for the
            // missing piece, as before.
            return null;
        }
        $biz = (int) $conversation->business_id;
        $product = Product::where('business_id', $biz)->find((int) ($pending['product_id'] ?? 0));
        if ($product === null) {
            return null;
        }
        $variant = ! empty($pending['variant_id']) ? \App\Models\ProductVariant::find((int) $pending['variant_id']) : null;
        $oldMsg = ChatMessage::find((int) $pending['message_id']);
        if ($oldMsg !== null) {
            $this->rejectDraft($oldMsg);
        }
        unset($state['pending_summary']);
        $conversation->state_data = $state;
        $conversation->save();
        $summary = $this->buildSummary($conversation, $product, $variant);
        $msg = ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'body' => $summary['body'],
            'status' => $mode === 'full_auto' ? 'sent' : 'draft',
            'meta' => array_merge($summary['meta'], ['kind' => 'order_summary', 'details_changed' => true]),
        ]);
        $this->markSummaryPending($conversation, $msg, $variant);
        $this->log($biz, $msg->status === 'sent' ? 'auto_sent' : 'draft_created', $conversation->customer_name . ' — Order summary (details corrected)', null, $conversation);

        return $msg;
    }

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
            $pendQty = (int) ($pending['qty'] ?? 1);
            $pendAvail = $this->focusAvailable($product, $pVariant);
            if ($pendQty > 1 && $pendAvail !== null && $pendAvail < $pendQty) {
                // The shelf dropped below the summarised quantity
                // after the summary was shown (batch 13): no order —
                // the honest count instead, never a short order.
                unset($state['pending_summary']);
                $conversation->state_data = $state;
                $conversation->save();
                $this->noteQtyOffer($conversation, $product, $pVariant, (int) $pendAvail);
                $msg = $this->simpleOutgoing($conversation, $this->insufficientQtyBody($biz, $conversation->customer_name, $product, $pVariant, $pendQty, (int) $pendAvail), 'insufficient_stock', $mode, ['insufficient_stock' => true, 'qty' => $pendQty, 'available' => (int) $pendAvail]);

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
        if ($last !== []) {
            // The guard must not outlive the order itself (batch 9):
            // the snapshot is only as good as the order it names. If
            // that order was cancelled by the seller — or came back
            // as a return — it no longer stands, so the memory is
            // cleared (with any summary snapshot of that flow) and
            // the chain starts a fresh flow below. Live 2026-11-25
            // 18:53: OB300 was cancelled in the app, yet "order korte
            // chai" still got OB300's already-confirmed reply and no
            // new flow could start. A row that is gone entirely
            // (deleted) is dead the same way. An order still alive
            // (Pending/Processing/Shipped/Delivered) keeps the
            // batch-3 behaviour exactly.
            $lastOrder = ! empty($last['id']) ? \App\Models\Order::find((int) $last['id']) : null;
            if ($lastOrder === null || in_array($lastOrder->status, ['Cancelled', 'Returned'], true)) {
                unset($state['last_order'], $state['pending_summary']);
                $conversation->state_data = $state;
                $conversation->save();
                $last = [];
            }
        }
        if ($last !== []
            && $this->namedAmount($text) === null
            && $this->extractPhone($text) === null
            && $this->extractLabelledAddress($this->guard->normalizeDigits($text), null) === null
            && $this->explicitProductFor($biz, $text) === null
            // A missing-confirmation QUESTION is not a repeat
            // confirmation (batch 15): "কনফার্মেশন মেসেজ পাইনি"
            // contains the acceptance word 'কনফার্ম' but asks where
            // the confirmation went — the order-status branch
            // answers it from the record instead.
            && ! $this->confirmationMissing($hay)) {
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
        foreach (['হ্যাঁ', 'হ্যা', 'হুম', 'এটাই', 'ঠিক', 'ওকে', 'yes', 'ha'] as $w) {
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
     * Does this message carry the customer's own contact details — a
     * parseable phone number, a labelled address, or a labelled
     * name? The same read flowCancellation and handleCollect have
     * always made (batch 5/7), named once for batch 9: a message
     * carrying details is order input wherever it lands — never a
     * cancellation, never a side question, and never a counter-offer.
     */
    private function carriesDetails(string $text): bool
    {
        $norm = $this->guard->normalizeDigits($text);
        $phoneInMsg = $this->extractPhone($text);

        return $phoneInMsg !== null
            || $this->extractLabelledAddress($norm, $phoneInMsg) !== null
            || $this->extractName($norm) !== null;
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
        if (! $flowActive && ! empty($state['flow_just_closed'])) {
            // A switch close-out (batch 16): the clarification pick
            // (batch 11b) or an explicit product switch tore the
            // flow down so completely that the test above finds
            // nothing left — yet the flow DID just end, and the
            // customer's very next message may be the cancel that
            // closes the conversation about the new product (live
            // 2026-11-25 23:5x: the switch to Panjabi 2 was answered
            // with its price, then "বাদ দিন" got the no-rule price
            // repeat + a handover). The close-out arms exactly this
            // one message; the arm is consumed here whether or not
            // the message turns out to be a cancel, so a cancel that
            // arrives later is judged on its own (no flow = today's
            // behaviour, unchanged).
            unset($state['flow_just_closed']);
            $conversation->state_data = $state;
            $conversation->save();
            $flowActive = true;
        }
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
            unset($state['collect'], $state['cart'], $state['floor_repeats'], $state['pending_summary'], $state['qty'], $state['qty_offer'], $state['flow_just_closed']);
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
        //
        // One shape must never route sideways (batch 9): the awaited
        // address itself. House and road numbers are digits, so with a
        // bargained offer standing (neg_step >= 1) detectIntent's
        // mid-bargain digit branch reads an address answer as another
        // ladder push — the bargain-mode defect, one state over — and
        // the capture below never sees it. An address-shaped message
        // (a label, a comma, an address word — the same signals the
        // capture accepts) carrying NO money marker and NO bargain
        // word is the answer to the question on the table; a genuine
        // push always carries one of those two, so it still routes
        // sideways exactly as batch 5 built it.
        $sideIntent = null;
        if (! $carriesDetails) {
            $candidate = $this->cleanAddressCandidate(trim(str_replace($phoneInMsg ?? '~~~', '', $norm)), $this->extractName($norm));
            $addressShaped = ! preg_match('/টাকা|taka|\btk\b|৳/iu', $text)
                && ! $this->intentMatches('bargain', mb_strtolower($text))
                && ($this->extractLabelledAddress($norm, $phoneInMsg) !== null
                    || ($candidate !== null && (str_contains($candidate, ',')
                        || (bool) preg_match('/বাসা|রোড|হোল্ডিং|থানা|জেলা|সেক্টর|ব্লক|গ্রাম/u', $candidate))));
            if (! $addressShaped) {
                $intent = $this->detectIntent($text, $conversation);
                if ($intent === 'price' || $intent === 'bargain') {
                    $sideIntent = $intent;
                }
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

        // The returning-customer address question (batch 15, finding
        // #25): "আগের ঠিকানায় পাঠাব?" is on the table and this
        // message is its answer. A yes marks the record's address as
        // THIS flow's — the customer confirmed it; it was never
        // silently reused — and the collection completes below. A no
        // closes the question and asks for the new address plainly.
        // A message carrying details IS the new answer and falls
        // through to the capture below; a side question falls
        // through to the side answer with the question still
        // standing; anything else re-asks the question once rather
        // than dropping it.
        if (($collect['address_confirm'] ?? null) === 'pending') {
            $answer = $carriesDetails ? null : $this->yesNo($text);
            if (! $carriesDetails && $answer === true) {
                $collect['address_confirm'] = 'done';
                $state['collect'] = $collect;
                $conversation->state_data = $state;
                $this->markDetailFresh($conversation, 'address');
                $conversation->save();
            } elseif (! $carriesDetails && $answer === false) {
                $collect['address_confirm'] = 'done';
                $state['collect'] = $collect;
                $conversation->state_data = $state;
                $conversation->save();

                return $this->simpleOutgoing($conversation, $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name), 'collect_question', $mode, ['collect' => 'address']);
            } elseif (! $carriesDetails && $sideIntent === null) {
                $previousAddress = $this->previousDeliveredAddress($conversation);
                if ($previousAddress !== null) {
                    return $this->simpleOutgoing($conversation, $this->addressConfirmQuestion($biz, $conversation->customer_name, $previousAddress), 'collect_question', $mode, ['collect' => 'address', 'address_confirm' => true]);
                }
                $collect['address_confirm'] = 'done';
                $state['collect'] = $collect;
                $conversation->state_data = $state;
                $conversation->save();
            }
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
            if ($addr === null && ! $this->isCorrectionText(mb_strtolower($text))) {
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

        // A variant named mid-collection replaces the one the
        // collection started with (batch 14, finding #13): the
        // collection's frozen variant_id used to be whatever
        // orderTaking recorded, so "M ta diben" between the collect
        // question and the details changed nothing and the summary
        // came out for the first variant. variantFor reads the
        // message (a named variant wins over the remembered
        // choice); the collection follows it, and the local state
        // is re-read afterwards because variantFor persists its
        // own copy.
        $collectProduct = Product::where('business_id', $biz)->find($collect['product_id'] ?? $conversation->product_id);
        if ($collectProduct !== null && $collectProduct->hasVariants()) {
            $namedVariant = $this->variantFor($conversation, $collectProduct, $text);
            if ($namedVariant !== null && (int) ($collect['variant_id'] ?? 0) !== (int) $namedVariant->id) {
                $collect['variant_id'] = $namedVariant->id;
                $state = (array) ($conversation->state_data ?? []);
                $state['collect'] = $collect;
                $conversation->state_data = $state;
                $conversation->save();
            }
        }

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
                // the thread and the handover is on the record. The
                // customer hears the one holding line too (batch 10).
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — collection product no longer exists', null, $conversation);

                return $this->holdingReply($conversation, $mode);
            }
            $variant = !empty($collect['variant_id']) ? \App\Models\ProductVariant::find($collect['variant_id']) : null;
            // Stock 0 = NO order (owner addendum, Round 6 P1): if the item
            // sold out while the details were being collected, the sale
            // stops here with the honest answer - no summary, no order.
            if ($this->focusAvailable($product, $variant) <= 0) {
                return $this->simpleOutgoing($conversation, $this->outOfStockBody($biz, $conversation->customer_name, $product, $variant), 'out_of_stock', $mode, ['out_of_stock' => true]);
            }
            // Fewer pieces on the shelf than the flow is buying
            // (batch 13): the honest count stops the summary the
            // same way — the customer can still take what IS there.
            $wantQty = $this->flowQty($conversation);
            $availQty = $this->focusAvailable($product, $variant);
            if ($wantQty > 1 && $availQty !== null && $availQty < $wantQty) {
                $this->noteQtyOffer($conversation, $product, $variant, (int) $availQty);

                return $this->simpleOutgoing($conversation, $this->insufficientQtyBody($biz, $conversation->customer_name, $product, $variant, $wantQty, (int) $availQty), 'insufficient_stock', $mode, ['insufficient_stock' => true, 'qty' => $wantQty, 'available' => (int) $availQty]);
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
            // Resolution before the side answer (batch 11b): batch
            // 11's resolveProduct() never ran inside collect mode,
            // so a side question naming a CATEGORY ("Panjabi tar dam
            // koto?" while collecting one of two Panjabis) was
            // answered about the collect product — a silent guess,
            // live 2026-11-25 20:26. A category tie now clarifies
            // from here too; the pick is then resolved by the
            // existing machinery at the top of handleIncoming and
            // routed at the collecting dispatch.
            $clarify = $this->collectClarification($conversation, $collect, $sideIntent, $text, $mode);
            if ($clarify !== null) {
                return $clarify;
            }

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

                // The flow gives up here; the customer used to hear
                // nothing at exactly that moment (batch 10) — one
                // holding line goes out before the thread goes quiet.
                return $this->holdingReply($conversation, $mode);
            }
        } else {
            $collect['retries'] = 0;
        }
        $collect['step'] = $next;
        $state['collect'] = $collect;
        $conversation->state_data = $state;
        $conversation->save();

        // The address is the only piece left and a previous DELIVERED
        // order's address is on the record (batch 15, finding #25):
        // the ask is the confirmation question, not the plain one.
        if ($missing === ['address'] && ! isset($collect['address_confirm'])) {
            $previousAddress = $this->previousDeliveredAddress($conversation);
            if ($previousAddress !== null) {
                $collect['address_confirm'] = 'pending';
                $state['collect'] = $collect;
                $conversation->state_data = $state;
                $conversation->save();

                return $this->simpleOutgoing($conversation, $this->addressConfirmQuestion($biz, $conversation->customer_name, $previousAddress), 'collect_question', $mode, ['collect' => 'address', 'address_confirm' => true]);
            }
        }

        return $this->simpleOutgoing($conversation, $this->collectQuestion($biz, $this->collectMissing($conversation), $conversation->customer_name), 'collect_question', $mode, ['collect' => $next]);
    }

    /**
     * The clarification a mid-collection side question can owe
     * (brain fix batch 11b): the customer is collecting one product
     * but asks about a category that ties two or more ("Panjabi tar
     * dam koto?" while collecting one of two Panjabis). Batch 11's
     * resolution runs here exactly as in the main chain — the side
     * question is resolved, and only a genuine category tie
     * (ambiguousProductsFor) clarifies: the candidates are listed by
     * name, no prices, and NO collect reminder is appended (the
     * clarification is a question of its own, not a side answer).
     * The collection state is left exactly as it was; the pending
     * pick is remembered with the collect product's id, so the
     * collecting dispatch in handleIncoming can tell a pick of the
     * collect product (answer + keep collecting) from a pick of a
     * different one (a product switch). Anything that is not a tie
     * — the collect product itself, an explicit name, a bare
     * follow-up to the fresh focus — returns null and the batch-5
     * side answer proceeds untouched. (resolveProduct's bare-
     * question listing is deliberately not imported: mid-collection
     * the collect product IS the referent of a bare question, and
     * the resolution's FOCUS_REF outcome already says so whenever
     * the focus is fresh; the tie check keeps the listing from
     * leaking in through a stale focus timestamp.)
     */
    private function collectClarification(Conversation $conversation, array $collect, string $sideIntent, string $text, string $mode): ?ChatMessage
    {
        $biz = (int) $conversation->business_id;
        $rule = AiRule::where('business_id', $biz)->where('active', true)
            ->where('trigger_type', 'intent')->where('match_text', $sideIntent)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
            ->first();
        if ($rule === null) {
            return null;
        }
        $resolution = $this->resolveProduct($conversation, $text, $rule);
        if ($resolution['type'] !== 'category_multi' || $this->ambiguousProductsFor($biz, $text) === null) {
            return null;
        }
        $candidates = $resolution['candidates'];
        $names = collect($candidates)->map(fn (Product $p) => $p->name)->implode(', ');
        $body = $this->honorificPrefix($biz, $conversation->customer_name)
            . 'কোন পণ্যটার কথা বলছেন? আমাদের পণ্য: ' . $names . '। কোনটা, সেটা বলুন — তাহলেই দাম জানিয়ে দিচ্ছি।';
        $ids = collect($candidates)->map(fn (Product $p) => (int) $p->id)->all();
        $state = (array) ($conversation->state_data ?? []);
        $state['product_clarify'] = [
            'product_ids' => $ids,
            'intent' => $sideIntent,
            'collect_product_id' => (int) ($collect['product_id'] ?? $conversation->product_id),
        ];
        $conversation->state_data = $state;
        $conversation->save();
        $this->log($biz, 'product_clarify', $conversation->customer_name . ' — ambiguous product mid-collection, asked which one: ' . $names, $rule, $conversation);

        return $this->simpleOutgoing($conversation, $body, 'product_clarify', $mode, ['clarify' => true, 'candidates' => $ids]);
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
                // hands over at exactly this point too — logged, the
                // thread stays flagged for the human, and the customer
                // hears the one holding line (batch 10), never silence.
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', null, $conversation);

                return $this->holdingReply($conversation, $mode);
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
        $inside = $this->insideDeliveryCost($businessId);
        $outside = $this->outsideDeliveryCost($businessId);
        $figures = [$inside, $outside, $this->negotiation->config($businessId)['free_delivery_min']];
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
                $figures[] = $p + $outside;
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
                $figures[] = (float) $rung + $outside;
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
                // A message nobody answered is a thread a human must
                // see (batch 10): the log alone left the flag down, so
                // the inbox never surfaced the waiting customer.
                $conversation->needs_human = true;
                $conversation->save();
                $this->log((int) $conversation->business_id, 'unanswered', $conversation->customer_name . ' — message produced no reply, draft or logged handover', null, $conversation);
            }
        }

        return $result;
    }

    public function handleIncoming(Conversation $conversation, string $text, array $opts = []): ?ChatMessage
    {
        $startedAt = microtime(true);
        $biz = (int) $conversation->business_id;
        // The media pipeline's high-confidence pass-through (a photo
        // whose caption/focus/hash already identified the product)
        // invokes the brain with the bare caption as its text; its
        // no-rule product answer is that flow's designed reply, not
        // an unreviewed conversational fallback (batch 10 scoping).
        $mediaMatch = ! empty($opts['media_match']);
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

        // A quantity stated anywhere in the message rides the flow
        // (batch 13) — captured after the switch, so a quantity in
        // the switch message itself counts for the NEW product.
        $this->captureQuantityFromText($conversation, $text);

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

        // Product resolution (batch 11, generalising batch 8): the
        // message is resolved to ONE outcome before the chain reads
        // a product — an explicit name, a category tie to clarify
        // (focus or no focus), a fresh focus the message points
        // back at, or nothing. On a clarification no product is put
        // in focus until the customer picks; the candidates are
        // listed by the clarification branch in the reply chain
        // below. (A message naming an item the shop does not sell
        // resolves to none and keeps its existing path: the generic
        // clarify, or the not-available answer when a product is in
        // focus.)
        $clarifyWith = null;
        $resolutionType = null;
        if ($pendingPick !== null) {
            $product = $pendingPick;
            // A pick from a clarification is the customer naming the
            // product themselves — an establishment, like an explicit
            // mention (batch 14, Step 2 slot-binding reads this).
            $resolutionType = 'explicit';
        } else {
            $resolution = $this->resolveProduct($conversation, $text, $rule);
            $resolutionType = $resolution['type'];
            if ($resolution['type'] === 'category_multi') {
                $clarifyWith = $resolution['candidates'];
            }
            $product = $clarifyWith !== null ? null : $resolution['product'];
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
                    // The classifier's suggestion is a guess, not an
                    // establishment (batch 14, Step 2 slot-binding).
                    $resolutionType = 'guessed';
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
            && $rule && (in_array($rule->match_text, ['stock', 'order_intent'], true)
                // A price question needs the variant too when the
                // variants disagree on price (batch 14, finding
                // #15): there is no single figure to quote, and the
                // product's base price is not any variant's price.
                // When every variant shares one price the question
                // is answered directly with that figure (the slot
                // override in the reply tail), so no question is
                // needed.
                || ($rule->match_text === 'price' && $this->variantPricesDiffer($product)));

        // A confirmation that names a figure at/above the floor settles
        // the negotiation at THAT figure before order taking reads
        // neg_offer (the accept-at-counter semantics of
        // NegotiationService::quote). A standing offer confirmed as-is
        // is likewise an agreement, recorded for the reply's meta.
        $agreedNow = null;
        if ($product && $rule && $rule->match_text === 'order_intent' && $conversation->neg_offer !== null) {
            // A figure named inside a message that carries the
            // customer's details is never a settlement figure (batch
            // 9): the details are the message's business, so the
            // standing offer stands exactly as it was.
            $named = $this->carriesDetails($text) ? null : $this->namedAmount($text);
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
            if ($rule->match_text === 'abuse') {
                // Profanity (batch 15): FULLY silent — no holding
                // line, no acknowledgement, nothing drafted. The
                // needs_human flag and the handover log above are
                // the whole outcome; a human decides what happens
                // next. Anger without profanity keeps its holding
                // line exactly as before.
                return null;
            }
            // A handover rule carrying a real acknowledgement template
            // (payment proof received, a cancel request) still sends
            // that one line through the normal guarded path below —
            // silence after a customer sends money proof reads as
            // broken — and the conversation stays flagged for the
            // human either way. A template-less handover (legal_threat)
            // used to stay silent; it now sends the one holding line
            // instead (batch 10), throttled like every holding line,
            // and the flag above stays up either way.
            if (mb_strlen(trim((string) $rule->reply_template)) < 10) {
                $hMode = $this->applyWorkingHours($biz, $auto[$conversation->channel] ?? 'draft');
                if ($hMode !== 'off') {
                    return $this->holdingReply($conversation, $hMode);
                }

                return null;
            }
            $handoverAck = true;
        }

        $mode = $this->applyWorkingHours($biz, $auto[$conversation->channel] ?? 'draft');
        if ($mode === 'off') {
            return null;
        }

        // Outside working hours the reply below only drafts — and
        // until batch 15 the customer heard NOTHING at all, which
        // reads as a dead page. One line per off-hours stretch tells
        // them the shop is closed and when it opens; the reply itself
        // still waits for the morning as a draft.
        $this->offHoursLineOnce($conversation, $auto[$conversation->channel] ?? 'draft');

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
            // A clarification raised INSIDE collection (batch 11b)
            // gets its pick here: the pick resolution at the top of
            // this method has already identified the product, and
            // handleCollect would only read the bare pick ("2") as
            // a non-answer and re-ask the collect question — the
            // second half of the live 20:26 failure.
            if ($pendingPick !== null && ! empty($pendingClarify['collect_product_id'] ?? null)) {
                if ((int) $pendingPick->id === (int) $pendingClarify['collect_product_id']
                    && ! $this->carriesDetails($text)) {
                    // The collect product itself: the pending
                    // question is answered the batch-5 way (its
                    // price, or the ladder for a bargain — the
                    // pick text names no figure, so quote() reads
                    // it as the original push) and the collection
                    // continues untouched. A pick carrying details
                    // stays collection input instead (batch 5/9
                    // doctrine) and falls to handleCollect below.
                    $pickCollect = (array) (($conversation->state_data ?? [])['collect'] ?? []);
                    $pickIntent = in_array((string) ($pendingClarify['intent'] ?? ''), ['price', 'bargain'], true)
                        ? (string) $pendingClarify['intent'] : 'price';

                    return $this->collectSideAnswer($conversation, $pickCollect, $pickIntent, $text, $mode);
                }
                if ((int) $pendingPick->id !== (int) $pendingClarify['collect_product_id']) {
                    // A different product: a switch, with the same
                    // close-out switchProductIfNamed performs — the
                    // old collection ends, its ladder dies, and the
                    // details it collected are marked consumed (they
                    // stay on the record for the order-status lookup
                    // and the channel identity; they stop counting
                    // for the new product). A detail stated in the
                    // pick message itself belongs to the new flow.
                    // The focus already moved to the pick above; the
                    // chain below then answers the pending question
                    // for the new product.
                    $pickState = (array) ($conversation->state_data ?? []);
                    foreach (['collect', 'cart', 'variant_pending', 'variant_id', 'floor_repeats', 'pending_summary', 'last_order'] as $key) {
                        unset($pickState[$key]);
                    }
                    $pickState['details_consumed'] = true;
                    $fresh = ['name' => false, 'phone' => false, 'address' => false];
                    if ($this->extractName($this->guard->normalizeDigits($text)) !== null) {
                        $fresh['name'] = true;
                    }
                    $pickPhone = $this->extractPhone($text);
                    if ($pickPhone !== null) {
                        $fresh['phone'] = true;
                    }
                    if ($this->extractLabelledAddress($this->guard->normalizeDigits($text), $pickPhone) !== null) {
                        $fresh['address'] = true;
                    }
                    $pickState['details_fresh'] = $fresh;
                    // The collection this switch just closed was a
                    // live flow (batch 16): arm the next message for
                    // flowCancellation, whose flow-active test would
                    // otherwise find nothing left to cancel and let
                    // a following "বাদ দিন" fall to the no-rule
                    // price repeat.
                    $pickState['flow_just_closed'] = true;
                    $conversation->state_data = $pickState;
                    $conversation->state = null;
                    $conversation->neg_step = 0;
                    $conversation->neg_offer = null;
                    $conversation->save();
                    $this->log($biz, 'product_switched', $conversation->customer_name . ' — clarification pick switched the collection to: ' . $pendingPick->name, null, $conversation);
                }
            }
            if ($conversation->state === 'collecting') {
                return $this->handleCollect($conversation, $text, $mode);
            }
        }

        // A standing reduced-quantity offer gets its yes/no first
        // (batch 14, finding #18): the insufficient-stock reply
        // asked "Xটা নিলে বলুন" and this message may be the bare
        // answer. A yes takes the offered count into order taking
        // for the offered product (the chain below runs it as an
        // order intent); a no closes the offer with a short
        // acknowledgement. An answer naming a DIFFERENT product
        // kills the offer instead — the switch at the top of this
        // method already moved the focus — and a message that is
        // not a yes/no leaves the offer standing, exactly like the
        // photo confirmation's pending question.
        $offerState = (array) ($conversation->state_data ?? []);
        if (! empty($offerState['qty_offer']) && empty($offerState['pending_summary'])) {
            $offer = (array) $offerState['qty_offer'];
            $offerProduct = Product::where('business_id', $biz)->find((int) ($offer['product_id'] ?? 0));
            $namedOther = $this->explicitProductFor($biz, $text);
            if ($offerProduct === null || ($namedOther !== null && (int) $namedOther->id !== (int) $offerProduct->id)) {
                unset($offerState['qty_offer']);
                $conversation->state_data = $offerState;
                $conversation->save();
            } else {
                $offerAnswer = $this->yesNo($text);
                if ($offerAnswer === false) {
                    unset($offerState['qty_offer']);
                    $conversation->state_data = $offerState;
                    $conversation->save();

                    return $this->simpleOutgoing($conversation, $this->honorificPrefix($biz, $conversation->customer_name) . 'ঠিক আছে, কোনো সমস্যা নেই। অন্য কিছু জানতে চাইলে বলুন।', 'qty_offer_declined', $mode);
                }
                if ($offerAnswer === true) {
                    // The repeat-confirmation guard must not swallow
                    // this yes: it answers the offer, not the last
                    // order, so that memory is cleared as the new
                    // flow starts (the same reason batch 9 clears it
                    // when the order it names is dead).
                    unset($offerState['qty_offer'], $offerState['last_order']);
                    $offerState['qty'] = max(1, (int) ($offer['qty'] ?? 1));
                    $conversation->state_data = $offerState;
                    $conversation->product_id = $offerProduct->id;
                    $conversation->focus_at = now();
                    $conversation->save();
                    $product = $offerProduct;
                    $variant = ! empty($offer['variant_id'])
                        ? \App\Models\ProductVariant::find((int) $offer['variant_id'])
                        : $this->variantFor($conversation, $offerProduct, $text);
                    $intent = 'order_intent';
                    $rule = AiRule::where('business_id', $biz)->where('active', true)
                        ->where('trigger_type', 'intent')->where('match_text', 'order_intent')
                        ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $conversation->channel))
                        ->first() ?? $rule;
                }
            }
        }

        // A variant change with a summary on the table re-renders
        // the summary at the new variant (batch 14) — before the
        // confirmation hook, like the quantity change below, so a
        // "confirm" can never create an order for the variant the
        // customer just replaced.
        $variantChange = $this->variantChangeRerender($conversation, $text, $mode);
        if ($variantChange !== null) {
            return $variantChange;
        }

        // A quantity change with a summary on the table re-renders
        // the summary at the new quantity (batch 13) — before the
        // confirmation hook, so "না, ৩টা করেন" never confirms the
        // old x1 summary.
        $qtyChange = $this->qtyChangeRerender($conversation, $mode);
        if ($qtyChange !== null) {
            return $qtyChange;
        }

        // A details correction with a summary on the table (batch
        // 15): capture has already put the corrected name/phone/
        // address on the record, and the confirmation hook below
        // would only fall through and point at the stale summary —
        // the summary is re-rendered at once with the corrected
        // details, the twin of the quantity/variant rerenders.
        $detailsChange = $this->detailsChangeRerender($conversation, $text, $mode);
        if ($detailsChange !== null) {
            return $detailsChange;
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
                // And never unflagged (batch 10): an unanswered customer
                // is a thread a human must see in the inbox.
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — bargain message with no product in focus', $rule, $conversation);

                return null;
            }
            if ($this->carriesDetails($text)) {
                // A message carrying the customer's details is never
                // a counter-offer, whatever matched the bargain rule
                // (batch 9 — the chain-level net behind detectIntent's
                // own guard): the details are already captured on the
                // record, so take the order at the standing offer —
                // the summary when they are complete, the
                // missing-pieces question when they are not. The
                // ladder does not move and no rung is consumed.
                $o = $this->orderTaking($conversation, $product, $variant, $biz, $conversation->neg_offer !== null ? (float) $conversation->neg_offer : null);
                $slottedBody = $o['slottedBody'];
                $kind = $o['kind'];
                $meta = $o['meta'];
                $body = $o['body'];
                if (isset($o['meta']['offer'])) {
                    $slotValues = ['offer' => $o['meta']['offer']];
                }
            } else {
                $quote = $this->negotiation->quote($conversation, $product, $this->namedAmount($text));
                if ($quote === null) {
                    $conversation->needs_human = true;
                    $conversation->save();
                    $this->log($biz, 'handed_human', $conversation->customer_name . ' — দাম floor-এ পৌঁছেছে', $rule, $conversation);

                    // The handover is on the record and the thread is
                    // flagged — but the customer used to hear NOTHING
                    // at this point (batch 10): one holding line goes
                    // out first, throttled to one per day.
                    return $this->holdingReply($conversation, $mode);
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
            }
        } elseif ($rule && $rule->match_text === 'order_intent') {
            if (!$product) {
                $conversation->needs_human = true;
                $conversation->save();
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
        } elseif ($rule && ($multi = $this->multiIntentParts($conversation, $text, $rule, $product, $variant, $biz)) !== null) {
            // Two or three independent informational questions in
            // ONE message (batch 15, Step 7): each part is produced
            // by its own existing path and the parts are joined into
            // a single reply, which then passes the SAME talker,
            // money and claim verification below as any single
            // answer. The helper returns null for anything else —
            // more than three parts, a part that would hand over,
            // draft or fall back, or any flow-state intent — and
            // today's single-intent behaviour proceeds untouched.
            $slottedBody = $multi['body'];
            $kind = 'multi_intent';
            $meta = ['multi_intent' => $multi['intents']];
            if ($multi['slotValues'] !== []) {
                $slotValues = $multi['slotValues'];
            }
        } elseif ($product && $product->hasVariants() && ($listKind = $this->variantListAsk($text)) !== null
            && ! in_array($rule?->match_text ?? '', ['price', 'stock', 'bargain', 'order_intent'], true)) {
            // "কি কি কালার আছে?" / "সাইজ কি কি?" (batch 15): the
            // product's ACTUAL variant names, read from its data —
            // never an invented list. A price/stock/bargain/order
            // rule keeps its own branch: the list question is the
            // message's business only when nothing stronger claims it.
            $slottedBody = $this->variantListBody($biz, $conversation->customer_name, $product, $listKind);
            $kind = 'variant_list';
            $meta = ['variant_list' => true];
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
            // Language matching (batch 15, finding #24): an
            // English-dominant message gets the English template for
            // the four core informational intents — the lightest
            // honest version; the rest of the catalog stays Bangla,
            // and romanised Bangla is not English (see
            // isEnglishDominant).
            $english = $this->isEnglishDominant($text);
            if ($english && isset(self::ENGLISH_TEMPLATES[$rule->match_text])) {
                $enRule = clone $rule;
                $enRule->reply_template = self::ENGLISH_TEMPLATES[$rule->match_text];
                $slottedBody = $this->buildReply($enRule, $product, $biz, true, $this->honorific($biz, $conversation->customer_name));
            } else {
                $slottedBody = $this->buildReply($rule, $product, $biz, true, $this->honorific($biz, $conversation->customer_name));
            }
            if ($product !== null
                && in_array($resolutionType, ['scored', 'guessed'], true)
                && ! in_array($rule->match_text, ['price', 'stock', 'bargain', 'order_intent'], true)
                && str_contains((string) ($rule->reply_template ?? ''), '{product}')) {
                // Slot-binding (batch 14, Step 2 remainder): a rule
                // whose template speaks about {product} may only
                // name a product the customer ESTABLISHED — named
                // outright, picked from a clarification, or the
                // fresh focus. A merely scored overlap (a shared
                // name word) or the classifier's guess is not an
                // establishment, so the product slots are struck
                // from the slotted body here, where nothing
                // downstream can refill them: the reply goes out
                // product-free, or — when the template also carries
                // the price — as the broken-figure clarification
                // below. The four product intents keep batch 11's
                // resolution semantics, scored winner included:
                // there the scored match IS the resolution step's
                // own answer.
                $slottedBody = str_replace(['⟦product⟧', '⟦price⟧', '⟦stock⟧'], ['পণ্যটা', '', ''], $slottedBody);
            }
            if ($rule->match_text === 'delivery') {
                // A delivery-charge question that names its area is
                // answered with THAT area's charge (batch 13 — the
                // reviewer's catalog 'Area charge' case): the rule's
                // both-charges template would not answer the question
                // that was asked. No area named: the template stands.
                $areaBody = $english
                    ? $this->areaChargeBodyEn($biz, $text, $conversation->customer_name)
                    : $this->areaChargeBody($biz, $text, $conversation->customer_name);
                if ($areaBody !== null) {
                    $slottedBody = $areaBody;
                }
            }
            if (trim($slottedBody) === '') {
                $conversation->needs_human = true;
                $conversation->save();
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
            // A quoted invoice number is looked up FIRST (batch 12,
            // finding #20): the old reply told the customer to give
            // the number, but only the phone was ever searched. The
            // lookup is scoped to THIS shop — another shop's number
            // is simply "not found" — and the number itself is the
            // credential, as in normal shop practice, so the answer
            // carries status only, never names, addresses or items.
            $order = null;
            $mention = $this->invoiceMention($text);
            if ($mention !== null) {
                $order = \App\Models\Order::where('business_id', $biz)
                    ->whereRaw("UPPER(REPLACE(invoice_no, '-', '')) = ?", [$mention])
                    ->first();
                if ($order) {
                    $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'আপনার অর্ডার (⟦invoice⟧) এখন ⟦status⟧ অবস্থায় আছে।';
                    $slotValues = ['invoice' => $order->invoice_no, 'status' => (string) $order->status];
                    $meta = ['order_status' => $order->id];
                } else {
                    $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'দুঃখিত, ⟦invoice⟧ নম্বরের কোনো অর্ডার আমাদের এখানে খুঁজে পাইনি। নম্বরটা ঠিক আছে কিনা একটু দেখুন — অথবা যে ফোন নম্বর দিয়ে অর্ডার করেছিলেন, সেটা দিলে সেই নম্বর দিয়ে খুঁজে দিচ্ছি।';
                    $slotValues = ['invoice' => $mention];
                    $meta = ['order_status' => null, 'invoice_not_found' => $mention];
                }
                $kind = 'order_status';
            } elseif (!empty($conversation->customer_phone)) {
                $order = \App\Models\Order::where('business_id', $biz)
                    ->where('customer_phone', $conversation->customer_phone)
                    ->latest('id')->first();
            }
            if ($mention === null) {
                if ($order) {
                    $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'আপনার অর্ডার (⟦invoice⟧) এখন ⟦status⟧ অবস্থায় আছে।';
                    $slotValues = ['invoice' => $order->invoice_no, 'status' => (string) $order->status];
                    $meta = ['order_status' => $order->id];
                } else {
                    $slottedBody = $this->honorificPrefix($biz, $conversation->customer_name) . 'আপনার নামে কোনো অর্ডার খুঁজে পাইনি। অর্ডারের নম্বরটা দিলে দেখে দিচ্ছি।';
                    $meta = ['order_status' => null];
                }
                $kind = 'order_status';
            }
        } elseif (! $rule && $intent === null && $product && $this->carriesDetails($text)) {
            // Details with no bargain and no order words (batch 14,
            // finding #16): the price reply told the customer to
            // send name, address and phone — this message IS that
            // answer. Batch 9 routed such messages into order
            // taking from the bargain branch, but with no bargain
            // started they matched no rule and no intent and fell
            // into the no-rule fallback below (a draft, a human
            // flag, no order), even though the details were already
            // captured and a product stands in focus. They are
            // order input here too: the summary when the details
            // complete, the collection for what is missing.
            $o = $this->orderTaking($conversation, $product, $variant, $biz, $conversation->neg_offer !== null ? (float) $conversation->neg_offer : null);
            $slottedBody = $o['slottedBody'];
            $kind = $o['kind'];
            $meta = $o['meta'];
            $body = $o['body'];
            if (isset($o['meta']['offer'])) {
                $slotValues = ['offer' => $o['meta']['offer']];
            }
        } elseif (! $rule && ($short = $this->shortReply($conversation, $text)) !== null) {
            // A bare acknowledgement ("ওকে", "আচ্ছা") or a bare
            // presence ping ("আছেন?") with nothing pending (batch
            // 15): a brief polite line, word-for-word. This is the
            // DELIBERATE carve-out from batch 10's no-rule safety —
            // a RECOGNISED acknowledgement is not an unmatched
            // message, so it sends normally and raises no human
            // flag. It can only be reached with no rule matched and
            // every pending question (summary confirmation, photo
            // confirmation, quantity offer, collection) already
            // handled above, so an acceptance still confirms first.
            $slottedBody = $short['body'];
            $kind = $short['kind'];
            $meta = [$short['kind'] => true];
        } else {
            // No rule matched. First try the shop's own Knowledge — the AI
            // may answer policy questions only from what the shop wrote.
            $knowledge = $this->knowledgeFor($biz, $text);
            if ($knowledge !== null) {
                $slottedBody = (string) $knowledge->body;
                $meta = ['knowledge_id' => $knowledge->id];
            } elseif (!$product) {
                $conversation->needs_human = true;
                $conversation->save();
                $this->log($biz, 'unanswered', $conversation->customer_name . ' — no rule matched and no product in focus', null, $conversation);

                return null;
            } else {
                // Draft from the product description; a human always checks
                // a no-rule draft before it goes out — batch 10 makes that
                // literally true below, where a no-rule reply is forced to
                // draft in every mode and the thread is flagged.
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
        if (! $variant && $product && $rule && $rule->match_text === 'price' && $product->hasVariants()
            && ! $this->variantPricesDiffer($product)) {
            // Every variant costs the same (batch 14, finding #15):
            // that shared figure is the honest quote — the product's
            // own base price may be a different number nothing
            // actually sells at.
            $slotValues['price'] = $product->activeVariants()->first()->effectivePrice();
        }

        // The talker only phrases what was decided above, working on the
        // slotted text — it never sees a real figure. Code renders the
        // slots afterwards, then re-verifies figures AND claims before
        // anything can be sent. The order summary is never phrased.
        $talkerMeta = [];
        $slotValues = $slotValues ?? [];
        if ($kind === 'order_summary') {
            $draftRendered = $body;
        } elseif ($kind === 'variant_question' || $kind === 'collect_question' || $kind === 'out_of_stock' || $kind === 'product_clarify' || $kind === 'insufficient_stock' || $kind === 'variant_list' || $kind === 'acknowledgement' || $kind === 'presence') {
            // Fixed questions and the out-of-stock answer must reach the
            // customer word-for-word — no phrasing. The batch-15 fixed
            // lines (a variant list read from data, a bare
            // acknowledgement, a presence answer) join that list for
            // the same reason: their wording IS the behaviour.
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
            $insideNow = $this->insideDeliveryCost($biz);
            $extraFigures = array_merge($extraFigures, [$neg, $neg + $insideNow, $neg + $this->outsideDeliveryCost($biz)]);
        }
        if (($kind ?? null) === 'order_summary' && isset($meta['total'])) {
            // The summary's own totals (batch 13): the line total is
            // unit x qty and the grand total adds the delivery charge
            // for the customer's area — both are honest for THIS
            // conversation only, derived from the summary snapshot
            // the customer is looking at, so the guard must allow
            // exactly them (a x3 summary's 3,900 line is in no
            // per-product figure set).
            $extraFigures[] = (float) $meta['total'];
            if (isset($meta['unit_price'])) {
                $extraFigures[] = (float) $meta['unit_price'] * (int) ($meta['qty'] ?? 1);
            }
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
        if (! empty($meta['no_rule']) && ! $mediaMatch) {
            // A no-rule fallback — the focus product's description or
            // price line, composed for a message NOTHING matched —
            // never auto-sends, whatever the mode (batch 10): in Full
            // auto (and plain Auto, since it commits nothing) the
            // status above was 'sent', so a customer's "ওকে" was
            // answered with a product description nobody had checked.
            // It is always a draft, and the thread is flagged so the
            // human the draft waits for actually comes. Guard findings
            // stay on the draft's meta, and approval re-runs the
            // guards at send time exactly as before. The media
            // pipeline's high-confidence pass-through is exempt: there
            // the product was identified by the customer's own photo
            // caption, and this product answer is that flow's designed
            // reply (its gate pins "no human flag for a matched photo").
            $status = 'draft';
            $conversation->needs_human = true;
            $conversation->save();
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
            $insideNow = $this->insideDeliveryCost($biz);
            $extras = [$neg, $neg + $insideNow, $neg + $this->outsideDeliveryCost($biz)];
        }
        if (!empty($msg->meta['unit_price'])) {
            $unit = (float) $msg->meta['unit_price'];
            $insideNow = $this->insideDeliveryCost($biz);
            $extras = array_merge($extras, [$unit, $unit + $insideNow, $unit + $this->outsideDeliveryCost($biz)]);
            if (!empty($msg->meta['total'])) {
                // A summary draft's own figures (batch 13): its line
                // total is unit x qty and its grand total adds the
                // area's delivery charge — both quoted to the
                // customer in this very draft, so approval must not
                // hold it for figures it honestly carries.
                $extras[] = (float) $msg->meta['total'];
                $extras[] = $unit * (int) ($msg->meta['qty'] ?? 1);
            }
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

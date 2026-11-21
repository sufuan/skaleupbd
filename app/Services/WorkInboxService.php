<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\CourierBookingLog;
use App\Models\DailyClosing;
use App\Models\IpPhoneBlock;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Models\WorkEvent;
use App\Models\WorkTask;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * M10 Work Inbox — the system's "problem notebook".
 *
 * Every ~5 minutes (workinbox:run) the enabled rules are checked against
 * live data. A problem creates ONE open task (dedupe by fingerprint);
 * when the problem clears, the task closes itself with the reason kept
 * in history. Overdue tasks escalate one priority step and notify.
 *
 * Rule settings live per business in `workinbox.{biz}.rules`; shops
 * without stored settings fall back to the catalog defaults below, so
 * existing shops work without any settings rows being invented.
 */
class WorkInboxService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SmsService $sms,
    ) {}

    public const PRIORITIES = ['normal' => 'সাধারণ', 'urgent' => 'জরুরি', 'critical' => 'খুব জরুরি'];

    public const GROUPS = [
        'orders' => 'Orders',
        'delivery' => 'Delivery / Courier',
        'messages' => 'Messages',
        'inventory' => 'Inventory',
        'purchase' => 'Purchase',
        'followup' => 'Call Center ও Follow-up',
        'finance' => 'Finance',
        'system' => 'System',
    ];

    public const ASSIGNEES = [
        'pool' => 'পুল (Unassigned)',
        'delivery' => 'Delivery role',
        'accounts' => 'Accounts role',
        'purchase' => 'Purchase role',
        'processor' => 'যে process করেছে',
        'claim' => 'Claim handler',
        'chat' => 'যে chat-এ ছিল',
        'admin' => 'মালিক / Admin',
        'staff' => 'নির্দিষ্ট staff',
    ];

    private const ROLE_MODES = ['delivery', 'accounts', 'purchase', 'claim', 'admin'];

    /** Why a task closed itself, per rule — kept in the task history. */
    private const RESOLVE_REASONS = [
        'pending_stuck' => 'order Pending থেকে সরে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'risky_fraud' => 'fraud সিদ্ধান্ত হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'blocklist_review' => 'block সরিয়ে দেওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'preorder_notify' => 'customer-কে জানানো হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'ready_to_ship' => 'order courier-এ পাঠানো হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'booking_failing' => 'booking সফল হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'shipped_stuck' => 'order Delivered হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'return_undecided' => 'return-এর সিদ্ধান্ত হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'ai_handover' => 'chat ধরা হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'window_closing' => 'উত্তর পাঠানো হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'ai_draft_stuck' => 'draft পাঠানো/সরানো হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'comment_reply' => 'comment-এর জবাব হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'low_stock' => 'stock ফিরে আসায় task নিজে থেকে বন্ধ হয়েছে',
        'ledger_mismatch' => 'ledger মিলে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'warranty_overdue' => 'claim বন্ধ হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'negative_stock' => 'stock ঠিক হয়ে যাওয়ায় task নিজে থেকে বন্ধ হয়েছে',
        'sms_balance_low' => 'balance recharge করায় task নিজে থেকে বন্ধ হয়েছে',
    ];

    /**
     * The 36-rule catalog. 'value'/'unit' = the rule's time window
     * (trigger delay for state rules, fix-it window for event rules).
     * 'dedupe': open = one open task per fingerprint, may recur after
     * resolution; ever = one task per fingerprint for good.
     * Rules whose source module is not built yet (Purchase, follow-up
     * callbacks, channel health) have no evaluator and never fire —
     * they still show in Settings exactly as the design lists them.
     */
    public static function catalog(): array
    {
        $r = [];
        $add = function (int $num, string $key, string $group, string $name, string $desc, bool $on, float $value, string $unit, string $priority, string $assign, array $extra = []) use (&$r) {
            $r[$key] = array_merge([
                'num' => $num, 'key' => $key, 'group' => $group, 'name' => $name, 'desc' => $desc,
                'enabled' => $on, 'value' => $value, 'unit' => $unit, 'priority' => $priority,
                'assign' => $assign, 'assign_user' => null,
                'note_required' => false, 'dedupe' => 'open', 'autoclose' => true,
            ], $extra);
        };

        // ---- Orders (M2) ----
        $add(1, 'pending_stuck', 'orders', 'Pending order confirm না হয়ে পড়ে আছে', 'Order Pending অবস্থায় এই সময় পার হলে', true, 2, 'hours', 'urgent', 'pool');
        $add(2, 'risky_fraud', 'orders', 'Fraud check-এ Risky, সিদ্ধান্ত হয়নি', 'Risky আসার পর advance/সিদ্ধান্ত ছাড়া এই সময় পার হলে', true, 1, 'hours', 'critical', 'processor', ['note_required' => true]);
        $add(3, 'blocklist_review', 'orders', 'Block List-এর order review হয়নি', 'Block List-এ আটকানোর পর এই সময় পার হলে', true, 4, 'hours', 'normal', 'pool');
        $add(4, 'preorder_notify', 'orders', 'Preorder এসেছে, customer-কে জানানো হয়নি', 'Preorder-এর product stock-এ আসার পর এই সময় পার হলে', true, 4, 'hours', 'normal', 'pool');
        $add(5, 'advance_unmatched', 'orders', 'Advance টাকা এসেছে, order-এ মেলেনি', 'Payment আসার পর কোনো order-এ না মিললে', true, 1, 'days', 'urgent', 'accounts');
        $add(6, 'repeat_canceller', 'orders', 'একই customer বারবার cancel করছে', 'একই customer 3 বার cancel করলেই', false, 0, 'immediate', 'normal', 'pool', ['dedupe' => 'ever', 'autoclose' => false]);

        // ---- Delivery / Courier (M3) — Done note mandatory (courier/return rules) ----
        $courier = ['note_required' => true];
        $add(7, 'ready_to_ship', 'delivery', 'Ready to Ship-এ আছে, পাঠানো হয়নি', 'Ready to Ship-এ ঢোকার পর এই সময় পার হলে', true, 1, 'days', 'urgent', 'delivery', $courier);
        $add(8, 'booking_failing', 'delivery', 'Courier booking বারবার fail করছে', 'একই order-এর booking 2 বার fail করলেই', true, 0, 'immediate', 'critical', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(9, 'shipped_stuck', 'delivery', 'Shipped, অনেকদিন Delivered হচ্ছে না', 'Shipped হওয়ার পর এই কদিন পার হলে (প্রতি 12 ঘণ্টায় আবার যাচাই)', true, 4, 'days', 'urgent', 'delivery', $courier);
        $add(10, 'courier_hold', 'delivery', 'Courier hold — parcel নড়ছে না', 'Parcel-এর status এই কদিন না বদলালে', true, 2, 'days', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(11, 'pathao_return', 'delivery', 'Pathao-তে return requested', 'Pathao panel-এ return request এলেই (সিস্টেম ~5 মিনিটে যাচাই করে)', true, 30, 'minutes', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(12, 'steadfast_cancel', 'delivery', 'Steadfast-এ cancel requested', 'Steadfast-এ cancel request এলেই', true, 30, 'minutes', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(13, 'parcel_lost_damaged', 'delivery', 'Parcel lost / damaged', 'Courier webhook-এ lost/damaged এলেই সাথে সাথে', true, 0, 'immediate', 'critical', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(14, 'parcel_resent', 'delivery', 'Parcel আবার পাঠানো (resent) হয়েছে', 'Resent হলেই — চোখ রাখার জন্য', false, 0, 'hours', 'normal', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(15, 'return_undecided', 'delivery', 'Return এসেছে, সিদ্ধান্ত হয়নি', 'Return পৌঁছানোর পর এই সময়ে সিদ্ধান্ত না হলে', true, 1, 'days', 'urgent', 'delivery', $courier);
        $add(16, 'courier_money', 'delivery', 'Courier-এর টাকা মেলেনি', 'Ledger-এ unmatched টাকা এই কদিন পড়ে থাকলে', true, 3, 'days', 'urgent', 'accounts', $courier + ['dedupe' => 'ever', 'autoclose' => false]);

        // ---- Messages (M6) ----
        $add(17, 'ai_handover', 'messages', 'AI handover করেছে, কেউ ধরেনি', 'Handover-এর পর এই সময়ে কেউ chat না ধরলে', true, 15, 'minutes', 'critical', 'chat');
        $add(18, 'window_closing', 'messages', '24 ঘণ্টার window শেষ হওয়ার পথে, উত্তর বাকি', 'Window শেষ হওয়ার এই সময় আগে, উত্তর বাকি থাকলে', true, 2, 'hours', 'urgent', 'pool');
        $add(19, 'ai_draft_stuck', 'messages', 'AI draft পড়ে আছে, কেউ পাঠায়নি', 'Draft তৈরি হওয়ার পর এই সময় পার হলে', true, 1, 'hours', 'normal', 'pool');
        $add(20, 'comment_reply', 'messages', 'Comment-এর private reply-এর সময় শেষের পথে', '7 দিনের সীমা শেষ হওয়ার এই সময় আগে', true, 1, 'days', 'normal', 'pool');
        $add(21, 'channel_broken', 'messages', 'Channel ভেঙে গেছে', 'Token-এর মেয়াদ শেষ / connection broken হলেই সাথে সাথে', true, 0, 'immediate', 'critical', 'admin');

        // ---- Inventory (M4) ----
        $add(22, 'low_stock', 'inventory', 'Stock শেষ / low stock — কেনার সিদ্ধান্ত হয়নি', 'Stock শেষ হওয়ার পর এই কদিন সিদ্ধান্ত না হলে', true, 2, 'days', 'normal', 'purchase');
        $add(23, 'ledger_mismatch', 'inventory', 'Stock ledger-এ গরমিল', 'Integrity scan-এ গরমিল ধরা পড়লেই সাথে সাথে', true, 0, 'immediate', 'urgent', 'admin');
        $add(24, 'warranty_overdue', 'inventory', 'Warranty claim overdue', 'Claim-এর নির্ধারিত দিন পার হলেই', true, 0, 'days', 'urgent', 'claim');
        $add(25, 'negative_stock', 'inventory', 'Stock negative হয়ে গেছে', 'কোনো product-এর stock 0-এর নিচে গেলেই', true, 0, 'immediate', 'urgent', 'pool');

        // ---- Purchase (M5 — module not built yet: catalog only) ----
        $add(26, 'purchase_late', 'purchase', 'Purchase পাঠানো হয়েছে, goods আসেনি', 'Purchase-এর পর এই কদিনে goods না এলে', true, 3, 'days', 'normal', 'purchase');
        $add(27, 'supplier_due', 'purchase', 'Supplier-এর পাওনা overdue', 'পাওনার নির্ধারিত তারিখ পার হলেই', true, 0, 'days', 'urgent', 'accounts');
        $add(28, 'receive_damaged', 'purchase', 'Receive-এ damaged/missing — সিদ্ধান্ত বাকি', 'Damaged/missing ওঠার পর এই সময়ে সিদ্ধান্ত না হলে', true, 1, 'days', 'normal', 'purchase');

        // ---- Call Center ও Follow-up (M8 / M9) ----
        $add(29, 'callback_missed', 'followup', 'Callback প্রতিশ্রুতি, call হয়নি', 'প্রতিশ্রুত সময়ের পর এই সময় পার হলে', true, 30, 'minutes', 'urgent', 'chat');
        $add(30, 'followup_overdue', 'followup', 'Follow-up-এর নির্ধারিত call হয়নি', 'Follow-up-এর সময় পার হওয়ার পর', true, 1, 'hours', 'urgent', 'pool');
        $add(31, 'autocall_fail', 'followup', 'Auto Call fail / balance শেষের পথে', 'বারবার call fail হলে বা balance থ্রেশহোল্ডের নিচে গেলে', true, 0, 'immediate', 'urgent', 'admin', ['dedupe' => 'ever', 'autoclose' => false]);

        // ---- Finance (M14) ----
        $add(32, 'cash_mismatch', 'finance', 'দিনের cash হিসাব মেলেনি', 'আগের দিনের হিসাব না মিললে পরদিন সকালে', true, 0, 'hours', 'urgent', 'accounts', ['dedupe' => 'ever', 'autoclose' => false]);
        $add(33, 'customer_due', 'finance', 'Customer-এর বকেয়া অনেকদিন', 'বকেয়া এই কদিন পার হলে', false, 7, 'days', 'normal', 'pool');

        // ---- System ----
        $add(34, 'device_approval', 'system', 'নতুন device approval অপেক্ষায়', 'নতুন device এলেই সাথে সাথে', true, 0, 'immediate', 'normal', 'admin');
        $add(35, 'sms_balance_low', 'system', 'SMS / Auto Call balance কম', 'Balance থ্রেশহোল্ডের নিচে গেলেই', true, 0, 'immediate', 'urgent', 'admin');
        $add(36, 'channel_data_expiry', 'system', 'Channel-এর data access-এর মেয়াদ শেষের পথে', 'মেয়াদ শেষ হওয়ার এই কদিন আগে', true, 7, 'days', 'urgent', 'admin');

        return $r;
    }

    // ---------- settings ----------

    public function ruleSettings(int $biz): array
    {
        $stored = (array) $this->settings->get("workinbox.{$biz}.rules", []);
        $out = [];
        foreach (self::catalog() as $key => $def) {
            $s = $stored[$key] ?? [];
            $out[$key] = [
                'enabled' => array_key_exists('enabled', $s) ? (bool) $s['enabled'] : $def['enabled'],
                'value' => isset($s['value']) && is_numeric($s['value']) ? (float) $s['value'] : (float) $def['value'],
                'unit' => in_array($s['unit'] ?? '', ['minutes', 'hours', 'days', 'immediate'], true) ? $s['unit'] : $def['unit'],
                'priority' => in_array($s['priority'] ?? '', array_keys(self::PRIORITIES), true) ? $s['priority'] : $def['priority'],
                'assign' => in_array($s['assign'] ?? '', array_keys(self::ASSIGNEES), true) ? $s['assign'] : $def['assign'],
                'assign_user' => isset($s['assign_user']) && $s['assign_user'] ? (int) $s['assign_user'] : $def['assign_user'],
            ];
        }

        return $out;
    }

    public function saveRuleSettings(int $biz, array $rules): void
    {
        $clean = [];
        foreach (self::catalog() as $key => $def) {
            $s = $rules[$key] ?? null;
            if (! is_array($s)) {
                continue;
            }
            $clean[$key] = [
                'enabled' => ! empty($s['enabled']),
                'value' => isset($s['value']) && is_numeric($s['value']) ? max(0, (float) $s['value']) : (float) $def['value'],
                'unit' => in_array($s['unit'] ?? '', ['minutes', 'hours', 'days', 'immediate'], true) ? $s['unit'] : $def['unit'],
                'priority' => in_array($s['priority'] ?? '', array_keys(self::PRIORITIES), true) ? $s['priority'] : $def['priority'],
                'assign' => in_array($s['assign'] ?? '', array_keys(self::ASSIGNEES), true) ? $s['assign'] : $def['assign'],
                'assign_user' => isset($s['assign_user']) && $s['assign_user'] ? (int) $s['assign_user'] : null,
            ];
        }
        $this->settings->set("workinbox.{$biz}.rules", $clean);
    }

    public function toggle(int $biz, string $name, ?bool $default = null): bool
    {
        $defaults = ['notify_new' => true, 'notify_overdue' => true, 'auto_close' => true, 'my_work_enabled' => false];

        return (bool) $this->settings->get("workinbox.{$biz}.{$name}", $default ?? $defaults[$name] ?? false);
    }

    public function setToggle(int $biz, string $name, bool $value): void
    {
        $this->settings->set("workinbox.{$biz}.{$name}", $value);
    }

    public function myWorkEnabled(int $biz): bool
    {
        return $this->toggle($biz, 'my_work_enabled');
    }

    /** Write the catalog defaults for a brand-new shop (idempotent). */
    public function seedDefaults(int $biz): void
    {
        if ($this->settings->get("workinbox.{$biz}.rules") === null) {
            $defaults = [];
            foreach (self::catalog() as $key => $def) {
                $defaults[$key] = [
                    'enabled' => $def['enabled'], 'value' => (float) $def['value'], 'unit' => $def['unit'],
                    'priority' => $def['priority'], 'assign' => $def['assign'], 'assign_user' => null,
                ];
            }
            $this->settings->set("workinbox.{$biz}.rules", $defaults);
        }
    }

    // ---------- helpers ----------

    public static function windowMinutes(array $cfg): int
    {
        $v = (float) ($cfg['value'] ?? 0);

        return match ($cfg['unit'] ?? 'hours') {
            'minutes' => (int) $v,
            'days' => (int) ($v * 1440),
            default => (int) ($v * 60),
        };
    }

    public static function bnAge(Carbon $from, ?Carbon $to = null): string
    {
        $to ??= now();
        $mins = max(0, (int) $from->diffInMinutes($to));
        if ($mins < 60) {
            return $mins . ' মিনিট';
        }
        $hours = intdiv($mins, 60);
        if ($hours < 24) {
            return $hours . ' ঘণ্টা';
        }

        return intdiv($hours, 24) . ' দিন';
    }

    private function resolveAssignee(int $biz, array $def, array $cfg, array $problem): array
    {
        $assign = $cfg['assign'] ?? $def['assign'];
        if (in_array($assign, self::ROLE_MODES, true)) {
            return ['mode' => 'role', 'role' => $assign, 'user' => null, 'label' => self::ASSIGNEES[$assign]];
        }
        if ($assign === 'staff' && ! empty($cfg['assign_user'])) {
            $u = User::where('business_id', $biz)->find($cfg['assign_user']);
            if ($u) {
                return ['mode' => 'user', 'role' => null, 'user' => $u->id, 'label' => $u->name];
            }
        }
        if ($assign === 'processor' && ! empty($problem['processor_id'])) {
            $u = User::find($problem['processor_id']);
            if ($u) {
                return ['mode' => 'user', 'role' => null, 'user' => $u->id, 'label' => $u->name . ' (যে process করেছে)'];
            }
        }
        if ($assign === 'chat' && ! empty($problem['chat_user_id'])) {
            $u = User::find($problem['chat_user_id']);
            if ($u) {
                return ['mode' => 'user', 'role' => null, 'user' => $u->id, 'label' => $u->name];
            }
        }

        return ['mode' => 'pool', 'role' => null, 'user' => null, 'label' => 'কেউ না (পুল)'];
    }

    private function notify(string $title, ?string $body, ?string $link): void
    {
        AppNotification::create(['kind' => 'task', 'title' => $title, 'body' => $body, 'link' => $link]);
    }

    // ---------- the runner ----------

    public function evaluate(int $biz): array
    {
        $stats = ['created' => 0, 'auto_closed' => 0, 'escalated' => 0, 'overdue_notified' => 0];
        $cfg = $this->ruleSettings($biz);
        $catalog = self::catalog();
        $autoClose = $this->toggle($biz, 'auto_close');
        $notifyNew = $this->toggle($biz, 'notify_new');

        foreach ($catalog as $key => $def) {
            $c = $cfg[$key];
            if (! $c['enabled']) {
                continue;
            }
            $method = 'rule' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            if (! method_exists($this, $method)) {
                continue; // catalog-only rule (source module not built yet)
            }
            $problems = $this->{$method}($biz, $def, $c);
            $liveFingerprints = [];
            foreach ($problems as $p) {
                $liveFingerprints[] = $p['fingerprint'];
                $exists = WorkTask::where('business_id', $biz)->where('rule_key', $key)
                    ->where('fingerprint', $p['fingerprint'])
                    ->when($def['dedupe'] === 'open', fn ($q) => $q->whereIn('status', WorkTask::OPEN_STATUSES))
                    ->exists();
                if ($exists) {
                    continue;
                }
                $who = $this->resolveAssignee($biz, $def, $c, $p);
                $winMins = self::windowMinutes($c);
                $due = now()->addMinutes($winMins > 0 ? $winMins : 240);
                $task = WorkTask::create([
                    'business_id' => $biz,
                    'rule_key' => $key,
                    'source' => $def['group'],
                    'title' => $p['title'],
                    'detail' => $p['detail'] ?? null,
                    'priority' => $c['priority'],
                    'status' => 'open',
                    'assignee_mode' => $who['mode'],
                    'assignee_role' => $who['role'],
                    'assignee_user_id' => $who['user'],
                    'source_type' => $p['source_type'] ?? null,
                    'source_id' => $p['source_id'] ?? null,
                    'source_label' => $p['source_label'] ?? null,
                    'link' => $p['link'] ?? null,
                    'fingerprint' => $p['fingerprint'],
                    'due_at' => $due,
                ]);
                $stats['created']++;
                if ($notifyNew) {
                    $this->notify('নতুন কাজ — ' . $task->title, 'নিয়ম ' . $def['num'] . ': ' . $def['name'] . ' · দায়িত্বে: ' . $who['label'], '/work-inbox/control');
                }
            }

            // Auto-close: the problem cleared on its own.
            if ($autoClose && $def['autoclose']) {
                $open = WorkTask::where('business_id', $biz)->where('rule_key', $key)
                    ->whereIn('status', WorkTask::OPEN_STATUSES)->get();
                foreach ($open as $task) {
                    if (! in_array($task->fingerprint, $liveFingerprints, true)) {
                        $task->update([
                            'status' => 'auto_closed',
                            'auto_close_reason' => self::RESOLVE_REASONS[$key] ?? 'সমস্যা নিজে থেকে মিটে গেছে',
                            'closed_at' => now(),
                        ]);
                        $stats['auto_closed']++;
                    }
                }
            }
        }

        // Overdue reminder + escalation.
        $notifyOverdue = $this->toggle($biz, 'notify_overdue');
        $open = WorkTask::where('business_id', $biz)->whereIn('status', WorkTask::OPEN_STATUSES)
            ->whereNotNull('due_at')->where('due_at', '<', now())->get();
        foreach ($open as $task) {
            if ($notifyOverdue && ! $task->overdue_notified_at) {
                $this->notify('Overdue কাজ — ' . $task->title, 'নির্ধারিত সময় পার হয়ে গেছে। Work Control-এ দেখো।', '/work-inbox/control');
                $task->overdue_notified_at = now();
                $task->save();
                $stats['overdue_notified']++;
            }
            if (! $task->escalated) {
                $grace = $task->rule_key && isset($cfg[$task->rule_key])
                    ? max(60, self::windowMinutes($cfg[$task->rule_key]))
                    : 1440;
                if ($task->due_at->copy()->addMinutes($grace)->isPast()) {
                    $bump = ['normal' => 'urgent', 'urgent' => 'critical', 'critical' => 'critical'][$task->priority] ?? $task->priority;
                    $task->update(['escalated' => true, 'escalated_at' => now(), 'priority' => $bump]);
                    $stats['escalated']++;
                    if ($notifyOverdue) {
                        $this->notify('আটকে থাকা কাজ — ' . $task->title, 'Overdue হয়ে গুরুত্ব বেড়ে গেছে (' . self::PRIORITIES[$bump] . ')।', '/work-inbox/control');
                    }
                }
            }
        }

        return $stats;
    }

    public function evaluateAll(): array
    {
        $totals = ['created' => 0, 'auto_closed' => 0, 'escalated' => 0, 'overdue_notified' => 0];
        foreach (\App\Models\Business::pluck('id') as $biz) {
            $s = $this->evaluate((int) $biz);
            foreach ($totals as $k => $v) {
                $totals[$k] += $s[$k];
            }
        }

        return $totals;
    }

    // ---------- task actions ----------

    public function createManual(int $biz, User $actor, array $data): WorkTask
    {
        $assignUser = null;
        $mode = 'pool';
        if (($data['assign'] ?? 'self') === 'self') {
            $assignUser = $actor->id;
            $mode = 'user';
        } elseif (! empty($data['assign_user'])) {
            $u = User::where('business_id', $biz)->find((int) $data['assign_user']);
            if ($u) {
                $assignUser = $u->id;
                $mode = 'user';
            }
        }

        return WorkTask::create([
            'business_id' => $biz,
            'rule_key' => null,
            'source' => 'manual',
            'title' => $data['title'],
            'detail' => $data['detail'] ?? null,
            'priority' => in_array($data['priority'] ?? '', array_keys(self::PRIORITIES), true) ? $data['priority'] : 'normal',
            'status' => 'open',
            'assignee_mode' => $mode,
            'assignee_role' => null,
            'assignee_user_id' => $assignUser,
            'source_type' => 'manual',
            'source_label' => 'Manual',
            'due_at' => ! empty($data['due_at']) ? Carbon::parse($data['due_at']) : null,
        ]);
    }

    public function noteRequired(WorkTask $task): bool
    {
        if (! $task->rule_key) {
            return false;
        }

        return (bool) (self::catalog()[$task->rule_key]['note_required'] ?? false);
    }

    public function markDone(WorkTask $task, User $actor, ?string $note): WorkTask
    {
        $note = trim((string) $note);
        if ($this->noteRequired($task) && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'এই কাজটায় note লেখা বাধ্যতামূলক — এক লাইন লিখে দাও।',
            ]);
        }
        $task->update([
            'status' => 'done',
            'done_note' => $note !== '' ? $note : null,
            'done_by' => $actor->id,
            'done_at' => now(),
            'closed_at' => now(),
        ]);

        return $task;
    }

    public function start(WorkTask $task, User $actor): WorkTask
    {
        if ($task->status === 'open') {
            $task->update(['status' => 'in_progress']);
            if (! $task->assignee_user_id) {
                $task->update(['assignee_user_id' => $actor->id, 'assignee_mode' => 'user', 'assignee_role' => null]);
            }
        }

        return $task;
    }

    public function reassign(WorkTask $task, ?int $userId): WorkTask
    {
        if ($userId) {
            $task->update(['assignee_user_id' => $userId, 'assignee_mode' => 'user', 'assignee_role' => null]);
        } else {
            $task->update(['assignee_user_id' => null, 'assignee_mode' => 'pool', 'assignee_role' => null]);
        }

        return $task;
    }

    /** @return array{done:int, skipped:int} */
    public function bulkDone(int $biz, User $actor, array $ids): array
    {
        $done = 0;
        $skipped = 0;
        $tasks = WorkTask::where('business_id', $biz)->whereIn('id', $ids)
            ->whereIn('status', WorkTask::OPEN_STATUSES)->get();
        foreach ($tasks as $task) {
            if ($this->noteRequired($task)) {
                $skipped++;
                continue;
            }
            $this->markDone($task, $actor, null);
            $done++;
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    // ---------- rule evaluators ----------
    // Each returns problem rows: fingerprint, title, source_type,
    // source_id, source_label, link (+ processor_id / chat_user_id hints).

    private function orderLabel(Order $o): string
    {
        return 'Order ' . $o->invoice_no . ' · ৳' . number_format((float) $o->grand_total);
    }

    private function rulePendingStuck(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->where('status', 'Pending')->where('created_at', '<=', $cut)
            ->orderBy('created_at')->limit(200)->get()->each(function (Order $o) use (&$out) {
                $out[] = [
                    'fingerprint' => 'order:' . $o->id,
                    'title' => $o->invoice_no . ' order-টা ' . self::bnAge($o->created_at) . ' ধরে Pending — confirm করে Processing-এ নাও',
                    'source_type' => 'order', 'source_id' => $o->id,
                    'source_label' => $this->orderLabel($o), 'link' => '/orders/' . $o->id,
                    'processor_id' => $o->processed_by,
                ];
            });

        return $out;
    }

    private function ruleRiskyFraud(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->where('fraud_verdict', 'risky')
            ->whereIn('status', ['Pending', 'Processing'])->where('created_at', '<=', $cut)
            ->orderBy('created_at')->limit(200)->get()->each(function (Order $o) use (&$out) {
                $out[] = [
                    'fingerprint' => 'order:' . $o->id,
                    'title' => $o->invoice_no . ' order-টা fraud check-এ Risky এসেছে — advance নেওয়ার সিদ্ধান্ত নাও',
                    'source_type' => 'order', 'source_id' => $o->id,
                    'source_label' => $this->orderLabel($o), 'link' => '/orders/' . $o->id,
                    'processor_id' => $o->processed_by,
                ];
            });

        return $out;
    }

    private function ruleBlocklistReview(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        IpPhoneBlock::where('business_id', $biz)->where('created_at', '<=', $cut)
            ->orderBy('created_at')->limit(200)->get()->each(function (IpPhoneBlock $b) use (&$out) {
                $what = $b->value ?: 'Block';
                $out[] = [
                    'fingerprint' => 'block:' . $b->id,
                    'title' => 'Block List-এ ' . $what . ' আটকে আছে — review করে সিদ্ধান্ত নাও',
                    'source_type' => 'block', 'source_id' => $b->id,
                    'source_label' => 'Block · ' . $what, 'link' => '/orders/block-list',
                ];
            });

        return $out;
    }

    private function rulePreorderNotify(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->where('is_preorder', true)->where('preorder_notified', false)
            ->whereNotIn('status', ['Cancelled', 'Delivered', 'Returned'])->where('created_at', '<=', $cut)
            ->with('items')->orderBy('created_at')->limit(200)->get()
            ->each(function (Order $o) use (&$out, $biz) {
                $pids = $o->items->pluck('product_id')->filter()->all();
                $inStock = $pids !== [] && Product::where('business_id', $biz)->whereIn('id', $pids)
                    ->whereRaw('stock_qty - reserved_qty > 0')->exists();
                if (! $inStock) {
                    return;
                }
                $out[] = [
                    'fingerprint' => 'order:' . $o->id,
                    'title' => $o->invoice_no . ' preorder-এর product stock-এ এসেছে — customer-কে জানাও',
                    'source_type' => 'order', 'source_id' => $o->id,
                    'source_label' => $this->orderLabel($o), 'link' => '/orders/' . $o->id,
                    'processor_id' => $o->processed_by,
                ];
            });

        return $out;
    }

    private function ruleRepeatCanceller(int $biz, array $def, array $c): array
    {
        $out = [];
        $rows = Order::where('business_id', $biz)->where('status', 'Cancelled')
            ->selectRaw('customer_phone, COUNT(*) as n, MAX(id) as last_id')
            ->groupBy('customer_phone')->having('n', '>=', 3)->get();
        foreach ($rows as $row) {
            $last = Order::find($row->last_id);
            if (! $last) {
                continue;
            }
            $out[] = [
                'fingerprint' => 'phone:' . $row->customer_phone . ':' . $row->n,
                'title' => $last->customer_name . ' (' . $row->customer_phone . ') বারবার order cancel করছে — মোট ' . $row->n . ' বার, pattern-টা দেখো',
                'source_type' => 'phone', 'source_id' => $last->id,
                'source_label' => 'Customer · ' . $last->customer_name, 'link' => '/orders/' . $last->id,
            ];
        }

        return $out;
    }

    private function ruleReadyToShip(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->where('status', 'Processing')
            ->where(fn ($q) => $q->where('approved_at', '<=', $cut)->orWhere(fn ($q2) => $q2->whereNull('approved_at')->where('updated_at', '<=', $cut)))
            ->orderBy('approved_at')->limit(200)->get()->each(function (Order $o) use (&$out) {
                $since = $o->approved_at ?? $o->updated_at;
                $out[] = [
                    'fingerprint' => 'order:' . $o->id,
                    'title' => $o->invoice_no . ' order-টা ' . self::bnAge($since) . ' ধরে Ready to Ship-এ পড়ে আছে — courier-এ পাঠাও',
                    'source_type' => 'order', 'source_id' => $o->id,
                    'source_label' => $this->orderLabel($o), 'link' => '/delivery/ready',
                    'processor_id' => $o->processed_by,
                ];
            });

        return $out;
    }

    /** Work-event driven delivery rules (8, 10–14) share one scan. */
    private function parcelEventProblems(int $biz, string $want): array
    {
        $out = [];
        $events = WorkEvent::where('business_id', $biz)->where('kind', 'parcel_issue')
            ->orderByDesc('id')->limit(300)->get();
        foreach ($events as $e) {
            $text = mb_strtolower($e->title . ' ' . ($e->body ?? ''));
            $kind = 'hold';
            if (str_contains($text, 'lost') || str_contains($text, 'damag') || str_contains($text, 'হারিয়ে')) {
                $kind = 'lost';
            } elseif (str_contains($text, 'resent') || str_contains($text, 're-sent') || str_contains($text, 'আবার পাঠানো')) {
                $kind = 'resent';
            } elseif (str_contains($text, 'return')) {
                $kind = 'return';
            } elseif (str_contains($text, 'cancel')) {
                $kind = 'cancel';
            }
            if ($kind !== $want) {
                continue;
            }
            $orderId = null;
            if ($e->link && preg_match('#/orders/(\d+)#', $e->link, $m)) {
                $orderId = (int) $m[1];
            }
            $order = $orderId ? Order::where('business_id', $biz)->find($orderId) : null;
            if ($order && in_array($order->status, ['Delivered', 'Returned', 'Cancelled'], true)) {
                continue; // already resolved downstream
            }
            $inv = $order?->invoice_no ?? 'Parcel';
            $title = match ($want) {
                'lost' => $inv . '-এর parcel lost / damaged — customer-কে জানিয়ে নতুন করে পাঠানো বা refund-এর সিদ্ধান্ত নাও',
                'resent' => $inv . '-এর parcel আবার পাঠানো (resent) হয়েছে — চোখ রাখো',
                'return' => $inv . '-এর return request এসেছে — দেখে সিদ্ধান্ত নাও',
                'cancel' => $inv . '-এর cancel request এসেছে — দেখে সিদ্ধান্ত নাও',
                default => $inv . '-এর parcel courier-এ আটকে আছে — নড়ছে না, খোঁজ নাও',
            };
            $out[] = [
                'fingerprint' => 'we:' . $e->id,
                'title' => $title,
                'detail' => $e->body,
                'source_type' => 'work_event', 'source_id' => $e->id,
                'source_label' => $order ? $this->orderLabel($order) : 'Parcel',
                'link' => $order ? '/orders/' . $order->id : '/delivery/parcels',
                'processor_id' => $order?->processed_by,
            ];
        }

        return $out;
    }

    private function ruleBookingFailing(int $biz, array $def, array $c): array
    {
        $out = [];
        $events = WorkEvent::where('business_id', $biz)->where('kind', 'booking_failure')
            ->orderByDesc('id')->limit(200)->get();
        foreach ($events as $e) {
            $orderId = null;
            if ($e->link && preg_match('#/orders/(\d+)#', $e->link, $m)) {
                $orderId = (int) $m[1];
            }
            $order = $orderId ? Order::where('business_id', $biz)->find($orderId) : null;
            if ($order) {
                $booked = CourierBookingLog::where('order_id', $order->id)->where('booking_status', 'booked')
                    ->where('created_at', '>=', $e->created_at)->exists();
                if ($booked || in_array($order->status, ['Shipped', 'Delivered', 'Returned', 'Cancelled'], true)) {
                    continue;
                }
            }
            $out[] = [
                'fingerprint' => 'we:' . $e->id,
                'title' => ($order?->invoice_no ?? 'Order') . ' order-এর courier booking বারবার fail করছে — কারণটা দেখো',
                'detail' => $e->body,
                'source_type' => 'work_event', 'source_id' => $e->id,
                'source_label' => $order ? $this->orderLabel($order) : 'Order',
                'link' => $order ? '/orders/' . $order->id : '/delivery/ready',
                'processor_id' => $order?->processed_by,
            ];
        }

        return $out;
    }

    private function ruleCourierHold(int $biz, array $def, array $c): array
    {
        return $this->parcelEventProblems($biz, 'hold');
    }

    private function rulePathaoReturn(int $biz, array $def, array $c): array
    {
        return $this->parcelEventProblems($biz, 'return');
    }

    private function ruleSteadfastCancel(int $biz, array $def, array $c): array
    {
        return $this->parcelEventProblems($biz, 'cancel');
    }

    private function ruleParcelLostDamaged(int $biz, array $def, array $c): array
    {
        return $this->parcelEventProblems($biz, 'lost');
    }

    private function ruleParcelResent(int $biz, array $def, array $c): array
    {
        return $this->parcelEventProblems($biz, 'resent');
    }

    private function ruleShippedStuck(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->where('status', 'Shipped')->with('history')
            ->orderBy('updated_at')->limit(200)->get()->each(function (Order $o) use (&$out, $cut) {
                $shippedAt = $o->history->where('to_status', 'Shipped')->sortByDesc('created_at')->first()?->created_at ?? $o->updated_at;
                if ($shippedAt && $shippedAt->lte($cut)) {
                    $out[] = [
                        'fingerprint' => 'order:' . $o->id,
                        'title' => $o->invoice_no . ' order-টা ' . self::bnAge($shippedAt) . ' ধরে Shipped — এখনো Delivered হয়নি, courier-এর সাথে যোগাযোগ করো',
                        'source_type' => 'order', 'source_id' => $o->id,
                        'source_label' => $this->orderLabel($o), 'link' => '/orders/' . $o->id,
                        'processor_id' => $o->processed_by,
                    ];
                }
            });

        return $out;
    }

    private function ruleReturnUndecided(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Order::where('business_id', $biz)->whereIn('status', ['Returned', 'Pending Return'])
            ->where('updated_at', '<=', $cut)->orderBy('updated_at')->limit(200)->get()
            ->each(function (Order $o) use (&$out) {
                $out[] = [
                    'fingerprint' => 'order:' . $o->id,
                    'title' => $o->invoice_no . '-এর return পৌঁছেছে — সিদ্ধান্ত নাও (আবার বিক্রি / stock-এ তোলা / damage)',
                    'source_type' => 'order', 'source_id' => $o->id,
                    'source_label' => $this->orderLabel($o), 'link' => '/orders/' . $o->id,
                    'processor_id' => $o->processed_by,
                ];
            });

        return $out;
    }

    private function ruleCourierMoney(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        WorkEvent::where('business_id', $biz)->where('kind', 'settlement_difference')
            ->where('created_at', '<=', $cut)->orderByDesc('id')->limit(200)->get()
            ->each(function (WorkEvent $e) use (&$out) {
                $out[] = [
                    'fingerprint' => 'we:' . $e->id,
                    'title' => 'Courier-এর টাকা মেলেনি — ' . $e->title,
                    'detail' => $e->body,
                    'source_type' => 'work_event', 'source_id' => $e->id,
                    'source_label' => 'Courier Ledger', 'link' => '/delivery/ledger',
                ];
            });

        return $out;
    }

    private function channelLabel(string $channel): string
    {
        return ['facebook' => 'Messenger', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram'][$channel] ?? $channel;
    }

    private function ruleAiHandover(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Conversation::where('business_id', $biz)->where('needs_human', true)->where('status', 'open')
            ->where('updated_at', '<=', $cut)->orderBy('updated_at')->limit(200)->get()
            ->each(function (Conversation $conv) use (&$out, $biz) {
                $lastStaff = ChatMessage::where('conversation_id', $conv->id)->where('sender', 'staff')
                    ->whereNotNull('user_id')->orderByDesc('id')->first();
                $out[] = [
                    'fingerprint' => 'conv:' . $conv->id,
                    'title' => 'AI customer-কে মানুষের হাতে দিয়েছে — ' . $conv->customer_name . ' ' . self::bnAge($conv->updated_at) . ' ধরে অপেক্ষা করছে, chat-টা ধরো',
                    'source_type' => 'conversation', 'source_id' => $conv->id,
                    'source_label' => 'Chat · ' . $conv->customer_name . ' (' . $this->channelLabel($conv->channel) . ')',
                    'link' => '/messages/inbox',
                    'chat_user_id' => $lastStaff?->user_id,
                ];
            });

        return $out;
    }

    private function ruleWindowClosing(int $biz, array $def, array $c): array
    {
        $out = [];
        $warnMins = self::windowMinutes($c);
        Conversation::where('business_id', $biz)->where('status', 'open')
            ->orderByDesc('last_message_at')->limit(300)->get()
            ->each(function (Conversation $conv) use (&$out, $warnMins) {
                $lastIn = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'in')
                    ->orderByDesc('id')->first();
                if (! $lastIn) {
                    return;
                }
                $replied = ChatMessage::where('conversation_id', $conv->id)->where('direction', 'out')
                    ->where('id', '>', $lastIn->id)->exists();
                if ($replied) {
                    return;
                }
                $windowEnd = $lastIn->created_at->copy()->addHours(24);
                if (now()->gte($windowEnd) || now()->lt($windowEnd->copy()->subMinutes($warnMins))) {
                    return;
                }
                $out[] = [
                    'fingerprint' => 'conv:' . $conv->id . ':' . $lastIn->id,
                    'title' => $conv->customer_name . '-এর chat-এর 24 ঘণ্টার window শেষ হওয়ার পথে — উত্তর বাকি আছে',
                    'source_type' => 'conversation', 'source_id' => $conv->id,
                    'source_label' => 'Chat · ' . $conv->customer_name . ' (' . $this->channelLabel($conv->channel) . ')',
                    'link' => '/messages/inbox',
                ];
            });

        return $out;
    }

    private function ruleAiDraftStuck(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        ChatMessage::where('business_id', $biz)->where('status', 'draft')->where('created_at', '<=', $cut)
            ->with('conversation')->orderBy('created_at')->limit(200)->get()
            ->each(function (ChatMessage $m) use (&$out) {
                $name = $m->conversation?->customer_name ?? 'Customer';
                $out[] = [
                    'fingerprint' => 'msg:' . $m->id,
                    'title' => 'AI draft ' . self::bnAge($m->created_at) . ' ধরে পড়ে আছে — পাঠিয়ে দাও বা বদলে দাও (' . $name . ')',
                    'source_type' => 'chat_message', 'source_id' => $m->id,
                    'source_label' => 'Chat · ' . $name, 'link' => '/messages/inbox',
                ];
            });

        return $out;
    }

    private function ruleCommentReply(int $biz, array $def, array $c): array
    {
        $out = [];
        $warnMins = self::windowMinutes($c);
        Comment::where('business_id', $biz)->where('status', 'awaiting')->whereNull('private_reply_at')
            ->orderBy('created_at')->limit(200)->get()
            ->each(function (Comment $cm) use (&$out, $warnMins) {
                $deadline = $cm->created_at->copy()->addDays(7);
                if (now()->gte($deadline) || now()->lt($deadline->copy()->subMinutes($warnMins))) {
                    return;
                }
                $out[] = [
                    'fingerprint' => 'comment:' . $cm->id,
                    'title' => $cm->author_name . '-এর comment-এর private reply-এর সময় শেষের পথে — জবাব দাও',
                    'source_type' => 'comment', 'source_id' => $cm->id,
                    'source_label' => 'Comment · ' . $cm->author_name, 'link' => '/messages/comments',
                ];
            });

        return $out;
    }

    private function ruleLowStock(int $biz, array $def, array $c): array
    {
        $cut = now()->subMinutes(self::windowMinutes($c));
        $out = [];
        Product::where('business_id', $biz)->where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'alert_qty')->where('updated_at', '<=', $cut)
            ->orderBy('stock_qty')->limit(200)->get()->each(function (Product $p) use (&$out) {
                $out[] = [
                    'fingerprint' => 'product:' . $p->id,
                    'title' => $p->name . '-এর stock ' . ($p->stock_qty <= 0 ? 'শেষ' : 'কমে গেছে (' . $p->stock_qty . 'টা আছে)') . ' — কেনার সিদ্ধান্ত নাও, না কিনলে product-টা বন্ধ রাখো',
                    'source_type' => 'product', 'source_id' => $p->id,
                    'source_label' => 'Product · ' . $p->name, 'link' => '/inventory/products',
                ];
            });

        return $out;
    }

    private function ruleLedgerMismatch(int $biz, array $def, array $c): array
    {
        $out = [];
        $productIds = StockLedger::where('business_id', $biz)->whereNull('branch_id')
            ->whereNotNull('new_stock')->distinct()->pluck('product_id');
        foreach ($productIds as $pid) {
            $p = Product::where('business_id', $biz)->find($pid);
            if (! $p) {
                continue;
            }
            $latest = StockLedger::where('business_id', $biz)->where('product_id', $pid)
                ->whereNull('branch_id')->whereNotNull('new_stock')->orderByDesc('id')->first();
            if ($latest && (int) $latest->new_stock !== (int) $p->stock_qty) {
                $out[] = [
                    'fingerprint' => 'product:' . $p->id,
                    'title' => 'Stock ledger-এ গরমিল — ' . $p->name . ': ledger-এ ' . $latest->new_stock . ', product-এ ' . $p->stock_qty,
                    'source_type' => 'product', 'source_id' => $p->id,
                    'source_label' => 'Product · ' . $p->name, 'link' => '/inventory/ledger',
                ];
            }
        }

        return $out;
    }

    private function ruleWarrantyOverdue(int $biz, array $def, array $c): array
    {
        $cutDate = now()->subMinutes(self::windowMinutes($c))->toDateString();
        $out = [];
        WarrantyClaim::where('business_id', $biz)->whereNotNull('due_date')->where('due_date', '<', $cutDate)
            ->whereNotIn('status', ['closed', 'rejected', 'cancelled'])
            ->orderBy('due_date')->limit(200)->get()->each(function (WarrantyClaim $w) use (&$out) {
                $out[] = [
                    'fingerprint' => 'claim:' . $w->id,
                    'title' => 'Warranty claim ' . $w->claim_no . '-এর নির্ধারিত দিন পার হয়ে গেছে — supplier-এর সাথে কথা বলে customer-কে জানাও',
                    'source_type' => 'claim', 'source_id' => $w->id,
                    'source_label' => 'Claim ' . $w->claim_no, 'link' => '/inventory/warranty',
                ];
            });

        return $out;
    }

    private function ruleNegativeStock(int $biz, array $def, array $c): array
    {
        $out = [];
        Product::where('business_id', $biz)->where('stock_qty', '<', 0)
            ->orderBy('stock_qty')->limit(200)->get()->each(function (Product $p) use (&$out) {
                $out[] = [
                    'fingerprint' => 'product:' . $p->id,
                    'title' => $p->name . '-এর stock negative হয়ে গেছে (' . $p->stock_qty . ') — এখনই ঠিক করো',
                    'source_type' => 'product', 'source_id' => $p->id,
                    'source_label' => 'Product · ' . $p->name, 'link' => '/inventory/products',
                ];
            });

        return $out;
    }

    private function ruleAutocallFail(int $biz, array $def, array $c): array
    {
        $n = Order::where('business_id', $biz)->where('auto_call_status', 'failed')
            ->where('updated_at', '>=', now()->subDay())->count();
        if ($n < 3) {
            return [];
        }

        return [[
            'fingerprint' => 'autocall:' . now()->toDateString(),
            'title' => 'Auto Call বারবার fail করছে — গত 24 ঘণ্টায় ' . $n . 'টা order-এর call fail হয়েছে',
            'source_type' => 'system', 'source_id' => null,
            'source_label' => 'Auto Call', 'link' => '/orders/autocall',
        ]];
    }

    private function ruleCashMismatch(int $biz, array $def, array $c): array
    {
        $out = [];
        DailyClosing::where('business_id', $biz)->where('difference', '!=', 0)
            ->whereDate('date', '<', now()->toDateString())->with('account')
            ->orderByDesc('date')->limit(100)->get()->each(function (DailyClosing $d) use (&$out) {
                $out[] = [
                    'fingerprint' => 'closing:' . $d->id,
                    'title' => $d->date->format('d M') . '-এর cash হিসাব ৳' . number_format(abs((float) $d->difference)) . ' মেলেনি — হিসাব মেলাও',
                    'source_type' => 'closing', 'source_id' => $d->id,
                    'source_label' => 'Finance · ' . ($d->account?->name ?? 'Cash'), 'link' => '/finance',
                ];
            });

        return $out;
    }

    private function ruleSmsBalanceLow(int $biz, array $def, array $c): array
    {
        $balance = $this->sms->balance($biz);
        if ($balance >= 100) {
            return [];
        }

        return [[
            'fingerprint' => 'sms_balance',
            'title' => 'SMS balance কমে গেছে — মাত্র ৳' . number_format($balance) . ' আছে, recharge করো',
            'source_type' => 'system', 'source_id' => null,
            'source_label' => 'SMS Balance', 'link' => '/marketing/sms',
        ]];
    }
}

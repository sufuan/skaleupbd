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

    // Labels are English source strings (lang keys): the Settings and
    // Control pages translate them with t() at render, and strings that
    // get stored (notification bodies) keep the English literal - the
    // same mechanism order notifications use.
    public const PRIORITIES = ['normal' => 'Normal', 'urgent' => 'Urgent', 'critical' => 'Critical'];

    public const GROUPS = [
        'orders' => 'Orders',
        'delivery' => 'Delivery / Courier',
        'messages' => 'Messages',
        'inventory' => 'Inventory',
        'purchase' => 'Purchase',
        'followup' => 'Call Center & Follow-up',
        'finance' => 'Finance',
        'system' => 'System',
    ];

    public const ASSIGNEES = [
        'pool' => 'Pool (Unassigned)',
        'delivery' => 'Delivery role',
        'accounts' => 'Accounts role',
        'purchase' => 'Purchase role',
        'processor' => 'The one who processed it',
        'claim' => 'Claim handler',
        'chat' => 'The one who was in the chat',
        'admin' => 'Owner / Admin',
        'staff' => 'Specific staff',
    ];

    private const ROLE_MODES = ['delivery', 'accounts', 'purchase', 'claim', 'admin'];

    /** Why a task closed itself, per rule - kept in the task history. */
    private const RESOLVE_REASONS = [
        'pending_stuck' => 'Task closed itself because the order moved out of Pending',
        'risky_fraud' => 'Task closed itself because the fraud decision was made',
        'blocklist_review' => 'Task closed itself because the block was removed',
        'preorder_notify' => 'Task closed itself because the customer was notified',
        'ready_to_ship' => 'Task closed itself because the order was sent to the courier',
        'booking_failing' => 'Task closed itself because the booking succeeded',
        'shipped_stuck' => 'Task closed itself because the order was Delivered',
        'return_undecided' => 'Task closed itself because the return decision was made',
        'ai_handover' => 'Task closed itself because the chat was picked up',
        'window_closing' => 'Task closed itself because the reply was sent',
        'ai_draft_stuck' => 'Task closed itself because the draft was sent or removed',
        'comment_reply' => 'Task closed itself because the comment was answered',
        'low_stock' => 'Task closed itself because the stock came back',
        'supplier_due' => 'Task closed itself because the supplier due was paid',
        'ledger_mismatch' => 'Task closed itself because the ledger matches again',
        'warranty_overdue' => 'Task closed itself because the claim was closed',
        'negative_stock' => 'Task closed itself because the stock was fixed',
        'sms_balance_low' => 'Task closed itself because the balance was recharged',
        'token_balance_low' => 'Task closed itself because the points were recharged',
        'channel_broken' => 'Task closed itself because the connection is healthy again',
        'courier_unverified' => 'Task closed itself because the webhook secret was saved',
    ];

    /**
     * The 38-rule catalog. 'value'/'unit' = the rule's time window
     * (trigger delay for state rules, fix-it window for event rules).
     * 'dedupe': open = one open task per fingerprint, may recur after
     * resolution; ever = one task per fingerprint for good.
     * Rules whose source module is not built yet (follow-up
     * callbacks, channel data expiry) have no evaluator and never
     * fire — they still show in Settings exactly as the design lists
     * them. Channel health (rule 21) got its evaluator in ধাপ ৫, when
     * per-seller channel_connections landed.
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
        $add(1, 'pending_stuck', 'orders', 'Pending order not confirmed yet', 'When an order stays in Pending past this time', true, 2, 'hours', 'urgent', 'pool');
        $add(2, 'risky_fraud', 'orders', 'Risky in fraud check, no decision yet', 'When this time passes after a Risky result with no advance or decision', true, 1, 'hours', 'critical', 'processor', ['note_required' => true]);
        $add(3, 'blocklist_review', 'orders', 'Block List order not reviewed', 'When this time passes after being stopped on the Block List', true, 4, 'hours', 'normal', 'pool');
        $add(4, 'preorder_notify', 'orders', 'Preorder arrived, customer not told', 'When this time passes after the preorder product comes into stock', true, 4, 'hours', 'normal', 'pool');
        $add(5, 'advance_unmatched', 'orders', 'Advance money arrived, not matched to an order', 'When a payment arrives and matches no order', true, 1, 'days', 'urgent', 'accounts');
        $add(6, 'repeat_canceller', 'orders', 'Same customer keeps cancelling', 'As soon as the same customer cancels 3 times', false, 0, 'immediate', 'normal', 'pool', ['dedupe' => 'ever', 'autoclose' => false]);

        // ---- Delivery / Courier (M3) - Done note mandatory (courier/return rules) ----
        $courier = ['note_required' => true];
        $add(7, 'ready_to_ship', 'delivery', 'Sitting in Ready to Ship, not sent', 'When this time passes after entering Ready to Ship', true, 1, 'days', 'urgent', 'delivery', $courier);
        $add(8, 'booking_failing', 'delivery', 'Courier booking keeps failing', 'As soon as the booking for the same order fails 3 times', true, 0, 'immediate', 'critical', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(9, 'shipped_stuck', 'delivery', 'Shipped, not Delivered for a long time', 'When these days pass after shipping (checked again every 12 hours)', true, 4, 'days', 'urgent', 'delivery', $courier);
        $add(10, 'courier_hold', 'delivery', 'Courier hold - parcel not moving', 'When the parcel status does not change for these days', true, 2, 'days', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(11, 'pathao_return', 'delivery', 'Return requested at Pathao', 'As soon as a return request appears on the Pathao panel (the system checks every ~5 minutes)', true, 30, 'minutes', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(12, 'steadfast_cancel', 'delivery', 'Cancel requested at Steadfast', 'As soon as a cancel request appears at Steadfast', true, 30, 'minutes', 'urgent', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(13, 'parcel_lost_damaged', 'delivery', 'Parcel lost / damaged', 'Immediately when a lost/damaged event arrives on the courier webhook', true, 0, 'immediate', 'critical', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(14, 'parcel_resent', 'delivery', 'Parcel was sent again (resent)', 'As soon as it is resent - to keep an eye on it', false, 0, 'hours', 'normal', 'delivery', $courier + ['dedupe' => 'ever']);
        $add(15, 'return_undecided', 'delivery', 'Return arrived, no decision yet', 'When no decision is made within this time after the return arrives', true, 1, 'days', 'urgent', 'delivery', $courier);
        $add(16, 'courier_money', 'delivery', 'Courier money did not match', 'When unmatched money sits in the ledger for these days', true, 3, 'days', 'urgent', 'accounts', $courier + ['dedupe' => 'ever', 'autoclose' => false]);
        $add(38, 'courier_unverified', 'delivery', 'Courier connection has no webhook secret', 'Immediately when a connected courier has no webhook secret/token', true, 0, 'immediate', 'urgent', 'admin');

        // ---- Messages (M6) ----
        $add(17, 'ai_handover', 'messages', 'AI handed over, nobody picked it up', 'When nobody picks up the chat within this time after the handover', true, 15, 'minutes', 'critical', 'chat');
        $add(18, 'window_closing', 'messages', '24-hour window about to end, reply still due', 'When a reply is still due this long before the window ends', true, 2, 'hours', 'urgent', 'pool');
        $add(19, 'ai_draft_stuck', 'messages', 'AI draft sitting unsent', 'When this time passes after the draft was created', true, 1, 'hours', 'normal', 'pool');
        $add(20, 'comment_reply', 'messages', 'Comment private reply time about to run out', 'This long before the 7-day limit ends', true, 1, 'days', 'normal', 'pool');
        $add(21, 'channel_broken', 'messages', 'Channel is broken', 'Immediately when the token expires or the connection breaks', true, 0, 'immediate', 'critical', 'admin');

        // ---- Inventory (M4) ----
        $add(22, 'low_stock', 'inventory', 'Out of stock / low stock - no buying decision yet', 'When no decision is made in these days after the stock runs out', true, 2, 'days', 'normal', 'purchase');
        $add(23, 'ledger_mismatch', 'inventory', 'Mismatch in the stock ledger', 'Immediately when the integrity scan finds a mismatch', true, 0, 'immediate', 'urgent', 'admin');
        $add(24, 'warranty_overdue', 'inventory', 'Warranty claim overdue', 'As soon as the claim due date passes', true, 0, 'days', 'urgent', 'claim');
        $add(25, 'negative_stock', 'inventory', 'Stock has gone negative', 'As soon as any product stock goes below 0', true, 0, 'immediate', 'urgent', 'pool');

        // ---- Purchase (M5 - module not built yet: catalog only) ----
        $add(26, 'purchase_late', 'purchase', 'Purchase sent, goods not arrived', 'When the goods do not arrive within these days after the purchase', true, 3, 'days', 'normal', 'purchase');
        $add(27, 'supplier_due', 'purchase', 'Supplier due is overdue', 'As soon as the due date of the payable passes', true, 0, 'days', 'urgent', 'accounts');
        $add(28, 'receive_damaged', 'purchase', 'Damaged/missing in receive - decision pending', 'When no decision is made within this time after damaged/missing is reported', true, 1, 'days', 'normal', 'purchase');

        // ---- Call Center & Follow-up (M8 / M9) ----
        $add(29, 'callback_missed', 'followup', 'Callback promised, call not made', 'When this time passes after the promised time', true, 30, 'minutes', 'urgent', 'chat');
        $add(30, 'followup_overdue', 'followup', 'Scheduled follow-up call not made', 'After the follow-up time has passed', true, 1, 'hours', 'urgent', 'pool');
        $add(31, 'autocall_fail', 'followup', 'Auto Call failing / balance running out', 'When calls keep failing or the balance falls below the threshold', true, 0, 'immediate', 'urgent', 'admin', ['dedupe' => 'ever', 'autoclose' => false]);

        // ---- Finance (M14) ----
        $add(32, 'cash_mismatch', 'finance', "The day's cash does not match", "Next morning, when the previous day's accounts do not match", true, 0, 'hours', 'urgent', 'accounts', ['dedupe' => 'ever', 'autoclose' => false]);
        $add(33, 'customer_due', 'finance', 'Customer due is very old', 'When the due is older than these days', false, 7, 'days', 'normal', 'pool');

        // ---- System ----
        $add(34, 'device_approval', 'system', 'New device waiting for approval', 'Immediately when a new device appears', true, 0, 'immediate', 'normal', 'admin');
        $add(35, 'sms_balance_low', 'system', 'SMS / Auto Call balance is low', 'As soon as the balance falls below the threshold', true, 0, 'immediate', 'urgent', 'admin');
        $add(36, 'channel_data_expiry', 'system', 'Channel data access about to expire', 'These days before the access expires', true, 7, 'days', 'urgent', 'admin');
        $add(37, 'token_balance_low', 'system', 'Points / token balance is low', 'As soon as the points balance falls below the threshold', true, 0, 'immediate', 'urgent', 'admin');

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

    /** English twin of bnAge() - used in stored strings (task titles). */
    public static function ageEn(Carbon $from, ?Carbon $to = null): string
    {
        $to ??= now();
        $mins = max(0, (int) $from->diffInMinutes($to));
        if ($mins < 60) {
            return $mins . ' minutes';
        }
        $hours = intdiv($mins, 60);
        if ($hours < 24) {
            return $hours . ' hours';
        }

        return intdiv($hours, 24) . ' days';
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
                return ['mode' => 'user', 'role' => null, 'user' => $u->id, 'label' => $u->name . ' (the one who processed it)'];
            }
        }
        if ($assign === 'chat' && ! empty($problem['chat_user_id'])) {
            $u = User::find($problem['chat_user_id']);
            if ($u) {
                return ['mode' => 'user', 'role' => null, 'user' => $u->id, 'label' => $u->name];
            }
        }

        return ['mode' => 'pool', 'role' => null, 'user' => null, 'label' => 'Nobody (Pool)'];
    }

    private function notify(string $title, ?string $body, ?string $link, int $biz): void
    {
        AppNotification::create(['business_id' => $biz, 'kind' => 'task', 'title' => $title, 'body' => $body, 'link' => $link]);
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
                    $this->notify('New task - ' . $task->title, 'Rule ' . $def['num'] . ': ' . $def['name'] . ' · Assigned to: ' . $who['label'], '/work-inbox/control', $biz);
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
                            'auto_close_reason' => self::RESOLVE_REASONS[$key] ?? 'The problem cleared on its own',
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
                $this->notify('Overdue task - ' . $task->title, 'The due time has passed. See it in Work Control.', '/work-inbox/control', $biz);
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
                        $this->notify('Stuck task - ' . $task->title, 'Overdue - priority raised to ' . self::PRIORITIES[$bump] . '.', '/work-inbox/control', $biz);
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
                'note' => __('A note is required for this task - write one line.'),
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
                    'title' => 'Order ' . $o->invoice_no . ' has been Pending for ' . self::ageEn($o->created_at) . ' - confirm it and move it to Processing',
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
                    'title' => 'Order ' . $o->invoice_no . ' came out Risky in the fraud check - decide about taking an advance',
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
                    'title' => $what . ' is stuck on the Block List - review it and decide',
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
                    'title' => 'The product for preorder ' . $o->invoice_no . ' is in stock - tell the customer',
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
                'title' => $last->customer_name . ' (' . $row->customer_phone . ') keeps cancelling orders - ' . $row->n . ' times in total, look at the pattern',
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
                    'title' => 'Order ' . $o->invoice_no . ' has been sitting in Ready to Ship for ' . self::ageEn($since) . ' - send it to the courier',
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
                'lost' => 'Parcel for ' . $inv . ' is lost / damaged - tell the customer and decide: send again or refund',
                'resent' => 'Parcel for ' . $inv . ' was sent again (resent) - keep an eye on it',
                'return' => 'A return request came for ' . $inv . ' - look and decide',
                'cancel' => 'A cancel request came for ' . $inv . ' - look and decide',
                default => 'Parcel for ' . $inv . ' is stuck at the courier - not moving, follow up',
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
                'title' => ($order?->invoice_no ?? 'Order') . ' order: courier booking keeps failing - check the reason',
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
                        'title' => 'Order ' . $o->invoice_no . ' has been Shipped for ' . self::ageEn($shippedAt) . ' - still not Delivered, contact the courier',
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
                    'title' => 'Return for ' . $o->invoice_no . ' has arrived - decide (sell again / put back in stock / damaged)',
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
                    'title' => 'Courier money did not match - ' . $e->title,
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
                    'title' => 'AI handed the customer over to a human - ' . $conv->customer_name . ' has been waiting for ' . self::ageEn($conv->updated_at) . ', pick up the chat',
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
                    'title' => $conv->customer_name . "'s chat 24-hour window is about to end - a reply is still due",
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
                    'title' => 'An AI draft has been sitting for ' . self::ageEn($m->created_at) . ' - send it or change it (' . $name . ')',
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
                    'title' => $cm->author_name . "'s comment private reply time is about to run out - reply now",
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
                    'title' => $p->name . ' stock ' . ($p->stock_qty <= 0 ? 'is finished' : 'is low (' . $p->stock_qty . ' left)') . ' - decide about buying, or keep the product off',
                    'source_type' => 'product', 'source_id' => $p->id,
                    'source_label' => 'Product · ' . $p->name, 'link' => '/inventory/products',
                ];
            });

        return $out;
    }

    /** M5: a supplier bill whose credit terms ran out while money is still due. */
    private function ruleSupplierDue(int $biz, array $def, array $c): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('purchases')) {
            return [];
        }
        $out = [];
        $svc = app(\App\Services\PurchaseService::class);
        foreach ($svc->supplierSummaries($biz) as $row) {
            if ($row['overdue'] <= 0) {
                continue;
            }
            $s = $row['supplier'];
            $out[] = [
                'fingerprint' => 'supplier:' . $s->id,
                'title' => $s->name . ' due is overdue - ৳' . number_format($row['overdue'], 0, '.', ',') . ' outstanding, oldest due ' . ($row['oldest_due_date'] ?? ''),
                'source_type' => 'supplier', 'source_id' => $s->id,
                'source_label' => 'Supplier · ' . $s->name, 'link' => '/suppliers',
            ];
        }

        return $out;
    }

    private function ruleLedgerMismatch(int $biz, array $def, array $c): array
    {
        $out = [];
        // Variant rows (variant_id set) track the variant's own stock
        // pool, not the product's - their new_stock must not be compared
        // against the product's stock_qty.
        $productIds = StockLedger::where('business_id', $biz)->whereNull('branch_id')->whereNull('variant_id')
            ->whereNotNull('new_stock')->distinct()->pluck('product_id');
        foreach ($productIds as $pid) {
            $p = Product::where('business_id', $biz)->find($pid);
            if (! $p) {
                continue;
            }
            $latest = StockLedger::where('business_id', $biz)->where('product_id', $pid)
                ->whereNull('branch_id')->whereNull('variant_id')->whereNotNull('new_stock')->orderByDesc('id')->first();
            if ($latest && (int) $latest->new_stock !== (int) $p->stock_qty) {
                $out[] = [
                    'fingerprint' => 'product:' . $p->id,
                    'title' => 'Stock ledger mismatch - ' . $p->name . ': ledger says ' . $latest->new_stock . ', product says ' . $p->stock_qty,
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
                    'title' => 'Warranty claim ' . $w->claim_no . ' is past its due date - talk to the supplier and update the customer',
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
                    'title' => $p->name . ' stock has gone negative (' . $p->stock_qty . ') - fix it now',
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
            'title' => 'Auto Call keeps failing - calls for ' . $n . ' orders failed in the last 24 hours',
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
                    'title' => 'Cash for ' . $d->date->format('d M') . ' is off by ৳' . number_format(abs((float) $d->difference)) . ' - reconcile the accounts',
                    'source_type' => 'closing', 'source_id' => $d->id,
                    'source_label' => 'Finance · ' . ($d->account?->name ?? 'Cash'), 'link' => '/finance',
                ];
            });

        return $out;
    }

    /**
     * ধাপ ৫: one open task per channel connection of this shop whose
     * status is error (a dead/expired token, found by a failed check
     * or an auth-class send failure). The fingerprint is the
     * connection id, so the task auto-closes the moment the same
     * connection checks healthy again.
     */
    private function ruleChannelBroken(int $biz, array $def, array $c): array
    {
        $labels = ['facebook' => 'Facebook Page', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram'];
        $out = [];
        $broken = \App\Models\ChannelConnection::where('business_id', $biz)
            ->where('status', 'error')->orderBy('id')->get();
        foreach ($broken as $conn) {
            $label = $labels[$conn->channel] ?? $conn->channel;
            $name = trim((string) ($conn->display_name ?? '')) ?: (string) $conn->external_id;
            $out[] = [
                'fingerprint' => 'channel:' . $conn->id,
                'title' => $label . ' connection is broken (' . $name . ') - reconnect it from Settings',
                'detail' => $conn->last_error,
                'source_type' => 'system', 'source_id' => $conn->id,
                'source_label' => 'Messaging Channels · ' . $label, 'link' => '/settings?group=api',
            ];
        }

        return $out;
    }

    private function ruleCourierUnverified(int $biz, array $def, array $c): array
    {
        $out = [];
        $methods = \App\Models\CourierMethod::where('business_id', $biz)
            ->where('is_active', true)->orderBy('id')->get();
        foreach ($methods as $m) {
            // Only couriers whose webhook events can be verified at all
            // (CourierMethod::webhookCredentialKey) can be missing the
            // credential; manual couriers never raise this task.
            if ($m->webhookCredentialKey() === null || $m->hasWebhookCredential()) {
                continue;
            }
            $label = $m->label();
            $out[] = [
                'fingerprint' => 'courier-method:' . $m->id,
                'title' => $label . ' has no webhook secret saved - delivery updates cannot be verified, add the secret in Settings',
                'detail' => 'Delivery updates coming through this courier\'s webhook are being accepted without any verification. Add the webhook secret/token on the ' . $label . ' connection in Settings → API Connection → Courier Integration and updates will be verified.',
                'source_type' => 'system', 'source_id' => $m->id,
                'source_label' => 'Courier Integration · ' . $label, 'link' => '/settings?group=api',
            ];
        }

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
            'title' => 'SMS balance is low - only ৳' . number_format($balance) . ' left, recharge now',
            'source_type' => 'system', 'source_id' => null,
            'source_label' => 'SMS Balance', 'link' => '/marketing/sms',
        ]];
    }

    private function ruleTokenBalanceLow(int $biz, array $def, array $c): array
    {
        $tokens = app(TokenService::class);
        if (! $tokens->enforcementOn() || ! $tokens->wallet($biz)) {
            return [];
        }
        $balance = $tokens->balance($biz);
        if ($balance > $tokens->lowThreshold()) {
            return [];
        }
        $empty = $balance < $tokens->pointsPerOrder();

        return [[
            'fingerprint' => 'token_balance',
            'title' => $empty
                ? 'Points are finished - new orders are blocked, buy a pack to start again'
                : 'Points balance is low - only ' . number_format($balance) . ' points left, buy a pack',
            'source_type' => 'system', 'source_id' => null,
            'source_label' => 'Points Balance', 'link' => '/billing',
        ]];
    }
}

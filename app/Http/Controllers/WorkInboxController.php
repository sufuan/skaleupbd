<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkTask;
use App\Services\WorkInboxService;
use App\Support\DhakaTime;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class WorkInboxController extends Controller
{
    public function __construct(private readonly WorkInboxService $wi) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function staff(int $biz)
    {
        return User::where('business_id', $biz)->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role])->all();
    }

    private function assigneeLabel(WorkTask $t): string
    {
        if ($t->assignee_user_id) {
            return $t->assignee?->name ?? 'Staff';
        }
        if ($t->assignee_role) {
            return WorkInboxService::ASSIGNEES[$t->assignee_role] ?? $t->assignee_role;
        }

        return 'কেউ না (পুল)';
    }

    private function createdLabel(WorkTask $t): string
    {
        $c = $t->created_at;
        if (DhakaTime::fmt($c, 'Y-m-d') === DhakaTime::today()) {
            $mins = (int) $c->diffInMinutes(now());
            if ($mins < 60) {
                return $mins . ' মিনিট আগে';
            }

            return intdiv($mins, 60) . ' ঘণ্টা আগে';
        }
        if ($c->isYesterday()) {
            return 'গতকাল ' . $c->format('H:i');
        }

        return $c->format('d M H:i');
    }

    private function dueLabel(WorkTask $t): ?array
    {
        if (! in_array($t->status, WorkTask::OPEN_STATUSES, true) || ! $t->due_at) {
            return null;
        }
        if ($t->due_at->isPast()) {
            // A task can be born overdue (manual task with a past due time):
            // never claim it has been overdue longer than it has existed —
            // the card also shows "তৈরি: X আগে", and the two contradicted.
            $since = $t->due_at->greaterThan($t->created_at) ? $t->due_at : $t->created_at;

            return ['text' => 'Overdue · ' . WorkInboxService::bnAge($since) . ' পার', 'chip' => 'chip-red'];
        }
        if (DhakaTime::fmt($t->due_at, 'Y-m-d') === DhakaTime::today()) {
            return ['text' => 'আজকের মধ্যে', 'chip' => 'chip-amber'];
        }
        if ($t->due_at->lte(now()->addDay())) {
            return ['text' => 'সময় বাকি ' . WorkInboxService::bnAge(now(), $t->due_at), 'chip' => 'chip-amber'];
        }

        return null;
    }

    private function linkLabel(WorkTask $t): string
    {
        $link = (string) $t->link;

        return match (true) {
            str_starts_with($link, '/messages/inbox') => 'Chat খোলো',
            str_starts_with($link, '/messages/comments') => 'Comments খোলো',
            str_starts_with($link, '/delivery/ready') => 'Ready to Ship খোলো',
            str_starts_with($link, '/delivery/parcels') => 'Courier page খোলো',
            str_starts_with($link, '/delivery/ledger') => 'Ledger খোলো',
            str_starts_with($link, '/inventory/ledger') => 'Ledger খোলো',
            str_starts_with($link, '/inventory/products') => 'Product খোলো',
            str_starts_with($link, '/inventory/warranty') => 'Warranty খোলো',
            str_starts_with($link, '/orders/block-list') => 'Block List খোলো',
            str_starts_with($link, '/orders/autocall') => 'Auto Call খোলো',
            str_starts_with($link, '/orders') => 'Order খোলো',
            str_starts_with($link, '/finance') => 'Finance খোলো',
            str_starts_with($link, '/marketing') => 'SMS page খোলো',
            default => 'খোলো',
        };
    }

    private function present(WorkTask $t): array
    {
        $catalog = WorkInboxService::catalog();
        $rule = $t->rule_key ? ($catalog[$t->rule_key] ?? null) : null;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'detail' => $t->detail,
            'status' => $t->status,
            'priority' => $t->priority,
            'priority_label' => WorkInboxService::PRIORITIES[$t->priority] ?? $t->priority,
            'source' => $t->source,
            'source_label' => $t->source_label,
            'link' => $t->link,
            'link_label' => $this->linkLabel($t),
            'rule_num' => $rule['num'] ?? null,
            'rule_name' => $rule['name'] ?? null,
            'note_required' => $this->wi->noteRequired($t),
            'assignee_id' => $t->assignee_user_id,
            'assignee_label' => $this->assigneeLabel($t),
            'created_label' => $this->createdLabel($t),
            'due' => $this->dueLabel($t),
            'is_overdue' => $t->isOverdue(),
            'escalated' => (bool) $t->escalated,
            'done_note' => $t->done_note,
            'done_by_label' => $t->doneBy?->name,
            'done_label' => $t->done_at ? $t->done_at->format('d M H:i') : null,
            'auto_close_reason' => $t->auto_close_reason,
            'closed_label' => $t->closed_at ? $t->closed_at->format('d M H:i') : null,
        ];
    }

    private function presentMany($tasks): array
    {
        return $tasks->map(fn (WorkTask $t) => $this->present($t))->all();
    }

    // ---------- pages ----------

    public function control(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'source' => (string) $request->query('source', ''),
            'assignee' => (string) $request->query('assignee', ''),
            'due' => (string) $request->query('due', ''),
            'q' => (string) $request->query('q', ''),
        ];

        $open = WorkTask::where('business_id', $biz)->open();
        // "Not assigned" means no PERSON holds the task — the same
        // definition the assignee filter below uses (whereNull user).
        // The old count also required assignee_mode='pool', so a task
        // queued for a role (user still null) showed in the Pool filter
        // while this counter read 0.
        $kpis = [
            'open_total' => (clone $open)->count(),
            'unassigned' => (clone $open)->whereNull('assignee_user_id')->count(),
            'overdue' => (clone $open)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'done_today' => WorkTask::where('business_id', $biz)->where('status', 'done')
                ->whereBetween('done_at', DhakaTime::dayBoundsUtc(DhakaTime::today()))->count(),
        ];

        $pool = WorkTask::where('business_id', $biz)->open()
            ->whereNull('assignee_user_id')
            ->with(['assignee', 'doneBy'])->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->oldest('due_at')->get();

        $q = WorkTask::where('business_id', $biz)->with(['assignee', 'doneBy']);
        match ($filters['status']) {
            'open' => $q->where('status', 'open'),
            'progress' => $q->where('status', 'in_progress'),
            'done' => $q->whereIn('status', ['done', 'auto_closed']),
            'all' => $q,
            default => $q->open(),
        };
        if ($filters['source'] !== '') {
            $q->where('source', $filters['source']);
        }
        if ($filters['assignee'] === 'pool') {
            $q->whereNull('assignee_user_id');
        } elseif ($filters['assignee'] !== '') {
            $q->where('assignee_user_id', (int) $filters['assignee']);
        }
        if ($filters['due'] === 'overdue') {
            $q->whereNotNull('due_at')->where('due_at', '<', now());
        } elseif ($filters['due'] === 'today') {
            $q->whereBetween('due_at', DhakaTime::dayBoundsUtc(DhakaTime::today()));
        }
        if ($filters['q'] !== '') {
            $needle = '%' . $filters['q'] . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $needle)->orWhere('source_label', 'like', $needle));
        }
        $tasks = $q->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->oldest('due_at')->latest('id')->limit(300)->get();

        return Inertia::render('WorkInbox/Control', [
            'kpis' => $kpis,
            'poolTasks' => $this->presentMany($pool),
            'tasks' => $this->presentMany($tasks),
            'staff' => $this->staff($biz),
            'filters' => $filters,
            'sources' => WorkInboxService::GROUPS + ['manual' => 'Manual'],
        ]);
    }

    public function my(Request $request): InertiaResponse|RedirectResponse
    {
        $biz = $this->biz($request);
        if (! $this->wi->myWorkEnabled($biz)) {
            return redirect('/work-inbox/control')->with('error', 'My Work আপাতত বন্ধ আছে — সব কাজ Work Control থেকে হবে');
        }
        $me = $request->user()->id;
        $mine = WorkTask::where('business_id', $biz)
            ->where(fn ($q) => $q->where('assignee_user_id', $me)
                ->orWhere(fn ($q2) => $q2->where('assignee_mode', 'pool')->whereNull('assignee_user_id')))
            ->where(fn ($q) => $q->open()->orWhereBetween('done_at', DhakaTime::dayBoundsUtc(DhakaTime::today())))
            ->with(['assignee', 'doneBy'])
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->oldest('due_at')->latest('id')->limit(300)->get();

        $openMine = $mine->filter(fn (WorkTask $t) => in_array($t->status, WorkTask::OPEN_STATUSES, true));
        $kpis = [
            'open' => $openMine->count(),
            'overdue' => $openMine->filter(fn (WorkTask $t) => $t->isOverdue())->count(),
            'due_today' => $openMine->filter(fn (WorkTask $t) => $t->due_at && DhakaTime::fmt($t->due_at, 'Y-m-d') === DhakaTime::today())->count(),
            'critical' => $openMine->filter(fn (WorkTask $t) => $t->priority === 'critical')->count(),
            'done_today' => WorkTask::where('business_id', $biz)->where('done_by', $me)
                ->whereBetween('done_at', DhakaTime::dayBoundsUtc(DhakaTime::today()))->count(),
        ];

        return Inertia::render('WorkInbox/MyWork', [
            'kpis' => $kpis,
            'tasks' => $this->presentMany($mine),
            'staff' => $this->staff($biz),
            'sources' => WorkInboxService::GROUPS + ['manual' => 'Manual'],
        ]);
    }

    public function settings(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $cfg = $this->wi->ruleSettings($biz);
        $groups = [];
        foreach (WorkInboxService::GROUPS as $gkey => $glabel) {
            $rules = [];
            foreach (WorkInboxService::catalog() as $key => $def) {
                if ($def['group'] !== $gkey) {
                    continue;
                }
                $c = $cfg[$key];
                $rules[] = [
                    'key' => $key, 'num' => $def['num'], 'name' => $def['name'], 'desc' => $def['desc'],
                    'enabled' => $c['enabled'], 'value' => $c['value'], 'unit' => $c['unit'],
                    'priority' => $c['priority'], 'assign' => $c['assign'], 'assign_user' => $c['assign_user'],
                    'immediate' => $def['unit'] === 'immediate',
                ];
            }
            $groups[] = ['key' => $gkey, 'label' => $glabel, 'count' => count($rules), 'rules' => $rules];
        }

        return Inertia::render('WorkInbox/Settings', [
            'groups' => $groups,
            'toggles' => [
                'notify_new' => $this->wi->toggle($biz, 'notify_new'),
                'notify_overdue' => $this->wi->toggle($biz, 'notify_overdue'),
                'auto_close' => $this->wi->toggle($biz, 'auto_close'),
                'my_work_enabled' => $this->wi->myWorkEnabled($biz),
            ],
            'staff' => $this->staff($biz),
            'priorities' => WorkInboxService::PRIORITIES,
            'assignees' => WorkInboxService::ASSIGNEES,
        ]);
    }

    // ---------- actions ----------

    public function saveSettings(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'rules' => ['array'],
            'toggles' => ['array'],
        ]);
        $this->wi->saveRuleSettings($biz, (array) ($data['rules'] ?? []));
        foreach (['notify_new', 'notify_overdue', 'auto_close', 'my_work_enabled'] as $name) {
            if (isset($data['toggles'][$name])) {
                $this->wi->setToggle($biz, $name, (bool) $data['toggles'][$name]);
            }
        }

        return back()->with('success', 'Work Inbox settings saved');
    }

    public function store(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assign' => ['nullable', 'string', 'in:self,pool,user'],
            'assign_user' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string', 'in:normal,urgent,critical'],
            'due_at' => ['nullable', 'date'],
        ]);
        $this->wi->createManual($biz, $request->user(), $data);

        return back()->with('success', 'Task created');
    }

    public function start(Request $request, WorkTask $task): RedirectResponse
    {
        abort_unless($task->business_id === $this->biz($request), 404);
        $this->wi->start($task, $request->user());

        return back();
    }

    public function done(Request $request, WorkTask $task): RedirectResponse
    {
        abort_unless($task->business_id === $this->biz($request), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->wi->markDone($task, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Task done');
    }

    public function reassign(Request $request, WorkTask $task): RedirectResponse
    {
        abort_unless($task->business_id === $this->biz($request), 404);
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);
        $userId = null;
        if (! empty($data['user_id'])) {
            $u = User::where('business_id', $this->biz($request))->find((int) $data['user_id']);
            $userId = $u?->id;
        }
        $this->wi->reassign($task, $userId);

        return back();
    }

    public function bulkDone(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $res = $this->wi->bulkDone($biz, $request->user(), $data['ids']);
        $msg = $res['done'] . 'টা কাজ শেষ করা হয়েছে';
        if ($res['skipped'] > 0) {
            $msg .= ' · ' . $res['skipped'] . 'টা note-বাধ্যতামূলক কাজ বাদ গেছে (আলাদা করে Done করো)';
        }

        return back()->with('success', $msg);
    }
}

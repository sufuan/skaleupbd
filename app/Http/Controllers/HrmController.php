<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Sop;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use App\Support\DhakaTime;

class HrmController extends Controller
{
    public function __construct(private AttendanceService $att) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function canManage(Request $request): bool
    {
        $u = $request->user();

        return $u->is_platform_admin || in_array($u->role, ['admin', 'manager'], true);
    }

    private function adminOnly(Request $request): bool
    {
        $u = $request->user();

        return $u->is_platform_admin || $u->role === 'admin';
    }

    private function deny(): RedirectResponse
    {
        return back()->with('error', 'এই কাজ করার permission নেই।');
    }

    /** Shared "today" row shape for the dashboard and attendance pages. */
    private function todayRows(int $biz): array
    {
        $grace = (int) $this->att->setting($biz, 'late_grace', 15);

        return $this->att->todayList($biz)->map(function (array $row) use ($grace) {
            $u = $row['user'];
            $r = $row['record'];
            $dept = $u->department();
            if (! $r) {
                [$key, $label] = ['absent', 'আসেনি'];
            } elseif ($r->status === 'leave') {
                [$key, $label] = ['leave', 'ছুটি'];
            } elseif (! $r->check_in_at) {
                [$key, $label] = ['absent', 'আসেনি'];
            } elseif ($r->status === 'late') {
                [$key, $label] = ['late', 'দেরি'];
            } elseif ($r->late_minutes > 0) {
                [$key, $label] = ['grace', 'দেরি (grace-এর ভিতরে)'];
            } else {
                [$key, $label] = ['present', 'এসেছে'];
            }

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'department' => $dept?->name,
                'check_in' => DhakaTime::fmt($r?->check_in_at, 'h:i A'),
                'check_out' => DhakaTime::fmt($r?->check_out_at, 'h:i A'),
                'status_key' => $key,
                'status_label' => $label,
                'late_minutes' => (int) ($r?->late_minutes ?? 0),
                'in_grace' => $key === 'grace',
            ];
        })->all();
    }

    private function pendingLeaves(int $biz): array
    {
        return LeaveRequest::where('business_id', $biz)
            ->where('status', 'pending')
            ->with('user')
            ->orderBy('from_date')
            ->get()
            ->map(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'name' => $l->user?->name,
                'from' => $l->from_date->format('d M'),
                'to' => $l->to_date->format('d M'),
                'days' => $l->days(),
                'reason' => $l->reason,
            ])->all();
    }

    public function dashboard(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $rows = $this->todayRows($biz);

        return Inertia::render('Hrm/Dashboard', [
            'stats' => [
                'total' => count($rows),
                'present' => collect($rows)->whereIn('status_key', ['present', 'late', 'grace'])->count(),
                'leave' => collect($rows)->where('status_key', 'leave')->count(),
                'late' => collect($rows)->where('status_key', 'late')->count(),
            ],
            'today' => $rows,
            'pending_leaves' => $this->pendingLeaves($biz),
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function attendance(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $user = $request->user();
        $tab = $request->query('tab', 'today');
        $month = $request->query('month', substr($this->att->todayDate(), 0, 7));

        $mine = $this->att->recordFor($user, $this->att->todayDate());
        $myRecord = $mine ? [
            'check_in' => DhakaTime::fmt($mine->check_in_at, 'h:i A'),
            'check_out' => DhakaTime::fmt($mine->check_out_at, 'h:i A'),
            'break_minutes' => (int) $mine->break_minutes,
            'on_break' => $mine->break_started_at !== null,
            'work_minutes' => $mine->workMinutes(),
            'status' => $mine->status,
        ] : null;

        $report = null;
        if ($tab === 'report') {
            $report = [
                'month' => $month,
                'working_days' => count($this->att->workingDaysInMonth($biz, $month)),
                'rows' => $this->att->monthSummary($biz, $month)->map(fn (array $r) => [
                    'name' => $r['user']->name,
                    'present' => $r['present'],
                    'late' => $r['late'],
                    'leave' => $r['leave'],
                    'absent' => $r['absent'],
                    'hours' => round($r['work_minutes'] / 60, 1),
                ])->all(),
            ];
        }

        $shifts = null;
        if ($tab === 'shifts') {
            $shifts = Shift::where('business_id', $biz)->with('members')->orderBy('start_time')->get()
                ->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'start' => substr((string) $s->start_time, 0, 5),
                    'end' => substr((string) $s->end_time, 0, 5),
                    'members' => $s->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->all(),
                ])->all();
        }

        return Inertia::render('Hrm/Attendance', [
            'tab' => $tab,
            'my_record' => $myRecord,
            'today' => $this->todayRows($biz),
            'pending_leaves' => $this->pendingLeaves($biz),
            'my_leaves' => LeaveRequest::where('business_id', $biz)->where('user_id', $user->id)
                ->latest()->limit(10)->get()
                ->map(fn (LeaveRequest $l) => [
                    'id' => $l->id,
                    'from' => $l->from_date->format('d M'),
                    'to' => $l->to_date->format('d M'),
                    'days' => $l->days(),
                    'reason' => $l->reason,
                    'status' => $l->status,
                ])->all(),
            'report' => $report,
            'shifts' => $shifts,
            'staff_options' => User::where('business_id', $biz)->where('staff_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function exportReport(Request $request)
    {
        $biz = $this->biz($request);
        $month = $request->query('month', substr($this->att->todayDate(), 0, 7));
        $rows = $this->att->monthSummary($biz, $month);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Present', 'Late', 'Leave', 'Absent', 'Work hours']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['user']->name, $r['present'], $r['late'], $r['leave'], $r['absent'], round($r['work_minutes'] / 60, 1)]);
            }
            fclose($out);
        }, 'attendance-'.$month.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function checkIn(Request $request): RedirectResponse
    {
        try {
            $this->att->checkIn($request->user());

            return back()->with('success', 'Check-in হয়ে গেছে।');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkOut(Request $request): RedirectResponse
    {
        try {
            $this->att->checkOut($request->user());

            return back()->with('success', 'Check-out হয়ে গেছে।');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function breakToggle(Request $request): RedirectResponse
    {
        try {
            $record = $this->att->recordFor($request->user(), $this->att->todayDate());
            if ($record && $record->break_started_at) {
                $this->att->endBreak($request->user());

                return back()->with('success', 'Break শেষ - কাজে ফিরেছেন।');
            }
            $this->att->startBreak($request->user());

            return back()->with('success', 'Break শুরু হলো।');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function applyLeave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'nullable|string|max:500',
        ]);
        LeaveRequest::create([
            'business_id' => $this->biz($request),
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return back()->with('success', 'ছুটির আবেদন পাঠানো হয়েছে।');
    }

    public function decideLeave(Request $request, LeaveRequest $leave): RedirectResponse
    {
        if (! $this->canManage($request) || $leave->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $leave->update([
            'status' => $data['decision'],
            'decided_by' => $request->user()->id,
            'decided_at' => now('Asia/Dhaka'),
        ]);
        if ($data['decision'] === 'approved') {
            $cursor = $leave->from_date->copy();
            while ($cursor->lte($leave->to_date)) {
                $this->att->ensureLeaveRecords($leave->business_id, $cursor->toDateString());
                $cursor->addDay();
            }
        }

        return back()->with('success', $data['decision'] === 'approved' ? 'ছুটি অনুমোদন হয়েছে।' : 'ছুটির আবেদন বাতিল করা হয়েছে।');
    }

    // ---------- Departments & SOP ----------

    public function departments(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);

        return Inertia::render('Hrm/Departments', [
            'departments' => Department::where('business_id', $biz)->with(['head', 'members'])->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'head' => $d->head ? ['id' => $d->head->id, 'name' => $d->head->name] : null,
                    'members' => $d->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->all(),
                ])->all(),
            'sops' => Sop::where('business_id', $biz)->with('department')->orderBy('title')->get()
                ->map(fn (Sop $s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'department_id' => $s->department_id,
                    'department_name' => $s->department?->name,
                    'steps' => $s->steps ?? [],
                ])->all(),
            'staff_options' => User::where('business_id', $biz)->where('staff_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('business_id', $biz)],
            'member_ids' => 'array',
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('business_id', $biz)],
        ]);
        $dept = Department::create(['business_id' => $biz, 'name' => $data['name'], 'head_user_id' => $data['head_user_id'] ?? null]);
        $this->syncMembers($dept, $data['member_ids'] ?? []);

        return back()->with('success', 'Department তৈরি হয়েছে।');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        if (! $this->canManage($request) || $department->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'head_user_id' => ['nullable', Rule::exists('users', 'id')->where('business_id', $biz)],
            'member_ids' => 'array',
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('business_id', $biz)],
        ]);
        $department->update(['name' => $data['name'], 'head_user_id' => $data['head_user_id'] ?? null]);
        $this->syncMembers($department, $data['member_ids'] ?? []);

        return back()->with('success', 'Department বদলানো হয়েছে।');
    }

    public function destroyDepartment(Request $request, Department $department): RedirectResponse
    {
        if (! $this->canManage($request) || $department->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        if ($department->members()->count() > 0) {
            return back()->with('error', 'এই Department-এ এখনো কর্মী আছে - আগে তাদের সরান, তারপর মুছুন।');
        }
        $department->delete();

        return back()->with('success', 'Department মোছা হয়েছে।');
    }

    /** One primary department per user: assigning here moves them out of any other. */
    private function syncMembers(Department $dept, array $memberIds): void
    {
        foreach ($memberIds as $uid) {
            $user = User::find($uid);
            $user?->departments()->detach();
        }
        $dept->members()->sync($memberIds);
    }

    public function storeSop(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('business_id', $biz)],
            'steps_text' => 'required|string',
        ]);
        Sop::create([
            'business_id' => $biz,
            'title' => $data['title'],
            'department_id' => $data['department_id'] ?? null,
            'steps' => $this->parseSteps($data['steps_text']),
        ]);

        return back()->with('success', 'SOP তৈরি হয়েছে।');
    }

    public function updateSop(Request $request, Sop $sop): RedirectResponse
    {
        if (! $this->canManage($request) || $sop->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('business_id', $biz)],
            'steps_text' => 'required|string',
        ]);
        $sop->update([
            'title' => $data['title'],
            'department_id' => $data['department_id'] ?? null,
            'steps' => $this->parseSteps($data['steps_text']),
        ]);

        return back()->with('success', 'SOP বদলানো হয়েছে।');
    }

    public function destroySop(Request $request, Sop $sop): RedirectResponse
    {
        if (! $this->canManage($request) || $sop->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $sop->delete();

        return back()->with('success', 'SOP মোছা হয়েছে।');
    }

    private function parseSteps(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text))));
    }

    // ---------- Shifts ----------

    public function storeShift(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);
        Shift::create(['business_id' => $this->biz($request), ...$data]);

        return back()->with('success', 'Shift তৈরি হয়েছে।');
    }

    public function updateShift(Request $request, Shift $shift): RedirectResponse
    {
        if (! $this->canManage($request) || $shift->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);
        $shift->update($data);

        return back()->with('success', 'Shift বদলানো হয়েছে।');
    }

    public function destroyShift(Request $request, Shift $shift): RedirectResponse
    {
        if (! $this->canManage($request) || $shift->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        if ($shift->members()->count() > 0) {
            return back()->with('error', 'এই Shift-এ এখনো কর্মী আছে - আগে তাদের সরান, তারপর মুছুন।');
        }
        $shift->delete();

        return back()->with('success', 'Shift মোছা হয়েছে।');
    }

    public function assignShift(Request $request, Shift $shift): RedirectResponse
    {
        if (! $this->canManage($request) || $shift->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'member_ids' => 'array',
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('business_id', $biz)],
        ]);
        foreach ($data['member_ids'] ?? [] as $uid) {
            User::find($uid)?->shifts()->detach();
        }
        $shift->members()->sync($data['member_ids'] ?? []);

        return back()->with('success', 'Shift-এর কর্মী ঠিক করা হয়েছে।');
    }

    // ---------- User & Role ----------

    public function users(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $showSalary = $this->adminOnly($request);

        return Inertia::render('Hrm/Users', [
            'users' => User::where('business_id', $biz)->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'role_label' => Permissions::roles()[$u->role] ?? $u->role,
                    'permissions' => $u->effectivePermissions(),
                    'uses_default' => ! is_array($u->permissions) || $u->permissions === [],
                    'monthly_salary' => $showSalary ? (float) $u->monthly_salary : 0,
                    'staff_active' => (bool) $u->staff_active,
                    'is_self' => $u->id === $request->user()->id,
                ])->all(),
            'modules' => Permissions::LABELS,
            'roles' => Permissions::roles(),
            'can_manage' => $this->adminOnly($request),
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        if (! $this->adminOnly($request)) {
            return $this->deny();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(array_keys(Permissions::roles()))],
            'monthly_salary' => 'nullable|numeric|min:0',
        ]);
        $user = User::create([
            'business_id' => $this->biz($request),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'monthly_salary' => $data['monthly_salary'] ?? 0,
            'email_verified_at' => now(),
        ]);
        $user->finance_access = in_array($data['role'], ['admin', 'accountant'], true) ? 'full' : 'entry';
        $user->save();

        return back()->with('success', 'নতুন User তৈরি হয়েছে।');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        if (! $this->adminOnly($request) || $user->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'role' => ['required', Rule::in(array_keys(Permissions::roles()))],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(Permissions::ALL)],
            'use_default' => 'boolean',
            'monthly_salary' => 'nullable|numeric|min:0',
            'staff_active' => 'boolean',
        ]);
        if ($user->id === $request->user()->id && $data['role'] !== 'admin') {
            return back()->with('error', 'নিজের Role Admin থেকে বদলানো যাবে না।');
        }
        if ($user->id === $request->user()->id && ! ($data['staff_active'] ?? true)) {
            return back()->with('error', 'নিজেকে বন্ধ করা যাবে না।');
        }
        $user->name = $data['name'];
        $user->role = $data['role'];
        $user->permissions = ($data['use_default'] ?? false) ? null : ($data['permissions'] ?? []);
        $user->monthly_salary = $data['monthly_salary'] ?? 0;
        $user->staff_active = $data['staff_active'] ?? true;
        $user->finance_access = in_array($data['role'], ['admin', 'accountant'], true) ? 'full' : 'entry';
        $user->save();

        return back()->with('success', 'User বদলানো হয়েছে।');
    }

    // ---------- HR Settings ----------

    public function settings(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);

        return Inertia::render('Hrm/Settings', [
            'settings' => [
                'late_grace' => (int) $this->att->setting($biz, 'late_grace', 15),
                'max_break' => (int) $this->att->setting($biz, 'max_break', 60),
                'weekly_off' => (string) $this->att->setting($biz, 'weekly_off', 'Friday'),
                'require_checkin' => (bool) $this->att->setting($biz, 'require_checkin', false),
                'auto_checkout' => (bool) $this->att->setting($biz, 'auto_checkout', true),
                'payroll_enabled' => (bool) $this->att->setting($biz, 'payroll_enabled', false),
            ],
            'holidays' => Holiday::where('business_id', $biz)->orderBy('date')->get()
                ->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name])->all(),
            'can_manage' => $this->adminOnly($request),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        if (! $this->adminOnly($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'late_grace' => 'required|integer|min:0|max:120',
            'max_break' => 'required|integer|min:0|max:480',
            'weekly_off' => 'required|in:Saturday,Sunday,Monday,Tuesday,Wednesday,Thursday,Friday',
            'require_checkin' => 'boolean',
            'auto_checkout' => 'boolean',
            'payroll_enabled' => 'boolean',
        ]);
        foreach ($data as $name => $value) {
            $this->att->setSetting($biz, $name, $value, $request->user()->id);
        }

        return back()->with('success', 'HR Settings বদলানো হয়েছে।');
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => 'required|string|max:120',
        ]);
        if (Holiday::where('business_id', $biz)->whereDate('date', $data['date'])->exists()) {
            return back()->with('error', 'এই তারিখে ইতিমধ্যে একটি ছুটির দিন আছে।');
        }
        Holiday::create(['business_id' => $biz, ...$data]);

        return back()->with('success', 'ছুটির দিন যোগ হয়েছে।');
    }

    public function destroyHoliday(Request $request, Holiday $holiday): RedirectResponse
    {
        if (! $this->canManage($request) || $holiday->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $holiday->delete();

        return back()->with('success', 'ছুটির দিন মোছা হয়েছে।');
    }
}

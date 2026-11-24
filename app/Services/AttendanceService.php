<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance engine (M13): check-in/out, breaks, late math against the
 * assigned shift + grace, leave materialisation, auto check-out sweep,
 * working-day calendar and the month report. All dates Asia/Dhaka.
 */
class AttendanceService
{
    public function __construct(private SettingsService $settings) {}

    private function skey(int $businessId, string $name): string
    {
        return 'hr.'.$businessId.'.'.$name;
    }

    public function setting(int $businessId, string $name, mixed $default = null): mixed
    {
        return $this->settings->get($this->skey($businessId, $name), $default);
    }

    public function setSetting(int $businessId, string $name, mixed $value, ?int $userId = null): void
    {
        $this->settings->set($this->skey($businessId, $name), $value, $userId);
    }

    public function todayDate(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    /**
     * "Now" in the app's storage frame: datetime columns hold Dhaka wall-clock
     * values (parsed back as UTC by Eloquent), so all arithmetic uses Carbons
     * whose wall numbers are Dhaka time in a UTC frame — one consistent frame.
     */
    public function nowDhaka(): Carbon
    {
        $d = now('Asia/Dhaka');

        return Carbon::createFromFormat('Y-m-d H:i:s', $d->format('Y-m-d H:i:s'), 'UTC');
    }

    public function shiftFor(User $user): ?Shift
    {
        return $user->shifts()->first();
    }

    /** Shift start as a Carbon on the given date (default 09:00 when no shift), in the storage frame. */
    public function shiftStart(User $user, string $date): Carbon
    {
        $shift = $this->shiftFor($user);
        $time = $shift ? substr((string) $shift->start_time, 0, 5) : '09:00';

        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, 'UTC');
    }

    public function shiftEnd(User $user, string $date): Carbon
    {
        $shift = $this->shiftFor($user);
        $time = $shift ? substr((string) $shift->end_time, 0, 5) : '18:00';

        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, 'UTC');
    }

    public function recordFor(User $user, string $date): ?AttendanceRecord
    {
        return AttendanceRecord::where('user_id', $user->id)->whereDate('date', $date)->first();
    }

    public function approvedLeaveOn(int $businessId, int $userId, string $date): ?LeaveRequest
    {
        return LeaveRequest::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->first();
    }

    /** Create leave rows for approved leaves covering the date (idempotent). */
    public function ensureLeaveRecords(int $businessId, string $date): void
    {
        $leaves = LeaveRequest::where('business_id', $businessId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->get();

        foreach ($leaves as $leave) {
            $existing = AttendanceRecord::where('user_id', $leave->user_id)->whereDate('date', $date)->first();
            if (! $existing) {
                AttendanceRecord::create([
                    'business_id' => $businessId,
                    'user_id' => $leave->user_id,
                    'date' => $date,
                    'status' => 'leave',
                ]);
            }
        }
    }

    public function checkIn(User $user): AttendanceRecord
    {
        $businessId = (int) $user->business_id;
        $today = $this->todayDate();

        if ($this->approvedLeaveOn($businessId, $user->id, $today)) {
            throw new \RuntimeException(__('You have approved leave today - no check-in needed.'));
        }

        $record = $this->recordFor($user, $today);
        if ($record && $record->check_in_at) {
            throw new \RuntimeException(__('You have already checked in today.'));
        }

        $now = $this->nowDhaka();
        $start = $this->shiftStart($user, $today);
        $grace = (int) $this->setting($businessId, 'late_grace', 15);
        $lateMinutes = $now->greaterThan($start) ? (int) $start->diffInMinutes($now) : 0;
        $status = $lateMinutes > $grace ? 'late' : 'present';

        $record ??= new AttendanceRecord(['business_id' => $businessId, 'user_id' => $user->id, 'date' => $today]);
        $record->check_in_at = $now;
        $record->late_minutes = $lateMinutes;
        $record->status = $status;
        $record->save();

        return $record;
    }

    public function checkOut(User $user): AttendanceRecord
    {
        $today = $this->todayDate();
        $record = $this->recordFor($user, $today);
        if (! $record || ! $record->check_in_at) {
            throw new \RuntimeException(__('Please check in first.'));
        }
        if ($record->check_out_at) {
            throw new \RuntimeException(__('You have already checked out today.'));
        }
        if ($record->break_started_at) {
            $this->endBreak($user);
            $record->refresh();
        }
        $record->check_out_at = $this->nowDhaka();
        $record->save();

        return $record;
    }

    public function startBreak(User $user): AttendanceRecord
    {
        $record = $this->recordFor($user, $this->todayDate());
        if (! $record || ! $record->check_in_at || $record->check_out_at) {
            throw new \RuntimeException(__('Please check in before taking a break.'));
        }
        if ($record->break_started_at) {
            throw new \RuntimeException(__('A break is already running.'));
        }
        $record->break_started_at = $this->nowDhaka();
        $record->save();

        return $record;
    }

    /**
     * The day's counted break minutes after adding a finished break,
     * with the HR max_break cap applied: break time past the cap does
     * not count as break, so the counted total never grows beyond it.
     * The clamped number on the record is itself the visible record of
     * the cap - the schema has no separate note field.
     */
    private function countedBreak(AttendanceRecord $record, int $addMinutes): int
    {
        $cap = max(0, (int) $this->setting((int) $record->business_id, 'max_break', 60));

        return min((int) $record->break_minutes + $addMinutes, $cap);
    }

    public function endBreak(User $user): AttendanceRecord
    {
        $record = $this->recordFor($user, $this->todayDate());
        if (! $record || ! $record->break_started_at) {
            throw new \RuntimeException(__('No break is running.'));
        }
        $record->break_minutes = $this->countedBreak($record, (int) $record->break_started_at->diffInMinutes($this->nowDhaka()));
        $record->break_started_at = null;
        $record->save();

        return $record;
    }

    /** Lazy auto check-out: open records past shift end + 10 min close at shift end. */
    public function sweepAutoCheckout(int $businessId): void
    {
        if (! $this->setting($businessId, 'auto_checkout', true)) {
            return;
        }
        $today = $this->todayDate();
        $open = AttendanceRecord::where('business_id', $businessId)
            ->whereDate('date', $today)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->with('user')
            ->get();

        foreach ($open as $record) {
            if (! $record->user) {
                continue;
            }
            $end = $this->shiftEnd($record->user, $today);
            if ($this->nowDhaka()->greaterThan($end->copy()->addMinutes(10))) {
                if ($record->break_started_at) {
                    $record->break_minutes = $this->countedBreak($record, (int) $record->break_started_at->diffInMinutes($end));
                    $record->break_started_at = null;
                }
                $record->check_out_at = $end;
                $record->auto_closed = true;
                $record->save();
            }
        }
    }

    public function weeklyOffName(int $businessId): string
    {
        return (string) $this->setting($businessId, 'weekly_off', 'Friday');
    }

    public function isWorkingDay(int $businessId, Carbon $date): bool
    {
        if ($date->format('l') === $this->weeklyOffName($businessId)) {
            return false;
        }

        return ! Holiday::where('business_id', $businessId)->whereDate('date', $date->toDateString())->exists();
    }

    /** @return string[] working-day date strings (Y-m-d) of the month. */
    public function workingDaysInMonth(int $businessId, string $month): array
    {
        $start = Carbon::parse($month.'-01', 'Asia/Dhaka');
        $days = [];
        $cursor = $start->copy();
        while ($cursor->month === $start->month) {
            if ($this->isWorkingDay($businessId, $cursor)) {
                $days[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Month report rows per active staff member of the business.
     *
     * @return Collection<int, array{user: User, present: int, late: int, leave: int, absent: int, work_minutes: int}>
     */
    public function monthSummary(int $businessId, string $month): Collection
    {
        $workingDays = $this->workingDaysInMonth($businessId, $month);
        $today = $this->todayDate();
        $isCurrentMonth = substr($today, 0, 7) === $month;
        $elapsedWorkingDays = $isCurrentMonth
            ? array_values(array_filter($workingDays, fn ($d) => $d <= $today))
            : $workingDays;

        $staff = User::where('business_id', $businessId)->where('staff_active', true)->where('role', '!=', 'admin')->orderBy('name')->get();
        $records = AttendanceRecord::where('business_id', $businessId)
            ->where('date', 'like', $month.'%')
            ->get()
            ->groupBy('user_id');

        return $staff->map(function (User $user) use ($records, $elapsedWorkingDays) {
            $mine = $records->get($user->id, collect());
            $present = $mine->where('status', 'present')->count();
            $late = $mine->where('status', 'late')->count();
            $leave = $mine->where('status', 'leave')->count();
            $attendedDates = $mine->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
            $absent = count(array_filter($elapsedWorkingDays, fn ($d) => ! in_array($d, $attendedDates, true)));
            $workMinutes = $mine->sum(fn (AttendanceRecord $r) => $r->workMinutes());

            return [
                'user' => $user,
                'present' => $present,
                'late' => $late,
                'leave' => $leave,
                'absent' => $absent,
                'work_minutes' => $workMinutes,
            ];
        });
    }

    /** Absent working days of one user in a month (for payroll). */
    public function absentDaysInMonth(int $businessId, int $userId, string $month): int
    {
        $workingDays = $this->workingDaysInMonth($businessId, $month);
        $attended = AttendanceRecord::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('date', 'like', $month.'%')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        return count(array_filter($workingDays, fn ($d) => ! in_array($d, $attended, true)));
    }

    /** Today's list: every active staff member with their record (or null). */
    public function todayList(int $businessId): Collection
    {
        $today = $this->todayDate();
        $this->ensureLeaveRecords($businessId, $today);
        $this->sweepAutoCheckout($businessId);

        $records = AttendanceRecord::where('business_id', $businessId)
            ->whereDate('date', $today)
            ->get()
            ->keyBy('user_id');

        return User::where('business_id', $businessId)
            ->where('staff_active', true)
            ->where('role', '!=', 'admin')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['user' => $u, 'record' => $records->get($u->id)]);
    }
}

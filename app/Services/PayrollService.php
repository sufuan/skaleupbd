<?php

namespace App\Services;

use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payroll engine (M13, built but DISABLED by default — the hr.{biz}.payroll_enabled
 * switch gates every route). A paid run posts exactly ONE Finance expense
 * (বেতন category) — Finance stays the money home, Payroll only calculates.
 */
class PayrollService
{
    public function __construct(
        private AttendanceService $attendance,
        private FinanceService $finance,
    ) {}

    public function enabled(int $businessId): bool
    {
        return (bool) $this->attendance->setting($businessId, 'payroll_enabled', false);
    }

    /** Build (or rebuild, while still draft) the run for a YYYY-MM month. */
    public function buildRun(int $businessId, string $month, ?User $actor = null): PayrollRun
    {
        $existing = PayrollRun::where('business_id', $businessId)->where('month', $month)->first();
        if ($existing && $existing->status === 'paid') {
            throw new \RuntimeException('এই মাসের বেতন ইতিমধ্যে দেওয়া হয়ে গেছে।');
        }

        $workingDays = count($this->attendance->workingDaysInMonth($businessId, $month));
        $staff = User::where('business_id', $businessId)
            ->where('staff_active', true)
            ->where('role', '!=', 'admin')
            ->where('monthly_salary', '>', 0)
            ->orderBy('name')
            ->get();

        return DB::transaction(function () use ($businessId, $month, $existing, $workingDays, $staff) {
            $run = $existing ?? PayrollRun::create(['business_id' => $businessId, 'month' => $month, 'status' => 'draft']);
            $run->items()->delete();

            $total = 0;
            foreach ($staff as $user) {
                $absent = $this->attendance->absentDaysInMonth($businessId, (int) $user->id, $month);
                $basic = (float) $user->monthly_salary;
                $absentCut = $workingDays > 0 ? round($basic / $workingDays * $absent, 2) : 0;

                $item = PayrollItem::create([
                    'run_id' => $run->id,
                    'user_id' => $user->id,
                    'basic' => $basic,
                    'working_days' => $workingDays,
                    'absent_days' => $absent,
                    'absent_cut' => $absentCut,
                    'advance_cut' => 0,
                    'other_adjust' => 0,
                    'net' => 0,
                ]);
                $item->recomputeNet();
                $item->save();
                $total += (float) $item->net;
            }

            $run->update(['total' => round($total, 2)]);

            return $run->fresh('items');
        });
    }

    public function refreshTotal(PayrollRun $run): void
    {
        $run->update(['total' => round((float) $run->items()->sum('net'), 2)]);
    }

    /** Mark a run paid and post the single Finance expense (idempotent). */
    public function markPaid(PayrollRun $run, int $accountId, User $actor): PayrollRun
    {
        if ($run->status === 'paid') {
            throw new \RuntimeException('এই মাসের বেতন ইতিমধ্যে দেওয়া হয়ে গেছে।');
        }
        // Money floor (Hunt Y4 F8): a run whose total is negative can
        // never be paid - paying one posted a NEGATIVE expense into
        // Finance, inflating every expense aggregate. Checked in the
        // service as well as the item form, so no caller can pay one.
        if ((float) $run->total < 0) {
            throw new \RuntimeException(__('This payroll total is negative - fix the adjustments before paying.'));
        }
        $businessId = (int) $run->business_id;
        $this->finance->ensureCategories($businessId);
        $category = FinanceCategory::where('business_id', $businessId)->where('name', 'বেতন')->first();

        $key = 'payroll-run-'.$run->id;
        $txn = FinanceTransaction::where('business_id', $businessId)->where('idempotency_key', $key)->first();
        if (! $txn) {
            $txn = $this->finance->post($businessId, [
                'account_id' => $accountId,
                'direction' => 'out',
                'type' => 'expense',
                'amount' => (float) $run->total,
                'category_id' => $category?->id,
                'note' => 'Payroll - '.$run->month,
                'source' => 'payroll',
                'ref_type' => 'payroll_run',
                'ref_id' => $run->id,
                'idempotency_key' => $key,
            ], $actor);
        }

        $run->update([
            'status' => 'paid',
            'finance_transaction_id' => $txn->id,
            'paid_by' => $actor->id,
            'paid_at' => now('Asia/Dhaka'),
        ]);

        return $run;
    }
}

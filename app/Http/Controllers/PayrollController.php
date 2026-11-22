<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Payroll pages (M13). Built but DISABLED by default: every action sits
 * behind the hr.{biz}.payroll_enabled switch in HR Settings.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function guard(Request $request): ?RedirectResponse
    {
        if (! $this->payroll->enabled($this->biz($request))) {
            return redirect('/hrm')->with('error', 'Payroll এখন বন্ধ আছে - চালু করতে HR Settings-এ যান।');
        }

        return null;
    }

    private function canManage(Request $request): bool
    {
        $u = $request->user();

        return $u->is_platform_admin || in_array($u->role, ['admin', 'accountant'], true);
    }

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $biz = $this->biz($request);
        $month = $request->query('month', substr(now('Asia/Dhaka')->toDateString(), 0, 7));
        $run = PayrollRun::where('business_id', $biz)->where('month', $month)
            ->with('items.user')->first();

        return Inertia::render('Hrm/Payroll', [
            'month' => $month,
            'run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'total' => (float) $run->total,
                'items' => $run->items->map(fn (PayrollItem $i) => [
                    'id' => $i->id,
                    'name' => $i->user?->name,
                    'basic' => (float) $i->basic,
                    'working_days' => (int) $i->working_days,
                    'absent_days' => (int) $i->absent_days,
                    'absent_cut' => (float) $i->absent_cut,
                    'advance_cut' => (float) $i->advance_cut,
                    'other_adjust' => (float) $i->other_adjust,
                    'net' => (float) $i->net,
                ])->all(),
            ] : null,
            'runs' => PayrollRun::where('business_id', $biz)->orderByDesc('month')->limit(12)->get()
                ->map(fn (PayrollRun $r) => ['month' => $r->month, 'total' => (float) $r->total, 'status' => $r->status])->all(),
            'accounts' => FinanceAccount::where('business_id', $biz)->orderBy('name')->get()
                ->map(fn (FinanceAccount $a) => ['id' => $a->id, 'name' => $a->name])->all(),
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function build(Request $request): RedirectResponse
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        if (! $this->canManage($request)) {
            return back()->with('error', 'এই কাজ করার permission নেই।');
        }
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        try {
            $this->payroll->buildRun($this->biz($request), $data['month'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/hrm/payroll?month='.$data['month'])->with('success', 'এই মাসের বেতনের হিসাব তৈরি হয়েছে।');
    }

    public function updateItem(Request $request, PayrollItem $item): RedirectResponse
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        $run = $item->run;
        if (! $this->canManage($request) || ! $run || $run->business_id !== $this->biz($request)) {
            return back()->with('error', 'এই কাজ করার permission নেই।');
        }
        if ($run->status === 'paid') {
            return back()->with('error', 'বেতন দেওয়া হয়ে গেছে - আর বদলানো যাবে না।');
        }
        $data = $request->validate([
            'advance_cut' => 'nullable|numeric|min:0',
            'other_adjust' => 'nullable|numeric',
        ]);
        $item->advance_cut = $data['advance_cut'] ?? 0;
        $item->other_adjust = $data['other_adjust'] ?? 0;
        $item->recomputeNet();
        $item->save();
        $this->payroll->refreshTotal($run);

        return back()->with('success', 'হিসাব বদলানো হয়েছে।');
    }

    public function pay(Request $request, PayrollRun $run): RedirectResponse
    {
        if ($deny = $this->guard($request)) {
            return $deny;
        }
        if (! $this->canManage($request) || $run->business_id !== $this->biz($request)) {
            return back()->with('error', 'এই কাজ করার permission নেই।');
        }
        $data = $request->validate(['account_id' => 'required|integer']);
        $account = FinanceAccount::where('business_id', $this->biz($request))->find($data['account_id']);
        if (! $account) {
            return back()->with('error', 'Account পাওয়া যায়নি।');
        }
        try {
            $this->payroll->markPaid($run, $account->id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'বেতন দেওয়া হয়েছে - Finance-এ খরচ entry হয়েছে।');
    }
}

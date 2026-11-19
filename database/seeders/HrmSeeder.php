<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Business;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Sop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * M13 HRM seed: roles for the existing owners, the Demo Shop team from the
 * prototype, departments, shifts, today's attendance, SOPs, leave requests
 * and holidays. Other Shop gets an owner role + one empty department.
 */
class HrmSeeder extends Seeder
{
    public function run(): void
    {
        $demo = Business::where('public_id', 'demo-shop')->first();
        $other = Business::where('public_id', 'other-shop')->first();
        $today = Carbon::now('Asia/Dhaka');

        // Owners become Admin of their own shop.
        foreach (['admin@skaleup.test', 'tenant2@skaleup.test', 'platform@skaleup.test'] as $email) {
            User::where('email', $email)->update(['role' => 'admin']);
        }
        User::where('email', 'admin@skaleup.test')->update(['finance_access' => 'full']);
        User::where('email', 'tenant2@skaleup.test')->update(['finance_access' => 'full']);

        if (! $demo) {
            return;
        }
        $biz = $demo->id;

        $mk = function (string $name, string $email, string $role, ?array $perms, float $salary) use ($biz) {
            $u = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password', 'email_verified_at' => now(), 'business_id' => $biz]
            );
            $u->role = $role;
            $u->permissions = $perms;
            $u->monthly_salary = $salary;
            $u->staff_active = true;
            $u->finance_access = in_array($role, ['admin', 'accountant'], true) ? 'full' : 'entry';
            $u->save();

            return $u;
        };

        $arif = $mk('Arif Hossain', 'arif@skaleup.test', 'manager', null, 25000);
        $sumon = $mk('Sumon Mia', 'sumon@skaleup.test', 'staff', ['dashboard', 'orders', 'delivery', 'hrm'], 15000);
        $nabila = $mk('Nabila Rahman', 'nabila@skaleup.test', 'staff', ['dashboard', 'messages', 'customers', 'hrm'], 16000);
        $tanvir = $mk('Tanvir Ahmed', 'tanvir@skaleup.test', 'staff', ['dashboard', 'orders', 'delivery', 'hrm'], 14000);
        $riya = $mk('Riya Chowdhury', 'riya@skaleup.test', 'accountant', null, 18000);
        $imran = $mk('Imran Khan', 'imran@skaleup.test', 'staff', ['dashboard', 'messages', 'hrm'], 15000);

        // Departments (prototype)
        $ops = Department::updateOrCreate(['business_id' => $biz, 'name' => 'Operations'], ['head_user_id' => $arif->id]);
        $ops->members()->sync([$arif->id, $sumon->id, $tanvir->id]);
        $msg = Department::updateOrCreate(['business_id' => $biz, 'name' => 'Messages ও Support'], ['head_user_id' => $nabila->id]);
        $msg->members()->sync([$nabila->id, $imran->id]);
        $acc = Department::updateOrCreate(['business_id' => $biz, 'name' => 'Accounts'], ['head_user_id' => $riya->id]);
        $acc->members()->sync([$riya->id]);

        // Shifts (prototype)
        $morning = Shift::updateOrCreate(['business_id' => $biz, 'name' => 'সকালের Shift'], ['start_time' => '09:00', 'end_time' => '18:00']);
        $morning->members()->sync([$arif->id, $sumon->id, $tanvir->id, $riya->id]);
        $noon = Shift::updateOrCreate(['business_id' => $biz, 'name' => 'দুপুরের Shift'], ['start_time' => '12:00', 'end_time' => '21:00']);
        $noon->members()->sync([$nabila->id, $imran->id]);

        // SOPs (prototype titles)
        $sops = [
            ['নতুন Order এলে কী করবে', $ops->id, ['Order List-এ নতুন Order খোলো', 'Customer-কে call করে confirm করো', 'ঠিকানা ভালো করে মিলিয়ে নাও', 'Processing-এ পাঠাও']],
            ['Parcel Packing নিয়ম', $ops->id, ['Product ঠিক আছে কিনা দেখো', 'ভাঙা যায় এমন হলে bubble wrap দাও', 'Invoice ভিতরে দাও', 'Packing slip বাইরে লাগাও']],
            ['Customer Complaint সামলানো', $msg->id, ['আগে শোনো, মাঝখানে থামিয়ো না', 'দুঃখ প্রকাশ করো', 'সমস্যাটা নিজের ভাষায় ফিরিয়ে বলো', 'সমাধান দাও বা বড় কাউকে জানাও']],
            ['Return Parcel এলে করণীয়', $ops->id, ['Parcel খুলে Product মেলাও', 'ছবি তুলে রাখো', 'Stock-এ ফেরত নাও', 'কারণটা note করে রাখো']],
        ];
        foreach ($sops as [$title, $deptId, $steps]) {
            Sop::updateOrCreate(['business_id' => $biz, 'title' => $title], ['department_id' => $deptId, 'steps' => $steps]);
        }

        // Today's attendance (prototype times; Imran is on approved leave)
        $at = fn (string $time) => Carbon::parse($today->toDateString().' '.$time, 'Asia/Dhaka');
        $seed = [
            [$arif, '09:04', 'present', 4],
            [$sumon, '09:12', 'present', 12],
            [$nabila, '12:21', 'late', 21],
            [$tanvir, '08:58', 'present', 0],
            [$riya, '09:02', 'present', 2],
        ];
        foreach ($seed as [$user, $time, $status, $late]) {
            $rec = AttendanceRecord::where('user_id', $user->id)->whereDate('date', $today->toDateString())->first();
            $vals = ['business_id' => $biz, 'check_in_at' => $at($time), 'status' => $status, 'late_minutes' => $late];
            if ($rec) { $rec->update($vals); } else { AttendanceRecord::create(['user_id' => $user->id, 'date' => $today->toDateString()] + $vals); }
        }

        // Imran: approved leave today (+ the materialised leave record)
        $imranLeave = LeaveRequest::where('business_id', $biz)->where('user_id', $imran->id)->whereDate('from_date', $today->toDateString())->first();
        $leaveVals = ['to_date' => $today->toDateString(), 'reason' => 'পারিবারিক কাজ', 'status' => 'approved', 'decided_at' => $today];
        if ($imranLeave) { $imranLeave->update($leaveVals); } else { LeaveRequest::create(['business_id' => $biz, 'user_id' => $imran->id, 'from_date' => $today->toDateString()] + $leaveVals); }
        $imranRec = AttendanceRecord::where('user_id', $imran->id)->whereDate('date', $today->toDateString())->first();
        if ($imranRec) { $imranRec->update(['status' => 'leave', 'check_in_at' => null]); } else { AttendanceRecord::create(['business_id' => $biz, 'user_id' => $imran->id, 'date' => $today->toDateString(), 'status' => 'leave']); }

        // Pending leave requests (dashboard approval cards)
        $pendingSeed = function (User $u, string $from, string $to, string $reason) use ($biz) {
            $ex = LeaveRequest::where('business_id', $biz)->where('user_id', $u->id)->whereDate('from_date', $from)->first();
            $vals = ['to_date' => $to, 'reason' => $reason, 'status' => 'pending'];
            if ($ex) { $ex->update($vals); } else { LeaveRequest::create(['business_id' => $biz, 'user_id' => $u->id, 'from_date' => $from] + $vals); }
        };
        $pendingSeed($sumon, $today->copy()->addDays(3)->toDateString(), $today->copy()->addDays(3)->toDateString(), 'পারিবারিক কাজ');
        $pendingSeed($riya, $today->copy()->addDays(9)->toDateString(), $today->copy()->addDays(11)->toDateString(), 'অসুস্থতা');

        // Holidays
        foreach ([['2026-12-16', 'বিজয় দিবস'], ['2026-12-25', 'বড়দিন']] as [$hDate, $hName]) {
            if (! Holiday::where('business_id', $biz)->whereDate('date', $hDate)->exists()) {
                Holiday::create(['business_id' => $biz, 'date' => $hDate, 'name' => $hName]);
            }
        }

        if ($other) {
            Department::updateOrCreate(['business_id' => $other->id, 'name' => 'General'], []);
        }
    }
}

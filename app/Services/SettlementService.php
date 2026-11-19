<?php

namespace App\Services;

use App\Models\CourierMethod;
use App\Models\CourierPayment;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementAllocation;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkEvent;
use App\Services\Couriers\DriverFactory;
use Illuminate\Support\Collection;

/**
 * Courier money (§5.3c, §10.7c). Definitions used everywhere in M3:
 *  - Collected by courier  = Σ COD of Delivered parcels (what the courier took from customers)
 *  - Expected per parcel   = COD − the courier's committed charge (Settings rate card, zone by district)
 *  - Settled to you        = Σ over allocations of (net amount + that parcel's committed charge)
 *  - Still with courier    = Collected − Settled
 * Settlement data is pulled via API or matched from an upload / one-line entry — never hand-typed per parcel.
 */
class SettlementService
{
    public function committedCharge(Order $order): float
    {
        return $order->courierMethod ? $order->courierMethod->chargeFor($order->effectiveDistrict()) : (float) $order->delivery_charge;
    }

    public function expectedNet(Order $order): float
    {
        return round($order->codAmount() - $this->committedCharge($order), 2);
    }

    public function settledNet(Order $order): float
    {
        return (float) CourierSettlementAllocation::where('order_id', $order->id)->sum('amount');
    }

    /** Delivered parcels with a courier that still owe money (or were short-paid). */
    public function receivables(int $businessId, ?int $methodId = null): Collection
    {
        $orders = Order::with('courierMethod')
            ->where('business_id', $businessId)
            ->where('status', 'Delivered')
            ->whereNotNull('courier_method_id')
            ->when($methodId, fn ($q) => $q->where('courier_method_id', $methodId))
            ->orderBy('delivered_at')
            ->get();

        return $orders->map(function (Order $o) {
            $expected = $this->expectedNet($o);
            $settled = $this->settledNet($o);
            $days = $o->delivered_at ? (int) $o->delivered_at->diffInDays(now()) : 0;

            return [
                'order' => $o,
                'cod' => $o->codAmount(),
                'committed_charge' => $this->committedCharge($o),
                'expected' => $expected,
                'settled' => $settled,
                'difference' => round($settled - $expected, 2),
                'days' => $days,
                'bucket' => $days <= 7 ? '0–7' : ($days <= 15 ? '8–15' : ($days <= 30 ? '16–30' : '31+')),
                'unsettled' => $settled < $expected - 0.01,
            ];
        })->filter(fn ($r) => $r['unsettled'] || abs($r['difference']) > 0.01)->values();
    }

    /** The three ledger numbers, per method and combined. */
    public function ledger(int $businessId): array
    {
        $methods = CourierMethod::where('business_id', $businessId)->orderBy('name')->get();
        $rows = [];
        $total = ['collected' => 0.0, 'settled' => 0.0, 'still' => 0.0];
        foreach ($methods as $m) {
            $delivered = Order::where('business_id', $businessId)->where('status', 'Delivered')->where('courier_method_id', $m->id)->get();
            $collected = $delivered->sum(fn (Order $o) => $o->codAmount());
            $settled = 0.0;
            foreach (CourierSettlementAllocation::whereHas('settlement', fn ($q) => $q->where('courier_method_id', $m->id))->with('order')->get() as $a) {
                $settled += (float) $a->amount + ($a->order ? $this->committedCharge($a->order) : 0);
            }
            $row = ['method' => $m, 'collected' => round($collected, 2), 'settled' => round($settled, 2), 'still' => round($collected - $settled, 2)];
            $rows[] = $row;
            $total['collected'] += $row['collected'];
            $total['settled'] += $row['settled'];
            $total['still'] += $row['still'];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Oldest-first proposed allocation of a payment across unpaid delivered parcels. */
    public function proposeAllocation(CourierMethod $method, float $amount): array
    {
        $remaining = $amount;
        $proposal = [];
        foreach ($this->receivables($method->business_id, $method->id)->where('unsettled', true) as $r) {
            if ($remaining <= 0) {
                break;
            }
            $due = round($r['expected'] - $r['settled'], 2);
            $give = min($due, $remaining);
            if ($give > 0) {
                $proposal[] = ['order_id' => $r['order']->id, 'invoice' => $r['order']->invoice, 'amount' => round($give, 2), 'expected' => $r['expected']];
                $remaining = round($remaining - $give, 2);
            }
        }

        return ['allocations' => $proposal, 'leftover' => $remaining];
    }

    /** Record a one-line payment (Class B/C) with staff-confirmed allocations. */
    public function recordPayment(CourierMethod $method, float $amount, string $date, ?string $reference, ?string $depositAccount, array $allocations, User $actor): CourierPayment
    {
        $settlement = CourierSettlement::create([
            'business_id' => $method->business_id, 'courier_method_id' => $method->id,
            'amount' => $amount, 'reference' => $reference, 'source' => 'payment_entry',
            'status_label' => 'Recorded', 'paid_at' => $date,
        ]);
        $sum = 0.0;
        foreach ($allocations as $a) {
            $order = Order::where('business_id', $method->business_id)->find($a['order_id']);
            if (! $order) {
                continue;
            }
            CourierSettlementAllocation::create(['settlement_id' => $settlement->id, 'order_id' => $order->id, 'amount' => $a['amount']]);
            $sum += (float) $a['amount'];
        }
        $payment = CourierPayment::create([
            'business_id' => $method->business_id, 'courier_method_id' => $method->id,
            'amount' => $amount, 'date' => $date, 'reference' => $reference,
            'deposit_account' => $depositAccount,
            'status' => abs($sum - $amount) < 0.01 ? 'allocated' : 'difference',
            'settlement_id' => $settlement->id,
        ]);
        if (abs($sum - $amount) >= 0.01) {
            $this->raiseDifferenceEvent($method, 'A recorded payment of ৳' . number_format($amount) . ' could not be fully matched to delivered parcels (৳' . number_format(abs($sum - $amount)) . ' left over).');
        }
        $payment->setRelation('method', $method);
        app(FinanceService::class)->syncCourierPayment($payment);

        return $payment;
    }

    /**
     * Match uploaded statement lines (invoice → paid net amount).
     * Preview mode returns matched/unmatched/duplicate without writing.
     */
    public function matchUpload(int $businessId, array $lines, bool $commit, User $actor, string $source = 'upload', ?string $reference = null): array
    {
        $matched = [];
        $unmatched = [];
        $duplicates = [];
        $seen = [];
        foreach ($lines as $line) {
            $invoice = trim((string) ($line['invoice'] ?? ''));
            $paid = (float) ($line['paid_amount'] ?? 0);
            if ($invoice === '') {
                continue;
            }
            if (isset($seen[$invoice])) {
                $duplicates[] = ['invoice' => $invoice, 'paid_amount' => $paid, 'reason' => 'Same invoice twice in this file'];
                continue;
            }
            $seen[$invoice] = true;
            $order = Order::where('business_id', $businessId)->where('invoice_no', $invoice)->with('courierMethod')->first();
            if (! $order || ! $order->courier_method_id) {
                $unmatched[] = ['invoice' => $invoice, 'paid_amount' => $paid, 'reason' => 'No booked order with this invoice'];
                continue;
            }
            $already = $this->settledNet($order);
            if ($already >= $this->expectedNet($order) - 0.01 && $already > 0) {
                $duplicates[] = ['invoice' => $invoice, 'paid_amount' => $paid, 'reason' => 'This parcel is already settled'];
                continue;
            }
            $matched[] = ['invoice' => $invoice, 'paid_amount' => $paid, 'order_id' => $order->id, 'method_id' => $order->courier_method_id, 'expected' => $this->expectedNet($order)];
        }

        if ($commit) {
            $byMethod = collect($matched)->groupBy('method_id');
            foreach ($byMethod as $methodId => $group) {
                $method = CourierMethod::find($methodId);
                $settlement = CourierSettlement::create([
                    'business_id' => $businessId, 'courier_method_id' => $methodId,
                    'amount' => $group->sum('paid_amount'), 'source' => $source,
                    'reference' => $reference,
                    'status_label' => $source === 'manual' ? 'Manual entry' : 'Statement upload', 'paid_at' => now(),
                ]);
                foreach ($group as $m) {
                    CourierSettlementAllocation::create(['settlement_id' => $settlement->id, 'order_id' => $m['order_id'], 'amount' => $m['paid_amount']]);
                    if ($method && $m['paid_amount'] < $m['expected'] - 0.01) {
                        $this->raiseDifferenceEvent($method, 'Statement paid ৳' . number_format($m['paid_amount']) . ' for ' . $m['invoice'] . ' but ৳' . number_format($m['expected']) . ' was expected — short by ৳' . number_format($m['expected'] - $m['paid_amount']) . '.');
                    }
                }
            }
            if ($unmatched) {
                $this->raiseDifferenceEvent(null, count($unmatched) . ' statement line(s) could not be matched to any order.');
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched, 'duplicates' => $duplicates];
    }

    /** Class A: pull Steadfast payouts and allocate parcel by parcel. */
    public function pullSettlements(CourierMethod $method, User $actor): array
    {
        $driver = DriverFactory::for($method);
        $created = 0;
        $allocated = 0;
        foreach ($driver->getPayments($method) as $p) {
            if (CourierSettlement::where('courier_method_id', $method->id)->where('payment_id', $p['payment_id'])->exists()) {
                continue;
            }
            $settlement = CourierSettlement::create([
                'business_id' => $method->business_id, 'courier_method_id' => $method->id,
                'payment_id' => $p['payment_id'], 'amount' => $p['amount'] ?? 0, 'method' => $p['method'] ?? null,
                'due_bills' => $p['due_bills'] ?? null, 'paid_bills' => $p['paid_bills'] ?? null,
                'charges' => $p['charges'] ?? null, 'total' => $p['total'] ?? null,
                'status_label' => $p['status_label'] ?? null, 'source' => 'api',
                'paid_at' => $p['paid_at'] ?? null, 'ready_at' => $p['ready_at'] ?? null,
            ]);
            $created++;
            foreach ($driver->getPaymentConsignments($method, $p['payment_id']) as $c) {
                $order = Order::where('business_id', $method->business_id)->where('invoice_no', $c['invoice'])->first();
                if (! $order) {
                    continue;
                }
                $amount = $c['amount'] ?? $this->expectedNet($order);
                CourierSettlementAllocation::create([
                    'settlement_id' => $settlement->id, 'order_id' => $order->id,
                    'amount' => $amount,
                ]);
                $allocated++;
                if ($amount < $this->expectedNet($order) - 0.01) {
                    $this->raiseDifferenceEvent($method, 'Payout ' . $p['payment_id'] . ' paid ৳' . number_format($amount) . ' for ' . $order->invoice . ' but ৳' . number_format($this->expectedNet($order)) . ' was expected.');
                }
            }
        }

        return ['payments' => $created, 'parcels' => $allocated];
    }

    public function raiseDifferenceEvent(?CourierMethod $method, string $body): void
    {
        WorkEvent::create([
            'business_id' => $method?->business_id ?? auth()->user()?->business_id,
            'kind' => 'settlement_difference',
            'title' => 'Courier money difference' . ($method ? ' — ' . $method->label() : ''),
            'body' => $body,
            'link' => '/delivery/invoice',
        ]);
    }
}

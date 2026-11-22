<?php

namespace App\Http\Controllers;

use App\Models\CourierMethod;
use App\Models\CourierPayment;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementAllocation;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Support\DhakaTime;

class CourierLedgerController extends Controller
{
    public function __construct(private SettlementService $settlements) {}

    private function biz(Request $request): int
    {
        return $request->user()->business_id;
    }

    private function settlementRows(int $biz)
    {
        return CourierSettlement::with(['courierMethod', 'allocations.order'])
            ->where('business_id', $biz)->latest()->limit(100)->get()
            ->map(fn (CourierSettlement $s) => [
                'id' => $s->id, 'courier' => $s->courierMethod?->label(), 'payment_id' => $s->payment_id,
                'amount' => (float) $s->amount, 'method' => $s->method, 'reference' => $s->reference,
                'source' => $s->source, 'status_label' => $s->status_label,
                'date' => DhakaTime::fmt($s->paid_at ?? $s->created_at, 'd M Y'),
                'parcels' => $s->allocations->map(fn ($a) => ['invoice' => $a->order?->invoice, 'amount' => (float) $a->amount, 'allocation_id' => $a->id, 'disputed' => $a->disputed])->all(),
            ])->all();
    }

    /** Receivable / bucket / difference data — displayed on the Ledger page (its one home). */
    private function moneyProps(int $biz): array
    {
        $receivables = $this->settlements->receivables($biz);
        $buckets = ['0–7' => 0.0, '8–15' => 0.0, '16–30' => 0.0, '31+' => 0.0];
        foreach ($receivables->where('unsettled', true) as $r) {
            $buckets[$r['bucket']] += $r['expected'] - $r['settled'];
        }

        return [
            'receivables' => $receivables->map(fn ($r) => [
                'invoice' => $r['order']->invoice, 'courier' => $r['order']->courierMethod?->label(),
                'cod' => $r['cod'], 'committed_charge' => $r['committed_charge'], 'expected' => $r['expected'],
                'settled' => $r['settled'], 'difference' => $r['difference'], 'bucket' => $r['bucket'],
                'unsettled' => $r['unsettled'],
            ])->all(),
            'buckets' => $buckets,
            // Short-paid only: a parcel that was paid something but less than expected.
            // Never-paid parcels are owed money — they live in COD Receivable, not here.
            'differences' => $receivables->filter(fn ($r) => $r['difference'] < -0.01 && $r['settled'] > 0)->map(fn ($r) => [
                'invoice' => $r['order']->invoice, 'courier' => $r['order']->courierMethod?->label(),
                'type' => 'COD Difference', 'severity' => $r['difference'] < -500 ? 'High' : ($r['difference'] < -100 ? 'Medium' : 'Low'),
                'expected' => $r['expected'], 'actual' => $r['settled'], 'difference' => $r['difference'],
            ])->values()->all(),
        ];
    }

    public function ledger(Request $request)
    {
        $biz = $this->biz($request);
        $data = $this->settlements->ledger($biz);
        $methods = CourierMethod::where('business_id', $biz)->orderBy('name')->get();

        return Inertia::render('Delivery/Ledger', [
            'rows' => collect($data['rows'])->map(fn ($r) => [
                'courier' => $r['method']->label(), 'class' => $r['method']->courierClass(),
                'company' => \App\Models\CourierMethod::LABELS[$r['method']->courier_type] ?? $r['method']->label(),
                'manual' => $r['method']->courier_type === 'OTHERS',
                'delivered' => \App\Models\Order::where('business_id', $biz)->where('courier_method_id', $r['method']->id)->where('status', 'Delivered')->count(),
                'collected' => $r['collected'], 'settled' => $r['settled'], 'still' => $r['still'],
            ])->all(),
            'total' => $data['total'],
            'ratecard' => $methods->map(fn (CourierMethod $m) => [
                'id' => $m->id, 'label' => $m->label(), 'class' => $m->courierClass(),
                'charge_inside' => (float) $m->charge_inside_dhaka, 'charge_outside' => (float) $m->charge_outside_dhaka,
            ])->all(),
            ...$this->moneyProps($biz),
        ]);
    }

    public function workbench(Request $request)
    {
        $biz = $this->biz($request);
        $methods = CourierMethod::where('business_id', $biz)->orderBy('name')->get();
        $money = $this->moneyProps($biz);

        return Inertia::render('Delivery/InvoiceUpload', [
            'methods' => $methods->map(fn (CourierMethod $m) => [
                'id' => $m->id, 'label' => $m->label(), 'class' => $m->courierClass(), 'is_active' => $m->is_active,
            ])->all(),
            'history' => $this->settlementRows($biz),
            'payments' => CourierPayment::with('method')->where('business_id', $biz)->latest()->limit(50)->get()
                ->map(fn (CourierPayment $p) => [
                    'id' => $p->id, 'courier' => $p->method?->label(), 'amount' => (float) $p->amount,
                    'date' => $p->date?->format('d M Y'), 'reference' => $p->reference,
                    'deposit_account' => $p->deposit_account, 'status' => $p->status,
                ])->all(),
            // Lookup data for the Search & Add card (the receivable display itself lives on the Ledger page).
            'receivables' => $money['receivables'],
        ]);
    }

    /** CSV statement preview — parses the file and matches lines without writing. */
    public function uploadPreview(Request $request)
    {
        $request->validate(['file' => 'required|file|max:2048']);
        $name = strtolower($request->file('file')->getClientOriginalName());
        if (str_ends_with($name, '.pdf')) {
            return response()->json(['ok' => false, 'error' => 'PDF is not accepted — upload the CSV or XLSX statement from the courier'], 422);
        }
        $lines = $this->parseStatement($request->file('file')->getRealPath());
        if ($lines === null) {
            return response()->json(['ok' => false, 'error' => 'Could not read the file — the statement needs invoice and paid amount columns'], 422);
        }
        $result = $this->settlements->matchUpload($this->biz($request), $lines, false, $request->user());

        return response()->json(['ok' => true, 'lines' => $lines] + $result);
    }

    public function uploadConfirm(Request $request)
    {
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.invoice' => 'required|string',
            'lines.*.paid_amount' => 'required|numeric',
            'source' => 'nullable|in:upload,manual',
            'deposit_account' => 'nullable|string|max:120',
        ]);
        $reference = ! empty($data['deposit_account']) ? 'Deposit: ' . $data['deposit_account'] : null;
        $result = $this->settlements->matchUpload($this->biz($request), $data['lines'], true, $request->user(), $data['source'] ?? 'upload', $reference);

        return back()->with('success', 'Statement saved - ' . count($result['matched']) . ' parcel(s) settled' . (count($result['unmatched']) ? ', ' . count($result['unmatched']) . ' line(s) did not match' : ''));
    }

    private function parseStatement(string $path): ?array
    {
        // XLSX is a zip of XML — read the first sheet + shared strings directly.
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $shared = [];
            if (($sx = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($sx);
                foreach ($xml->si as $si) {
                    $shared[] = (string) ($si->t ?? collect($si->xpath('.//*[local-name()="t"]'))->map(fn ($t) => (string) $t)->implode(''));
                }
            }
            $sheetName = $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
            $sheetXml = $sheetName ? $zip->getFromName($sheetName) : false;
            $zip->close();
            if ($sheetXml !== false) {
                $xml = simplexml_load_string($sheetXml);
                $rows = [];
                foreach ($xml->sheetData->row as $row) {
                    $cells = [];
                    foreach ($row->c as $c) {
                        $v = isset($c->v) ? (string) $c->v : '';
                        if ((string) $c['t'] === 's' && $v !== '') {
                            $v = $shared[(int) $v] ?? $v;
                        } elseif ((string) $c['t'] === 'inlineStr') {
                            $v = (string) ($c->is->t ?? '');
                        }
                        $cells[] = $v;
                    }
                    $rows[] = $cells;
                }

                return $this->linesFromRows($rows);
            }
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            return null;
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $this->linesFromRows($rows);
    }

    /** @param array<int, array<int, string>> $rows */
    private function linesFromRows(array $rows): ?array
    {
        if (count($rows) < 2) {
            return null;
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $invIdx = null;
        $amtIdx = null;
        foreach ($header as $i => $h) {
            if (str_contains($h, 'invoice')) {
                $invIdx = $i;
            }
            if (str_contains($h, 'paid') || $h === 'amount' || str_contains($h, 'amount')) {
                $amtIdx = $i;
            }
        }
        if ($invIdx === null || $amtIdx === null) {
            return null;
        }
        $lines = [];
        foreach (array_slice($rows, 1) as $row) {
            $invoice = trim((string) ($row[$invIdx] ?? ''));
            $amount = preg_replace('/[^0-9.\-]/', '', (string) ($row[$amtIdx] ?? ''));
            if ($invoice !== '' && is_numeric($amount)) {
                $lines[] = ['invoice' => $invoice, 'paid_amount' => (float) $amount];
            }
        }

        return $lines;
    }

    public function proposePayment(Request $request)
    {
        $data = $request->validate(['method_id' => 'required|integer', 'amount' => 'required|numeric|min:1']);
        $method = CourierMethod::where('business_id', $this->biz($request))->findOrFail($data['method_id']);

        return response()->json($this->settlements->proposeAllocation($method, (float) $data['amount']));
    }

    public function storePayment(Request $request)
    {
        $data = $request->validate([
            'method_id' => 'required|integer',
            'amount' => 'required|numeric|min:1',
            'date' => 'required|date',
            'reference' => 'nullable|string|max:120',
            'deposit_account' => 'nullable|string|max:120',
            'allocations' => 'required|array|min:1',
            'allocations.*.order_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|min:0',
        ]);
        $method = CourierMethod::where('business_id', $this->biz($request))->findOrFail($data['method_id']);
        $this->settlements->recordPayment($method, (float) $data['amount'], $data['date'], $data['reference'] ?? null, $data['deposit_account'] ?? null, $data['allocations'], $request->user());

        return back()->with('success', 'Payment recorded and matched to parcels');
    }

    public function pull(Request $request)
    {
        $data = $request->validate(['method_id' => 'required|integer']);
        $method = CourierMethod::where('business_id', $this->biz($request))->findOrFail($data['method_id']);
        if ($method->courierClass() !== 'A') {
            return back()->with('error', 'Only Steadfast (Class A) pays out through the API - other couriers use statement upload or a payment entry');
        }
        $result = $this->settlements->pullSettlements($method, $request->user());

        return back()->with('success', "Pulled {$result['payments']} new payout(s), {$result['parcels']} parcel(s) matched");
    }

    public function markDisputed(Request $request, CourierSettlementAllocation $allocation)
    {
        $allocation->update(['disputed' => true]);

        return back()->with('success', 'Marked as disputed');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\DhakaTime;
use App\Models\CustomerUpload;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M7 Customers (seller-facing): the logged-in seller's own customers —
 * the people who buy from their shop.
 */
class CustomersController extends Controller
{
    private function bizId(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    private function aggregates(int $bizId, Customer $c): array
    {
        $orders = Order::where('business_id', $bizId)->where('customer_id', $c->id);
        $total = (clone $orders)->count();
        $delivered = (clone $orders)->where('status', 'Delivered')->count();
        $returned = (clone $orders)->where('status', 'Returned')->count();
        $outcomes = $delivered + $returned;

        return [
            'orders' => $total,
            'delivered' => $delivered,
            // One definition everywhere (same as SuccessRateService and the
            // Customer Report): success is decided outcomes only — a customer
            // whose parcels are still in transit has NO rate yet, not 0%.
            // Spent is Delivered-only — the settled definition, identical to
            // Customer::lifetimeValue(), the inbox customer pane and the
            // Customer Report's "spent".
            'success_rate' => $outcomes > 0 ? (int) round($delivered / $outcomes * 100) : null,
            'spent' => (float) (clone $orders)->where('status', 'Delivered')->sum('grand_total'),
            'last_order' => DhakaTime::fmt((clone $orders)->latest('id')->first()?->created_at, 'd M Y'),
        ];
    }

    public function index(Request $request): Response
    {
        $bizId = $this->bizId($request);
        $q = trim((string) $request->query('q', ''));
        $customers = Customer::where('business_id', $bizId)
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            // Sort by the figure the list actually displays: TOTAL SPENT,
            // Delivered-only (the same sum aggregates() returns per row,
            // and the same definition as the profile page, the inbox pane
            // and the Customer Report).
            ->withSum(['orders as spent_live' => fn ($x) => $x->where('status', 'Delivered')], 'grand_total')
            ->orderByDesc('spent_live')->orderBy('phone')->get();
        $rows = $customers->map(fn ($c) => array_merge([
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'address' => $c->address,
            'district' => $c->district, 'blacklisted' => (bool) $c->is_blacklisted,
        ], $this->aggregates($bizId, $c)))->all();

        // S-F10 + X2.3: all three stat cards describe ONE scope - the
        // customers the list is showing (the filtered set; unfiltered
        // that is the whole shop) - and the order figures use the one
        // settled definition, Delivered orders only, identical to the
        // rows, Customer::lifetimeValue(), the inbox pane and the
        // Customer Report. The old cards mixed scopes under a search
        // (CLV divided the shop's whole revenue by the filtered count)
        // and counted booked value the rows did not show.
        $filteredOrders = Order::where('business_id', $bizId)
            ->whereIn('customer_id', $customers->pluck('id'))
            ->where('status', 'Delivered');
        $filteredCount = (clone $filteredOrders)->count();
        $filteredSum = (float) (clone $filteredOrders)->sum('grand_total');

        return Inertia::render('Customers/Index', [
            'customers' => $rows,
            'q' => $q,
            'stats' => [
                'total' => $customers->count(),
                'aov' => $filteredCount > 0 ? (int) round($filteredSum / $filteredCount) : 0,
                'clv' => $customers->count() > 0 ? (int) round($filteredSum / $customers->count()) : 0,
            ],
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        abort_unless($customer->business_id === $this->bizId($request), 404);
        $orders = Order::where('business_id', $customer->business_id)->where('customer_id', $customer->id)
            ->latest('id')->limit(50)->get()
            ->map(fn ($o) => [
                'id' => $o->id, 'invoice' => $o->invoice_no, 'status' => $o->status,
                'total' => (float) $o->grand_total, 'date' => DhakaTime::fmt($o->created_at, 'd M Y'),
            ])->all();

        return Inertia::render('Customers/Show', [
            'customer' => array_merge([
                'id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone,
                'email' => $customer->email, 'address' => $customer->address, 'district' => $customer->district,
                'area' => $customer->area, 'blacklisted' => (bool) $customer->is_blacklisted,
                'since' => DhakaTime::fmt($customer->created_at, 'd M Y'),
            ], $this->aggregates($customer->business_id, $customer)),
            'orders' => $orders,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:400'],
            'district' => ['nullable', 'string', 'max:80'],
        ]);
        if (! \App\Services\PhoneNormalizer::isValid($data['phone'])) {
            return back()->with('error', __('Enter a valid mobile number (01XXXXXXXXX)'));
        }
        // S-F5: store the number in the ONE canonical form every other
        // path keys on (order intake, block list): 01XXXXXXXXX. Saving
        // the raw input ("+880 1799-998888") made the duplicate check
        // miss the same person typed nationally, and order intake then
        // linked orders to a second row while this one sat orphaned.
        $data['phone'] = \App\Services\PhoneNormalizer::normalize($data['phone']);
        $exists = Customer::where('business_id', $this->bizId($request))->where('phone', $data['phone'])->exists();
        if ($exists) {
            return back()->with('error', __('A customer with this phone number already exists'));
        }
        Customer::create($data + ['business_id' => $this->bizId($request)]);

        return back()->with('success', __('Customer added'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->business_id === $this->bizId($request), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:400'],
            'district' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'max:120'],
        ]);
        $customer->update($data);

        return back()->with('success', __('Customer saved'));
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->business_id === $this->bizId($request), 404);
        if (Order::where('customer_id', $customer->id)->exists()) {
            return back()->with('error', __('This customer has orders - they cannot be deleted'));
        }
        $customer->delete();

        return redirect()->route('customers.index')->with('success', __('Customer deleted'));
    }

    public function toggleBlacklist(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->business_id === $this->bizId($request), 404);
        $customer->update(['is_blacklisted' => ! $customer->is_blacklisted]);

        return back()->with('success', $customer->is_blacklisted ? __('Customer blocked') : __('Customer unblocked'));
    }

    public function upload(Request $request): Response
    {
        return Inertia::render('Customers/Upload', [
            'history' => CustomerUpload::where('business_id', $this->bizId($request))->latest('id')->limit(20)->get()
                ->map(fn ($u) => ['id' => $u->id, 'file' => $u->file_name, 'imported' => (int) $u->imported, 'skipped' => (int) $u->skipped, 'status' => $u->status, 'date' => DhakaTime::fmt($u->created_at, 'd M Y')])->all(),
        ]);
    }

    public function uploadStore(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $file = $request->file('file');
        $bizId = $this->bizId($request);
        $imported = 0;
        $skipped = 0;
        $handle = fopen($file->getRealPath(), 'r');
        $first = true;
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if ($first) {
                $first = false;
                if (str_contains(strtolower($row[0] ?? ''), 'name')) {
                    continue; // header row
                }
            }
            $name = $row[0] ?? '';
            // S-F5: the same canonical normalization as manual entry
            // and order intake - the old code only stripped spaces, so
            // an imported "+880..." number never matched the person.
            $phone = \App\Services\PhoneNormalizer::normalize($row[1] ?? '');
            $address = $row[2] ?? null;
            if ($phone === '' || $name === '') {
                $skipped++;
                continue;
            }
            // Same BD mobile rule the order form enforces - an invalid
            // number is skipped and counted, never imported (junk numbers
            // poison fraud checks and SMS sends later).
            if (! \App\Services\PhoneNormalizer::isValid($phone)) {
                $skipped++;
                continue;
            }
            $exists = Customer::where('business_id', $bizId)->where('phone', $phone)->exists();
            if ($exists) {
                $skipped++;
                continue;
            }
            Customer::create(['business_id' => $bizId, 'name' => $name, 'phone' => $phone, 'address' => $address]);
            $imported++;
        }
        fclose($handle);
        CustomerUpload::create([
            'business_id' => $bizId, 'file_name' => $file->getClientOriginalName(),
            'imported' => $imported, 'skipped' => $skipped, 'status' => 'Done',
        ]);

        return back()->with('success', __('Imported :imported customers, skipped :skipped', ['imported' => $imported, 'skipped' => $skipped]));
    }

    public function sampleCsv(): HttpResponse
    {
        $csv = "Name,Phone,Address\nRahim Uddin,01712345678,\"House 5, Dhanmondi, Dhaka\"\nSharmin Akter,01898765432,\"Mirpur 10, Dhaka\"\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customers-sample.csv"',
        ]);
    }
}

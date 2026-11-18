<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Package;
use App\Models\SellerPayment;
use App\Models\SellerSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform (Super Admin) — the sellers are the shops that buy SkaleUp's
 * service. Packages, subscriptions, payments received from sellers, and a
 * per-seller drill-down into the seller's own shop and customers.
 */
class PlatformController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) (auth()->user()?->is_platform_admin), 403);
    }

    private function currentSub(int $businessId): ?SellerSubscription
    {
        return SellerSubscription::where('business_id', $businessId)->with('package')
            ->orderByDesc('id')->first();
    }

    private function sellerRow(Business $b): array
    {
        $sub = $this->currentSub($b->id);
        $orders = Order::where('business_id', $b->id);
        $live = $sub && $sub->isLive();

        return [
            'id' => $b->id,
            'name' => $b->name,
            'public_id' => $b->public_id,
            'owner_name' => $b->owner_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'address' => $b->address,
            'joined' => $b->created_at?->format('d M Y'),
            'package' => $sub?->package?->name,
            'package_price' => $sub ? (float) $sub->amount : null,
            'status' => $sub ? ($live ? 'active' : ($sub->status === 'cancelled' ? 'cancelled' : 'expired')) : 'none',
            'expires_at' => $sub?->expires_at?->format('d M Y'),
            'days_left' => $sub ? (int) today()->diffInDays($sub->expires_at, false) : null,
            'paid_total' => (float) SellerPayment::where('business_id', $b->id)->sum('amount'),
            'orders' => (clone $orders)->count(),
            'revenue' => (float) (clone $orders)->where('status', 'Delivered')->sum('grand_total'),
            'customers' => Customer::where('business_id', $b->id)->count(),
        ];
    }

    public function dashboard(): Response
    {
        $sellers = Business::orderBy('name')->get()->map(fn ($b) => $this->sellerRow($b));
        $payments = SellerPayment::with('business')->latest('paid_at')->limit(10)->get();
        $monthIncome = (float) SellerPayment::whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount');

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'sellers' => $sellers->count(),
                'active' => $sellers->where('status', 'active')->count(),
                'expired' => $sellers->where('status', 'expired')->count(),
                'income_total' => (float) SellerPayment::sum('amount'),
                'income_month' => $monthIncome,
                'expiring_soon' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->count(),
            ],
            'sellers' => $sellers->all(),
            'recentPayments' => $payments->map(fn ($p) => [
                'id' => $p->id, 'seller' => $p->business?->name, 'amount' => (float) $p->amount,
                'method' => $p->method, 'note' => $p->note, 'when' => $p->paid_at?->format('d M Y, h:i A'),
            ])->all(),
            'topSellers' => $sellers->sortByDesc('revenue')->take(5)->values()->all(),
            'expiring' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->values()->all(),
            'packages' => Package::where('active', true)->orderBy('sort')->get()->map(fn ($p) => [
                'name' => $p->name, 'price' => (float) $p->price,
                'sellers' => SellerSubscription::where('package_id', $p->id)->where('status', 'active')->distinct('business_id')->count('business_id'),
            ])->all(),
        ]);
    }

    public function sellers(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $sellers = Business::orderBy('name')
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('owner_name', 'like', "%{$q}%")))
            ->get()->map(fn ($b) => $this->sellerRow($b));

        return Inertia::render('Platform/Sellers', ['sellers' => $sellers->all(), 'q' => $q]);
    }

    public function sellerShow(Business $business): Response
    {
        $row = $this->sellerRow($business);
        $customers = Customer::where('business_id', $business->id)->orderByDesc('lifetime_value')->limit(50)->get()
            ->map(function ($c) use ($business) {
                $orders = Order::where('business_id', $business->id)->where('customer_id', $c->id);
                $total = (clone $orders)->count();
                $delivered = (clone $orders)->where('status', 'Delivered')->count();

                return [
                    'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'address' => $c->address,
                    'orders' => $total, 'delivered' => $delivered,
                    'spent' => (float) (clone $orders)->where('status', 'Delivered')->sum('grand_total'),
                    'last_order' => (clone $orders)->latest('id')->first()?->created_at?->format('d M Y'),
                ];
            })->all();

        return Inertia::render('Platform/SellerDetail', [
            'seller' => $row,
            'subscriptions' => SellerSubscription::where('business_id', $business->id)->with('package')->orderByDesc('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'package' => $s->package?->name ?? 'Custom', 'amount' => (float) $s->amount, 'starts_at' => $s->starts_at?->format('d M Y'), 'expires_at' => $s->expires_at?->format('d M Y'), 'status' => $s->isLive() ? 'active' : $s->status])->all(),
            'payments' => SellerPayment::where('business_id', $business->id)->latest('paid_at')->get()
                ->map(fn ($p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->note, 'when' => $p->paid_at?->format('d M Y, h:i A')])->all(),
            'packages' => Package::where('active', true)->orderBy('sort')->get(['id', 'name', 'price', 'duration_days'])->all(),
            'customers' => $customers,
        ]);
    }

    public function subscribe(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $package = Package::findOrFail($data['package_id']);
        $amount = $data['amount'] ?? (float) $package->price;
        $current = $this->currentSub($business->id);
        $start = ($current && $current->isLive()) ? $current->expires_at->copy()->addDay() : today();
        $sub = SellerSubscription::create([
            'business_id' => $business->id, 'package_id' => $package->id, 'amount' => $amount,
            'starts_at' => $start, 'expires_at' => $start->copy()->addDays((int) $package->duration_days - 1), 'status' => 'active',
        ]);
        if ($amount > 0) {
            SellerPayment::create([
                'business_id' => $business->id, 'subscription_id' => $sub->id, 'amount' => $amount,
                'method' => $data['method'], 'note' => $data['note'] ?? ($package->name . ' package'), 'paid_at' => now(),
            ]);
        }

        return back()->with('success', 'Package assigned and payment recorded');
    }

    public function storePayment(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        SellerPayment::create($data + ['business_id' => $business->id, 'paid_at' => now()]);

        return back()->with('success', 'Payment recorded');
    }

    public function updateSeller(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:400'],
        ]);
        $business->update($data);

        return back()->with('success', 'Seller details saved');
    }
}

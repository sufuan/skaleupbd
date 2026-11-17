<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderSource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $base = Order::where('business_id', $biz);
        $count = fn (string $s) => (clone $base)->where('status', $s)->count();

        $delivered = (clone $base)->where('status', 'Delivered')->with('items')->get();
        $revenue = (float) $delivered->sum('grand_total');
        $costs = (float) $delivered->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->cost * $i->qty) + (float) $o->delivery_charge);
        $profit = $revenue - $costs;

        $sourceLabels = OrderSource::where('business_id', $biz)->pluck('label', 'code');
        $sources = (clone $base)->selectRaw('source, count(*) as c, sum(grand_total) as v')->groupBy('source')->get()
            ->map(fn ($r) => ['label' => $sourceLabels[$r->source] ?? $r->source, 'count' => (int) $r->c, 'value' => (float) $r->v])
            ->sortByDesc('count')->values();

        $notSent = (clone $base)->whereIn('status', ['Pending', 'Processing'])->with('items')->latest()->limit(5)->get()
            ->map(fn ($o) => ['invoice' => $o->invoice_no, 'name' => $o->customer_name,
                'courier' => $o->delivery_method ? strtolower(strtok($o->delivery_method, ' ')) : '—', 'status' => $o->status]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'total' => (clone $base)->count(),
                'pending' => $count('Pending'),
                'incomplete' => $count('Incomplete'),
                'processing' => $count('Processing'),
                'shipped' => $count('Shipped'),
                'delivered' => $count('Delivered'),
                'returned' => $count('Returned'),
                'cancelled' => $count('Cancelled'),
                'approved' => (clone $base)->whereNotNull('approved_at')->count(),
                'revenue' => $revenue,
                'profit' => $profit,
                'created_today' => (clone $base)->whereDate('created_at', today())->count(),
                'sent' => $count('Shipped') + $count('Delivered'),
                'sources' => $sources,
                'courier_rows' => $notSent,
            ],
        ]);
    }
}

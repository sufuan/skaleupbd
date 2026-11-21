<?php

namespace App\Http\Controllers;

use App\Models\PointPack;
use App\Services\TokenService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M19 — the public pricing page. Renders LIVE from point_packs and the
 * pricing settings, so the page and Settings can never disagree.
 */
class PricingController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function index(): Response
    {
        $ppo = $this->tokens->pointsPerOrder();
        $map = fn (PointPack $p) => [
            'id' => $p->id, 'name' => $p->name, 'price_bdt' => (float) $p->price_bdt,
            'credits' => (int) $p->credits, 'validity_type' => $p->validity_type,
            'orders' => (int) floor($p->credits / max(1, $ppo)),
            'per_order' => round($p->rate() * $ppo, 2),
        ];
        $packs = PointPack::where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('Pricing', [
            'monthly' => $packs->where('validity_type', 'monthly')->map($map)->values()->all(),
            'wallets' => $packs->where('validity_type', 'yearly_wallet')->map($map)->values()->all(),
            'pointsPerOrder' => $ppo,
            'onboardingFee' => $this->tokens->onboardingFee(),
        ]);
    }
}

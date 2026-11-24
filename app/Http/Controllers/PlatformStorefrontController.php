<?php

namespace App\Http\Controllers;

use App\Models\Storefront;
use App\Models\StorefrontReport;
use App\Services\StorefrontService;
use App\Support\DhakaTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M18 — platform admin: every storefront, takedown/restore, custom
 * domain activation (after the cPanel alias is added by hand), and the
 * "report this page" queue.
 */
class PlatformStorefrontController extends Controller
{
    public function __construct(private readonly StorefrontService $svc) {}

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->is_platform_admin, 403);
    }

    public function index(Request $request): Response
    {
        $this->guard($request);
        $storefronts = Storefront::with('business:id,name')->latest('id')->get()->map(fn ($sf) => [
            'id' => $sf->id,
            'shop' => $sf->business?->name,
            'subdomain' => $sf->subdomain,
            'subdomainUrl' => $sf->subdomainUrl(),
            'customDomain' => $sf->custom_domain,
            'domainStatus' => $sf->domain_status,
            'status' => $sf->status,
            'orders' => $sf->orders()->count(),
            'openReports' => $sf->reports()->where('status', 'open')->count(),
            'adminNote' => $sf->admin_note,
        ])->all();
        $reports = StorefrontReport::with(['business:id,name', 'storefront:id,subdomain'])
            ->latest('id')->limit(100)->get()->map(fn ($r) => [
                'id' => $r->id,
                'shop' => $r->business?->name,
                'subdomain' => $r->storefront?->subdomain,
                'reason' => $r->reason,
                'contact' => $r->contact,
                'pageUrl' => $r->page_url,
                'status' => $r->status,
                'when' => DhakaTime::fmt($r->created_at, 'd M Y, h:i A'),
            ])->all();

        return Inertia::render('Platform/Storefronts', [
            'storefronts' => $storefronts,
            'reports' => $reports,
            'activeDomains' => $this->svc->activeDomainCount(),
            'domainCap' => $this->svc->domainCap(),
            'domainWarnAt' => $this->svc->domainWarnAt(),
        ]);
    }

    public function takedown(Request $request, Storefront $storefront)
    {
        $this->guard($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $storefront->status = 'taken_down';
        $storefront->admin_note = $data['note'] ?? null;
        $storefront->save();
        $this->svc->forgetHostCache($storefront);

        return back()->with('success', __('Storefront taken down.'));
    }

    public function restore(Request $request, Storefront $storefront)
    {
        $this->guard($request);
        $storefront->status = 'draft';
        $storefront->admin_note = null;
        $storefront->save();
        $this->svc->forgetHostCache($storefront);

        return back()->with('success', __('Storefront restored as a draft. The seller can publish it again.'));
    }

    /** The cPanel alias exists now → the domain goes live (cap enforced). */
    public function markDomainAdded(Request $request, Storefront $storefront)
    {
        $this->guard($request);
        if ($storefront->domain_status !== 'verified') {
            return back()->with('error', __('Only a verified domain can be activated.'));
        }
        if (! $this->svc->activationAllowed()) {
            return back()->with('error', __('The custom-domain cap (:cap) is reached. This seller goes on the waitlist.', ['cap' => $this->svc->domainCap()]));
        }
        $storefront->domain_status = 'active';
        $storefront->domain_added_at = now();
        $storefront->save();
        // Mirror onto the primary domain row (M18 merge §3.5).
        $primary = $storefront->primaryDomain();
        if ($primary) {
            $primary->status = 'active';
            $primary->added_at = now();
            $primary->save();
        }
        $this->svc->forgetHostCache($storefront);

        return back()->with('success', __('Custom domain is now active.'));
    }

    public function resolveReport(Request $request, StorefrontReport $report)
    {
        $this->guard($request);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:resolved,dismissed'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $report->status = $data['status'];
        $report->admin_note = $data['note'] ?? null;
        $report->save();

        return back()->with('success', __('Report :status.', ['status' => $data['status']]));
    }
}

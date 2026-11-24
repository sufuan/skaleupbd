<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\PlatformViewLog;
use App\Support\ShopContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * View-as-seller (ধাপ ৩): a platform admin enters ONE shop read-only
 * for at most 30 minutes. The state is three session keys (see
 * ShopContext); the admin's identity never changes - only the shop
 * scope moves. Every enter and exit is audit-logged
 * (platform_view_logs). Entering a second shop replaces the first:
 * the old view is logged as an exit, the new one as an enter.
 */
class ViewAsController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) (auth()->user()?->is_platform_admin), 403);
    }

    public function enter(Request $request, Business $business): RedirectResponse
    {
        $admin = $request->user();

        $current = ShopContext::rawSessionView($request);
        if ($current && $current['business_id'] !== $business->id) {
            PlatformViewLog::record((int) $admin->id, $current['business_id'], 'exit', $request, [
                'shop_name' => Business::whereKey($current['business_id'])->value('name'),
                'reason' => 'replaced',
            ]);
        }

        $request->session()->put([
            ShopContext::SESSION_BUSINESS => $business->id,
            ShopContext::SESSION_STARTED => now()->timestamp,
            ShopContext::SESSION_ADMIN => (int) $admin->id,
        ]);
        // The resolver caches per request; this response redirects, but
        // keep the current request consistent anyway.
        $request->attributes->remove('shop_context.view');
        $request->attributes->remove('shop_context.id');

        PlatformViewLog::record((int) $admin->id, $business->id, 'enter', $request, [
            'shop_name' => $business->name,
        ]);

        return redirect('/dashboard')
            ->with('success', __('You are now viewing this shop read-only.'));
    }

    public function exit(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $current = ShopContext::rawSessionView($request);
        if ($current) {
            $state = ShopContext::viewState($request);
            PlatformViewLog::record((int) $admin->id, $current['business_id'], 'exit', $request, [
                'shop_name' => Business::whereKey($current['business_id'])->value('name'),
                'seconds_left' => $state['seconds_left'] ?? 0,
            ]);
        }
        ShopContext::clear($request);

        return redirect('/admin/sellers')
            ->with('success', __('Read-only view closed.'));
    }
}

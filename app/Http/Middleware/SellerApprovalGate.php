<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\StorefrontService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller onboarding gate: a business whose status is not 'approved'
 * (pending review, or rejected) may only reach the account status
 * page, Points & Billing and the payment-claim routes — paying must
 * never deadlock behind approval. Everything else on the platform
 * host redirects to the status page. Platform admins always pass,
 * users without a business pass, tenant (storefront) hosts are public
 * shop space and are never gated, and a business with no status value
 * (legacy row) counts as approved.
 *
 * Ahead of the approval check sits registration verification: while
 * its Settings switch is ON, a seller holding an unverified code is
 * held on the code page before even the pending flow opens.
 */
class SellerApprovalGate
{
    private const EXEMPT = [
        'account.status',
        'billing',
        'billing.claims.store',
        'billing.claims.submit',
        'logout',
        'locale.switch',
        'verification.send',
        'registration.verify',
        'registration.verify.store',
        'registration.verify.resend',
        'sidebar.counts',
    ];

    /** The only routes an unverified seller may touch. */
    private const VERIFY_EXEMPT = [
        'registration.verify',
        'registration.verify.store',
        'registration.verify.resend',
        'logout',
        'locale.switch',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->is_platform_admin || ! $user->business_id) {
            return $next($request);
        }
        if (! app(StorefrontService::class)->isMainHost($request->getHost())) {
            return $next($request);
        }
        $name = (string) ($request->route()?->getName() ?? '');
        // Registration verification comes before everything else: while
        // the platform switch is ON, a seller who still holds an
        // unverified code may reach ONLY the code page (+ resend,
        // logout, language) until they enter it — then this gate's
        // normal pending/claim flow below resumes.
        if (! in_array($name, self::VERIFY_EXEMPT, true) && ! str_starts_with($name, 'totp.')
            && app(\App\Services\RegistrationVerificationService::class)->needsVerification($user)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Please verify your account first.'], 403);
            }

            return redirect()->route('registration.verify');
        }
        if (in_array($name, self::EXEMPT, true) || str_starts_with($name, 'totp.')) {
            return $next($request);
        }
        $status = Business::where('id', $user->business_id)->value('status');
        if ($status === null || $status === '' || $status === 'approved') {
            return $next($request);
        }
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Your account is under review.'], 403);
        }

        return redirect()->route('account.status');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\RegistrationVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registration code step (owner order, 2026-11-22): shown only
 * while the platform switch is ON and the seller still holds an
 * unverified code. Everything else in the app stays gated until the
 * code is entered (see SellerApprovalGate).
 */
class RegistrationVerificationController extends Controller
{
    public function __construct(private readonly RegistrationVerificationService $svc) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $this->svc->needsVerification($user)) {
            return redirect()->route('dashboard');
        }
        $row = $this->svc->rowFor($user);

        return Inertia::render('Auth/VerifyRegistration', [
            'shopName' => $user->business_id
                ? (string) (Business::whereKey($user->business_id)->value('name') ?? '')
                : '',
            'channels' => $row?->channels ?? $this->svc->channel(),
            'maskedEmail' => $this->svc->maskedEmail($user),
            'maskedPhone' => $this->svc->maskedPhone($user),
            'cooldown' => $this->svc->cooldownSeconds($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
        ]);

        $result = $this->svc->verify($request->user(), (string) $data['code']);
        if ($result === 'ok') {
            return redirect()->route('dashboard')
                ->with('success', __('Your account is verified - welcome to Scalenow Bd.'));
        }
        if ($result === 'none') {
            return redirect()->route('dashboard');
        }

        throw ValidationException::withMessages([
            'code' => match ($result) {
                'expired' => __('That code has expired. Please ask for a new code.'),
                'locked' => __('Too many wrong tries. Please ask for a new code.'),
                default => __('That code is not right. Please check it and try again.'),
            },
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $this->svc->needsVerification($user)) {
            return redirect()->route('dashboard');
        }
        $result = $this->svc->resend($user);
        if (! ($result['ok'] ?? false)) {
            if (($result['wait'] ?? 0) > 0) {
                return back()->with('error', __('Please wait :count seconds before asking for a new code.', ['count' => $result['wait']]));
            }

            return back()->with('error', __('We could not send a new code. Please try again in a moment.'));
        }

        return back()->with('success', __('A new code was sent.'));
    }
}

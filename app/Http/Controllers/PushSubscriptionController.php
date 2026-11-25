<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browser Web Push subscription endpoints for the signed-in app.
 * A user only ever touches their own subscriptions: rows are keyed
 * by endpoint but every write is scoped to the current user, so one
 * user cannot remove or hijack another user's browser.
 */
class PushSubscriptionController extends Controller
{
    public function publicKey(PushService $push): JsonResponse
    {
        return response()->json(['key' => $push->publicKey()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }
}

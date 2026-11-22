<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, AppNotification $notification): RedirectResponse
    {
        // Only a notification the signed-in user is allowed to see can
        // be marked. Anything else is a silent no-op, so another shop's
        // rows are never mutated and their ids never confirmed.
        if (AppNotification::visibleTo($request->user())->whereKey($notification->id)->exists()) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        AppNotification::visibleTo($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}

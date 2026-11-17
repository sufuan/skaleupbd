<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\RedirectResponse;

class NotificationController extends Controller
{
    public function markRead(AppNotification $notification): RedirectResponse
    {
        $notification->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        AppNotification::whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}

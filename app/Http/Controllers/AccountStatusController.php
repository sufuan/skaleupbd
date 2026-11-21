<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one page a seller whose shop is not approved yet can reach:
 * pending → "under review", rejected → the admin's note. Approved
 * sellers are bounced straight to the dashboard.
 */
class AccountStatusController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $biz = $user?->business_id ? Business::find($user->business_id) : null;
        $status = $biz?->status ?: 'approved';
        if ($status === 'approved') {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Account/Status', [
            'status' => $status,
            'note' => $biz?->status_note,
            'shopName' => $biz?->name,
        ]);
    }
}

<?php

use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\ExternalOrderController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderToolsController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Public: language switch (en|bn), same pattern as TPDA e-Sheba.
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'bn'])
    ->name('locale.switch');

// TOTP enrollment + challenge (reachable while signed in; the totp
// middleware exempts these routes itself).
Route::middleware('auth')->group(function () {
    Route::get('/totp/setup', [TotpController::class, 'setup'])->name('totp.setup');
    Route::post('/totp/setup', [TotpController::class, 'confirmSetup'])->name('totp.setup.confirm');
    Route::get('/totp/challenge', [TotpController::class, 'challenge'])->name('totp.challenge');
    Route::post('/totp/challenge', [TotpController::class, 'verifyChallenge'])->name('totp.challenge.verify');
});

Route::middleware(['auth', 'verified', 'totp'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/totp', [SettingsController::class, 'updateTotp'])->name('settings.totp');
    Route::post('/settings/business', [SettingsController::class, 'updateBusiness'])->name('settings.business');
    Route::post('/settings/sources', [SettingsController::class, 'updateSources'])->name('settings.sources');
    Route::post('/settings/sources/reset', [SettingsController::class, 'resetSources'])->name('settings.sources.reset');
    Route::post('/settings/templates', [SettingsController::class, 'updateTemplates'])->name('settings.templates');
    Route::post('/settings/order-toggles', [SettingsController::class, 'updateOrderToggles'])->name('settings.orderToggles');

    // M2 Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/next', [OrderController::class, 'next'])->name('orders.next');
    Route::get('/orders/autopick', [OrderToolsController::class, 'autopick'])->name('orders.autopick');
    Route::get('/orders/autopick/{status}/next', [OrderToolsController::class, 'autopickNext'])->name('orders.autopick.next');
    Route::post('/orders/autopick/toggle', [OrderToolsController::class, 'autopickToggle'])->name('orders.autopick.toggle');
    Route::get('/orders/autocall', [OrderToolsController::class, 'autocall'])->name('orders.autocall');
    Route::post('/orders/autocall/log', [OrderToolsController::class, 'autocallLog'])->name('orders.autocall.log');
    Route::get('/orders/block-list', [OrderToolsController::class, 'blocklist'])->name('orders.blocklist');
    Route::post('/orders/block-list', [OrderToolsController::class, 'blockStore'])->name('orders.blocklist.store');
    Route::post('/orders/block-list/cleanup', [OrderToolsController::class, 'blockCleanup'])->name('orders.blocklist.cleanup');
    Route::delete('/orders/block-list/{block}', [OrderToolsController::class, 'blockDestroy'])->name('orders.blocklist.destroy');
    Route::get('/orders/preorders', [OrderToolsController::class, 'preorders'])->name('orders.preorders');
    Route::post('/orders/preorders/{order}/notify', [OrderToolsController::class, 'preorderNotify'])->name('orders.preorders.notify');
    Route::get('/orders/dashboard', [OrderToolsController::class, 'dashboard'])->name('orders.dashboard');
    Route::get('/orders/super-edit', [OrderToolsController::class, 'superEdit'])->name('orders.superedit');
    Route::post('/orders/super-edit/{order}', [OrderToolsController::class, 'superEditSave'])->name('orders.superedit.save');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->name('orders.approve');
    Route::post('/orders/{order}/sms', [OrderController::class, 'sendSms'])->name('orders.sms');
    Route::post('/orders/{order}/block', [OrderController::class, 'block'])->name('orders.block');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
    Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');

    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('/module/{slug}', function (string $slug) {
        return Inertia::render('Module/Placeholder', [
            'title' => ucwords(str_replace('-', ' ', $slug)),
        ]);
    })->name('module');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// External API intake for sellers' websites (API-key auth, no session).
Route::post('/api/external/v1/orders', [ExternalOrderController::class, 'store'])->name('api.external.orders');

require __DIR__.'/auth.php';

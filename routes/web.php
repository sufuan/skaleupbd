<?php

use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\ExternalOrderController;
use App\Http\Controllers\WebhookController;
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
    Route::post('/settings/courier-methods', [SettingsController::class, 'addCourierMethod'])->name('settings.courierMethods');

    // M2 Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
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
    Route::get('/orders/dashboard/export', [OrderToolsController::class, 'dashboardExport'])->name('orders.dashboard.export');
    Route::get('/orders/super-edit', [OrderToolsController::class, 'superEdit'])->name('orders.superedit');
    Route::post('/orders/super-edit/{order}', [OrderToolsController::class, 'superEditSave'])->name('orders.superedit.save');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/packing-slip', [\App\Http\Controllers\DeliveryController::class, 'packingSlip'])->name('orders.packingSlip');

    // M3 Delivery
    Route::get('/delivery/ready', [\App\Http\Controllers\DeliveryController::class, 'ready'])->name('delivery.ready');
    Route::post('/delivery/send', [\App\Http\Controllers\DeliveryController::class, 'send'])->name('delivery.send');
    Route::post('/delivery/send-bulk', [\App\Http\Controllers\DeliveryController::class, 'sendBulk'])->name('delivery.sendBulk');
    Route::post('/delivery/{order}/retry', [\App\Http\Controllers\DeliveryController::class, 'retry'])->name('delivery.retry');
    Route::post('/delivery/{order}/change-courier', [\App\Http\Controllers\DeliveryController::class, 'changeCourier'])->name('delivery.changeCourier');
    Route::post('/delivery/{order}/cancel-booking', [\App\Http\Controllers\DeliveryController::class, 'cancelBooking'])->name('delivery.cancelBooking');
    Route::post('/delivery/{order}/force-check', [\App\Http\Controllers\DeliveryController::class, 'forceCheck'])->name('delivery.forceCheck');
    Route::patch('/delivery/parcels/{order}', [\App\Http\Controllers\DeliveryController::class, 'updateParcel'])->name('delivery.parcel.update');
    Route::get('/delivery/parcels', [\App\Http\Controllers\DeliveryController::class, 'parcels'])->name('delivery.parcels');
    Route::get('/delivery/entries/export', [\App\Http\Controllers\DeliveryController::class, 'entriesExport'])->name('delivery.entries.export');
    Route::get('/delivery/scan', [\App\Http\Controllers\DeliveryController::class, 'scanPage'])->name('delivery.scan');
    Route::post('/delivery/scan', [\App\Http\Controllers\DeliveryController::class, 'scan'])->name('delivery.scan.apply');
    Route::get('/delivery/ledger', [\App\Http\Controllers\CourierLedgerController::class, 'ledger'])->name('delivery.ledger');
    Route::get('/delivery/invoice', [\App\Http\Controllers\CourierLedgerController::class, 'workbench'])->name('delivery.invoice');
    Route::post('/delivery/invoice/preview', [\App\Http\Controllers\CourierLedgerController::class, 'uploadPreview'])->name('delivery.invoice.preview');
    Route::post('/delivery/invoice/confirm', [\App\Http\Controllers\CourierLedgerController::class, 'uploadConfirm'])->name('delivery.invoice.confirm');
    Route::post('/delivery/payments/propose', [\App\Http\Controllers\CourierLedgerController::class, 'proposePayment'])->name('delivery.payments.propose');
    Route::post('/delivery/payments', [\App\Http\Controllers\CourierLedgerController::class, 'storePayment'])->name('delivery.payments.store');
    Route::post('/delivery/pull', [\App\Http\Controllers\CourierLedgerController::class, 'pull'])->name('delivery.pull');
    Route::post('/delivery/allocations/{allocation}/dispute', [\App\Http\Controllers\CourierLedgerController::class, 'markDisputed'])->name('delivery.allocations.dispute');

    // Settings → Courier (M3 wiring)
    Route::post('/settings/couriers', [\App\Http\Controllers\CourierSettingsController::class, 'store'])->name('settings.couriers.store');
    Route::patch('/settings/couriers/{courier}', [\App\Http\Controllers\CourierSettingsController::class, 'update'])->name('settings.couriers.update');
    Route::post('/settings/couriers/{courier}/toggle', [\App\Http\Controllers\CourierSettingsController::class, 'toggle'])->name('settings.couriers.toggle');
    Route::post('/settings/couriers/{courier}/default', [\App\Http\Controllers\CourierSettingsController::class, 'setDefault'])->name('settings.couriers.default');
    Route::post('/settings/couriers/{courier}/preferred', [\App\Http\Controllers\CourierSettingsController::class, 'setPreferred'])->name('settings.couriers.preferred');
    Route::post('/settings/couriers/{courier}/test', [\App\Http\Controllers\CourierSettingsController::class, 'testConnection'])->name('settings.couriers.test');
    Route::post('/settings/couriers/{courier}/sync-stores', [\App\Http\Controllers\CourierSettingsController::class, 'syncStores'])->name('settings.couriers.syncStores');
    Route::delete('/settings/couriers/{courier}', [\App\Http\Controllers\CourierSettingsController::class, 'destroy'])->name('settings.couriers.destroy');
    Route::post('/settings/courier-rules', [\App\Http\Controllers\CourierSettingsController::class, 'storeRule'])->name('settings.courierRules.store');
    Route::delete('/settings/courier-rules/{rule}', [\App\Http\Controllers\CourierSettingsController::class, 'destroyRule'])->name('settings.courierRules.destroy');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.add');
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
Route::post('/api/webhooks/steadfast/dm/{method}', [WebhookController::class, 'steadfast'])->name('api.webhooks.steadfast');
Route::post('/api/webhooks/pathao/dm/{method}', [WebhookController::class, 'pathao'])->name('api.webhooks.pathao');
Route::post('/api/webhooks/carrybee/dm/{method}', [WebhookController::class, 'carrybee'])->name('api.webhooks.carrybee');

require __DIR__.'/auth.php';

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
    // Signed-in users keep the old behaviour (straight to the dashboard);
    // guests get the small public front page (legal name/address for
    // Meta Business Verification + a login button).
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Landing');
});

// Public: language switch (en|bn), same pattern as TPDA e-Sheba.
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'bn'])
    ->name('locale.switch');

// Friendly shortcuts: the login lives at /admin/login and the inbox at
// /messages/inbox — the short forms people type should land there too
// instead of 404ing.
Route::redirect('/login', '/admin/login');
Route::redirect('/messages', '/messages/inbox');

// Public pricing page (M19) — renders live from the point packs.
Route::get('/pricing', [\App\Http\Controllers\PricingController::class, 'index'])->name('pricing');

// M18 Storefront — public shop endpoints. These only work on a tenant
// host (ResolveStorefrontHost attaches the storefront); on the platform
// host they 404 in the controller.
Route::get('/p/{product}', [\App\Http\Controllers\StorefrontPublicController::class, 'product'])->whereNumber('product')->name('sf.product');
Route::post('/order', [\App\Http\Controllers\StorefrontPublicController::class, 'order'])->name('sf.order');
Route::get('/order-success/{invoice}', [\App\Http\Controllers\StorefrontPublicController::class, 'success'])->name('sf.success');
Route::get('/report', [\App\Http\Controllers\StorefrontPublicController::class, 'reportForm'])->name('sf.report');
Route::post('/report', [\App\Http\Controllers\StorefrontPublicController::class, 'reportStore'])->name('sf.report.store');

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
    Route::get('/dashboard/earnings', [\App\Http\Controllers\DashboardController::class, 'earnings'])->name('dashboard.earnings');

    // Live sidebar/topbar counts, polled by the app shell (no page reload needed).
    Route::get('/sidebar-counts', function (\Illuminate\Http\Request $request) {
        $biz = $request->user()?->business_id;
        $hasOrders = $biz && \Illuminate\Support\Facades\Schema::hasTable('orders');
        return response()->json([
            'orders_pending' => $hasOrders ? \App\Models\Order::where('business_id', $biz)->where('status', 'Pending')->count() : 0,
            'delivery_waiting' => $hasOrders ? \App\Models\Order::where('business_id', $biz)->where('status', 'Processing')->count() : 0,
            'notifications_unread' => \Illuminate\Support\Facades\Schema::hasTable('app_notifications')
                ? \App\Models\AppNotification::whereNull('read_at')->count()
                : 0,
            'messages_unread' => $biz && \Illuminate\Support\Facades\Schema::hasTable('conversations')
                ? (int) \App\Models\Conversation::where('business_id', $biz)->sum('unread_count')
                    + \App\Models\ChatMessage::where('business_id', $biz)->whereIn('status', ['draft', 'flagged'])->count()
                : 0,
            'workinbox_open' => $biz && \Illuminate\Support\Facades\Schema::hasTable('work_tasks')
                ? \App\Models\WorkTask::where('business_id', $biz)->whereIn('status', ['open', 'in_progress'])->count()
                : 0,
        ]);
    })->name('sidebar.counts');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/totp', [SettingsController::class, 'updateTotp'])->name('settings.totp');
    Route::post('/settings/business', [SettingsController::class, 'updateBusiness'])->name('settings.business');
    Route::post('/settings/sources', [SettingsController::class, 'updateSources'])->name('settings.sources');
    Route::post('/settings/sources/reset', [SettingsController::class, 'resetSources'])->name('settings.sources.reset');
    Route::post('/settings/templates', [SettingsController::class, 'updateTemplates'])->name('settings.templates');
    Route::post('/settings/order-toggles', [SettingsController::class, 'updateOrderToggles'])->name('settings.orderToggles');
    Route::post('/settings/inventory', [SettingsController::class, 'updateInventory'])->name('settings.inventory');
    Route::post('/settings/finance', [SettingsController::class, 'updateFinance'])->name('settings.finance');
    Route::post('/settings/marketing-sms', [SettingsController::class, 'updateMarketingSms'])->name('settings.marketingSms');
    Route::post('/settings/sms-check', [SettingsController::class, 'checkSmsConnection'])->name('settings.smsCheck');
    Route::post('/settings/sms-test', [SettingsController::class, 'sendTestSms'])->name('settings.smsTest');
    Route::post('/settings/payment-gateway', [\App\Http\Controllers\PaymentGatewayController::class, 'updateSeller'])->name('settings.paymentGateway');
    Route::post('/settings/payment-gateway/platform', [\App\Http\Controllers\PaymentGatewayController::class, 'updatePlatform'])->name('settings.paymentGateway.platform');
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

    // Finance (M14)
    Route::get('/finance', [\App\Http\Controllers\FinanceController::class, 'dashboard'])->name('finance.dashboard');
    Route::get('/finance/accounts', [\App\Http\Controllers\FinanceController::class, 'accounts'])->name('finance.accounts');
    Route::post('/finance/accounts', [\App\Http\Controllers\FinanceController::class, 'storeAccount'])->name('finance.accounts.store');
    Route::patch('/finance/accounts/{account}', [\App\Http\Controllers\FinanceController::class, 'updateAccount'])->name('finance.accounts.update');
    Route::post('/finance/transactions', [\App\Http\Controllers\FinanceController::class, 'storeTransaction'])->name('finance.transactions.store');
    Route::post('/finance/transactions/{transaction}/void', [\App\Http\Controllers\FinanceController::class, 'voidTransaction'])->name('finance.transactions.void');
    Route::patch('/finance/transactions/{transaction}', [\App\Http\Controllers\FinanceController::class, 'updateTransaction'])->name('finance.transactions.update');
    Route::get('/finance/income-expense', [\App\Http\Controllers\FinanceController::class, 'incomeExpense'])->name('finance.ie');
    Route::get('/finance/income-expense/export', [\App\Http\Controllers\FinanceController::class, 'exportCsv'])->name('finance.ie.export');
    Route::post('/finance/categories', [\App\Http\Controllers\FinanceController::class, 'storeCategory'])->name('finance.categories.store');
    Route::patch('/finance/categories/{category}', [\App\Http\Controllers\FinanceController::class, 'updateCategory'])->name('finance.categories.update');
    Route::delete('/finance/categories/{category}', [\App\Http\Controllers\FinanceController::class, 'deleteCategory'])->name('finance.categories.delete');
    Route::get('/finance/cash-flow', [\App\Http\Controllers\FinanceController::class, 'cashflow'])->name('finance.cashflow');
    Route::post('/finance/close-month', [\App\Http\Controllers\FinanceController::class, 'closeMonth'])->name('finance.closeMonth');
    Route::post('/finance/daily-close', [\App\Http\Controllers\FinanceController::class, 'dailyClose'])->name('finance.dailyClose');
    Route::post('/finance/payout-task', [\App\Http\Controllers\FinanceController::class, 'raisePayoutTask'])->name('finance.payoutTask');

    // HRM (M13)
    Route::get('/hrm', [\App\Http\Controllers\HrmController::class, 'dashboard'])->name('hrm.dashboard');
    Route::get('/hrm/attendance', [\App\Http\Controllers\HrmController::class, 'attendance'])->name('hrm.attendance');
    Route::get('/hrm/attendance/export', [\App\Http\Controllers\HrmController::class, 'exportReport'])->name('hrm.attendance.export');
    Route::post('/hrm/attendance/check-in', [\App\Http\Controllers\HrmController::class, 'checkIn'])->name('hrm.checkin');
    Route::post('/hrm/attendance/check-out', [\App\Http\Controllers\HrmController::class, 'checkOut'])->name('hrm.checkout');
    Route::post('/hrm/attendance/break', [\App\Http\Controllers\HrmController::class, 'breakToggle'])->name('hrm.break');
    Route::post('/hrm/leaves', [\App\Http\Controllers\HrmController::class, 'applyLeave'])->name('hrm.leaves.apply');
    Route::post('/hrm/leaves/{leave}/decide', [\App\Http\Controllers\HrmController::class, 'decideLeave'])->name('hrm.leaves.decide');
    Route::get('/hrm/departments', [\App\Http\Controllers\HrmController::class, 'departments'])->name('hrm.departments');
    Route::post('/hrm/departments', [\App\Http\Controllers\HrmController::class, 'storeDepartment'])->name('hrm.departments.store');
    Route::patch('/hrm/departments/{department}', [\App\Http\Controllers\HrmController::class, 'updateDepartment'])->name('hrm.departments.update');
    Route::delete('/hrm/departments/{department}', [\App\Http\Controllers\HrmController::class, 'destroyDepartment'])->name('hrm.departments.destroy');
    Route::post('/hrm/sops', [\App\Http\Controllers\HrmController::class, 'storeSop'])->name('hrm.sops.store');
    Route::patch('/hrm/sops/{sop}', [\App\Http\Controllers\HrmController::class, 'updateSop'])->name('hrm.sops.update');
    Route::delete('/hrm/sops/{sop}', [\App\Http\Controllers\HrmController::class, 'destroySop'])->name('hrm.sops.destroy');
    Route::post('/hrm/shifts', [\App\Http\Controllers\HrmController::class, 'storeShift'])->name('hrm.shifts.store');
    Route::patch('/hrm/shifts/{shift}', [\App\Http\Controllers\HrmController::class, 'updateShift'])->name('hrm.shifts.update');
    Route::delete('/hrm/shifts/{shift}', [\App\Http\Controllers\HrmController::class, 'destroyShift'])->name('hrm.shifts.destroy');
    Route::post('/hrm/shifts/{shift}/assign', [\App\Http\Controllers\HrmController::class, 'assignShift'])->name('hrm.shifts.assign');
    Route::get('/hrm/users', [\App\Http\Controllers\HrmController::class, 'users'])->name('hrm.users');
    Route::post('/hrm/users', [\App\Http\Controllers\HrmController::class, 'storeUser'])->name('hrm.users.store');
    Route::patch('/hrm/users/{user}', [\App\Http\Controllers\HrmController::class, 'updateUser'])->name('hrm.users.update');
    Route::get('/hrm/settings', [\App\Http\Controllers\HrmController::class, 'settings'])->name('hrm.settings');
    Route::post('/hrm/settings', [\App\Http\Controllers\HrmController::class, 'updateSettings'])->name('hrm.settings.update');
    Route::post('/hrm/holidays', [\App\Http\Controllers\HrmController::class, 'storeHoliday'])->name('hrm.holidays.store');
    Route::delete('/hrm/holidays/{holiday}', [\App\Http\Controllers\HrmController::class, 'destroyHoliday'])->name('hrm.holidays.destroy');
    // Payroll (M13) — built, disabled by default (HR Settings switch)
    Route::get('/hrm/payroll', [\App\Http\Controllers\PayrollController::class, 'index'])->name('hrm.payroll');
    Route::post('/hrm/payroll/build', [\App\Http\Controllers\PayrollController::class, 'build'])->name('hrm.payroll.build');
    Route::patch('/hrm/payroll/items/{item}', [\App\Http\Controllers\PayrollController::class, 'updateItem'])->name('hrm.payroll.items.update');
    Route::post('/hrm/payroll/{run}/pay', [\App\Http\Controllers\PayrollController::class, 'pay'])->name('hrm.payroll.pay');

    // Marketing (M12)
    Route::get('/marketing', [\App\Http\Controllers\MarketingController::class, 'automation'])->name('marketing.automation');
    Route::post('/marketing/flows/{key}', [\App\Http\Controllers\MarketingController::class, 'saveFlow'])->name('marketing.flows.save');
    Route::post('/marketing/templates', [\App\Http\Controllers\MarketingController::class, 'storeTemplate'])->name('marketing.templates.store');
    Route::patch('/marketing/templates/{template}', [\App\Http\Controllers\MarketingController::class, 'updateTemplate'])->name('marketing.templates.update');
    Route::delete('/marketing/templates/{template}', [\App\Http\Controllers\MarketingController::class, 'deleteTemplate'])->name('marketing.templates.delete');
    Route::get('/marketing/sms', [\App\Http\Controllers\MarketingController::class, 'sms'])->name('marketing.sms');
    Route::post('/marketing/sms/send', [\App\Http\Controllers\MarketingController::class, 'sendCampaign'])->name('marketing.sms.send');
    Route::get('/marketing/report', [\App\Http\Controllers\MarketingController::class, 'report'])->name('marketing.report');
    Route::post('/marketing/report/window', [\App\Http\Controllers\MarketingController::class, 'saveWindow'])->name('marketing.report.window');

    // Work Inbox (M10) — system-made problem tasks; My Work page is built
    // but stays OFF unless the shop turns it on in Work Inbox Settings.
    Route::get('/work-inbox', fn () => redirect('/work-inbox/control'))->name('workinbox.home');
    Route::get('/work-inbox/control', [\App\Http\Controllers\WorkInboxController::class, 'control'])->name('workinbox.control');
    Route::get('/work-inbox/my', [\App\Http\Controllers\WorkInboxController::class, 'my'])->name('workinbox.my');
    Route::get('/work-inbox/settings', [\App\Http\Controllers\WorkInboxController::class, 'settings'])->name('workinbox.settings');
    Route::post('/work-inbox/settings', [\App\Http\Controllers\WorkInboxController::class, 'saveSettings'])->name('workinbox.settings.save');
    Route::post('/work-inbox/tasks', [\App\Http\Controllers\WorkInboxController::class, 'store'])->name('workinbox.tasks.store');
    Route::post('/work-inbox/tasks/bulk-done', [\App\Http\Controllers\WorkInboxController::class, 'bulkDone'])->name('workinbox.tasks.bulkDone');
    Route::post('/work-inbox/tasks/{task}/start', [\App\Http\Controllers\WorkInboxController::class, 'start'])->name('workinbox.tasks.start');
    Route::post('/work-inbox/tasks/{task}/done', [\App\Http\Controllers\WorkInboxController::class, 'done'])->name('workinbox.tasks.done');
    Route::post('/work-inbox/tasks/{task}/reassign', [\App\Http\Controllers\WorkInboxController::class, 'reassign'])->name('workinbox.tasks.reassign');

    // Reports (M15)
    Route::get('/reports', [\App\Http\Controllers\ReportsController::class, 'pl'])->name('reports.pl');
    Route::post('/reports/pl/estimate', [\App\Http\Controllers\ReportsController::class, 'saveEstimate'])->name('reports.pl.estimate');
    Route::get('/reports/order', [\App\Http\Controllers\ReportsController::class, 'order'])->name('reports.order');
    Route::get('/reports/courier', [\App\Http\Controllers\ReportsController::class, 'courier'])->name('reports.courier');
    Route::get('/reports/product', [\App\Http\Controllers\ReportsController::class, 'product'])->name('reports.product');
    Route::get('/reports/employee', [\App\Http\Controllers\ReportsController::class, 'employee'])->name('reports.employee');
    Route::get('/reports/call', [\App\Http\Controllers\ReportsController::class, 'call'])->name('reports.call');
    Route::get('/reports/customer', [\App\Http\Controllers\ReportsController::class, 'customer'])->name('reports.customer');
    Route::get('/reports/limits', [\App\Http\Controllers\ReportsController::class, 'limits'])->name('reports.limits');
    Route::get('/reports/{key}/export', [\App\Http\Controllers\ReportsController::class, 'export'])->name('reports.export');

    // Inventory (M4)
    Route::get('/inventory/dashboard', [\App\Http\Controllers\InventoryController::class, 'dashboard'])->name('inventory.dashboard');
    Route::get('/inventory/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('inventory.products');
    Route::get('/inventory/products/create', [\App\Http\Controllers\InventoryController::class, 'createProduct'])->name('inventory.products.create');
    Route::post('/inventory/products', [\App\Http\Controllers\InventoryController::class, 'storeProduct'])->name('inventory.products.store');
    Route::post('/inventory/products/import', [\App\Http\Controllers\InventoryController::class, 'importProducts'])->name('inventory.products.import');
    Route::get('/inventory/products/export', [\App\Http\Controllers\InventoryController::class, 'exportProducts'])->name('inventory.products.export');
    Route::get('/inventory/products/template', [\App\Http\Controllers\InventoryController::class, 'templateProducts'])->name('inventory.products.template');
    Route::patch('/inventory/products/{product}', [\App\Http\Controllers\InventoryController::class, 'updateProduct'])->name('inventory.products.update');
    Route::delete('/inventory/products/{product}', [\App\Http\Controllers\InventoryController::class, 'destroyProduct'])->name('inventory.products.destroy');
    Route::post('/inventory/products/{product}/copy', [\App\Http\Controllers\InventoryController::class, 'copyProduct'])->name('inventory.products.copy');
    Route::post('/inventory/products/{product}/toggle', [\App\Http\Controllers\InventoryController::class, 'toggleProduct'])->name('inventory.products.toggle');
    Route::post('/inventory/products/{product}/adjust', [\App\Http\Controllers\InventoryController::class, 'adjustStock'])->name('inventory.products.adjust');
    Route::post('/inventory/categories', [\App\Http\Controllers\InventoryController::class, 'storeCategory'])->name('inventory.categories.store');
    Route::delete('/inventory/categories/{category}', [\App\Http\Controllers\InventoryController::class, 'destroyCategory'])->name('inventory.categories.destroy');
    Route::post('/inventory/brands', [\App\Http\Controllers\InventoryController::class, 'storeBrand'])->name('inventory.brands.store');
    Route::delete('/inventory/brands/{brand}', [\App\Http\Controllers\InventoryController::class, 'destroyBrand'])->name('inventory.brands.destroy');
    Route::post('/inventory/products/{product}/variants', [\App\Http\Controllers\InventoryController::class, 'storeVariant'])->name('inventory.variants.store');
    Route::delete('/inventory/variants/{variant}', [\App\Http\Controllers\InventoryController::class, 'destroyVariant'])->name('inventory.variants.destroy');
    Route::post('/inventory/groups/{kind}', [\App\Http\Controllers\InventoryController::class, 'storeGroup'])->name('inventory.groups.store');
    Route::delete('/inventory/groups/{kind}/{group}', [\App\Http\Controllers\InventoryController::class, 'destroyGroup'])->name('inventory.groups.destroy');
    Route::get('/inventory/ledger', [\App\Http\Controllers\InventoryOpsController::class, 'ledger'])->name('inventory.ledger');
    Route::post('/inventory/reconcile', [\App\Http\Controllers\InventoryOpsController::class, 'reconcile'])->name('inventory.reconcile');
    Route::get('/inventory/branches', [\App\Http\Controllers\InventoryOpsController::class, 'branches'])->name('inventory.branches');
    Route::post('/inventory/branches/toggle-stock', [\App\Http\Controllers\InventoryOpsController::class, 'toggleBranchStock'])->name('inventory.branches.toggleStock');
    Route::post('/inventory/branches', [\App\Http\Controllers\InventoryOpsController::class, 'storeBranch'])->name('inventory.branches.store');
    Route::patch('/inventory/branches/{branch}', [\App\Http\Controllers\InventoryOpsController::class, 'updateBranch'])->name('inventory.branches.update');
    Route::delete('/inventory/branches/{branch}', [\App\Http\Controllers\InventoryOpsController::class, 'destroyBranch'])->name('inventory.branches.destroy');
    Route::post('/inventory/transfers', [\App\Http\Controllers\InventoryOpsController::class, 'storeTransfer'])->name('inventory.transfers.store');
    Route::patch('/inventory/transfers/{transfer}', [\App\Http\Controllers\InventoryOpsController::class, 'updateTransfer'])->name('inventory.transfers.update');
    Route::get('/inventory/warranty', [\App\Http\Controllers\InventoryOpsController::class, 'warranty'])->name('inventory.warranty');
    Route::post('/inventory/warranty', [\App\Http\Controllers\InventoryOpsController::class, 'storeClaim'])->name('inventory.warranty.store');
    Route::patch('/inventory/warranty/{claim}', [\App\Http\Controllers\InventoryOpsController::class, 'updateClaim'])->name('inventory.warranty.update');
    Route::post('/inventory/warranty-settings', [\App\Http\Controllers\InventoryOpsController::class, 'saveWarrantySettings'])->name('inventory.warranty.settings');

    // M6 Messages
    Route::get('/messages/inbox', [\App\Http\Controllers\MessagesController::class, 'inbox'])->name('messages.inbox');
    Route::post('/messages/panic', [\App\Http\Controllers\MessagesController::class, 'panic'])->name('messages.panic');
    Route::post('/messages/conversations/{conversation}/reply', [\App\Http\Controllers\MessagesController::class, 'reply'])->name('messages.reply');
    Route::post('/messages/conversations/{conversation}/toggle-ai', [\App\Http\Controllers\MessagesController::class, 'toggleAi'])->name('messages.toggleAi');
    Route::post('/messages/drafts/{message}/approve', [\App\Http\Controllers\MessagesController::class, 'approveDraft'])->name('messages.drafts.approve');
    Route::post('/messages/drafts/{message}/reject', [\App\Http\Controllers\MessagesController::class, 'rejectDraft'])->name('messages.drafts.reject');
    Route::get('/messages/activity', [\App\Http\Controllers\MessagesController::class, 'activity'])->name('messages.activity');
    Route::get('/messages/comments', [\App\Http\Controllers\CommentsController::class, 'index'])->name('messages.comments');
    Route::post('/messages/comments/{comment}/reply', [\App\Http\Controllers\CommentsController::class, 'reply'])->name('messages.comments.reply');
    Route::post('/messages/comments/{comment}/private-reply', [\App\Http\Controllers\CommentsController::class, 'privateReply'])->name('messages.comments.private');
    Route::post('/messages/comments/{comment}/like', [\App\Http\Controllers\CommentsController::class, 'like'])->name('messages.comments.like');
    Route::post('/messages/comments/{comment}/hide', [\App\Http\Controllers\CommentsController::class, 'hide'])->name('messages.comments.hide');
    Route::post('/messages/comments/{comment}/done', [\App\Http\Controllers\CommentsController::class, 'done'])->name('messages.comments.done');
    Route::delete('/messages/comments/{comment}', [\App\Http\Controllers\CommentsController::class, 'destroy'])->name('messages.comments.destroy');
    Route::post('/messages/comment-replies/{reply}/approve', [\App\Http\Controllers\CommentsController::class, 'approveReply'])->name('messages.commentReplies.approve');
    Route::post('/messages/comment-replies/{reply}/reject', [\App\Http\Controllers\CommentsController::class, 'rejectReply'])->name('messages.commentReplies.reject');

    // Settings → AI & Auto Reply (M6)
    Route::post('/settings/ai-rules', [SettingsController::class, 'storeAiRule'])->name('settings.aiRules.store');
    Route::patch('/settings/ai-rules/{rule}', [SettingsController::class, 'updateAiRule'])->name('settings.aiRules.update');
    Route::delete('/settings/ai-rules/{rule}', [SettingsController::class, 'deleteAiRule'])->name('settings.aiRules.destroy');
    Route::post('/settings/ai-rules/test', [SettingsController::class, 'testAiRule'])->name('settings.aiRules.test');
    Route::post('/settings/ai-reply', [SettingsController::class, 'updateAiReply'])->name('settings.aiReply');
    Route::post('/settings/ai-negotiation', [SettingsController::class, 'updateAiNegotiation'])->name('settings.aiNegotiation');
    Route::post('/settings/ai-talker', [SettingsController::class, 'updateAiTalker'])->name('settings.aiTalker');
    Route::post('/settings/ai-talker/test', [SettingsController::class, 'testAiTalker'])->name('settings.aiTalker.test');
    Route::post('/settings/ai-behavior', [SettingsController::class, 'updateAiBehavior'])->name('settings.aiBehavior');
    Route::post('/settings/ai-knowledge', [SettingsController::class, 'storeKnowledge'])->name('settings.aiKnowledge.store');
    Route::patch('/settings/ai-knowledge/{entry}', [SettingsController::class, 'updateKnowledge'])->name('settings.aiKnowledge.update');
    Route::delete('/settings/ai-knowledge/{entry}', [SettingsController::class, 'deleteKnowledge'])->name('settings.aiKnowledge.destroy');
    Route::post('/settings/ai-proposals/{proposal}/approve', [SettingsController::class, 'approveProposal'])->name('settings.aiProposals.approve');
    Route::post('/settings/ai-proposals/{proposal}/reject', [SettingsController::class, 'rejectProposal'])->name('settings.aiProposals.reject');

    // M7 Customers (seller-facing)
    Route::get('/customers', [\App\Http\Controllers\CustomersController::class, 'index'])->name('customers.index');
    Route::post('/customers', [\App\Http\Controllers\CustomersController::class, 'store'])->name('customers.store');
    Route::get('/customers/upload', [\App\Http\Controllers\CustomersController::class, 'upload'])->name('customers.upload');
    Route::post('/customers/upload', [\App\Http\Controllers\CustomersController::class, 'uploadStore'])->name('customers.upload.store');
    Route::get('/customers/sample-csv', [\App\Http\Controllers\CustomersController::class, 'sampleCsv'])->name('customers.sample');
    Route::get('/customers/{customer}', [\App\Http\Controllers\CustomersController::class, 'show'])->name('customers.show');
    Route::patch('/customers/{customer}', [\App\Http\Controllers\CustomersController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{customer}', [\App\Http\Controllers\CustomersController::class, 'destroy'])->name('customers.destroy');
    Route::post('/customers/{customer}/blacklist', [\App\Http\Controllers\CustomersController::class, 'toggleBlacklist'])->name('customers.blacklist');

    // Platform (Super Admin) — Sellers & Packages
    Route::get('/admin', [\App\Http\Controllers\PlatformController::class, 'dashboard'])->name('platform.dashboard');
    Route::get('/admin/sellers', [\App\Http\Controllers\PlatformController::class, 'sellers'])->name('platform.sellers');
    Route::get('/admin/sellers/{business}', [\App\Http\Controllers\PlatformController::class, 'sellerShow'])->name('platform.seller');
    Route::patch('/admin/sellers/{business}', [\App\Http\Controllers\PlatformController::class, 'updateSeller'])->name('platform.seller.update');
    Route::post('/admin/sellers/{business}/subscribe', [\App\Http\Controllers\PlatformController::class, 'subscribe'])->name('platform.seller.subscribe');
    Route::post('/admin/sellers/{business}/refund-first-purchase', [\App\Http\Controllers\PlatformController::class, 'refundFirstPurchase'])->name('platform.seller.refund');
    Route::post('/admin/sellers/{business}/payments', [\App\Http\Controllers\PlatformController::class, 'storePayment'])->name('platform.seller.payment');
    Route::post('/admin/sellers/{business}/approve', [\App\Http\Controllers\PlatformController::class, 'approveSeller'])->name('platform.seller.approve');
    Route::post('/admin/sellers/{business}/reject', [\App\Http\Controllers\PlatformController::class, 'rejectSeller'])->name('platform.seller.reject');
    Route::get('/admin/packages', fn () => redirect('/settings?group=packages'))->name('platform.packages');
    Route::get('/admin/claims', [\App\Http\Controllers\PlatformController::class, 'claims'])->name('platform.claims');
    Route::post('/admin/claims/{claim}/approve', [\App\Http\Controllers\PlatformController::class, 'approveClaim'])->name('platform.claims.approve');
    Route::post('/admin/claims/{claim}/reject', [\App\Http\Controllers\PlatformController::class, 'rejectClaim'])->name('platform.claims.reject');
    Route::post('/settings/packages', [SettingsController::class, 'storePackage'])->name('settings.packages.store');
    Route::post('/settings/channels', [SettingsController::class, 'updateChannels'])->name('settings.channels');
    Route::post('/settings/channels/check', [SettingsController::class, 'checkChannel'])->name('settings.channels.check');
    Route::patch('/settings/packages/{package}', [SettingsController::class, 'updatePackage'])->name('settings.packages.update');
    Route::delete('/settings/packages/{package}', [SettingsController::class, 'deletePackage'])->name('settings.packages.destroy');
    Route::post('/settings/pricing', [SettingsController::class, 'updatePricing'])->name('settings.pricing');

    // M19 Pricing — the seller's points page + self-serve purchase flow
    Route::get('/account/status', [\App\Http\Controllers\AccountStatusController::class, 'index'])->name('account.status');
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing');
    Route::post('/billing/claims', [\App\Http\Controllers\BillingController::class, 'storeClaim'])->name('billing.claims.store');
    Route::post('/billing/claims/{claim}/submit', [\App\Http\Controllers\BillingController::class, 'submitClaim'])->name('billing.claims.submit');

    // M18 Storefront — seller section
    Route::get('/storefront', [\App\Http\Controllers\StorefrontController::class, 'overview'])->name('storefront.overview');
    Route::post('/storefront/publish', [\App\Http\Controllers\StorefrontController::class, 'publish'])->name('storefront.publish');
    Route::post('/storefront/unpublish', [\App\Http\Controllers\StorefrontController::class, 'unpublish'])->name('storefront.unpublish');
    Route::post('/storefront/otp/send', [\App\Http\Controllers\StorefrontController::class, 'sendOtp'])->name('storefront.otp.send');
    Route::post('/storefront/otp/verify', [\App\Http\Controllers\StorefrontController::class, 'verifyOtp'])->name('storefront.otp.verify');
    Route::get('/storefront/subdomain', [\App\Http\Controllers\StorefrontController::class, 'subdomain'])->name('storefront.subdomain');
    Route::get('/storefront/availability', [\App\Http\Controllers\StorefrontController::class, 'availability'])->name('storefront.availability');
    Route::post('/storefront/subdomain', [\App\Http\Controllers\StorefrontController::class, 'saveSubdomain'])->name('storefront.subdomain.save');
    Route::get('/storefront/branding', [\App\Http\Controllers\StorefrontController::class, 'branding'])->name('storefront.branding');
    Route::post('/storefront/branding', [\App\Http\Controllers\StorefrontController::class, 'saveBranding'])->name('storefront.branding.save');
    Route::get('/storefront/builder', [\App\Http\Controllers\StorefrontController::class, 'builder'])->name('storefront.builder');
    Route::post('/storefront/builder', [\App\Http\Controllers\StorefrontController::class, 'saveBlocks'])->name('storefront.builder.save');
    Route::post('/storefront/images', [\App\Http\Controllers\StorefrontController::class, 'uploadImage'])->name('storefront.images');
    Route::get('/storefront/domains', [\App\Http\Controllers\StorefrontController::class, 'domains'])->name('storefront.domains');
    Route::post('/storefront/domains', [\App\Http\Controllers\StorefrontController::class, 'requestDomain'])->name('storefront.domains.request');
    Route::post('/storefront/domains/verify', [\App\Http\Controllers\StorefrontController::class, 'verifyDomain'])->name('storefront.domains.verify');
    Route::delete('/storefront/domains', [\App\Http\Controllers\StorefrontController::class, 'removeDomain'])->name('storefront.domains.remove');

    // M18 Storefront — platform admin
    Route::get('/admin/storefronts', [\App\Http\Controllers\PlatformStorefrontController::class, 'index'])->name('platform.storefronts');
    Route::post('/admin/storefronts/{storefront}/takedown', [\App\Http\Controllers\PlatformStorefrontController::class, 'takedown'])->name('platform.storefronts.takedown');
    Route::post('/admin/storefronts/{storefront}/restore', [\App\Http\Controllers\PlatformStorefrontController::class, 'restore'])->name('platform.storefronts.restore');
    Route::post('/admin/storefronts/{storefront}/domain-added', [\App\Http\Controllers\PlatformStorefrontController::class, 'markDomainAdded'])->name('platform.storefronts.domainAdded');
    Route::post('/admin/storefronts/reports/{report}/resolve', [\App\Http\Controllers\PlatformStorefrontController::class, 'resolveReport'])->name('platform.storefronts.reports.resolve');

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
Route::get('/api/webhooks/meta', [\App\Http\Controllers\MetaWebhookController::class, 'verify'])->name('api.webhooks.meta.verify');
Route::post('/api/webhooks/meta', [\App\Http\Controllers\MetaWebhookController::class, 'receive'])->name('api.webhooks.meta');
Route::post('/api/webhooks/steadfast/dm/{method}', [WebhookController::class, 'steadfast'])->name('api.webhooks.steadfast');
Route::post('/api/webhooks/pathao/dm/{method}', [WebhookController::class, 'pathao'])->name('api.webhooks.pathao');
Route::post('/api/webhooks/carrybee/dm/{method}', [WebhookController::class, 'carrybee'])->name('api.webhooks.carrybee');
Route::post('/api/webhooks/paperfly/dm/{method}', [WebhookController::class, 'paperfly'])->name('api.webhooks.paperfly');
Route::post('/api/webhooks/redx/dm/{method}', [WebhookController::class, 'redx'])->name('api.webhooks.redx');
Route::post('/api/webhooks/sms/amarsms', [\App\Http\Controllers\SmsWebhookController::class, 'amarsms'])->name('api.webhooks.sms.amarsms');
Route::post('/api/webhooks/sms/nishchit', [\App\Http\Controllers\SmsWebhookController::class, 'nishchit'])->name('api.webhooks.sms.nishchit');

require __DIR__.'/auth.php';

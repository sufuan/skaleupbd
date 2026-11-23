<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The single permission registry (ধাপ ২).
 *
 * Every named route in the app is declared here, once, by route name:
 * which context it lives in, which module key it needs, and which of
 * the four ticks (view / edit / delete / export, RolePermissions)
 * using it requires. The PermissionGate middleware enforces this map
 * with default-deny; the sidebar menu, the Quick Search index and the
 * topbar actions are built from the same rows (menuFor), so what a
 * user cannot open is never offered to them either.
 *
 * Contexts:
 *  - public:   no sign-in at all (guest pages, tenant storefront,
 *              machine endpoints with their own credential checks).
 *  - account:  any signed-in user; identity-level routes (profile,
 *              password, TOTP, logout, shell data). No module key.
 *  - platform: platform admins only. A key is carried only where the
 *              surface genuinely is that module's platform scope
 *              (platform Settings writes, the messages panic switch).
 *  - shop:     a business user; the module key + tick are checked
 *              against RolePermissions::ticksFor(). Business-less
 *              non-platform users are denied (gap G-E).
 *
 * Flags:
 *  - ownerOnly:  the shop's owner-level users only (Staff & Roles,
 *              Super Edit). Not a tick — the controller keeps its own
 *              finer rules beside this backstop.
 *  - selfService: any staff member of the shop, whatever their ticks
 *              (own attendance / leave — the old ModuleAccess
 *              SELF_SERVICE list, carried over by route name).
 *
 * Owner-only module keys (billing, settings — RolePermissions::
 * OWNER_ONLY) are satisfied ONLY by owner-level users and platform
 * admins, whatever a stored legacy permission list says (note N2).
 *
 * Entry shape: 'route.name' => [context, key|null, tick|null, flags?]
 */
class RouteRegistry
{
    private const MAP = [
        // ------------------------------------------------ public
        'landing' => ['public'],
        'locale.switch' => ['public'],
        'login.redirect' => ['public'],
        'messages.redirect' => ['public'],
        'pricing' => ['public'],
        'register' => ['public'],
        'register.store' => ['public'],
        'login' => ['public'],
        'login.store' => ['public'],
        'password.request' => ['public'],
        'password.email' => ['public'],
        'password.reset' => ['public'],
        'password.store' => ['public'],
        // Tenant storefront (public shop space on tenant hosts).
        'sf.product' => ['public'],
        'sf.order' => ['public'],
        'sf.success' => ['public'],
        'sf.report' => ['public'],
        'sf.report.store' => ['public'],
        'sf.page' => ['public'],
        // Machine endpoints (API key / provider signature checked in
        // the controllers; no session permission applies).
        'api.external.orders' => ['public'],
        'api.webhooks.meta.verify' => ['public'],
        'api.webhooks.meta' => ['public'],
        'api.webhooks.steadfast' => ['public'],
        'api.webhooks.pathao' => ['public'],
        'api.webhooks.carrybee' => ['public'],
        'api.webhooks.paperfly' => ['public'],
        'api.webhooks.redx' => ['public'],
        'api.webhooks.sms.amarsms' => ['public'],
        'api.webhooks.sms.nishchit' => ['public'],

        // ------------------------------------------------ account
        'registration.verify' => ['account', null, 'view'],
        'registration.verify.store' => ['account', null, 'edit'],
        'registration.verify.resend' => ['account', null, 'edit'],
        'totp.setup' => ['account', null, 'view'],
        'totp.setup.confirm' => ['account', null, 'edit'],
        'totp.challenge' => ['account', null, 'view'],
        'totp.challenge.verify' => ['account', null, 'edit'],
        'verification.notice' => ['account', null, 'view'],
        'verification.verify' => ['account', null, 'view'],
        'verification.send' => ['account', null, 'edit'],
        'password.confirm' => ['account', null, 'view'],
        'password.confirm.store' => ['account', null, 'edit'],
        'password.update' => ['account', null, 'edit'],
        'logout' => ['account', null, 'edit'],
        'profile.edit' => ['account', null, 'view'],
        'profile.update' => ['account', null, 'edit'],
        'profile.destroy' => ['account', null, 'delete'],
        'sidebar.counts' => ['account', null, 'view'],
        'sidebar.counts.seen' => ['account', null, 'edit'],
        'notifications.readAll' => ['account', null, 'edit'],
        'notifications.read' => ['account', null, 'edit'],
        'account.status' => ['account', null, 'view'],
        // Placeholder shells for the deferred modules (Task & Follow-up,
        // Call Center, Ads, Support). The route itself whitelists the
        // slugs; any signed-in user may open a shell, as today.
        'module' => ['account', null, 'view'],

        // ------------------------------------------------ platform
        'platform.dashboard' => ['platform', null, 'view'],
        'platform.sellers' => ['platform', null, 'view'],
        'platform.seller' => ['platform', null, 'view'],
        'platform.seller.update' => ['platform', null, 'edit'],
        'platform.seller.subscribe' => ['platform', null, 'edit'],
        'platform.seller.refund' => ['platform', null, 'edit'],
        'platform.seller.payment' => ['platform', null, 'edit'],
        'platform.seller.approve' => ['platform', null, 'edit'],
        'platform.seller.reject' => ['platform', null, 'edit'],
        'platform.packages' => ['platform', null, 'view'],
        'platform.claims' => ['platform', null, 'view'],
        'platform.claims.approve' => ['platform', null, 'edit'],
        'platform.claims.reject' => ['platform', null, 'edit'],
        'platform.storefronts' => ['platform', null, 'view'],
        'platform.storefronts.takedown' => ['platform', null, 'edit'],
        'platform.storefronts.restore' => ['platform', null, 'edit'],
        'platform.storefronts.domainAdded' => ['platform', null, 'edit'],
        'platform.storefronts.reports.resolve' => ['platform', null, 'edit'],
        // Platform-scope Settings surfaces (same URLs as the shop
        // Settings page, platform-global writes).
        'settings.totp' => ['platform', 'settings', 'edit'],
        'settings.channels' => ['platform', 'settings', 'edit'],
        'settings.channels.check' => ['platform', 'settings', 'edit'],
        'settings.packages.store' => ['platform', 'settings', 'edit'],
        'settings.packages.update' => ['platform', 'settings', 'edit'],
        'settings.packages.destroy' => ['platform', 'settings', 'delete'],
        'settings.pricing' => ['platform', 'settings', 'edit'],
        'settings.registration' => ['platform', 'settings', 'edit'],
        'settings.paymentGateway.platform' => ['platform', 'settings', 'edit'],
        'settings.aiReply' => ['platform', 'settings', 'edit'],
        'settings.aiTalker' => ['platform', 'settings', 'edit'],
        'settings.aiTalker.test' => ['platform', 'settings', 'edit'],
        'settings.aiNegotiation' => ['platform', 'settings', 'edit'],
        'settings.aiBehavior' => ['platform', 'settings', 'edit'],
        // The AI panic switch flips a platform-global flag (ai.* is
        // platform scope until it is namespaced per business).
        'messages.panic' => ['platform', 'messages', 'edit'],

        // ------------------------------------------------ shop: dashboard
        'dashboard' => ['shop', 'dashboard', 'view'],
        'dashboard.earnings' => ['shop', 'dashboard', 'view'],

        // ------------------------------------------------ shop: settings
        'settings' => ['shop', 'settings', 'view'],
        'settings.business' => ['shop', 'settings', 'edit'],
        'settings.sources' => ['shop', 'settings', 'edit'],
        'settings.sources.reset' => ['shop', 'settings', 'edit'],
        'settings.templates' => ['shop', 'settings', 'edit'],
        'settings.orderToggles' => ['shop', 'settings', 'edit'],
        'settings.inventory' => ['shop', 'settings', 'edit'],
        'settings.finance' => ['shop', 'settings', 'edit'],
        'settings.marketingSms' => ['shop', 'settings', 'edit'],
        'settings.smsCheck' => ['shop', 'settings', 'edit'],
        'settings.smsTest' => ['shop', 'settings', 'edit'],
        'settings.paymentGateway' => ['shop', 'settings', 'edit'],
        'settings.courierMethods' => ['shop', 'settings', 'edit'],
        'settings.couriers.store' => ['shop', 'settings', 'edit'],
        'settings.couriers.update' => ['shop', 'settings', 'edit'],
        'settings.couriers.toggle' => ['shop', 'settings', 'edit'],
        'settings.couriers.default' => ['shop', 'settings', 'edit'],
        'settings.couriers.preferred' => ['shop', 'settings', 'edit'],
        'settings.couriers.test' => ['shop', 'settings', 'edit'],
        'settings.couriers.syncStores' => ['shop', 'settings', 'edit'],
        'settings.couriers.destroy' => ['shop', 'settings', 'delete'],
        'settings.courierRules.store' => ['shop', 'settings', 'edit'],
        'settings.courierRules.destroy' => ['shop', 'settings', 'delete'],
        'settings.aiRules.store' => ['shop', 'settings', 'edit'],
        'settings.aiRules.update' => ['shop', 'settings', 'edit'],
        'settings.aiRules.destroy' => ['shop', 'settings', 'delete'],
        'settings.aiRules.test' => ['shop', 'settings', 'edit'],
        'settings.aiKnowledge.store' => ['shop', 'settings', 'edit'],
        'settings.aiKnowledge.update' => ['shop', 'settings', 'edit'],
        'settings.aiKnowledge.destroy' => ['shop', 'settings', 'delete'],
        'settings.aiProposals.approve' => ['shop', 'settings', 'edit'],
        'settings.aiProposals.reject' => ['shop', 'settings', 'edit'],
        // Staff & Roles (ধাপ ৪): owner-level only, on top of the
        // settings key — management is not a grantable tick.
        'settings.staffRoles.store' => ['shop', 'settings', 'edit', ['ownerOnly']],
        'settings.staffRoles.update' => ['shop', 'settings', 'edit', ['ownerOnly']],
        'settings.staffRoles.destroy' => ['shop', 'settings', 'delete', ['ownerOnly']],
        'settings.staff.store' => ['shop', 'settings', 'edit', ['ownerOnly']],
        'settings.staff.update' => ['shop', 'settings', 'edit', ['ownerOnly']],

        // ------------------------------------------------ shop: orders
        'orders.index' => ['shop', 'orders', 'view'],
        'orders.create' => ['shop', 'orders', 'view'],
        'orders.store' => ['shop', 'orders', 'edit'],
        'orders.export' => ['shop', 'orders', 'export'],
        'orders.next' => ['shop', 'orders', 'view'],
        'orders.autopick' => ['shop', 'orders', 'view'],
        'orders.autopick.next' => ['shop', 'orders', 'view'],
        'orders.autopick.toggle' => ['shop', 'orders', 'edit'],
        'orders.autocall' => ['shop', 'orders', 'view'],
        'orders.autocall.log' => ['shop', 'orders', 'edit'],
        'orders.blocklist' => ['shop', 'orders', 'view'],
        'orders.blocklist.store' => ['shop', 'orders', 'edit'],
        'orders.blocklist.cleanup' => ['shop', 'orders', 'edit'],
        'orders.blocklist.destroy' => ['shop', 'orders', 'delete'],
        'orders.preorders' => ['shop', 'orders', 'view'],
        'orders.preorders.notify' => ['shop', 'orders', 'edit'],
        'orders.dashboard' => ['shop', 'orders', 'view'],
        'orders.dashboard.export' => ['shop', 'orders', 'export'],
        // Super Edit stays the shop owner's tool (the ধাপ ১ seam in
        // OrderToolsController keeps its own stricter gate beside).
        'orders.superedit' => ['shop', 'orders', 'edit', ['ownerOnly']],
        'orders.superedit.save' => ['shop', 'orders', 'edit', ['ownerOnly']],
        'orders.show' => ['shop', 'orders', 'view'],
        'orders.packingSlip' => ['shop', 'orders', 'view'],
        'orders.update' => ['shop', 'orders', 'edit'],
        'orders.items.add' => ['shop', 'orders', 'edit'],
        'orders.approve' => ['shop', 'orders', 'edit'],
        'orders.sms' => ['shop', 'orders', 'edit'],
        'orders.block' => ['shop', 'orders', 'edit'],
        'orders.destroy' => ['shop', 'orders', 'delete'],
        'orders.invoice' => ['shop', 'orders', 'view'],

        // ------------------------------------------------ shop: delivery
        'delivery.ready' => ['shop', 'delivery', 'view'],
        'delivery.send' => ['shop', 'delivery', 'edit'],
        'delivery.sendBulk' => ['shop', 'delivery', 'edit'],
        'delivery.retry' => ['shop', 'delivery', 'edit'],
        'delivery.changeCourier' => ['shop', 'delivery', 'edit'],
        'delivery.cancelBooking' => ['shop', 'delivery', 'edit'],
        'delivery.forceCheck' => ['shop', 'delivery', 'edit'],
        'delivery.parcel.update' => ['shop', 'delivery', 'edit'],
        'delivery.parcels' => ['shop', 'delivery', 'view'],
        'delivery.entries.export' => ['shop', 'delivery', 'export'],
        'delivery.scan' => ['shop', 'delivery', 'view'],
        'delivery.scan.apply' => ['shop', 'delivery', 'edit'],
        'delivery.ledger' => ['shop', 'delivery', 'view'],
        'delivery.invoice' => ['shop', 'delivery', 'view'],
        'delivery.invoice.preview' => ['shop', 'delivery', 'edit'],
        'delivery.invoice.confirm' => ['shop', 'delivery', 'edit'],
        'delivery.payments.propose' => ['shop', 'delivery', 'edit'],
        'delivery.payments.store' => ['shop', 'delivery', 'edit'],
        'delivery.pull' => ['shop', 'delivery', 'edit'],
        'delivery.allocations.dispute' => ['shop', 'delivery', 'edit'],

        // ------------------------------------------------ shop: finance
        'finance.dashboard' => ['shop', 'finance', 'view'],
        'finance.accounts' => ['shop', 'finance', 'view'],
        'finance.accounts.store' => ['shop', 'finance', 'edit'],
        'finance.accounts.update' => ['shop', 'finance', 'edit'],
        'finance.transactions.store' => ['shop', 'finance', 'edit'],
        'finance.transactions.void' => ['shop', 'finance', 'edit'],
        'finance.transactions.update' => ['shop', 'finance', 'edit'],
        'finance.ie' => ['shop', 'finance', 'view'],
        'finance.ie.export' => ['shop', 'finance', 'export'],
        'finance.categories.store' => ['shop', 'finance', 'edit'],
        'finance.categories.update' => ['shop', 'finance', 'edit'],
        'finance.categories.delete' => ['shop', 'finance', 'delete'],
        'finance.cashflow' => ['shop', 'finance', 'view'],
        'finance.closeMonth' => ['shop', 'finance', 'edit'],
        'finance.dailyClose' => ['shop', 'finance', 'edit'],
        'finance.payoutTask' => ['shop', 'finance', 'edit'],

        // ------------------------------------------------ shop: purchase
        'purchase.dashboard' => ['shop', 'purchase', 'view'],
        'purchases.index' => ['shop', 'purchase', 'view'],
        'purchases.create' => ['shop', 'purchase', 'view'],
        'purchases.store' => ['shop', 'purchase', 'edit'],
        'purchases.quick' => ['shop', 'purchase', 'view'],
        'purchases.quick.store' => ['shop', 'purchase', 'edit'],
        'purchases.receive' => ['shop', 'purchase', 'view'],
        'purchases.receive.store' => ['shop', 'purchase', 'edit'],
        'purchases.pay' => ['shop', 'purchase', 'edit'],
        'purchases.show' => ['shop', 'purchase', 'view'],
        'purchases.destroy' => ['shop', 'purchase', 'delete'],
        'suppliers.index' => ['shop', 'purchase', 'view'],
        'suppliers.store' => ['shop', 'purchase', 'edit'],
        'suppliers.update' => ['shop', 'purchase', 'edit'],
        'suppliers.destroy' => ['shop', 'purchase', 'delete'],
        'suppliers.pay' => ['shop', 'purchase', 'edit'],

        // ------------------------------------------------ shop: hrm
        'hrm.dashboard' => ['shop', 'hrm', 'view'],
        // Own attendance and leave stay open to every staff member of
        // the shop (selfService), exactly as the old SELF_SERVICE list.
        'hrm.attendance' => ['shop', 'hrm', 'view', ['selfService']],
        'hrm.attendance.export' => ['shop', 'hrm', 'export'],
        'hrm.checkin' => ['shop', 'hrm', 'edit', ['selfService']],
        'hrm.checkout' => ['shop', 'hrm', 'edit', ['selfService']],
        'hrm.break' => ['shop', 'hrm', 'edit', ['selfService']],
        'hrm.leaves.apply' => ['shop', 'hrm', 'edit', ['selfService']],
        'hrm.leaves.decide' => ['shop', 'hrm', 'edit'],
        'hrm.departments' => ['shop', 'hrm', 'view'],
        'hrm.departments.store' => ['shop', 'hrm', 'edit'],
        'hrm.departments.update' => ['shop', 'hrm', 'edit'],
        'hrm.departments.destroy' => ['shop', 'hrm', 'delete'],
        'hrm.sops.store' => ['shop', 'hrm', 'edit'],
        'hrm.sops.update' => ['shop', 'hrm', 'edit'],
        'hrm.sops.destroy' => ['shop', 'hrm', 'delete'],
        'hrm.shifts.store' => ['shop', 'hrm', 'edit'],
        'hrm.shifts.update' => ['shop', 'hrm', 'edit'],
        'hrm.shifts.destroy' => ['shop', 'hrm', 'delete'],
        'hrm.shifts.assign' => ['shop', 'hrm', 'edit'],
        'hrm.settings' => ['shop', 'hrm', 'view'],
        'hrm.settings.update' => ['shop', 'hrm', 'edit'],
        'hrm.holidays.store' => ['shop', 'hrm', 'edit'],
        'hrm.holidays.destroy' => ['shop', 'hrm', 'delete'],
        'hrm.payroll' => ['shop', 'hrm', 'view'],
        'hrm.payroll.build' => ['shop', 'hrm', 'edit'],
        'hrm.payroll.items.update' => ['shop', 'hrm', 'edit'],
        'hrm.payroll.pay' => ['shop', 'hrm', 'edit'],

        // ------------------------------------------------ shop: marketing + growth
        'marketing.automation' => ['shop', 'marketing', 'view'],
        'marketing.flows.save' => ['shop', 'marketing', 'edit'],
        'marketing.templates.store' => ['shop', 'marketing', 'edit'],
        'marketing.templates.update' => ['shop', 'marketing', 'edit'],
        'marketing.templates.delete' => ['shop', 'marketing', 'delete'],
        'marketing.sms' => ['shop', 'marketing', 'view'],
        'marketing.sms.send' => ['shop', 'marketing', 'edit'],
        'marketing.report' => ['shop', 'marketing', 'view'],
        'marketing.report.window' => ['shop', 'marketing', 'edit'],
        'marketing.growth' => ['shop', 'marketing', 'view'],
        'marketing.growth.summary' => ['shop', 'marketing', 'view'],
        'marketing.growth.tasks' => ['shop', 'marketing', 'view'],
        'marketing.growth.tasks.status' => ['shop', 'marketing', 'edit'],
        'marketing.growth.tasks.customers' => ['shop', 'marketing', 'view'],
        'marketing.growth.tasks.drafts' => ['shop', 'marketing', 'edit'],
        'marketing.growth.tasks.calls' => ['shop', 'marketing', 'edit'],
        'marketing.growth.why' => ['shop', 'marketing', 'view'],

        // ------------------------------------------------ shop: work inbox
        'workinbox.home' => ['shop', 'workinbox', 'view'],
        'workinbox.control' => ['shop', 'workinbox', 'view'],
        'workinbox.my' => ['shop', 'workinbox', 'view'],
        'workinbox.settings' => ['shop', 'workinbox', 'view'],
        'workinbox.settings.save' => ['shop', 'workinbox', 'edit'],
        'workinbox.tasks.store' => ['shop', 'workinbox', 'edit'],
        'workinbox.tasks.bulkDone' => ['shop', 'workinbox', 'edit'],
        'workinbox.tasks.start' => ['shop', 'workinbox', 'edit'],
        'workinbox.tasks.done' => ['shop', 'workinbox', 'edit'],
        'workinbox.tasks.reassign' => ['shop', 'workinbox', 'edit'],

        // ------------------------------------------------ shop: reports
        'reports.pl' => ['shop', 'reports', 'view'],
        'reports.pl.estimate' => ['shop', 'reports', 'edit'],
        'reports.order' => ['shop', 'reports', 'view'],
        'reports.courier' => ['shop', 'reports', 'view'],
        'reports.product' => ['shop', 'reports', 'view'],
        'reports.employee' => ['shop', 'reports', 'view'],
        'reports.call' => ['shop', 'reports', 'view'],
        'reports.customer' => ['shop', 'reports', 'view'],
        'reports.limits' => ['shop', 'reports', 'view'],
        'reports.export' => ['shop', 'reports', 'export'],

        // ------------------------------------------------ shop: inventory
        'inventory.dashboard' => ['shop', 'inventory', 'view'],
        'inventory.products' => ['shop', 'inventory', 'view'],
        'inventory.products.create' => ['shop', 'inventory', 'view'],
        'inventory.products.store' => ['shop', 'inventory', 'edit'],
        'inventory.products.import' => ['shop', 'inventory', 'edit'],
        'inventory.products.export' => ['shop', 'inventory', 'export'],
        'inventory.products.template' => ['shop', 'inventory', 'export'],
        'inventory.products.update' => ['shop', 'inventory', 'edit'],
        'inventory.products.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.products.copy' => ['shop', 'inventory', 'edit'],
        'inventory.products.toggle' => ['shop', 'inventory', 'edit'],
        'inventory.products.adjust' => ['shop', 'inventory', 'edit'],
        'inventory.categories.store' => ['shop', 'inventory', 'edit'],
        'inventory.categories.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.brands.store' => ['shop', 'inventory', 'edit'],
        'inventory.brands.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.variants.store' => ['shop', 'inventory', 'edit'],
        'inventory.variants.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.groups.store' => ['shop', 'inventory', 'edit'],
        'inventory.groups.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.ledger' => ['shop', 'inventory', 'view'],
        'inventory.reconcile' => ['shop', 'inventory', 'edit'],
        'inventory.branches' => ['shop', 'inventory', 'view'],
        'inventory.branches.toggleStock' => ['shop', 'inventory', 'edit'],
        'inventory.branches.store' => ['shop', 'inventory', 'edit'],
        'inventory.branches.update' => ['shop', 'inventory', 'edit'],
        'inventory.branches.destroy' => ['shop', 'inventory', 'delete'],
        'inventory.transfers.store' => ['shop', 'inventory', 'edit'],
        'inventory.transfers.update' => ['shop', 'inventory', 'edit'],
        'inventory.warranty' => ['shop', 'inventory', 'view'],
        'inventory.warranty.store' => ['shop', 'inventory', 'edit'],
        'inventory.warranty.update' => ['shop', 'inventory', 'edit'],
        'inventory.warranty.settings' => ['shop', 'inventory', 'edit'],

        // ------------------------------------------------ shop: messages + comments
        'messages.inbox' => ['shop', 'messages', 'view'],
        'messages.reply' => ['shop', 'messages', 'edit'],
        'messages.toggleAi' => ['shop', 'messages', 'edit'],
        'messages.drafts.approve' => ['shop', 'messages', 'edit'],
        'messages.drafts.reject' => ['shop', 'messages', 'edit'],
        'messages.activity' => ['shop', 'messages', 'view'],
        'messages.comments' => ['shop', 'messages', 'view'],
        'messages.comments.reply' => ['shop', 'messages', 'edit'],
        'messages.comments.private' => ['shop', 'messages', 'edit'],
        'messages.comments.like' => ['shop', 'messages', 'edit'],
        'messages.comments.hide' => ['shop', 'messages', 'edit'],
        'messages.comments.done' => ['shop', 'messages', 'edit'],
        'messages.comments.destroy' => ['shop', 'messages', 'delete'],
        'messages.commentReplies.approve' => ['shop', 'messages', 'edit'],
        'messages.commentReplies.reject' => ['shop', 'messages', 'edit'],

        // ------------------------------------------------ shop: customers
        'customers.index' => ['shop', 'customers', 'view'],
        'customers.store' => ['shop', 'customers', 'edit'],
        'customers.upload' => ['shop', 'customers', 'view'],
        'customers.upload.store' => ['shop', 'customers', 'edit'],
        'customers.sample' => ['shop', 'customers', 'export'],
        'customers.show' => ['shop', 'customers', 'view'],
        'customers.update' => ['shop', 'customers', 'edit'],
        'customers.destroy' => ['shop', 'customers', 'delete'],
        'customers.blacklist' => ['shop', 'customers', 'edit'],

        // ------------------------------------------------ shop: billing (owner-only key)
        'billing' => ['shop', 'billing', 'view'],
        'billing.claims.store' => ['shop', 'billing', 'edit'],
        'billing.claims.submit' => ['shop', 'billing', 'edit'],

        // ------------------------------------------------ shop: storefront
        'storefront.overview' => ['shop', 'storefront', 'view'],
        'storefront.state' => ['shop', 'storefront', 'view'],
        'storefront.publish' => ['shop', 'storefront', 'edit'],
        'storefront.unpublish' => ['shop', 'storefront', 'edit'],
        'storefront.otp.send' => ['shop', 'storefront', 'edit'],
        'storefront.otp.verify' => ['shop', 'storefront', 'edit'],
        'storefront.wizard' => ['shop', 'storefront', 'view'],
        'storefront.onboarding.save' => ['shop', 'storefront', 'edit'],
        'storefront.wizard.publish' => ['shop', 'storefront', 'edit'],
        'storefront.published' => ['shop', 'storefront', 'view'],
        'storefront.logo' => ['shop', 'storefront', 'edit'],
        'storefront.pages' => ['shop', 'storefront', 'view'],
        'storefront.pages.create' => ['shop', 'storefront', 'edit'],
        'storefront.pages.edit' => ['shop', 'storefront', 'view'],
        'storefront.pages.preview' => ['shop', 'storefront', 'view'],
        'storefront.pages.draft' => ['shop', 'storefront', 'edit'],
        'storefront.pages.publish' => ['shop', 'storefront', 'edit'],
        'storefront.pages.duplicate' => ['shop', 'storefront', 'edit'],
        'storefront.pages.pause' => ['shop', 'storefront', 'edit'],
        'storefront.pages.resume' => ['shop', 'storefront', 'edit'],
        'storefront.pages.restore' => ['shop', 'storefront', 'edit'],
        'storefront.pages.delete' => ['shop', 'storefront', 'delete'],
        'storefront.settings' => ['shop', 'storefront', 'view'],
        'storefront.settings.save' => ['shop', 'storefront', 'edit'],
        'storefront.connect' => ['shop', 'storefront', 'view'],
        'storefront.connect.key' => ['shop', 'storefront', 'edit'],
        'storefront.connect.webhook' => ['shop', 'storefront', 'edit'],
        'storefront.connect.test' => ['shop', 'storefront', 'edit'],
        'storefront.availability' => ['shop', 'storefront', 'view'],
        'storefront.subdomain.save' => ['shop', 'storefront', 'edit'],
        'storefront.branding.save' => ['shop', 'storefront', 'edit'],
        'storefront.builder.save' => ['shop', 'storefront', 'edit'],
        'storefront.images' => ['shop', 'storefront', 'edit'],
        'storefront.domains.request' => ['shop', 'storefront', 'edit'],
        'storefront.domains.verify' => ['shop', 'storefront', 'edit'],
        'storefront.domains.primary' => ['shop', 'storefront', 'edit'],
        'storefront.domains.remove' => ['shop', 'storefront', 'delete'],
        // Old GET pages that redirect into Store Settings / My Pages.
        'storefront.subdomain.page' => ['shop', 'storefront', 'view'],
        'storefront.branding.page' => ['shop', 'storefront', 'view'],
        'storefront.builder.page' => ['shop', 'storefront', 'view'],
        'storefront.domains.page' => ['shop', 'storefront', 'view'],
    ];

    /** Every declaration, keyed by route name (raw stored shape). */
    public static function declarations(): array
    {
        return self::MAP;
    }

    /** The normalized declaration for a route name, or null. */
    public static function for(string $name): ?array
    {
        $row = self::MAP[$name] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'context' => $row[0],
            'key' => $row[1] ?? null,
            'tick' => $row[2] ?? null,
            'flags' => $row[3] ?? [],
        ];
    }

    /**
     * Owner-level inside a shop: the marked owner (ধাপ ১), or a
     * business user carrying the shop-admin role string (the legacy
     * owner shape StaffRolesController's own owner rule accepts).
     * Platform admins are handled separately by callers.
     */
    public static function isOwnerLevel(User $user): bool
    {
        return $user->isShopOwner()
            || ($user->business_id !== null && $user->role === 'admin');
    }

    /**
     * The pure permission decision for one declaration (or route
     * name). Gate-level policies that are NOT permissions — approval
     * pending, zero balance, check-in, TOTP — live in their own
     * middleware and are not decided here.
     */
    public static function allows(User $user, array|string $declaration): bool
    {
        if (is_string($declaration)) {
            $declaration = self::for($declaration);
            if ($declaration === null) {
                return false;
            }
        }

        return match ($declaration['context']) {
            'public', 'account' => true,
            'platform' => $user->isPlatformAdmin(),
            'shop' => self::allowsShop($user, $declaration),
            default => false,
        };
    }

    private static function allowsShop(User $user, array $declaration): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }
        if (! $user->business_id) {
            return false; // gap G-E: no shop, no shop surface
        }
        if (self::isOwnerLevel($user)) {
            return true;
        }
        if (in_array('selfService', $declaration['flags'], true)) {
            return true;
        }
        if (in_array('ownerOnly', $declaration['flags'], true)) {
            return false;
        }
        $key = $declaration['key'];
        if ($key === null) {
            return false;
        }
        // Note N2: owner-only keys are never satisfied by a stored
        // list or a role — owner-level users already returned above.
        if (RolePermissions::isOwnerOnly($key)) {
            return false;
        }
        $ticks = RolePermissions::ticksFor($user);

        return ! empty($ticks[$key][$declaration['tick'] ?? 'view']);
    }

    /** May this user open the module at all (its view tick)? */
    public static function canViewModule(User $user, string $key): bool
    {
        return self::allows($user, ['context' => 'shop', 'key' => $key, 'tick' => 'view', 'flags' => []]);
    }


    /**
     * The sidebar structure, in the exact shape the shell renders.
     * 'key' is the module key that filters the item (the old client
     * MODKEY map, now server-side); an item without a key is shell
     * chrome for every signed-in user. Children ride with their
     * parent, as they always have. The Settings and HRM children and
     * the Platform section are assembled in menuFor() because they
     * depend on the user; the Storefront children are the defaults —
     * the shell still replaces them with the server-computed sfMenu
     * state and adds All Storefronts for platform admins.
     */
    private const MENU = [
        ['sec' => null, 'items' => [
            ['label' => 'Dashboard', 'icon' => 'grid', 'href' => '/dashboard', 'key' => 'dashboard'],
        ]],
        ['sec' => 'Operations', 'items' => [
            ['label' => 'Orders', 'icon' => 'box', 'href' => '/orders', 'key' => 'orders', 'children' => [
                ['label' => 'New Order', 'href' => '/orders/create'],
                ['label' => 'Order List', 'href' => '/orders', 'badgeKey' => 'orders_list'],
                ['label' => 'Auto Pick Orders', 'href' => '/orders/autopick'],
                ['label' => 'Auto Call Center', 'href' => '/orders/autocall'],
                ['label' => 'Order Block List', 'href' => '/orders/block-list'],
                ['label' => 'Preorders', 'href' => '/orders/preorders'],
                ['label' => 'Orders Dashboard', 'href' => '/orders/dashboard'],
            ]],
            ['label' => 'Delivery', 'icon' => 'truck', 'href' => '/delivery/ready', 'key' => 'delivery', 'children' => [
                ['label' => 'Ready to Ship', 'href' => '/delivery/ready', 'badgeKey' => 'delivery_ready'],
                ['label' => 'Courier Management', 'href' => '/delivery/parcels'],
                ['label' => 'Scan To Update', 'href' => '/delivery/scan'],
                ['label' => 'Courier Invoice Upload', 'href' => '/delivery/invoice'],
                ['label' => 'Courier Ledger', 'href' => '/delivery/ledger'],
            ]],
            ['label' => 'Inventory', 'icon' => 'layers', 'href' => '/inventory/dashboard', 'key' => 'inventory', 'children' => [
                ['label' => 'Inventory Dashboard', 'href' => '/inventory/dashboard'],
                ['label' => 'Products', 'href' => '/inventory/products'],
                ['label' => 'Stock Ledger', 'href' => '/inventory/ledger'],
                ['label' => 'Branches', 'href' => '/inventory/branches'],
                ['label' => 'Warranty', 'href' => '/inventory/warranty'],
            ]],
            ['label' => 'Messages', 'icon' => 'chat', 'href' => '/messages/inbox', 'key' => 'messages', 'children' => [
                ['label' => 'Inbox', 'href' => '/messages/inbox', 'badgeKey' => 'messages_inbox'],
                ['label' => 'Comments', 'href' => '/messages/comments'],
                ['label' => 'AI Activity', 'href' => '/messages/activity'],
            ]],
            ['label' => 'Purchase', 'icon' => 'cart', 'href' => '/purchase', 'key' => 'purchase', 'children' => [
                ['label' => 'Purchase Dashboard', 'href' => '/purchase'],
                ['label' => 'Purchases', 'href' => '/purchases', 'badgeKey' => 'purchases_toreceive'],
                ['label' => 'Suppliers', 'href' => '/suppliers'],
            ]],
            ['label' => 'Task & Follow-up', 'icon' => 'check', 'href' => '/module/task', 'key' => 'tasks', 'children' => ['Task Board', 'Agent Cockpit', 'Follow-up Management', 'My Follow-ups', 'Follow-up Performance']],
            ['label' => 'Call Center', 'icon' => 'phone', 'href' => '/module/call-center', 'key' => 'callcenter', 'children' => ['Call Dashboard', 'Call Reports', 'Contacts']],
            ['label' => 'Work Inbox', 'icon' => 'inbox', 'href' => '/work-inbox/control', 'key' => 'workinbox', 'children' => [
                ['label' => 'Work Control', 'href' => '/work-inbox/control', 'badgeKey' => 'work_control'],
                ['label' => 'Settings', 'href' => '/work-inbox/settings'],
            ]],
        ]],
        ['sec' => 'Growth', 'items' => [
            ['label' => 'Ads', 'icon' => 'chart', 'href' => '/module/ads', 'key' => 'ads', 'children' => ['Ads Dashboard', 'Meta Ads', 'TikTok Ads']],
            ['label' => 'Marketing', 'icon' => 'megaphone', 'href' => '/marketing', 'key' => 'marketing', 'children' => [
                ['label' => 'Automation', 'href' => '/marketing'],
                ['label' => 'SMS Marketing', 'href' => '/marketing/sms'],
                ['label' => 'SMS Report', 'href' => '/marketing/report'],
                ['label' => 'Sales Growth', 'href' => '/marketing/growth'],
            ]],
            ['label' => 'Customers', 'icon' => 'users', 'href' => '/customers', 'key' => 'customers', 'children' => [
                ['label' => 'Customer List', 'href' => '/customers'],
                ['label' => 'Bulk Upload', 'href' => '/customers/upload'],
            ]],
            ['label' => 'Storefront', 'icon' => 'store', 'href' => '/storefront', 'key' => 'storefront', 'children' => [
                ['label' => 'Overview', 'href' => '/storefront'],
                ['label' => 'My Pages', 'href' => '/storefront/pages'],
                ['label' => 'Store Settings', 'href' => '/storefront/settings'],
            ]],
        ]],
        ['sec' => 'Business Control', 'items' => [
            ['label' => 'HRM', 'icon' => 'briefcase', 'href' => '/hrm', 'key' => 'hrm', 'children' => [
                ['label' => 'HR Dashboard', 'href' => '/hrm'],
                ['label' => 'Attendance', 'href' => '/hrm/attendance'],
                ['label' => 'Departments & SOP', 'href' => '/hrm/departments'],
                ['label' => 'HR Settings', 'href' => '/hrm/settings'],
            ]],
            ['label' => 'Finance', 'icon' => 'wallet', 'href' => '/finance', 'key' => 'finance', 'children' => [
                ['label' => 'Finance Dashboard', 'href' => '/finance'],
                ['label' => 'Accounts & Bank', 'href' => '/finance/accounts'],
                ['label' => 'Income & Expense', 'href' => '/finance/income-expense'],
                ['label' => 'Cash Flow', 'href' => '/finance/cash-flow'],
            ]],
            ['label' => 'Reports', 'icon' => 'report', 'href' => '/reports', 'key' => 'reports', 'children' => [
                ['label' => 'Profit & Loss', 'href' => '/reports'],
                ['label' => 'Order Report', 'href' => '/reports/order'],
                ['label' => 'Courier Report', 'href' => '/reports/courier'],
                ['label' => 'Product Report', 'href' => '/reports/product'],
                ['label' => 'Employee Report', 'href' => '/reports/employee'],
                ['label' => 'Call Center Report', 'href' => '/reports/call'],
                ['label' => 'Customer Report', 'href' => '/reports/customer'],
            ]],
            ['label' => 'Points & Billing', 'icon' => 'wallet', 'href' => '/billing', 'key' => 'billing'],
            ['label' => 'Settings', 'icon' => 'gear', 'href' => '/settings', 'key' => 'settings', 'settings' => true, 'children' => []],
            ['label' => 'Support', 'icon' => 'help', 'href' => '/module/support', 'children' => ['Help Center', 'Updates']],
        ]],
    ];

    /**
     * The navigation for one user: sidebar sections already filtered
     * by the registry (the shell renders exactly this), plus the
     * topbar action switches. Quick Search derives its index from
     * these same sections client-side, so search can no longer offer
     * a page the user cannot open (gap G-I / G-J).
     */
    public static function menuFor(User $user): array
    {
        $payrollOn = $user->business_id && Schema::hasTable('settings')
            && (bool) app(\App\Services\SettingsService::class)->get('hr.'.$user->business_id.'.payroll_enabled', false);

        $sections = [];
        foreach (self::MENU as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (isset($item['key']) && ! self::canViewModule($user, $item['key'])) {
                    continue;
                }
                if ($item['label'] === 'HRM' && $payrollOn) {
                    $item['children'][] = ['label' => 'Payroll', 'href' => '/hrm/payroll'];
                }
                if (! empty($item['settings'])) {
                    $item['children'] = self::settingsChildren($user);
                }
                unset($item['key']);
                $items[] = $item;
            }
            if ($items !== []) {
                $sections[] = ['sec' => $section['sec'], 'items' => $items];
            }
        }

        if ($user->isPlatformAdmin()) {
            $sections[] = ['sec' => 'Platform', 'items' => [
                ['label' => 'Sellers', 'icon' => 'users', 'href' => '/admin', 'children' => [
                    ['label' => 'Seller Dashboard', 'href' => '/admin'],
                    ['label' => 'All Sellers', 'href' => '/admin/sellers'],
                    ['label' => 'Seller Verification', 'href' => '/admin/claims', 'badgeKey' => 'sellers_pending'],
                ]],
            ]];
        }

        return [
            'sections' => $sections,
            'topbar' => [
                'new_order' => self::allows($user, 'orders.create'),
                'order_list' => self::allows($user, 'orders.index'),
                'send_message' => self::allows($user, 'messages.inbox'),
            ],
        ];
    }

    /** Settings sub-menu groups, with the two conditional children. */
    private static function settingsChildren(User $user): array
    {
        $children = [
            ['label' => 'Settings Dashboard', 'g' => 'home'],
            ['label' => 'Business Settings', 'g' => 'business'],
            ['label' => 'API Connection', 'g' => 'api'],
            ['label' => 'Calls & Orders', 'g' => 'calls'],
            ['label' => 'AI & Auto Reply', 'g' => 'ai'],
            ['label' => 'Security & Records', 'g' => 'security'],
        ];
        if ($user->isPlatformAdmin()) {
            $children[] = ['label' => 'Seller Packages', 'g' => 'packages'];
        }
        if ($user->role === 'admin' && $user->business_id) {
            // Staff & Roles (ধাপ ৪) is the shop owner's alone.
            $children[] = ['label' => 'Staff & Roles', 'g' => 'staff'];
        }

        return $children;
    }
}

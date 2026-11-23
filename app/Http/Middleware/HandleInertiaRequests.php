<?php

namespace App\Http\Middleware;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default. Translations are
     * decoded server-side from lang/{locale}.json and shared with the
     * md5 of the file, so the client caches them by version (TPDA pattern).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $langPath = lang_path($locale.'.json');
        $translations = file_exists($langPath)
            ? (json_decode(file_get_contents($langPath), true) ?: [])
            : [];

        $notifications = ['items' => [], 'unread' => 0];
        if ($request->user() && Schema::hasTable('app_notifications') && Schema::hasColumn('app_notifications', 'business_id')) {
            // Tenant-scoped (G3): a user only ever receives their own
            // shop's notifications (platform rows for platform admins).
            $items = AppNotification::visibleTo($request->user())->latest()->limit(8)->get();
            $notifications = [
                'items' => $items->map(fn ($n) => [
                    'id' => $n->id,
                    'kind' => $n->kind,
                    'title' => $n->title,
                    'body' => $n->body,
                    'link' => $n->link,
                    'read' => $n->read_at !== null,
                    'time' => $n->created_at?->diffForHumans(),
                ])->all(),
                'unread' => AppNotification::visibleTo($request->user())->whereNull('read_at')->count(),
            ];
        }

        // ধাপ ৩: the shell's context. 'platform' = a platform admin
        // acting as the platform (NOT viewing a shop); 'shop' =
        // everyone else, including an admin inside view-as-seller.
        // The workspace business resolves through ShopContext: the
        // viewed shop in view mode, the user's own shop otherwise -
        // the banner (viewAs prop below) is what marks the difference.
        $viewState = $request->user() ? \App\Support\ShopContext::viewState($request) : null;
        $contextBiz = $request->user() ? \App\Support\ShopContext::id($request) : 0;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'context' => ($request->user()?->is_platform_admin && ! $viewState) ? 'platform' : 'shop',
                'business' => ($request->user() && $contextBiz && Schema::hasTable('businesses'))
                    ? \App\Models\Business::whereKey($contextBiz)
                        ->first(['id', 'name', 'public_id'])?->only(['id', 'name', 'public_id'])
                    : null,
                'permissions' => ($request->user() && Schema::hasColumn('users', 'role'))
                    ? $request->user()->effectivePermissions()
                    : \App\Support\Permissions::ALL,
                // Four-tick map (view/edit/delete/export per module,
                // ধাপ ৪) for shells that want action-level checks.
                'permission_ticks' => ($request->user() && Schema::hasColumn('users', 'role_id'))
                    ? $request->user()->permissionTicks()
                    : [],
                'payroll_enabled' => ($request->user() && $request->user()->business_id && Schema::hasTable('settings'))
                    ? (bool) app(\App\Services\SettingsService::class)->get('hr.'.$request->user()->business_id.'.payroll_enabled', false)
                    : false,
            ],
            // ধাপ ২: the sidebar menu, Quick Search source and topbar
            // actions are built server-side from the RouteRegistry,
            // already filtered by the user's contexts and ticks — the
            // shell renders exactly this and never offers a page the
            // PermissionGate would deny.
            'nav' => ($request->user() && Schema::hasColumn('users', 'role_id'))
                ? \App\Support\RouteRegistry::menuFor($request->user(), $viewState['business_id'] ?? null)
                : null,
            // M18 merge: the Storefront sub-menu is computed server-side
            // from store state; the shell renders exactly this. In view
            // mode it is the VIEWED shop's store state (ধাপ ৩).
            'sfMenu' => ($request->user() && $contextBiz && Schema::hasTable('storefronts'))
                ? app(\App\Services\StorefrontService::class)->menuState($contextBiz)
                : null,
            // View-as-seller (ধাপ ৩): present on EVERY response while a
            // view is open, so the shell's read-only banner can never be
            // forgotten by a page. Null outside view mode.
            'viewAs' => $viewState ? (function () use ($viewState) {
                $shop = \App\Models\Business::whereKey($viewState['business_id'])
                    ->first(['id', 'name', 'owner_name']);

                return $shop ? [
                    'business_id' => (int) $shop->id,
                    'shop_name' => $shop->name,
                    'owner_name' => $shop->owner_name,
                    'started_at' => $viewState['started_at'],
                    'expires_at' => $viewState['expires_at'],
                    'seconds_left' => $viewState['seconds_left'],
                ] : null;
            })() : null,
            'locale' => $locale,
            'translations' => $translations,
            'translations_version' => file_exists($langPath) ? md5_file($langPath) : null,
            'notifications' => $notifications,
            // Sidebar badge counts (same SidebarCountsService the shell
            // polls) so the first paint already shows watermarked values.
            'sidebarCounts' => $request->user()
                ? app(\App\Services\SidebarCountsService::class)->countsFor($request->user(), $viewState['business_id'] ?? null)
                : null,
            'tokenGate' => ($request->user() && $request->user()->business_id && Schema::hasTable('business_wallets'))
                ? (function () use ($request) {
                    $tokens = app(\App\Services\TokenService::class);
                    $biz = (int) $request->user()->business_id;

                    return [
                        'balance' => $tokens->balance($biz),
                        'blocked' => $tokens->blocked($biz),
                        'low' => $tokens->isLow($biz),
                        // A shop still waiting for platform approval is
                        // explained by its status page, not by the points
                        // banner — suppress the banner for it entirely.
                        'pending' => in_array(
                            \App\Models\Business::whereKey($biz)->value('status') ?: 'approved',
                            ['pending', 'rejected'],
                            true
                        ),
                    ];
                })()
                : null,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}

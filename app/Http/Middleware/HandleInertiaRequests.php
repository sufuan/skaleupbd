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
        if ($request->user() && Schema::hasTable('app_notifications')) {
            $items = AppNotification::latest()->limit(8)->get();
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
                'unread' => AppNotification::whereNull('read_at')->count(),
            ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // The signed-in user's OWN shop — the workspace label in
                // the shell must name their business, never another one.
                'business' => ($request->user() && $request->user()->business_id && Schema::hasTable('businesses'))
                    ? \App\Models\Business::whereKey($request->user()->business_id)
                        ->first(['id', 'name', 'public_id'])?->only(['id', 'name', 'public_id'])
                    : null,
                'permissions' => ($request->user() && Schema::hasColumn('users', 'role'))
                    ? $request->user()->effectivePermissions()
                    : \App\Support\Permissions::ALL,
                'payroll_enabled' => ($request->user() && $request->user()->business_id && Schema::hasTable('settings'))
                    ? (bool) app(\App\Services\SettingsService::class)->get('hr.'.$request->user()->business_id.'.payroll_enabled', false)
                    : false,
            ],
            'locale' => $locale,
            'translations' => $translations,
            'translations_version' => file_exists($langPath) ? md5_file($langPath) : null,
            'notifications' => $notifications,
            'delivery_waiting' => ($request->user() && $request->user()->business_id && Schema::hasTable('orders'))
                ? \App\Models\Order::where('business_id', $request->user()->business_id)->where('status', 'Processing')->count()
                : 0,
            'orders_pending' => ($request->user() && $request->user()->business_id && Schema::hasTable('orders'))
                ? \App\Models\Order::where('business_id', $request->user()->business_id)->where('status', 'Pending')->count()
                : 0,
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

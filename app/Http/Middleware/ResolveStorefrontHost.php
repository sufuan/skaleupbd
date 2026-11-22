<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StorefrontPublicController;
use App\Services\StorefrontService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M18 — tenant host resolution. Runs inside the web group (after
 * session + CSRF) but before the app's own middleware: on a storefront
 * host only the public shop paths exist; the dashboard/app routes stay
 * reachable on the platform's own host only. Unknown subdomains of the
 * platform domain get a plain not-found page — never another shop.
 */
class ResolveStorefrontHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $svc = app(StorefrontService::class);
        $host = $svc->normalizeHost($request->getHost());

        // www.<custom domain> → the bare domain is canonical (301).
        if (str_starts_with($host, 'www.') && ! $svc->isMainHost($host)) {
            $bare = substr($host, 4);
            if ($svc->resolveHost($bare)) {
                return redirect()->to($request->getScheme().'://'.$bare.$request->getRequestUri(), 301);
            }
        }

        if ($svc->isMainHost($host)) {
            return $next($request);
        }

        $resolved = $svc->resolveHost($host);
        if (! $resolved) {
            return response()->view('storefront.notfound', ['domain' => $svc->platformDomain()], 404);
        }

        // Static assets (uploaded logos/product photos, built files) are
        // served by the web server when they exist; let them pass so a
        // missing file 404s naturally instead of redirecting to the shop.
        $path = '/'.trim($request->path(), '/');
        if (preg_match('#^/(storage|build|css|js)/#', $path) || $path === '/favicon.ico') {
            return $next($request);
        }

        $sf = $resolved['storefront'];
        $request->attributes->set('storefront', $sf);
        $request->attributes->set('storefront_kind', $resolved['kind']);

        $isPublic = ($request->isMethod('GET') && ($path === '/' || preg_match('#^/p/\d+$#', $path)
                || preg_match('#^/order-success/[^/]+$#', $path) || $path === '/report'
                || preg_match('#^/[a-z0-9][a-z0-9-]*$#', $path)))
            || ($request->isMethod('POST') && ($path === '/order' || $path === '/report'));
        if (! $isPublic) {
            return redirect()->to($request->getScheme().'://'.$host.'/');
        }
        if ($path === '/' && $request->isMethod('GET')) {
            return app(StorefrontPublicController::class)->home($request);
        }
        if ($request->isMethod('GET') && preg_match('#^/([a-z0-9][a-z0-9-]*)$#', $path, $m)
            && ! in_array($m[1], ['report'], true)) {
            return app(StorefrontPublicController::class)->page($request, $m[1]);
        }

        return $next($request);
    }
}

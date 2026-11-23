<?php

// Goes in: app/Http/Controllers/Integrations/ApiKeyController.php (new file).
// Backs the key-management section of the Connect page.
// Handles: list keys, create a new key (with scopes), revoke a key.
//
// Security: business always comes from the authenticated seller session, never from request input.
// The raw key is returned ONCE on creation; thereafter only last4 is shown.
// Revocation sets revoked_at; the key_hash row stays so audit logs remain intact.

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Integrations\IntegrationHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    // Valid scope tokens. "*" is never accepted from the browser; full access is the default for
    // backward compatibility only and cannot be explicitly re-assigned from the UI.
    private const VALID_SCOPES = ['orders.read', 'orders.write', 'products.read', 'products.write', 'stock.read'];

    public function __construct(private readonly IntegrationHost $host)
    {
    }

    // ------------------------------------------------------------------ list

    /**
     * GET /storefront/integrations/keys
     * Returns all non-revoked keys for the shop (last4, label, scopes, created_at).
     */
    public function index(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);
        $keys = ApiKey::query()
            ->forBusiness($biz)
            ->active()
            ->orderByDesc('id')
            ->get(['id', 'last4', 'scopes', 'last_used_at', 'created_at'])
            ->map(fn (ApiKey $k) => [
                'id'          => $k->id,
                'last4'       => $k->last4,
                'scopes'      => $k->scopes ?? ['*'],
                'lastUsedAt'  => $k->last_used_at?->toIso8601String(),
                'createdAt'   => $k->created_at?->toIso8601String(),
            ]);

        return $this->json(['ok' => true, 'keys' => $keys]);
    }

    // ------------------------------------------------------------------ create

    /**
     * POST /storefront/integrations/keys
     * Body: { "scopes": ["orders.read", "products.write", ...] }
     * Returns the full raw key ONCE. Store it immediately.
     */
    public function store(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);

        $data = $request->validate([
            'scopes'   => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:' . implode(',', self::VALID_SCOPES)],
        ]);

        // Throttle: max 10 active keys per shop.
        $active = ApiKey::query()->forBusiness($biz)->active()->count();
        if ($active >= 10) {
            return $this->json(['ok' => false, 'message' => 'You can have at most 10 active keys. Revoke an old key first.'], 422);
        }

        $rawKey = 'sk_' . Str::random(48);

        $key = ApiKey::create([
            'business_id' => $biz,
            // One hash strategy platform-wide: sha256, the same lookup our
            // existing external order intake has always used. Never bcrypt.
            'key_hash'    => hash('sha256', $rawKey),
            'last4'       => substr($rawKey, -4),
            'scopes'      => $data['scopes'],
        ]);

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.api_key.created', [
            'key_id' => $key->id,
            'scopes' => $data['scopes'],
        ]);

        return $this->json([
            'ok'  => true,
            'key' => $rawKey,   // returned once only
            'id'  => $key->id,
            'last4' => $key->last4,
            'scopes' => $key->scopes,
        ]);
    }

    // ------------------------------------------------------------------ revoke

    /**
     * DELETE /storefront/integrations/keys/{id}
     * Soft-revokes a key (sets revoked_at). The row stays for audit purposes.
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);

        // Scope to the shop: a seller cannot revoke another shop's key.
        $key = ApiKey::query()->forBusiness($biz)->active()->findOrFail($id);
        $key->update(['revoked_at' => now()]);

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.api_key.revoked', [
            'key_id' => $id,
            'last4'  => $key->last4,
        ]);

        return $this->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ helper

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}

<?php

// Goes in: app/Services/Integrations/IntegrationHost.php (new file).
//
// THE ONLY FILE THAT TOUCHES EXISTING PLATFORM CODE I HAVE NOT SEEN. Four methods marked WIRE.
// Everything else in the integration layer calls these instead of reaching into the platform directly,
// so the integrator wires four small things once and every later stage reuses them.

namespace App\Services\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IntegrationHost
{
    /**
     * WIRED: the shop's current point balance, from the real wallet
     * (TokenService::balance - the same figure the billing pages show).
     * The owner's rule is literal: zero points means our services stop,
     * so this is the raw balance, not TokenService::canAfford() (which
     * compares against the dynamic points-per-order figure).
     */
    public function pointsAvailable(int $businessId): int
    {
        return app(\App\Services\TokenService::class)->balance($businessId);
    }

    /** Zero points means the connection is suspended (the owner's rule). */
    public function hasPoints(int $businessId): bool
    {
        return $this->pointsAvailable($businessId) > 0;
    }

    /** WIRE 2: the business behind the logged-in seller. Adjust to however AppLayout pages resolve it. */
    public function currentBusinessId(Request $request): int
    {
        $id = (int) ($request->user()?->business_id ?? 0);
        abort_if($id <= 0, 403);

        return $id;
    }

    /** The shop's public id (the "businessId" shown on the Connect page). Assumes businesses.public_id. */
    public function businessPublicId(int $businessId): string
    {
        return (string) (DB::table('businesses')->where('id', $businessId)->value('public_id') ?? '');
    }

    /**
     * WIRE 3: write to the existing audit log. Default writes a structured log line.
     * Never pass secrets, keys, or payload bodies in $meta.
     */
    public function audit(int $businessId, ?int $userId, string $action, array $meta = []): void
    {
        Log::info('integration.audit', ['business_id' => $businessId, 'user_id' => $userId, 'action' => $action] + $meta);
    }

    /**
     * WIRED: create a real Work Inbox task for the shop. WorkInboxService
     * requires a User actor, so the task is raised in the shop owner's
     * name (the shopOwner pattern) and assigned to them. If the shop has
     * no owner user at all, fall back to the log line rather than fail.
     */
    public function workInboxTask(int $businessId, string $title, string $body): void
    {
        $owner = \App\Models\User::shopOwner($businessId);

        if (! $owner) {
            Log::warning('integration.work_inbox', ['business_id' => $businessId, 'title' => $title]);

            return;
        }

        app(\App\Services\WorkInboxService::class)->createManual($businessId, $owner, [
            'title' => $title,
            'detail' => $body,
            'assign' => 'self',
        ]);
    }
}

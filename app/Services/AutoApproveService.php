<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Auto-approve: an incoming order whose customer's final success rate is
 * at or above the shop's own minimum approves itself (Pending -> Ready to
 * Ship) through the normal approval path, so every approve-time rule
 * (stock reservation, over-stock block, fraud re-check) still holds.
 *
 * The rule lives here only. It is evaluated at order intake (when the
 * intake snapshot is already final) and again when RefreshFraudCheckJob
 * completes the courier-network snapshot. Evaluation reads the
 * cache-only check, so it never waits on a courier API.
 */
class AutoApproveService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SuccessRateService $fraud,
        private readonly OrderService $orders,
    ) {}

    public function evaluate(Order $order): bool
    {
        $biz = (int) $order->business_id;

        if (! (bool) $this->settings->bizGet($biz, "orders.{$biz}.auto_approve_enabled", 'orders.auto_approve_enabled', true)) {
            return false;
        }
        $threshold = (int) $this->settings->bizGet($biz, "orders.{$biz}.auto_approve_threshold", 'orders.auto_approve_threshold', 60);
        $threshold = max(1, min(100, $threshold));

        $order = $order->fresh();
        if (! $order || $order->status !== 'Pending' || $order->is_preorder) {
            return false;
        }

        // Cache-only: a still-pending network figure means the snapshot is
        // not final yet; the refresh job re-evaluates when it lands.
        $check = $this->fraud->forPhone($biz, (string) $order->customer_phone, deferNetwork: true);
        if ($check['network_pending'] ?? false) {
            return false;
        }
        // A blocked or blacklisted number never auto-approves, however
        // good its rate looks.
        if ($check['blocked'] ?? false) {
            return false;
        }
        $rate = $check['rate'];
        if ($rate === null || $rate < $threshold) {
            return false;
        }

        $owner = User::shopOwner($biz);
        if (! $owner) {
            return false;
        }

        try {
            $this->orders->approve($order, $owner);
        } catch (ValidationException) {
            // Approval refused (e.g. not enough stock): the order simply
            // stays Pending for a human, nothing escapes.
            return false;
        }

        $order->logActivity('system', "Auto-approved - customer success rate {$rate}%, shop minimum {$threshold}%", $owner);

        return true;
    }
}

<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\CourierBookingLog;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkEvent;
use App\Services\Couriers\DriverFactory;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(private OrderService $orders, private SuggestCourierService $suggester) {}

    /**
     * Book one Processing order with the given (or suggested) courier.
     * @return array{ok:bool, error?:string, method?:CourierMethod, tracking_id?:string, needs_cn?:bool}
     */
    public function send(Order $order, ?int $methodId, ?string $cn, ?int $pickupStoreId, User $actor, string $mode = 'manual_override'): array
    {
        if ($order->status !== 'Processing') {
            return ['ok' => false, 'error' => 'Only Processing orders can be sent to a courier'];
        }
        // Atomic claim: courier APIs can take ~60s, and a second overlapping
        // send (double click, two tabs, bulk + single) used to pass the
        // status check above before the first finished — booking TWO
        // parcels at the courier for one order, each with its own CN, the
        // order keeping only the last. Claim the row with one UPDATE; only
        // the winner talks to the courier. Failure paths below release the
        // claim by writing 'failed'/'booked' as before.
        $claimed = Order::where('id', $order->id)
            ->where('status', 'Processing')
            ->whereNull('tracking_id')->whereNull('consignment_id')
            ->where(fn ($q) => $q->whereNull('booking_status')
                ->orWhereIn('booking_status', ['queued', 'failed', 'retrying', 'fallback', 'cancelled']))
            ->update(['booking_status' => 'sending']);
        if ($claimed !== 1) {
            $fresh = Order::find($order->id);
            if ($fresh && ($fresh->tracking_id || $fresh->consignment_id)) {
                return ['ok' => true, 'method' => $fresh->courierMethod, 'tracking_id' => $fresh->tracking_id, 'already_booked' => true];
            }
            return ['ok' => false, 'error' => 'This order is already being sent to a courier'];
        }
        $order->booking_status = 'sending';
        // The claim above wrote 'sending' through the query builder, so
        // sync the model's original state to match — otherwise setting
        // 'failed' back on a failure path looks "unchanged" to Eloquent
        // (the loaded value was already 'failed') and is never saved,
        // leaving the order stuck in 'sending' and un-retryable.
        $order->syncOriginal();
        $method = $methodId
            ? CourierMethod::where('business_id', $order->business_id)->where('is_active', true)->find($methodId)
            : $this->suggester->suggest($order)['method'] ?? null;
        if (! $method) {
            $order->update(['booking_status' => 'failed']);

            return ['ok' => false, 'error' => 'No courier configured yet'];
        }
        $suggestion = $this->suggester->suggest($order);
        if ($mode !== 'manual_cn' && $suggestion && $suggestion['method']->id === $method->id && ! $methodId) {
            $mode = 'suggested';
        }
        $storeId = $pickupStoreId ?? ($suggestion['pickup_store_id'] ?? null) ?? $order->pickup_store_id;

        $driver = DriverFactory::for($method);
        $isManual = $method->bookingMode() === 'manual';
        try {
            // The resolved store is a LOCAL pickup-store row id; drivers
            // need the courier-side store_id from that row. Passing null
            // here made couriers reject first bookings with "needs a
            // pickup store" even when the shop had stores synced.
            $storeRef = $storeId ? \App\Models\CourierPickupStore::find($storeId)?->store_id : null;
            $result = $driver->createBooking($order, $method, ['cn' => $cn, 'store_id' => $storeRef]);
        } catch (\Throwable $e) {
            // Release the claim above so the order is not stuck in 'sending'.
            $order->update(['courier_method_id' => $method->id, 'booking_status' => 'failed']);
            // An exception (timeout, network) is still a FAILED BOOKING —
            // it must leave a log row, or the failure counter behind the
            // Work Inbox rule never sees it (the OB289 miss: its timeout-era
            // attempts threw and were never counted).
            CourierBookingLog::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'courier_method_id' => $method->id,
                'pickup_store_id' => $storeId,
                'mode' => $isManual ? 'manual_cn' : $mode,
                'booking_status' => 'failed',
                'request_payload' => ['invoice' => $order->invoice, 'cod' => $order->codAmount(), 'courier' => $method->courier_type],
                'error' => mb_substr($e->getMessage(), 0, 250),
                'attempts' => CourierBookingLog::where('order_id', $order->id)->count() + 1,
                'created_by' => 'user',
            ]);
            $this->maybeRaiseFailureEvent($order, $method);
            throw $e;
        }

        $log = CourierBookingLog::create([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'courier_method_id' => $method->id,
            'pickup_store_id' => $storeId,
            'mode' => $isManual ? 'manual_cn' : $mode,
            'booking_status' => $result['ok'] ? 'booked' : 'failed',
            'tracking_id' => $result['tracking_id'] ?? null,
            'consignment_id' => $result['consignment_id'] ?? null,
            'label_url' => $result['label_url'] ?? null,
            'request_payload' => ['invoice' => $order->invoice, 'cod' => $order->codAmount(), 'courier' => $method->courier_type],
            'response_payload' => isset($result['raw']) ? (is_array($result['raw']) ? $result['raw'] : ['raw' => $result['raw']]) : null,
            'error' => $result['ok'] ? null : ($result['error'] ?? 'Booking failed'),
            'attempts' => CourierBookingLog::where('order_id', $order->id)->count() + 1,
            'created_by' => 'user',
        ]);

        if (! $result['ok']) {
            $order->update(['courier_method_id' => $method->id, 'booking_status' => 'failed']);
            $order->logActivity('action', 'Booking failed with ' . $method->label() . ': ' . ($result['error'] ?? 'unknown error'), $actor);
            $this->maybeRaiseFailureEvent($order, $method);

            return ['ok' => false, 'error' => $result['error'] ?? 'Booking failed', 'method' => $method, 'needs_cn' => ! empty($result['manual_fallback'])];
        }

        $order->courier_method_id = $method->id;
        $order->pickup_store_id = $storeId;
        $order->tracking_id = $result['tracking_id'] ?? null;
        $order->consignment_id = $result['consignment_id'] ?? null;
        $order->booking_status = 'booked';
        $order->courier_status = 'Booked';
        $order->delivery_method = $method->label() . ' - ' . (CourierMethod::isInsideDhaka($order->effectiveDistrict()) ? 'Inside Dhaka' : 'Outside Dhaka');
        $order->shipped_at = now();
        $order->save();
        $this->orders->changeStatus($order, 'Shipped', $actor, 'Sent via ' . $method->label() . ' · CN ' . ($order->consignment_id ?: $order->tracking_id));

        return ['ok' => true, 'method' => $method, 'tracking_id' => $order->tracking_id];
    }

    public function retry(Order $order, User $actor): array
    {
        return $this->send($order, $order->courier_method_id, null, $order->pickup_store_id, $actor);
    }

    /** Change courier before pickup: clears the pickup store and any tracking (§10.7b). */
    public function changeCourier(Order $order, int $methodId, User $actor): array
    {
        if ($order->status !== 'Processing') {
            return ['ok' => false, 'error' => 'The courier can only be changed before the parcel is shipped'];
        }
        $method = CourierMethod::where('business_id', $order->business_id)->find($methodId);
        if (! $method) {
            return ['ok' => false, 'error' => 'Courier not found'];
        }
        if ($order->tracking_id || $order->consignment_id) {
            CourierBookingLog::create([
                'business_id' => $order->business_id, 'order_id' => $order->id,
                'courier_method_id' => $order->courier_method_id, 'mode' => 'manual_override',
                'booking_status' => 'cancelled', 'tracking_id' => $order->tracking_id,
                'consignment_id' => $order->consignment_id, 'created_by' => 'user',
            ]);
        }
        $order->update([
            'courier_method_id' => $method->id, 'pickup_store_id' => null,
            'tracking_id' => null, 'consignment_id' => null,
            'courier_status' => null, 'booking_status' => 'queued',
        ]);
        $order->logActivity('action', 'Courier changed to ' . $method->label() . ' - pickup store and tracking cleared', $actor);

        return ['ok' => true, 'method' => $method];
    }

    public function cancelBooking(Order $order, User $actor): array
    {
        if ($order->courierMethod) {
            DriverFactory::for($order->courierMethod)->cancelBooking($order, $order->courierMethod);
        }
        CourierBookingLog::create([
            'business_id' => $order->business_id, 'order_id' => $order->id,
            'courier_method_id' => $order->courier_method_id, 'mode' => 'manual_override',
            'booking_status' => 'cancelled', 'tracking_id' => $order->tracking_id,
            'consignment_id' => $order->consignment_id, 'created_by' => 'user',
        ]);
        $order->update(['booking_status' => 'cancelled', 'tracking_id' => null, 'consignment_id' => null, 'courier_status' => null]);
        $order->logActivity('action', 'Courier booking cancelled', $actor);

        return ['ok' => true];
    }

    /** Pull the courier's own status; follow it into the lifecycle when it lands. */
    public function forceCheck(Order $order, User $actor): array
    {
        if (! $order->courierMethod || ! $order->tracking_id) {
            return ['ok' => false, 'error' => 'This parcel has no courier booking to check'];
        }
        $result = DriverFactory::for($order->courierMethod)->getStatus($order, $order->courierMethod);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Status check failed'];
        }
        $courierStatus = (string) $result['courier_status'];
        $this->applyCourierStatus($order, $courierStatus, $actor);

        // Report the APPLIED status, not the courier's raw reply: the
        // forward-only guard may have kept the stored status, and the
        // caller's message must never contradict the record.
        return ['ok' => true, 'courier_status' => $order->courier_status, 'reported' => $courierStatus];
    }

    /**
     * Record a courier-reported status and follow it into the order lifecycle.
     * Money-critical guards (from Steadfast's real status vocabulary, applied to all couriers):
     *  - anything ending in _approval_pending is the rider's word, NOT confirmed — never final;
     *  - a partial delivery is never the whole order delivered (its full COD must not count as collected);
     *  - a return still travelling back (rider assigned / processing) is not yet back with the seller.
     */
    public function applyCourierStatus(Order $order, string $courierStatus, ?User $actor): void
    {
        // Concurrency (Hunt Z1-9): the decision below reads the
        // order's status, and the caller's copy can be stale - two
        // deliveries of the same courier event, each holding a
        // pre-transition copy, could both decide "this delivers".
        // The whole application runs in one transaction on the row
        // locked and re-read here, so the second delivery decides on
        // the winner's committed state (and changeStatus's same-state
        // no-op, Z1-5, closes what is left). The caller's model is
        // synced back to the applied state before returning.
        DB::transaction(function () use ($order, $courierStatus, $actor) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }
            $this->applyCourierStatusLocked($fresh, $courierStatus, $actor);
            $order->setRawAttributes($fresh->getAttributes());
            $order->syncOriginal();
        });
    }

    /** applyCourierStatus()'s body, run on the locked row inside its transaction. */
    private function applyCourierStatusLocked(Order $order, string $courierStatus, ?User $actor): void
    {
        $lower = strtolower($courierStatus);

        $recordOnly = ! $actor || str_contains($lower, 'approval_pending') || str_contains($lower, 'partial');
        // Only explicit arrival words close a return: return_initiated / return_pending /
        // return_cancelled / *_rider_assigned mean the parcel is still travelling (or not coming).
        $returnArrived = str_contains($lower, 'return_received') || str_contains($lower, 'return_delivered')
            || str_contains($lower, 'return_accepted') || str_contains($lower, 'returned');
        $delivers = ! $recordOnly && str_contains($lower, 'delivered') && ! str_contains($lower, 'return') && $order->status === 'Shipped';
        $returns = ! $recordOnly && $returnArrived && in_array($order->status, ['Shipped', 'Delivered'], true);

        // M5: forward-only, mirroring the SMS delivery-report guard
        // (dlrRank/dlrMovesForward). A stale report - the mock driver
        // always answers "In Transit", a webhook can also arrive late -
        // must never move the recorded courier status backwards. A report
        // that actually drives the lifecycle (delivered / return arrived)
        // always records itself.
        if ($delivers || $returns || $this->courierMovesForward($order->courier_status, $courierStatus)) {
            $order->courier_status = $courierStatus;
        }

        if ($recordOnly) {
            $order->save();

            return;
        }
        if ($delivers) {
            $order->delivered_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Delivered', $actor, 'Courier confirmed delivery');

            return;
        }
        if ($returns) {
            $order->returned_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Returned', $actor, 'Courier returned the parcel');

            return;
        }
        $order->save();
    }

    /**
     * Forward-only rank for a courier-reported status: [rank, class].
     * Check order matters - 'undelivered' and 'out_for_delivery' contain
     * 'deliver', and anything with 'approval_pending' is unconfirmed even
     * when it also says delivered.
     *
     * @return array{0: int, 1: string}
     */
    private function courierRank(?string $status): array
    {
        $s = strtolower(trim((string) $status));
        if ($s === '') {
            return [0, 'none'];
        }
        if (str_contains($s, 'return')) {
            $arrived = str_contains($s, 'return_received') || str_contains($s, 'return_delivered')
                || str_contains($s, 'return_accepted') || str_contains($s, 'returned');

            return $arrived ? [5, 'returned'] : [3, 'returning'];
        }
        if (str_contains($s, 'cancel')) {
            return [5, 'cancelled'];
        }
        if (str_contains($s, 'approval_pending')) {
            return [4, 'approval'];
        }
        if (str_contains($s, 'undeliver') || str_contains($s, 'not_delivered') || str_contains($s, 'fail')) {
            return [4, 'failed'];
        }
        if (str_contains($s, 'out_for_delivery') || str_contains($s, 'out for delivery')
            || str_contains($s, 'assigned') || str_contains($s, 'rider')) {
            return [3, 'out'];
        }
        if (str_contains($s, 'deliver')) {
            return str_contains($s, 'partial') ? [4, 'partial'] : [5, 'delivered'];
        }
        if (str_contains($s, 'partial')) {
            return [4, 'partial'];
        }
        if (str_contains($s, 'hold')) {
            return [3, 'hold'];
        }
        if (str_contains($s, 'transit') || str_contains($s, 'picked') || str_contains($s, 'hub')
            || str_contains($s, 'processing') || str_contains($s, 'in_progress')) {
            return [2, 'transit'];
        }

        return [1, 'booked'];
    }

    /** A courier report may apply only if it moves the status forward (or repeats its own state). */
    private function courierMovesForward(?string $current, string $reported): bool
    {
        [$curRank, $curClass] = $this->courierRank($current);
        [$newRank, $newClass] = $this->courierRank($reported);
        if ($newRank > $curRank) {
            return true;
        }

        return $newRank === $curRank && $newClass === $curClass;
    }

    private function maybeRaiseFailureEvent(Order $order, CourierMethod $method): void
    {
        $failures = CourierBookingLog::where('order_id', $order->id)->where('booking_status', 'failed')->count();
        if ($failures < 3) {
            return;
        }
        $link = '/orders/' . $order->id;
        if (WorkEvent::where('business_id', $order->business_id)->where('kind', 'booking_failure')->where('link', $link)->exists()) {
            return;
        }
        WorkEvent::create([
            'business_id' => $order->business_id, 'kind' => 'booking_failure',
            'title' => 'Booking keeps failing for ' . $order->invoice,
            'body' => 'Sending ' . $order->invoice . ' with ' . $method->label() . ' failed ' . $failures . ' times. Check the courier connection or pick another courier.',
            'link' => $link,
        ]);
        AppNotification::create([
            'business_id' => $order->business_id,
            'kind' => 'delivery',
            'title' => 'Booking keeps failing - ' . $order->invoice,
            'body' => $method->label() . ' booking failed ' . $failures . ' times for this order.',
            'link' => $link,
        ]);
    }
}

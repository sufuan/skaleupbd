<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\CourierBookingLog;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkEvent;
use App\Services\Couriers\DriverFactory;

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
        $order->delivery_method = $method->label() . ' - ' . (strtolower((string) $order->effectiveDistrict()) === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka');
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

        return ['ok' => true, 'courier_status' => $courierStatus];
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
        $order->courier_status = $courierStatus;
        $lower = strtolower($courierStatus);

        if (! $actor || str_contains($lower, 'approval_pending') || str_contains($lower, 'partial')) {
            $order->save();

            return;
        }
        if (str_contains($lower, 'delivered') && ! str_contains($lower, 'return') && $order->status === 'Shipped') {
            $order->delivered_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Delivered', $actor, 'Courier confirmed delivery');

            return;
        }
        // Only explicit arrival words close a return: return_initiated / return_pending /
        // return_cancelled / *_rider_assigned mean the parcel is still travelling (or not coming).
        $returnArrived = str_contains($lower, 'return_received') || str_contains($lower, 'return_delivered')
            || str_contains($lower, 'return_accepted') || str_contains($lower, 'returned');
        if ($returnArrived && in_array($order->status, ['Shipped', 'Delivered'], true)) {
            $order->returned_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Returned', $actor, 'Courier returned the parcel');

            return;
        }
        $order->save();
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

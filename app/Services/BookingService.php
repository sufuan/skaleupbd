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
        $method = $methodId
            ? CourierMethod::where('business_id', $order->business_id)->where('is_active', true)->find($methodId)
            : $this->suggester->suggest($order)['method'] ?? null;
        if (! $method) {
            return ['ok' => false, 'error' => 'No courier configured yet'];
        }
        $suggestion = $this->suggester->suggest($order);
        if ($mode !== 'manual_cn' && $suggestion && $suggestion['method']->id === $method->id && ! $methodId) {
            $mode = 'suggested';
        }
        $storeId = $pickupStoreId ?? ($suggestion['pickup_store_id'] ?? null) ?? $order->pickup_store_id;

        $driver = DriverFactory::for($method);
        $isManual = $method->bookingMode() === 'manual';
        $result = $driver->createBooking($order, $method, ['cn' => $cn, 'store_id' => null]);

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
        $order->delivery_method = $method->label() . ' — ' . (strtolower((string) $order->district) === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka');
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
        $order->logActivity('action', 'Courier changed to ' . $method->label() . ' — pickup store and tracking cleared', $actor);

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
        $order->courier_status = $courierStatus;
        $lower = strtolower($courierStatus);
        if (str_contains($lower, 'delivered') && ! str_contains($lower, 'return') && $order->status === 'Shipped') {
            $order->delivered_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Delivered', $actor, 'Courier confirmed delivery');
        } elseif ((str_contains($lower, 'return') || str_contains($lower, 'rts')) && in_array($order->status, ['Shipped', 'Delivered'], true)) {
            $order->returned_at = now();
            $order->save();
            $this->orders->changeStatus($order, 'Returned', $actor, 'Courier returned the parcel');
        } else {
            $order->save();
        }

        return ['ok' => true, 'courier_status' => $courierStatus];
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
            'kind' => 'delivery',
            'title' => 'Booking keeps failing — ' . $order->invoice,
            'body' => $method->label() . ' booking failed ' . $failures . ' times for this order.',
            'link' => $link,
        ]);
    }
}

<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;

interface CourierDriver
{
    /** @return array{ok:bool, tracking_id?:string, consignment_id?:string, label_url?:string, raw?:mixed, error?:string} */
    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array;

    /** @return array{ok:bool, courier_status?:string, raw?:mixed, error?:string} */
    public function getStatus(Order $order, CourierMethod $method): array;

    /** @return array{ok:bool, error?:string} */
    public function cancelBooking(Order $order, CourierMethod $method): array;

    /** Network fraud/success check by phone. Null when this courier has no check or no data.
     * @return array{rate:?float, delivered:?int, returned:?int, label:?string}|null */
    public function fraudCheck(string $phone, CourierMethod $method): ?array;

    /** @return array{ok:bool, message:string} */
    public function testConnection(CourierMethod $method): array;

    /** @return array<int, array{store_id:string, name:string}> */
    public function syncStores(CourierMethod $method): array;

    /** Class A settlement payouts. @return array<int, array> */
    public function getPayments(CourierMethod $method): array;

    /** Consignments covered by one payout. @return array<int, array{invoice:string, amount:?float}> */
    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array;

    /** Per-parcel collection state (Carrybee / eCourier). @return array{collected:?float, cod_fee:?float, delivery_fee:?float, payment_status:?string}|null */
    public function parcelCollection(Order $order, CourierMethod $method): ?array;
}

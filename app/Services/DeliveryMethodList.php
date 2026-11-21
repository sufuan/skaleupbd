<?php

namespace App\Services;

use App\Models\CourierMethod;

/**
 * The delivery-method names a shop's order forms offer ("Pathao —
 * Inside Dhaka", …). These names belong to the shop that connected the
 * couriers — the list is stored per business (couriers.{biz}.methods).
 * It used to be one global list, so a brand-new seller's order form
 * offered couriers another shop had connected. A shop with no courier
 * connected yet gets an empty list.
 */
class DeliveryMethodList
{
    public function __construct(private readonly SettingsService $settings) {}

    public function key(int $biz): string
    {
        return "couriers.{$biz}.methods";
    }

    /** Names implied by the business's own active courier connections. */
    public function derived(int $biz): array
    {
        $out = [];
        foreach (CourierMethod::where('business_id', $biz)->where('is_active', true)->orderBy('id')->get() as $m) {
            if ($m->bookingMode() === 'manual') {
                $out[] = $m->label();
            } else {
                $out[] = $m->label().' — Inside Dhaka';
                $out[] = $m->label().' — Outside Dhaka';
            }
        }

        return $out;
    }

    /** The list this business's order forms and filters offer. */
    public function forBusiness(int $biz): array
    {
        $saved = $this->settings->get($this->key($biz));

        return is_array($saved) ? array_values($saved) : $this->derived($biz);
    }

    public function put(int $biz, array $list): void
    {
        $this->settings->set($this->key($biz), array_values($list));
    }

    /** After connections change: keep the shop's list, add any name its
     *  active connections imply that is not there yet. */
    public function syncFromConnections(int $biz): void
    {
        $list = $this->forBusiness($biz);
        foreach ($this->derived($biz) as $name) {
            if (! in_array($name, $list, true)) {
                $list[] = $name;
            }
        }
        $this->put($biz, $list);
    }
}

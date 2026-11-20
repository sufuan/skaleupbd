<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Product;

/**
 * The price ladder. The floor is held here, in code — the AI can phrase an
 * offer, it can never choose one below the floor.
 */
class NegotiationService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /** @return array{max:int, step:int, free_delivery_min:float} */
    public function config(): array
    {
        $n = $this->settings->get('ai.negotiation', []);

        return [
            'max' => (int) ($n['max_discount'] ?? 15),
            'step' => max(1, (int) ($n['step'] ?? 5)),
            'free_delivery_min' => (float) ($n['free_delivery_min'] ?? 1000),
        ];
    }

    /** Max discount for one product: its own setting wins over the shop-wide one. */
    public function maxDiscount(Product $product): int
    {
        return $product->max_discount_pct !== null ? (int) $product->max_discount_pct : $this->config()['max'];
    }

    public function floor(Product $product): float
    {
        return round($product->effectivePrice() * (1 - $this->maxDiscount($product) / 100), 2);
    }

    /** The full ladder for a product: [price, step1, step2, ..., floor]. */
    public function ladder(Product $product): array
    {
        $cfg = $this->config();
        $price = $product->effectivePrice();
        $rungs = [$price];
        $steps = (int) floor($this->maxDiscount($product) / $cfg['step']);
        for ($i = 1; $i <= $steps; $i++) {
            $rungs[] = round($price * (1 - ($cfg['step'] * $i) / 100), 2);
        }
        if (end($rungs) > $this->floor($product)) {
            $rungs[] = $this->floor($product);
        }

        return $rungs;
    }

    /** The next rung's value, without changing any state. */
    public function nextOfferValue(Conversation $conversation, Product $product): ?float
    {
        $cfg = $this->config();
        $floor = $this->floor($product);
        $nextStep = (int) $conversation->neg_step + 1;
        $offer = round($product->effectivePrice() * (1 - ($cfg['step'] * $nextStep) / 100), 2);
        if ($offer < $floor) {
            $offer = $floor;
        }
        $current = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : $product->effectivePrice();
        if ($current <= $floor || $offer >= $current) {
            return null;
        }

        return $offer;
    }

    /**
     * Next offer for a conversation, or null when the floor is already
     * reached and a human must take over.
     */
    public function nextOffer(Conversation $conversation, Product $product): ?float
    {
        $offer = $this->nextOfferValue($conversation, $product);
        if ($offer === null) {
            return null;
        }
        $conversation->neg_step = (int) $conversation->neg_step + 1;
        $conversation->neg_offer = $offer;
        $conversation->save();

        return $offer;
    }

    /**
     * Decide the answer to a bargaining message. $named is the price the
     * customer named, if any. Returns ['kind' => accept|counter|floor_repeat,
     * 'offer' => float], or null when a human must take over:
     *  - accept: the customer named a workable price at/above the floor and
     *    at/above the next rung — take it, do not haggle past their offer.
     *  - counter: the normal next rung.
     *  - floor_repeat: the floor is already the last word — repeat it once,
     *    then hand over on the next push.
     */
    public function quote(Conversation $conversation, Product $product, ?float $named): ?array
    {
        $floor = $this->floor($product);
        $current = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : $product->effectivePrice();

        if ($named !== null && $named >= $current) {
            return ['kind' => 'accept', 'offer' => $current];
        }
        if ($named !== null && $named >= $floor) {
            $next = $this->nextOfferValue($conversation, $product);
            if ($next === null || $named >= $next) {
                $conversation->neg_step = (int) $conversation->neg_step + 1;
                $conversation->neg_offer = round($named, 2);
                $conversation->save();

                return ['kind' => 'accept', 'offer' => round($named, 2)];
            }
        }
        $offer = $this->nextOffer($conversation, $product);
        if ($offer !== null) {
            return ['kind' => 'counter', 'offer' => $offer];
        }
        if ($current <= $floor + 0.01) {
            $state = (array) ($conversation->state_data ?? []);
            if ((int) ($state['floor_repeats'] ?? 0) < 1) {
                $state['floor_repeats'] = 1;
                $conversation->state_data = $state;
                $conversation->save();

                return ['kind' => 'floor_repeat', 'offer' => $floor];
            }
        }

        return null;
    }
}

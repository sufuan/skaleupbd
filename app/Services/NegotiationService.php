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

    public function floor(Product $product): float
    {
        $cfg = $this->config();

        return round(((float) $product->price) * (1 - $cfg['max'] / 100), 2);
    }

    /** The full ladder for a product: [price, step1, step2, ..., floor]. */
    public function ladder(Product $product): array
    {
        $cfg = $this->config();
        $price = (float) $product->price;
        $rungs = [$price];
        $steps = (int) floor($cfg['max'] / $cfg['step']);
        for ($i = 1; $i <= $steps; $i++) {
            $rungs[] = round($price * (1 - ($cfg['step'] * $i) / 100), 2);
        }
        if (end($rungs) > $this->floor($product)) {
            $rungs[] = $this->floor($product);
        }

        return $rungs;
    }

    /**
     * Next offer for a conversation, or null when the floor is already
     * reached and a human must take over.
     */
    public function nextOffer(Conversation $conversation, Product $product): ?float
    {
        $cfg = $this->config();
        $floor = $this->floor($product);
        $nextStep = (int) $conversation->neg_step + 1;
        $offer = round(((float) $product->price) * (1 - ($cfg['step'] * $nextStep) / 100), 2);
        if ($offer < $floor) {
            $offer = $floor;
        }
        $current = $conversation->neg_offer !== null ? (float) $conversation->neg_offer : (float) $product->price;
        if ($current <= $floor || $offer >= $current) {
            return null;
        }
        $conversation->neg_step = $nextStep;
        $conversation->neg_offer = $offer;
        $conversation->save();

        return $offer;
    }
}

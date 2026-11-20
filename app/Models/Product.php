<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = ['offer_starts_at' => 'date', 'offer_ends_at' => 'date', 'gallery' => 'array'];

    /** True while a campaign price exists and its window is open today. */
    public function offerActive(): bool
    {
        if ($this->offer_price === null) {
            return false;
        }
        $today = now()->toDateString();
        $started = $this->offer_starts_at === null || $this->offer_starts_at->toDateString() <= $today;
        $notEnded = $this->offer_ends_at === null || $this->offer_ends_at->toDateString() >= $today;

        return $started && $notEnded;
    }

    /** The price a customer pays today: the campaign price while it runs, else the regular price. */
    public function effectivePrice(): float
    {
        return $this->offerActive() ? (float) $this->offer_price : (float) $this->price;
    }

    /**
     * How ready this product is for the AI to sell it (brief §8): the AI can
     * only answer well about products that have a photo, a price, a written
     * description, the other names customers use, and recorded stock.
     *
     * @return array{score: int, missing: string[]}
     */
    public function aiReadiness(): array
    {
        $checks = [
            'Photo added' => [25, ! empty($this->image)],
            'Price set' => [20, (float) $this->price > 0],
            'Description written' => [20, trim((string) $this->short_description) !== '' || trim((string) $this->long_description) !== ''],
            'Other names added' => [15, trim((string) $this->aliases) !== ''],
            'Stock recorded' => [20, ! (bool) $this->manage_stock || (int) $this->stock_qty > 0],
        ];
        $score = 0;
        $missing = [];
        foreach ($checks as $label => [$weight, $ok]) {
            if ($ok) {
                $score += $weight;
            } else {
                $missing[] = $label;
            }
        }

        return ['score' => $score, 'missing' => $missing];
    }

    public function variants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->variants()->orderBy('id')->get();
    }

    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }
}

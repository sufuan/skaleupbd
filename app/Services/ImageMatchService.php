<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImageHash;
use Illuminate\Support\Facades\Storage;

/**
 * Matches a customer's photo to the shop's own products without any
 * model call first: a 64-bit perceptual hash compared against the
 * product gallery (and photos learned from earlier confirmed matches).
 * The vision model only sees a shortlist when the hash is unsure.
 * Confidence is decided here, in code: high / medium / low.
 */
class ImageMatchService
{
    public function __construct(private AiVisionService $vision)
    {
    }

    /** 64-bit dHash of an image file, as 16 hex chars. Null when GD is unavailable. */
    public function dHash(string $absolutePath): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($absolutePath)) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($absolutePath));
        if ($img === false) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $grey = [];
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey[] = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
            }
            for ($x = 0; $x < 8; $x++) {
                $bits .= $grey[$x] > $grey[$x + 1] ? '1' : '0';
            }
        }
        imagedestroy($img);
        imagedestroy($small);
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    public function distance(string $a, string $b): int
    {
        $d = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }

        return $d;
    }

    public function hashOf(string $relativePath): ?string
    {
        if (! Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        return $this->dHash(Storage::disk('public')->path($relativePath));
    }

    /** Remember this photo as a reference for the product (gallery, confirmed customer photo, post). */
    public function learn(int $businessId, int $productId, string $relativePath, string $source): void
    {
        $hash = $this->hashOf($relativePath);
        if ($hash === null) {
            return;
        }
        ProductImageHash::updateOrCreate(
            ['business_id' => $businessId, 'image_path' => $relativePath],
            ['product_id' => $productId, 'hash' => $hash, 'source' => $source]
        );
    }

    /** Hash the shop's product photos once, so matching has something to compare against. */
    public function seedProductHashes(int $businessId): void
    {
        if (ProductImageHash::where('business_id', $businessId)->exists()) {
            return;
        }
        foreach (Product::where('business_id', $businessId)->get() as $p) {
            $paths = array_filter(array_merge([(string) ($p->image ?? '')], (array) ($p->gallery ?? [])));
            foreach ($paths as $path) {
                $this->learn($businessId, $p->id, (string) $path, 'gallery');
            }
        }
    }

    /**
     * @return array{level: 'high'|'medium'|'low', product: ?Product, method: string, image_type: string}
     */
    public function identify(int $businessId, string $relativePath, string $caption): array
    {
        $this->seedProductHashes($businessId);
        $hash = $this->hashOf($relativePath);
        $hashBest = null;
        $hashCandidates = collect();
        if ($hash !== null) {
            $scored = ProductImageHash::where('business_id', $businessId)->get()
                ->map(fn ($h) => ['product_id' => (int) $h->product_id, 'dist' => $this->distance($hash, $h->hash), 'trusted' => in_array((string) $h->source, ['gallery', 'confirmed'], true)])
                ->sortBy('dist')
                ->values();
            if ($scored->isNotEmpty()) {
                $best = $scored[0];
                if ($best['dist'] <= 14) {
                    // Any close row — trusted or not — may put its
                    // product on the vision shortlist.
                    $hashCandidates = $scored->take(4)->pluck('product_id');
                }
                // Only a TRUSTED reference can carry a hash verdict
                // (batch 17): the shop's own gallery photos, and
                // photos a customer CONFIRMED through the
                // image-confirm question. A row the pipeline learned
                // automatically from an earlier identification
                // (source 'customer') never decides on its own: the
                // pre-batch-16 focus bug learned a photo of a red
                // bag as the focus Panjabi, and every re-send of
                // that photo then "matched" its own poisoned row at
                // distance 0 — high confidence, forever. An
                // untrusted row's product can still be confirmed by
                // the vision check below, or by the customer through
                // the medium path, whose "yes" re-learns it as
                // 'confirmed' — the trust ladder.
                if (! empty($best['trusted'])) {
                    $second = isset($scored[1]) ? (int) $scored[1]['dist'] : 999;
                    if ($best['dist'] <= 8 && ($second - $best['dist']) >= 6) {
                        return ['level' => 'high', 'product' => Product::find($best['product_id']), 'method' => 'hash', 'image_type' => 'product'];
                    }
                }
                $trustedBest = $scored->first(fn ($r) => ! empty($r['trusted']));
                if ($trustedBest !== null && $trustedBest['dist'] <= 14) {
                    $hashBest = (int) $trustedBest['product_id'];
                }
            }
        }

        $shortlist = Product::where('business_id', $businessId)
            ->when($hashCandidates->isNotEmpty(), fn ($q) => $q->whereIn('id', $hashCandidates))
            ->limit(8)
            ->get();
        if ($shortlist->isEmpty()) {
            $shortlist = Product::where('business_id', $businessId)->limit(8)->get();
        }
        $vision = $this->vision->identify($businessId, $relativePath, $shortlist, $caption);
        if ($vision !== null) {
            if ($vision['image_type'] !== 'product') {
                return ['level' => 'low', 'product' => null, 'method' => 'vision', 'image_type' => $vision['image_type']];
            }
            if ($vision['product_id'] !== null) {
                $product = $shortlist->firstWhere('id', $vision['product_id']);
                if ($product) {
                    $agrees = $hashBest !== null && $hashBest === (int) $product->id;

                    return ['level' => $agrees ? 'high' : 'medium', 'product' => $product, 'method' => 'vision', 'image_type' => 'product'];
                }
            }
        }
        if ($hashBest !== null) {
            return ['level' => 'medium', 'product' => Product::find($hashBest), 'method' => 'hash_grey', 'image_type' => 'product'];
        }

        return ['level' => 'low', 'product' => null, 'method' => 'none', 'image_type' => 'product'];
    }
}

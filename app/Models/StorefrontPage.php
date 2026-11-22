<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * M18 — one page of a store. The first page (slug null) is the store
 * root; later pages live at /{slug} (system-generated, never edited
 * by the seller). Blocks exist twice: draft_blocks (what the editor
 * autosaves) and published_blocks (what the public sees) — the live
 * page changes only on "Publish changes".
 */
class StorefrontPage extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'draft_blocks' => 'array',
        'published_blocks' => 'array',
        'published_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'live', 'paused'];
    public const TEMPLATES = ['single', 'store', 'offer'];

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StorefrontPageVersion::class, 'page_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(StorefrontDailyStat::class, 'page_id');
    }

    public function isRoot(): bool
    {
        return $this->slug === null;
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    /** True when the payload is the v2 editor model ({version, blocks}). */
    public static function isV2(mixed $blocks): bool
    {
        return is_array($blocks) && array_key_exists('version', $blocks) && is_array($blocks['blocks'] ?? null);
    }

    public function hasUnpublishedChanges(): bool
    {
        return json_encode($this->draft_blocks) !== json_encode($this->published_blocks);
    }

    /** Public path of this page on the shop host ('' for the root page). */
    public function path(): string
    {
        return $this->slug ? '/'.$this->slug : '/';
    }

    public function publicUrl(Storefront $sf): ?string
    {
        $base = $sf->customDomainUrl() ?? $sf->subdomainUrl();
        if (! $base) {
            return $sf->subdomainUrl();
        }

        return $base.($this->slug ? '/'.$this->slug : '');
    }
}

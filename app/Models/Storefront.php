<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * M18 — a business's hosted storefront: address (subdomain / custom
 * domain), the blocks-JSON page, branding (incl. tracking IDs), and the
 * publish / phone-verification / plan-lapse state.
 */
class Storefront extends Model
{
    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'branding' => 'array',
        'settings' => 'array',
        'otp_expires_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'published_at' => 'datetime',
        'domain_verified_at' => 'datetime',
        'domain_added_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'published', 'taken_down'];
    public const DOMAIN_STATUSES = ['none', 'pending_dns', 'verified', 'active'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StorefrontReport::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** The public base URL on the platform subdomain (the permanent address). */
    public function subdomainUrl(): ?string
    {
        if (! $this->subdomain) {
            return null;
        }
        $scheme = str_ends_with(config('storefront.domain'), '.test') ? 'http' : 'https';

        return $scheme.'://'.$this->subdomain.'.'.config('storefront.domain');
    }

    public function customDomainUrl(): ?string
    {
        if (! $this->custom_domain || $this->domain_status !== 'active') {
            return null;
        }

        return 'https://'.$this->custom_domain;
    }

    /** Delivery charges from the storefront settings JSON. */
    public function deliveryInside(): float
    {
        return (float) ($this->settings['delivery_inside'] ?? 60);
    }

    public function deliveryOutside(): float
    {
        return (float) ($this->settings['delivery_outside'] ?? 120);
    }
}

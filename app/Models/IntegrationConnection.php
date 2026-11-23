<?php

// Goes in: app/Models/IntegrationConnection.php (new file).

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IntegrationConnection extends Model
{
    protected $table = 'integration_connections';

    protected $fillable = [
        'business_id', 'platform', 'site_url', 'webhook_url', 'status',
        'credentials_enc', 'webhook_secret_enc', 'webhook_secret_last4', 'public_token',
        'last_event_at', 'last_success_at', 'failure_count', 'last_error',
        // Added by the later migrations (woo + reconciliation). These are
        // written via mass assignment by the connect/reconciliation code,
        // so they must be fillable or the writes silently no-op. The map/id
        // columns hold raw JSON text (the services json_encode/json_decode
        // them), so no array casts here.
        'woo_status_map', 'woo_webhook_ids', 'woo_push_map',
        'woo_orders_cursor', 'woo_products_cursor', 'woo_reconciled_at', 'last_synced_at',
    ];

    // Never serialised to the browser. Controllers build their responses from an explicit whitelist anyway.
    protected $hidden = ['credentials_enc', 'webhook_secret_enc'];

    protected function casts(): array
    {
        return [
            'credentials_enc' => 'encrypted:array',
            'webhook_secret_enc' => 'encrypted',
            'last_event_at' => 'datetime',
            'last_success_at' => 'datetime',
            'woo_reconciled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }
}

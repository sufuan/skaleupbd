<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Integrations\WooReconciliationJob;
use App\Models\IntegrationConnection;
use Illuminate\Console\Command;

/**
 * Console command that fans out WooReconciliationJob for every active WooCommerce connection.
 *
 * Place in: app/Console/Commands/RunWooReconciliation.php
 *
 * Register in routes/console.php:
 *   Schedule::command('integrations:reconcile-woo')->everyFifteenMinutes()->withoutOverlapping(10);
 */
final class RunWooReconciliation extends Command
{
    protected $signature   = 'integrations:reconcile-woo {--connection= : Reconcile a single connection ID only}';
    protected $description = 'Reconcile WooCommerce connections: re-register dead webhooks and pull missed orders.';

    public function handle(): int
    {
        $single = $this->option('connection');

        $query = IntegrationConnection::query()
            ->where('platform', 'woo')
            ->where('status', 'active');

        if ($single) {
            $query->where('id', (int) $single);
        }

        $connections = $query->get(['id']);

        if ($connections->isEmpty()) {
            $this->line('No active WooCommerce connections found.');
            return self::SUCCESS;
        }

        foreach ($connections as $conn) {
            WooReconciliationJob::dispatch($conn->id);
            $this->line("Dispatched reconciliation for connection #{$conn->id}.");
        }

        $this->info("Dispatched {$connections->count()} reconciliation job(s).");
        return self::SUCCESS;
    }
}

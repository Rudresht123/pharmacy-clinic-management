<?php

namespace App\Services\Platform;

use App\Models\Platform\TenantProvisionEvent;

/**
 * Counts a new tenant's migrations onto the provisioning step an
 * administrator is watching — "45 of 92 · create_invoices_table".
 *
 * Fed by the migrator's own MigrationStarted / MigrationEnded events
 * (AppServiceProvider), which say that a migration ran but not which one; the
 * name comes from the pending list worked out before the run, in the order
 * the migrator runs them.
 *
 * A no-op unless provisioning is tracking a step: `tenants:migrate` and the
 * test suite fire the same events and nothing is written for them.
 *
 * A singleton for one worker process, holding at most one step at a time —
 * a worker runs one job at a time.
 */
class ProvisioningProgress
{
    private ?TenantProvisionEvent $event = null;

    /** @var list<string> */
    private array $pending = [];

    private int $done = 0;

    private ?string $connection = null;

    /**
     * @param  list<string>  $pending  the migrations about to run, in the order they will run
     */
    public function track(TenantProvisionEvent $event, array $pending): void
    {
        $this->event = $event;
        $this->pending = array_values($pending);
        $this->done = 0;

        /*
         * The master connection, taken now. While a migration runs the
         * migrator points the default connection at the TENANT, and a row
         * written through it would land in the wrong database.
         */
        $this->connection = $event->getConnectionName() ?? config('database.default');

        $this->write([
            'progress_current' => 0,
            'progress_total' => count($this->pending),
            'detail' => $this->pending[0] ?? null,
        ]);
    }

    public function started(): void
    {
        if ($this->event) {
            $this->write(['detail' => $this->pending[$this->done] ?? null]);
        }
    }

    public function ended(): void
    {
        if (! $this->event) {
            return;
        }

        $this->done++;

        $this->write([
            'progress_current' => min($this->done, count($this->pending)),
            'detail' => $this->pending[$this->done - 1] ?? null,
        ]);
    }

    public function stop(): void
    {
        $this->event = null;
        $this->pending = [];
        $this->done = 0;
        $this->connection = null;
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(array $attributes): void
    {
        TenantProvisionEvent::on($this->connection)
            ->whereKey($this->event->getKey())
            ->update($attributes + ['updated_at' => now()]);
    }
}

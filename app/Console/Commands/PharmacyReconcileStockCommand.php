<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\StockMovement;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Checks that every batch's quantity is what its ledger adds up to.
 *
 * It reports; it never fixes. A mismatch means something wrote a quantity
 * outside StockMovementService — which the database is built to prevent —
 * and the right response is a person looking at it, not a job quietly
 * rewriting stock to agree with itself. Exits non-zero when anything is off.
 */
class PharmacyReconcileStockCommand extends Command
{
    protected $signature = 'pharmacy:reconcile-stock {--org= : One organization, by uuid}';

    protected $description = 'Compare every batch quantity with the sum of its ledger, in every tenant';

    public function handle(TenantConnectionService $tenants): int
    {
        $problems = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                $ledger = StockMovement::query()
                    ->selectRaw('medicine_batch_id, sum(quantity) as total')
                    ->groupBy('medicine_batch_id')
                    ->pluck('total', 'medicine_batch_id');

                $rows = [];

                MedicineBatch::withTrashed()
                    ->orderBy('id')
                    ->each(function (MedicineBatch $batch) use ($ledger, &$rows) {
                        $expected = (int) ($ledger[$batch->id] ?? 0);

                        if ($expected !== $batch->quantity_available) {
                            $rows[] = [$batch->id, $batch->batch_number, $batch->quantity_available, $expected];
                        }
                    });

                if ($rows === []) {
                    $this->line("{$organization->organization_name}: every batch agrees with its ledger.");

                    continue;
                }

                $problems += count($rows);
                $this->error("{$organization->organization_name}: ".count($rows).' batch(es) disagree with their ledger.');
                $this->table(['Batch id', 'Batch', 'On the batch', 'Ledger says'], $rows);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$organization->organization_name}: {$e->getMessage()}");
                $problems++;
            } finally {
                $tenants->disconnect();
            }
        }

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Organization> */
    private function organizations(): Collection
    {
        if ($uuid = $this->option('org')) {
            return Organization::query()->where('uuid', $uuid)->get();
        }

        return Organization::query()
            ->whereIn('status', [Organization::ACTIVE, Organization::SUSPENDED])
            ->whereNotNull('database_name')
            ->orderBy('id')
            ->get();
    }
}

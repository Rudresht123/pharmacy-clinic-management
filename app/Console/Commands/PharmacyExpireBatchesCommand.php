<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\MedicineBatch;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Marks batches past their expiry as expired, in every tenant.
 *
 * Stops dispensing at once. The quantity stays on the books — the stock is
 * still on the shelf, and has to be counted — until a pharmacist writes it
 * off with an expiry adjustment. Judged on the clinic's calendar: a batch is
 * expired from the start of its expiry date.
 */
class PharmacyExpireBatchesCommand extends Command
{
    protected $signature = 'pharmacy:expire-batches {--org= : One organization, by uuid}';

    protected $description = 'Mark batches past their expiry date as expired, in every tenant';

    public function handle(TenantConnectionService $tenants): int
    {
        $failed = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                $expired = 0;

                MedicineBatch::query()
                    ->where('status', MedicineBatch::ACTIVE)
                    ->whereDate('expiry_date', '<=', now()->toDateString())
                    ->orderBy('id')
                    ->each(function (MedicineBatch $batch) use (&$expired) {
                        // Saved through the model, so each is on the batch's history.
                        $batch->forceFill(['status' => MedicineBatch::EXPIRED])->save();
                        $expired++;
                    });

                $this->line("{$organization->organization_name}: {$expired} batch(es) expired.");
            } catch (Throwable $e) {
                report($e);
                $this->error("{$organization->organization_name}: {$e->getMessage()}");
                $failed++;
            } finally {
                $tenants->disconnect();
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Organization> */
    private function organizations(): Collection
    {
        if ($uuid = $this->option('org')) {
            return Organization::query()->where('uuid', $uuid)->get();
        }

        // Only tenants with a database to open.
        return Organization::query()
            ->whereIn('status', [Organization::ACTIVE, Organization::SUSPENDED])
            ->whereNotNull('database_name')
            ->orderBy('id')
            ->get();
    }
}

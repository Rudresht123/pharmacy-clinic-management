<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\Prescription;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Marks prescriptions past their `valid_until` as expired, in every tenant.
 *
 * Nothing more can be dispensed against an expired prescription; what was
 * already dispensed stays as it was. Judged on the clinic's calendar: valid
 * through the whole of its last day.
 */
class PrescriptionsExpireCommand extends Command
{
    protected $signature = 'prescriptions:expire {--org= : One organization, by uuid}';

    protected $description = 'Mark prescriptions past their valid-until date as expired, in every tenant';

    public function handle(TenantConnectionService $tenants): int
    {
        $failed = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                $expired = 0;

                Prescription::query()
                    ->whereIn('status', [Prescription::ISSUED, Prescription::PARTIALLY_DISPENSED])
                    ->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<', now()->toDateString())
                    ->orderBy('id')
                    ->each(function (Prescription $prescription) use (&$expired) {
                        // Saved through the model, so each is on the prescription's history.
                        $prescription->forceFill(['status' => Prescription::EXPIRED])->save();
                        $expired++;
                    });

                $this->line("{$organization->organization_name}: {$expired} prescription(s) expired.");
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

        return Organization::query()
            ->whereIn('status', [Organization::ACTIVE, Organization::SUSPENDED])
            ->whereNotNull('database_name')
            ->orderBy('id')
            ->get();
    }
}

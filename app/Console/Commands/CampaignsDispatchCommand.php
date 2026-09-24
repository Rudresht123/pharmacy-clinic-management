<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\CampaignDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends the campaigns whose time has come, in every tenant.
 *
 * This is what makes a scheduled campaign actually go out with nobody
 * present. Run it every minute from the scheduler; it is cheap when there is
 * nothing due — one indexed query per tenant — and idempotent when there is,
 * so a run that overlaps the previous one cannot send anything twice.
 *
 * One tenant's failure does not stop the others: a clinic with a broken
 * campaign must not hold up everybody else's nine o'clock send.
 */
class CampaignsDispatchCommand extends Command
{
    protected $signature = 'campaigns:dispatch
        {--org= : One organization, by uuid}
        {--pretend : Report what would be sent without sending it}';

    protected $description = 'Send scheduled campaigns whose time has come, in every tenant';

    public function handle(TenantConnectionService $tenants, CampaignDispatcher $dispatcher): int
    {
        $failed = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                if ($this->option('pretend')) {
                    $this->report($organization, $dispatcher);

                    continue;
                }

                $result = $dispatcher->dispatchDue();

                if ($result['dispatched'] > 0) {
                    $this->line(
                        "{$organization->organization_name}: {$result['dispatched']} campaign(s), ".
                        "{$result['recipients']} recipient(s)."
                    );
                }
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

    /** What is due, without touching it. */
    private function report(Organization $organization, CampaignDispatcher $dispatcher): void
    {
        $due = \App\Models\Tenant\Campaign::query()->due()->get();

        if ($due->isEmpty()) {
            return;
        }

        $this->line("{$organization->organization_name}: {$due->count()} due");

        foreach ($due as $campaign) {
            $this->line("  - {$campaign->name} (scheduled {$campaign->scheduled_at})");
        }
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

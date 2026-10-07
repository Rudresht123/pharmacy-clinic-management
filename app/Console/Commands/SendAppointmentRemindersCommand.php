<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Services\Communication\AppointmentReminders;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Appointment reminders, in every tenant whose time has come.
 *
 * Mirrors campaigns:dispatch: connect one tenant, do its work, disconnect —
 * always, in `finally` — so the next tenant can never inherit the connection
 * and send one clinic's reminders from another's records. One clinic failing
 * is reported and the rest carry on.
 *
 * Only organizations that can sign in: a suspended clinic's patients should
 * not be told to turn up.
 */
class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'communication:send-reminders
        {--org= : One organization, by uuid}';

    protected $description = 'Send the appointment reminders that are due, in every tenant';

    public function handle(TenantConnectionService $tenants, AppointmentReminders $reminders): int
    {
        $failed = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                $queued = $reminders->sendDue($organization);

                if ($queued > 0) {
                    $this->line("{$organization->organization_name}: {$queued} reminder(s) queued.");
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

    /** @return Collection<int, Organization> */
    private function organizations(): Collection
    {
        $query = Organization::query()->whereNotNull('database_name')->orderBy('id');

        if ($uuid = $this->option('org')) {
            $query->where('uuid', $uuid);
        }

        return $query->get()->filter(fn (Organization $organization) => $organization->canSignIn())->values();
    }
}

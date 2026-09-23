<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\OrganizationSetup;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * One command that fills a tenant with everything a demo needs.
 *
 * `php artisan demo:seed --org=demo`
 *
 * It runs the per-module seeders in the order their data depends on: the
 * clinic first (branches, doctors, patients, a busy OPD morning), then the
 * pharmacy (catalogue, suppliers, stock, a fortnight of bills), which sells
 * to the patients the first one registered.
 *
 * Each module keeps its own command, and this only sequences them — so a new
 * module adds one line here rather than a second copy of everything, and
 * anybody who wants just the pharmacy can still run just the pharmacy.
 *
 * Never part of `db:seed`: it writes real rows into a real organisation's
 * database, and that should only ever happen because somebody asked for it.
 */
class SeedDemoCommand extends Command
{
    protected $signature = 'demo:seed
        {--org= : Organization id or subdomain; the only one, if there is only one}
        {--days=14 : Days of history to build, ending today}
        {--skip-opd : Leave the clinic side alone}
        {--skip-pharmacy : Leave the pharmacy alone}
        {--skip-photos : Leave the doctors without photographs}';

    protected $description = 'Fill one organisation with demo data for every module, ready to show a client';

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $this->components->info("Demo data for {$organization->organization_name} ({$organization->subdomain})");

        $days = (int) $this->option('days');

        /*
         * The clinic first: the pharmacy sells to its patients, and a store
         * with no customers can only ever show walk-in sales.
         */
        if (! $this->option('skip-opd')) {
            $this->components->task('Clinic: branches, doctors, patients, OPD', fn () => $this->call('opd:demo', [
                '--org' => $organization->id,
                '--days' => min($days, 5),
                '--skip-photos' => (bool) $this->option('skip-photos'),
            ]) === self::SUCCESS);
        }

        if (! $this->option('skip-pharmacy')) {
            $this->components->task('Pharmacy: catalogue, stock, bills', fn () => $this->call('pharmacy:demo', [
                '--org' => $organization->id,
                '--days' => $days,
            ]) === self::SUCCESS);
        }

        $this->components->task('Setup: signed off', fn () => $this->finishSetup($organization));

        $this->newLine();
        $this->components->info('Ready. Sign in at https://'.$organization->subdomain.'.hms.local');

        return self::SUCCESS;
    }

    /**
     * Finish organisation setup, so the demo opens on the product.
     *
     * Until setup is signed off the owner is held on the setup screen and
     * everything else redirects to it — right for a real organisation, and
     * useless for a demo, where the first thing anybody wants to see is the
     * dashboard. Anything the setup insists on and the seeders did not
     * supply (the address, mostly) is filled in with something plausible
     * first, because the rule is checked on the server and should be.
     */
    private function finishSetup(Organization $organization): bool
    {
        $organization->fill(array_filter([
            'contact_person_name' => $organization->contact_person_name ?: 'Demo Owner',
            'phone_number' => $organization->phone_number ?: '9876543210',
            'address' => $organization->address ?: '14 Civil Lines',
        ]))->save();

        $organization->profile()->updateOrCreate([], array_filter([
            'city' => $organization->profile?->city ?: 'Pratapgarh',
            'state_province' => $organization->profile?->state_province ?: 'Uttar Pradesh',
            'postal_code' => $organization->profile?->postal_code ?: '230135',
        ]));

        $tenancy = new TenantConnectionService;
        $tenancy->connect($organization->database_name);

        try {
            $setup = app(OrganizationSetup::class);

            foreach (OrganizationSetup::CONFIRMABLE as $step) {
                try {
                    $setup->confirm($step);
                } catch (ValidationException) {
                    // Nothing to sign off in that section; `complete` says so too.
                }
            }

            $setup->complete($organization->refresh());
        } catch (ValidationException $refused) {
            foreach ($refused->errors() as $messages) {
                $this->warn('  Setup still wants: '.implode(' ', $messages));
            }

            return false;
        } finally {
            $tenancy->disconnect();
        }

        return true;
    }

    private function organization(): ?Organization
    {
        $asked = $this->option('org');

        if ($asked) {
            /*
             * A subdomain is never compared against `id`: Postgres types the
             * column as bigint and refuses the text outright, so `--org=demo`
             * used to fail with a cast error rather than finding the demo.
             */
            $organization = Organization::query()
                ->when(is_numeric($asked), fn ($query) => $query->whereKey((int) $asked))
                ->when(! is_numeric($asked), fn ($query) => $query->where('subdomain', $asked))
                ->first();

            if (! $organization) {
                $this->error("No organization matches \"{$asked}\".");
            }

            return $organization;
        }

        $all = Organization::all();

        if ($all->count() === 1) {
            return $all->first();
        }

        $this->error('Name one with --org=<id|subdomain>:');

        foreach ($all as $organization) {
            $this->line("  {$organization->id}  {$organization->subdomain}  {$organization->organization_name}");
        }

        return null;
    }
}

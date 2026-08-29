<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use App\Models\Platform\Organization;
use Illuminate\Support\Facades\DB;

class SystemMigrateCommand extends Command
{
    protected $signature = 'system:migrate';

    protected $description = 'Run master and organization migrations';

    public function handle(): int
    {
        $this->info('===================================');
        $this->info('Master Migration Started');
        $this->info('===================================');

        Artisan::call('migrate', [
            '--path'  => 'database/migrations/masterdb',
            '--force' => true,
        ]);

        Artisan::call('migrate', [
            '--path'  => 'database/migrations/masterdb/after-seed',
            '--force' => true,
        ]);

        $this->line(Artisan::output());

        $this->info('Master Migration Completed');

        $organizations = Organization::select(
            'id',
            'organization_name',
            'database_name'
        )->get();

        $success = 0;
        $failed  = 0;

        $this->newLine();
        $this->info('===================================');
        $this->info('Organization Migration Started');
        $this->info('===================================');

        foreach ($organizations as $organization) {

            try {

                $this->line('');
                $this->line(
                    "Migrating: {$organization->organization_name}"
                );

                Config::set(
                    'database.connections.organization.database',
                    $organization->database_name
                );

                DB::purge('organization');
                DB::reconnect('organization');

                Artisan::call('migrate', [
                    '--database' => 'organization',
                    '--path'     => 'database/migrations/organization',
                    '--force'    => true,
                ]);

                Artisan::call('migrate', [
                    '--database' => 'organization',
                    '--path'     => 'database/migrations/organization/after-seed',
                    '--force'    => true,
                ]);

                $this->line(Artisan::output());

                $success++;

                $this->info(
                    "✓ Success : {$organization->database_name}"
                );

            } catch (\Throwable $e) {

                $failed++;

                $this->error(
                    "✗ Failed : {$organization->database_name}"
                );

                $this->error(
                    $e->getMessage()
                );

                report($e);
            }
        }

        $this->newLine();

        $this->info('===================================');
        $this->info('Migration Summary');
        $this->info('===================================');

        $this->info("Success : {$success}");
        $this->error("Failed  : {$failed}");
        $this->line("Total   : " . ($success + $failed));

        return self::SUCCESS;
    }
}
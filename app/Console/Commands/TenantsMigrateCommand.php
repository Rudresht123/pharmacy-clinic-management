<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Platform\TenantMigrationState;
use App\Repositories\Platform\Contracts\TenantMigrationRunRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantMigrationStateRepositoryInterface;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs master and tenant migrations, tracking schema drift per tenant in
 * `tenant_migration_state` and `tenant_migration_runs` — the "who is
 * behind?" answer the Central/Platform DB reference calls for, without
 * having to open every tenant database to find out.
 *
 * Replaces `system:migrate`, which had no way to target one organization,
 * recorded nothing, and duplicated TenantConnectionService's connection
 * dance inline instead of reusing it.
 */
class TenantsMigrateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'tenants:migrate {--org=} {--pretend} {--force}';

    protected $description = 'Run master and tenant migrations, tracking schema drift per tenant';

    public function __construct(
        private readonly TenantConnectionService $tenants,
        private readonly TenantMigrationStateRepositoryInterface $migrationState,
        private readonly TenantMigrationRunRepositoryInterface $migrationRuns,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $organizations = $this->organizationsToMigrate();

        if ($uuid = $this->option('org')) {
            if ($organizations->isEmpty()) {
                $this->error("No organization found for uuid [{$uuid}].");

                return self::FAILURE;
            }
        } else {
            // Targeting one tenant means exactly that — not "everything
            // plus this tenant."
            $this->runMasterMigrations();

            if ($organizations->isEmpty()) {
                $this->info('No organizations to migrate.');

                return self::SUCCESS;
            }
        }

        $batchUuid = (string) Str::uuid();
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($organizations as $organization) {
            match ($this->migrateOne($organization, $batchUuid)) {
                'success' => $success++,
                'failed' => $failed++,
                'skipped' => $skipped++,
            };
        }

        $this->newLine();
        $this->info("Success: {$success}  Failed: {$failed}  Skipped (pretend): {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Organization> */
    private function organizationsToMigrate(): Collection
    {
        if ($uuid = $this->option('org')) {
            return Organization::where('uuid', $uuid)->get();
        }

        return Organization::all();
    }

    private function runMasterMigrations(): void
    {
        $this->info('Master migrations');

        foreach (['database/migrations/masterdb', 'database/migrations/masterdb/after-seed'] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        }

        $this->line(Artisan::output());
    }

    /** The newest migration name found on disk — what the codebase defines as current. */
    private function targetVersion(): ?string
    {
        return collect(File::files(database_path('migrations/organization')))
            ->merge(File::files(database_path('migrations/organization/after-seed')))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->sort()
            ->last();
    }

    /**
     * The newest migration name actually applied to the connected tenant
     * database — ordered by name, not by row id. The organization and
     * after-seed paths migrate as two separate batches, and after-seed's
     * own filenames are not chronologically after the main path's, so the
     * row most recently inserted is not reliably the same as the one
     * targetVersion() would call "latest" by name; comparing both the same
     * way is what makes `current === target` mean anything.
     */
    private function currentVersion(): ?string
    {
        if (! Schema::connection(TenantConnectionService::CONNECTION)->hasTable('migrations')) {
            return null;
        }

        return DB::connection(TenantConnectionService::CONNECTION)
            ->table('migrations')
            ->orderByDesc('migration')
            ->value('migration');
    }

    /** @return 'success'|'failed'|'skipped' */
    private function migrateOne(Organization $organization, string $batchUuid): string
    {
        $this->line("Migrating: {$organization->organization_name}");

        // The PDO connection is lazy — connect() itself rarely throws even
        // against a database that no longer exists. The failure only
        // surfaces on the first real query, so that has to be inside this
        // try too, not just the connect() call.
        try {
            $this->tenants->connect($organization->database_name);
            $target = $this->targetVersion();
            $before = $this->currentVersion();
        } catch (Throwable $e) {
            $this->error("  Failed to connect: {$e->getMessage()}");
            report($e);

            $this->tenants->disconnect();

            $this->migrationRuns->create([
                'organization_id' => $organization->id,
                'batch_uuid' => $batchUuid,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $state = $this->migrationState->firstOrCreateForOrganization($organization);
            $this->migrationState->update($state, [
                'status' => 'failed',
                'attempts' => $state->attempts + 1,
                'last_error' => $e->getMessage(),
                'last_run_at' => now(),
            ]);

            return 'failed';
        }

        $state = $this->migrationState->firstOrCreateForOrganization($organization, [
            'current_version' => $before,
            'target_version' => $target,
            'status' => $before === $target ? 'in_sync' : 'behind',
        ]);

        if ($this->option('pretend')) {
            return $this->pretendMigrate($state, $before, $target);
        }

        return $this->reallyMigrate($organization, $batchUuid, $state, $target);
    }

    private function pretendMigrate(TenantMigrationState $state, ?string $before, ?string $target): string
    {
        Artisan::call('migrate', [
            '--database' => TenantConnectionService::CONNECTION,
            '--path' => 'database/migrations/organization',
            '--pretend' => true,
        ]);
        $this->line(Artisan::output());

        $this->migrationState->update($state, [
            'current_version' => $before,
            'target_version' => $target,
            'status' => $before === $target ? 'in_sync' : 'behind',
        ]);

        $this->tenants->disconnect();

        return 'skipped';
    }

    private function reallyMigrate(
        Organization $organization,
        string $batchUuid,
        TenantMigrationState $state,
        ?string $target,
    ): string {
        $startedAt = now();

        try {
            foreach (['database/migrations/organization', 'database/migrations/organization/after-seed'] as $path) {
                Artisan::call('migrate', [
                    '--database' => TenantConnectionService::CONNECTION,
                    '--path' => $path,
                    '--force' => true,
                ]);
            }
            $this->line(Artisan::output());

            $after = $this->currentVersion();

            $this->migrationRuns->create([
                'organization_id' => $organization->id,
                'batch_uuid' => $batchUuid,
                'status' => 'success',
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $this->migrationState->update($state, [
                'current_version' => $after,
                'target_version' => $target,
                'status' => $after === $target ? 'in_sync' : 'behind',
                'attempts' => $state->attempts + 1,
                'last_error' => null,
                'last_run_at' => now(),
            ]);

            $this->info("  Success: {$organization->database_name}");

            return 'success';
        } catch (Throwable $e) {
            report($e);
            $this->error("  Failed: {$organization->database_name} — {$e->getMessage()}");

            $this->migrationRuns->create([
                'organization_id' => $organization->id,
                'batch_uuid' => $batchUuid,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $this->migrationState->update($state, [
                'current_version' => $this->currentVersion(),
                'target_version' => $target,
                'status' => 'failed',
                'attempts' => $state->attempts + 1,
                'last_error' => $e->getMessage(),
                'last_run_at' => now(),
            ]);

            return 'failed';
        } finally {
            $this->tenants->disconnect();
        }
    }
}

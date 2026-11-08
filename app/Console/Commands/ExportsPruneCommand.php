<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\ExportRun;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes Export Center files past their day, in every tenant, and closes
 * runs a worker abandoned.
 *
 * A finished file holds patient records, so it is kept a day
 * (ExportRun::KEEP_HOURS) and then deleted; its run stays as the record of who
 * exported what. A run still "running" long after any export could take — a
 * worker killed mid-file — is marked failed, so nobody watches a progress bar
 * that will never move.
 */
class ExportsPruneCommand extends Command
{
    protected $signature = 'exports:prune {--org= : One organization, by uuid}';

    protected $description = 'Delete expired Export Center files and close abandoned export runs, in every tenant';

    /** Past the job's own 30-minute limit with room to spare. */
    private const ABANDONED_AFTER_HOURS = 2;

    public function handle(TenantConnectionService $tenants): int
    {
        $failed = 0;

        foreach ($this->organizations() as $organization) {
            try {
                $tenants->connect($organization->database_name);

                $expired = 0;

                ExportRun::query()
                    ->where('status', ExportRun::COMPLETED)
                    ->where('expires_at', '<', Carbon::now())
                    ->orderBy('id')
                    ->each(function (ExportRun $run) use (&$expired) {
                        if ($run->file_path !== null) {
                            Storage::disk(ExportRun::DISK)->delete($run->file_path);
                        }

                        $run->forceFill(['status' => ExportRun::EXPIRED, 'file_path' => null])->save();
                        $expired++;
                    });

                $abandoned = ExportRun::query()
                    ->whereIn('status', ExportRun::ACTIVE)
                    ->where('created_at', '<', Carbon::now()->subHours(self::ABANDONED_AFTER_HOURS))
                    ->update([
                        'status' => ExportRun::FAILED,
                        'error' => 'The export stopped before the file was finished. Export it again.',
                        'finished_at' => Carbon::now(),
                    ]);

                $this->line("{$organization->organization_name}: {$expired} file(s) deleted, {$abandoned} abandoned run(s) closed.");
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

<?php

namespace App\Services\Platform;

use App\Models\Platform\Organization;
use App\Repositories\Platform\Contracts\DbClusterRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantProvisionEventRepositoryInterface;
use App\Services\Notifications\EmailService;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the multi-step creation of an organization:
 *
 *   1. store the logo            (files table + disk)
 *   2. insert the organization   (master database)
 *   3. create the tenant database
 *   4. migrate the tenant database
 *   5. email the setup link
 *
 * Steps 3-5 are tracked one row at a time in `tenant_provision_events`. A
 * step already marked `completed` there is skipped on the next attempt, so
 * a retry resumes from wherever it actually stopped instead of restarting
 * — and nothing is deleted or dropped on failure, since doing so is exactly
 * what would make resuming impossible. Only step 1's upload has no
 * organization to attach an event to yet, so it stays a plain best-effort
 * cleanup if step 2 itself fails.
 */
class OrganizationProvisioningService
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly TenantProvisionEventRepositoryInterface $provisionEvents,
        private readonly TenantDatabaseRepositoryInterface $tenantDatabases,
        private readonly DbClusterRepositoryInterface $dbClusters,
        private readonly TenantConnectionService $tenants = new TenantConnectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes, including database_name.
     *
     * @throws \Throwable
     */
    public function provision(array $data, ?UploadedFile $logo = null): Organization
    {
        $uploadedFileId = null;

        if ($logo) {
            $uploadedFileId = uploadFile($logo, 'organizations');
            $data['profile_image'] = $uploadedFileId;
        }

        $data['setup_token'] = Str::random(64);
        $data['setup_token_expires_at'] = now()->addDays(
            config('organization.setup_token_ttl_days', 7)
        );

        // §5: a new organization starts pending and is moved along by the
        // steps below, so its status always reflects how far it got.
        $data['status'] = Organization::PENDING;

        try {
            $organization = DB::transaction(
                fn () => $this->organizations->create($data)
            );
        } catch (\Throwable $e) {
            // Nothing exists yet for this attempt to resume, so the upload
            // really is orphaned — clean it up rather than leaving it behind.
            if ($uploadedFileId) {
                $this->attempt(fn () => deleteFile($uploadedFileId));
            }

            throw $e;
        }

        return $this->runProvisioningSteps($organization);
    }

    /**
     * Re-enter the step sequence for an organization whose provisioning
     * didn't finish. Steps already marked `completed` are skipped.
     *
     * @throws \Throwable
     */
    public function retryProvisioning(Organization $organization): Organization
    {
        if (! in_array($organization->status, [Organization::PENDING, Organization::PROVISIONING, Organization::FAILED], true)) {
            throw new \RuntimeException('Only a pending, provisioning or failed organization can be retried.');
        }

        return $this->runProvisioningSteps($organization);
    }

    private function runProvisioningSteps(Organization $organization): Organization
    {
        try {
            $organization = $this->organizations->changeStatus(
                $organization,
                Organization::PROVISIONING,
                'Provisioning started.',
                Auth::guard('platform')->id(),
            );

            $cluster = $this->dbClusters->findDefault();

            $this->runStep($organization, 'create_database', function () use ($organization, $cluster) {
                $tenantDatabase = $this->tenantDatabases->firstOrCreateForOrganization($organization, [
                    'db_cluster_id' => $cluster->id,
                    'db_name' => $organization->database_name,
                    'db_user' => config('database.connections.pgsql.username'),
                    'provision_status' => 'provisioning',
                ]);

                // CREATE DATABASE cannot run inside a transaction on
                // PostgreSQL. The existence check is what lets a retry skip
                // straight to migrating if the database itself already
                // exists from a prior attempt that failed right after this.
                if (! DatabaseService::exists($organization->database_name)) {
                    DatabaseService::create($organization->database_name);
                }

                $this->tenantDatabases->update($tenantDatabase, [
                    'provision_status' => 'provisioned',
                    'status' => 'healthy',
                ]);
            });

            $this->runStep(
                $organization,
                'migrate_tenant',
                fn () => $this->migrateTenant($organization->database_name),
            );

            $this->runStep(
                $organization,
                'send_setup_email',
                fn () => $this->sendSetupEmail($organization),
            );

            /*
             * Provisioned and reachable — §7 ends here with the organization
             * active, even though the owner has not chosen a password yet.
             * That happens when they follow the emailed link, which flips
             * is_setup_completed rather than the status.
             */
            $organization = $this->organizations->changeStatus(
                $organization,
                Organization::ACTIVE,
                'Provisioning completed.',
                Auth::guard('platform')->id(),
            );

            return $organization->fresh('organizationType');
        } catch (\Throwable $e) {
            $this->organizations->changeStatus(
                $organization,
                Organization::FAILED,
                'Provisioning failed — see provisioning history for detail.',
                Auth::guard('platform')->id(),
            );

            if ($tenantDatabase = $organization->tenantDatabase()->first()) {
                $this->tenantDatabases->update($tenantDatabase, ['provision_status' => 'failed']);
            }

            throw $e;
        }
    }

    /**
     * Run one provisioning step, recording it in `tenant_provision_events`.
     * A step already marked `completed` from an earlier attempt is skipped
     * — this is what makes a retry resume instead of restart.
     */
    private function runStep(Organization $organization, string $step, \Closure $action): void
    {
        $alreadyDone = $this->provisionEvents->query()
            ->where('organization_id', $organization->id)
            ->where('step', $step)
            ->where('status', 'completed')
            ->exists();

        if ($alreadyDone) {
            return;
        }

        $event = $this->provisionEvents->create([
            'organization_id' => $organization->id,
            'step' => $step,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        try {
            $action();

            $this->provisionEvents->update($event, [
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->provisionEvents->update($event, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Bring a freshly created tenant database up to date. Without this the
     * tenant has no users table, so the setup link cannot create its admin.
     *
     * Naturally idempotent — Laravel's migrator tracks what has already run
     * against this tenant database, so re-running it on a retry is safe.
     */
    public function migrateTenant(string $databaseName): void
    {
        $this->tenants->connect($databaseName);

        foreach ([
            'database/migrations/organization',
            'database/migrations/organization/after-seed',
        ] as $path) {
            Artisan::call('migrate', [
                '--database' => TenantConnectionService::CONNECTION,
                '--path' => $path,
                '--force' => true,
            ]);
        }
    }

    /**
     * @throws \RuntimeException if the email genuinely fails to send — a
     *                           missing/inactive template should be a retryable provisioning
     *                           failure, not a silently skipped invite.
     */
    private function sendSetupEmail(Organization $organization): void
    {
        $setupUrl = url("/organization/setup/{$organization->setup_token}");

        $sent = EmailService::send(
            'organization_setup',
            $organization->email,
            [
                'organization_name' => $organization->organization_name,
                'setup_button' => emailButton($setupUrl, 'Setup Account'),
                'expiry_date' => $organization->setup_token_expires_at?->format('d M Y h:i A'),
            ],
        );

        if (! $sent) {
            throw new \RuntimeException('Failed to send the organization setup email.');
        }
    }

    private function attempt(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

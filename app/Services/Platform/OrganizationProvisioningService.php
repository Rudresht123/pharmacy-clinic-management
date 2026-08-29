<?php

namespace App\Services\Platform;

use App\Models\Platform\Organization;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
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
 * Steps 1-4 are undone in reverse if any later step fails, so a failed
 * provision never leaves a half-built tenant behind.
 */
class OrganizationProvisioningService
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly TenantConnectionService $tenants = new TenantConnectionService(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  Validated attributes, including database_name.
     *
     * @throws \Throwable
     */
    public function provision(array $data, ?UploadedFile $logo = null): Organization
    {
        $organization = null;
        $uploadedFileId = null;
        $databaseCreated = false;

        try {
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

            $organization = DB::transaction(
                fn () => $this->organizations->create($data)
            );

            $organization = $this->organizations->changeStatus(
                $organization,
                Organization::PROVISIONING,
                'Provisioning started.',
                Auth::guard('platform')->id(),
            );

            // CREATE DATABASE cannot run inside a transaction on PostgreSQL,
            // so this deliberately sits outside the transaction above.
            DatabaseService::create($organization->database_name);
            $databaseCreated = true;

            $this->migrateTenant($organization->database_name);

            $this->sendSetupEmail($organization);

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
            $this->rollback($organization, $databaseCreated, $uploadedFileId);

            throw $e;
        }
    }

    /**
     * Bring a freshly created tenant database up to date. Without this the
     * tenant has no users table, so the setup link cannot create its admin.
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

    private function sendSetupEmail(Organization $organization): void
    {
        $setupUrl = url("/organization/setup/{$organization->setup_token}");

        EmailService::send(
            'organization_setup',
            $organization->email,
            [
                'organization_name' => $organization->organization_name,
                'setup_button' => emailButton($setupUrl, 'Setup Account'),
                'expiry_date' => $organization->setup_token_expires_at?->format('d M Y h:i A'),
            ],
        );
    }

    /**
     * Best-effort teardown; each step is isolated so one failure does not
     * prevent the others from running.
     */
    private function rollback(
        ?Organization $organization,
        bool $databaseCreated,
        ?int $uploadedFileId
    ): void {
        if ($organization && $databaseCreated) {
            // PostgreSQL refuses to drop a database that still has an open
            // session, and migrateTenant() leaves one on the tenant
            // connection — so release it before dropping.
            $this->attempt(fn () => $this->tenants->disconnect());

            $this->attempt(fn () => DatabaseService::drop($organization->database_name));
        }

        if ($organization) {
            $this->attempt(fn () => $organization->forceDelete());
        }

        if ($uploadedFileId) {
            $this->attempt(fn () => deleteFile($uploadedFileId));
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

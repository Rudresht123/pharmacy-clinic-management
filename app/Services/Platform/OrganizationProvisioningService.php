<?php

namespace App\Services\Platform;

use App\Jobs\ProvisionOrganization;
use App\Models\Platform\Organization;
use App\Models\Platform\TenantProvisionEvent;
use App\Models\Tenant\User as TenantUser;
use App\Repositories\Platform\Contracts\DbClusterRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantProvisionEventRepositoryInterface;
use App\Services\Communication\OptionalModuleTemplateSeeder;
use App\Services\Notifications\EmailService;
use App\Services\Subscriptions\SubscriptionOnboarding;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\DefaultRoleSeeder;
use App\Services\Tenant\DefaultTemplateSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Owns the multi-step creation of an organization:
 *
 *   1. store the logo            (files table + disk)
 *   2. insert the organization   (master database)
 *   3. create the tenant database
 *   4. migrate the tenant database
 *   5. create the owner's tenant account
 *   6. email the owner a sign-in link (or a setup link for legacy callers)
 *
 * Steps 1-2 happen in the request; 3-6 run on the `provisioning` queue
 * (ProvisionOrganization), so the request returns at once and a schema of a
 * hundred migrations can never time it out. The administrator watches the
 * steps land on the organization's page (`progress()`).
 *
 * Steps 3-6 are tracked one row at a time in `tenant_provision_events`. A
 * step already marked `completed` there is skipped on the next attempt, so
 * a retry resumes from wherever it actually stopped instead of restarting
 * — and nothing is deleted or dropped on failure, since doing so is exactly
 * what would make resuming impossible. Only step 1's upload has no
 * organization to attach an event to yet, so it stays a plain best-effort
 * cleanup if step 2 itself fails.
 */
class OrganizationProvisioningService
{
    /** The queued steps, in the order they run, as the administrator reads them. */
    public const STEPS = [
        'create_database' => 'Create the database',
        'migrate_tenant' => 'Build the tables',
        'seed_default_roles' => 'Add the default roles and print templates',
        'seed_notification_templates' => 'Add the message templates',
        'create_owner_account' => "Create the owner's login",
        'send_setup_email' => 'Email the owner',
    ];

    /** Where a tenant's schema lives, in the order the migrator runs it. */
    private const MIGRATION_PATHS = [
        'database/migrations/organization',
        'database/migrations/organization/after-seed',
    ];

    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly TenantProvisionEventRepositoryInterface $provisionEvents,
        private readonly TenantDatabaseRepositoryInterface $tenantDatabases,
        private readonly DbClusterRepositoryInterface $dbClusters,
        private readonly TenantConnectionService $tenants = new TenantConnectionService,
        private readonly ?ProvisioningProgress $progress = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes, including database_name.
     *
     * @throws \Throwable
     */
    public function provision(array $data, ?UploadedFile $logo = null): Organization
    {
        $password = $data['password'] ?? null;
        unset($data['password'], $data['password_confirmation']);

        // Which plan it starts on — not a column, so it is taken out first.
        $subscription = (array) ($data['subscription'] ?? []);
        unset($data['subscription']);

        if (is_string($password) && $password !== '') {
            // Keep only a one-way hash in the master database while the tenant
            // is being provisioned, so a failed attempt can still be retried.
            $data['owner_password_hash'] = Hash::make($password);
        } else {
            // Older API clients still use the original emailed setup flow.
            $data['setup_token'] = Str::random(64);
            $data['setup_token_expires_at'] = now()->addDays(
                config('organization.setup_token_ttl_days', 7)
            );
        }

        $uploadedFileId = null;

        if ($logo) {
            $uploadedFileId = uploadFile($logo, 'organizations');
            $data['profile_image'] = $uploadedFileId;
        }

        // §5: a new organization starts pending and is moved along by the
        // steps below, so its status always reflects how far it got.
        $data['status'] = Organization::PENDING;

        try {
            /*
             * The organization and its first subscription, together: no
             * organization ever exists without one, so none can be locked
             * by having nothing to say it may run. See SubscriptionOnboarding
             * for which plan it gets.
             */
            $organization = DB::transaction(function () use ($data, $subscription) {
                $organization = $this->organizations->create($data);

                app(SubscriptionOnboarding::class)->start($organization, $subscription);

                return $organization;
            });
        } catch (\Throwable $e) {
            // Nothing exists yet for this attempt to resume, so the upload
            // really is orphaned — clean it up rather than leaving it behind.
            if ($uploadedFileId) {
                $this->attempt(fn () => deleteFile($uploadedFileId));
            }

            throw $e;
        }

        return $this->queue($organization);
    }

    /**
     * Re-enter the step sequence for an organization whose provisioning
     * didn't finish. Steps already marked `completed` are skipped.
     *
     * @throws \Throwable
     */
    public function retryProvisioning(Organization $organization): Organization
    {
        if (! $this->isUnfinished($organization)) {
            throw new \RuntimeException('Only a pending, provisioning or failed organization can be retried.');
        }

        // Back in the queue: the page reads "waiting" until a worker picks it up.
        $organization = $this->organizations->changeStatus(
            $organization,
            Organization::PENDING,
            'Provisioning queued again.',
            Auth::guard('platform')->id(),
        );

        return $this->queue($organization);
    }

    public function isUnfinished(Organization $organization): bool
    {
        return in_array($organization->status, [Organization::PENDING, Organization::PROVISIONING, Organization::FAILED], true);
    }

    /**
     * Hand the steps to the `provisioning` queue and return at once.
     *
     * Where the queue is `sync` — the test suite, a machine with no worker
     * configured — the job runs right here, as provisioning always used to,
     * and a failure reaches the caller as before.
     */
    private function queue(Organization $organization): Organization
    {
        $job = (new ProvisionOrganization($organization->id, Auth::guard('platform')->id()))
            ->onConnection(config('queue.default') === 'sync' ? 'sync' : 'provisioning');

        app(Dispatcher::class)->dispatch($job);

        return $organization->fresh('organizationType');
    }

    /**
     * Where provisioning has got to, for the administrator watching it: every
     * step in order with its latest attempt, and how far the migrations are.
     *
     * @return array<string, mixed>
     */
    public function progress(Organization $organization): array
    {
        $events = $this->provisionEvents->query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->get();

        $steps = [];

        foreach (self::STEPS as $key => $label) {
            $latest = $events->where('step', $key)->last();

            $steps[] = [
                'key' => $key,
                'label' => $label,
                // Not attempted yet in this run, or ever.
                'status' => $latest?->status ?? 'waiting',
                'progress_current' => $latest?->progress_current,
                'progress_total' => $latest?->progress_total,
                'detail' => $latest?->detail,
                'error' => $latest?->status === 'failed' ? $latest->error : null,
                'started_at' => $latest?->started_at?->toIso8601String(),
                'finished_at' => $latest?->finished_at?->toIso8601String(),
            ];
        }

        $done = collect($steps)->where('status', 'completed')->count();

        return [
            'status' => $organization->status,
            'steps' => $steps,
            'completed_steps' => $done,
            'total_steps' => count($steps),
            'started_at' => $events->min('started_at')?->toIso8601String(),
            'finished_at' => $organization->status === Organization::ACTIVE
                ? $events->max('finished_at')?->toIso8601String()
                : null,
            // Queued, and no worker has started on it since.
            'waiting_for_worker' => $organization->status === Organization::PENDING,
            'queued_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Record that the worker gave up on this organization — a timeout or a
     * killed process leaves a step "in progress" that will never finish.
     */
    public function markFailed(int $organizationId, ?string $error = null, ?int $by = null): void
    {
        $organization = $this->organizations->query()->find($organizationId);

        if (! $organization) {
            return;
        }

        $this->provisionEvents->query()
            ->where('organization_id', $organizationId)
            ->where('status', 'in_progress')
            ->update([
                'status' => 'failed',
                'error' => $error ?: 'The provisioning worker stopped before this step finished.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if (in_array($organization->status, [Organization::PENDING, Organization::PROVISIONING], true)) {
            $this->organizations->changeStatus(
                $organization,
                Organization::FAILED,
                'Provisioning stopped — see provisioning history for detail.',
                $by,
            );

            if ($tenantDatabase = $organization->tenantDatabase()->first()) {
                $this->tenantDatabases->update($tenantDatabase, ['provision_status' => 'failed']);
            }
        }
    }

    /**
     * Run every step that has not completed yet. Called by the queued job.
     *
     * @param  int|null  $by  the administrator who asked for it — there is no
     *                        signed-in user on a queue worker to ask
     */
    public function runProvisioningSteps(Organization $organization, ?int $by = null): Organization
    {
        try {
            $organization = $this->organizations->changeStatus(
                $organization,
                Organization::PROVISIONING,
                'Provisioning started.',
                $by,
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
                fn (TenantProvisionEvent $event) => $this->migrateTenant($organization->database_name, $event),
            );

            /*
             * The roles a clinic actually staffs, from the modules this
             * organization was just sold.
             *
             * Its own step rather than part of the migration: which roles
             * apply depends on the master database's entitlements, which a
             * migration cannot read — the migrator repoints `database.default`
             * at the tenant while it runs.
             */
            $this->runStep(
                $organization,
                'seed_default_roles',
                fn () => $this->seedDefaultRoles($organization),
            );

            /*
             * The notification templates an optional module's events need —
             * lab report ready, for now. Its own step because, like roles and
             * document templates, it reads this organisation's module
             * entitlements from the MASTER database, which only this step
             * (not the migration before it) can do.
             */
            $this->runStep(
                $organization,
                'seed_notification_templates',
                fn () => $this->seedNotificationTemplates($organization),
            );

            $this->runStep(
                $organization,
                'create_owner_account',
                fn () => $this->createOwnerAccount($organization),
            );

            $this->runStep(
                $organization,
                'send_setup_email',
                fn () => $this->sendSetupEmail($organization),
            );

            /*
             * Provisioned and reachable — §7 ends here with the organization
             * active and its owner able to sign in using the credentials
             * entered during creation.
             */
            $organization = $this->organizations->changeStatus(
                $organization,
                Organization::ACTIVE,
                'Provisioning completed.',
                $by,
            );

            return $organization->fresh('organizationType');
        } catch (\Throwable $e) {
            $this->organizations->changeStatus(
                $organization,
                Organization::FAILED,
                'Provisioning failed — see provisioning history for detail.',
                $by,
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
            // The step's own row, for a step that reports how far it has got.
            $action($event);

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
     *
     * Given the step's row, it counts the migrations onto it as they run, so
     * the administrator sees "45 of 92" rather than a step that just sits.
     */
    public function migrateTenant(string $databaseName, ?TenantProvisionEvent $event = null): void
    {
        $this->tenants->connect($databaseName);

        $progress = $event ? ($this->progress ?? app(ProvisioningProgress::class)) : null;
        $progress?->track($event, $this->pendingMigrations());

        try {
            foreach (self::MIGRATION_PATHS as $path) {
                Artisan::call('migrate', [
                    '--database' => TenantConnectionService::CONNECTION,
                    '--path' => $path,
                    '--force' => true,
                ]);
            }
        } finally {
            $progress?->stop();
        }
    }

    /**
     * The migrations this tenant has not run yet, in the order the migrator
     * will run them: each path in turn, by file name — what the migrator
     * itself does.
     *
     * The DB facade for `migrations`: Laravel's own table, with no model.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $connection = TenantConnectionService::CONNECTION;

        $ran = Schema::connection($connection)->hasTable('migrations')
            ? array_flip(DB::connection($connection)->table('migrations')->pluck('migration')->all())
            : [];

        $pending = [];

        foreach (self::MIGRATION_PATHS as $path) {
            $names = array_map(
                fn (string $file) => basename($file, '.php'),
                glob(base_path($path).'/*_*.php') ?: [],
            );

            sort($names);

            foreach ($names as $name) {
                if (! isset($ran[$name])) {
                    $pending[] = $name;
                }
            }
        }

        return $pending;
    }

    /**
     * The starting role library — Branch manager, Doctor, Receptionist and so
     * on, narrowed to what this organization was actually sold.
     *
     * Connects first because the previous step may have disconnected, and
     * idempotent by role name, so a retried provisioning adds nothing twice.
     */
    private function seedDefaultRoles(Organization $organization): void
    {
        $this->tenants->connect($organization->database_name);

        app(DefaultRoleSeeder::class)->seed($organization);

        /* A printable letterhead for every document this organization was
           sold — an organization that can do nothing on day one is one
           somebody has to be walked through before it works. */
        app(DefaultTemplateSeeder::class)->seed($organization);
    }

    /**
     * Default notification templates for whatever optional modules this
     * organisation was sold — lab report ready, for an org with laboratory.
     * The unconditional defaults (appointment confirmed, reminders, payment
     * received, welcome) arrive via the tenant's own migrations instead; see
     * OptionalModuleTemplateSeeder's docblock for why this one piece can't.
     */
    private function seedNotificationTemplates(Organization $organization): void
    {
        $this->tenants->connect($organization->database_name);

        app(OptionalModuleTemplateSeeder::class)->seed($organization);
    }

    /**
     * Create the owner account in the tenant and consume the temporary hash.
     *
     * An existing owner is accepted so a retry can finish the master-database
     * update if the tenant insert succeeded immediately before a failure.
     */
    private function createOwnerAccount(Organization $organization): void
    {
        $this->tenants->run($organization->database_name, function () use ($organization): void {
            $user = TenantUser::query()->where('email', $organization->email)->first();

            if (! $user) {
                if (! $organization->owner_password_hash) {
                    // Organizations created before direct credentials were
                    // added still use their original emailed setup link.
                    if ($organization->setup_token && ! $organization->is_setup_completed) {
                        return;
                    }

                    throw new \RuntimeException('The owner password is unavailable for account creation.');
                }

                $user = TenantUser::query()->create([
                    'name' => $organization->contact_person_name ?: $organization->organization_name,
                    'email' => $organization->email,
                    'password' => $organization->owner_password_hash,
                    'is_active' => true,
                    'role' => TenantUser::OWNER,
                ]);
            }

            if ($user->role !== TenantUser::OWNER) {
                throw new \RuntimeException('The organization login ID is already assigned to a non-owner account.');
            }

            $organization->forceFill([
                'owner_password_hash' => null,
                'is_setup_completed' => true,
                'setup_completed_at' => now(),
                'setup_token' => null,
                'setup_token_expires_at' => null,
            ])->save();
        });
    }

    /**
     * @throws \RuntimeException if the email genuinely fails to send — a
     *                           missing/inactive template should be a retryable provisioning
     *                           failure, not a silently skipped notification.
     */
    private function sendSetupEmail(Organization $organization): void
    {
        if ($organization->is_setup_completed) {
            $domain = config('organization.main_domain');
            /*
             * The scheme from APP_URL, not from the request: this runs on a
             * queue worker, where there is no request — the console's stand-in
             * always says http, which would send a live clinic an http link.
             */
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
            $loginUrl = $domain
                ? $scheme.'://'.$organization->subdomain.'.'.$domain.'/login'
                : url('/login');

            $sent = EmailService::send(
                'organization_setup_completed',
                $organization->email,
                [
                    'user_name' => $organization->contact_person_name ?: $organization->organization_name,
                    'email' => $organization->email,
                    'organization_name' => $organization->organization_name,
                    'login_button' => emailButton($loginUrl, 'Login To Your Account'),
                ],
            );

            if (! $sent) {
                throw new \RuntimeException('Failed to send the organization account-ready email.');
            }

            return;
        }

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

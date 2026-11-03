<?php

namespace App\Services\Modules;

use App\Models\Platform\Organization;
use App\Services\Communication\OptionalModuleTemplateSeeder;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\DefaultRoleSeeder;
use App\Services\Tenant\DefaultTemplateSeeder;
use Throwable;

/**
 * What a newly granted module brings with it inside the organization.
 *
 * Starting roles, printable templates and notification templates are all drawn
 * from the modules running when they are written. Nothing is included by
 * default, so that is usually before most modules are granted, and without this
 * they would stay capped at whatever was on that day.
 *
 * Failure is reported and swallowed. The grant is already saved and is the thing
 * the administrator asked for; every step here is idempotent, and `tenants:migrate`
 * runs the seeders again on the next deploy.
 */
class ModuleGrantSetup
{
    public function __construct(
        private readonly ModuleAccess $modules,
        private readonly DefaultRoleSeeder $roles,
        private readonly DefaultTemplateSeeder $templates,
        private readonly OptionalModuleTemplateSeeder $notifications,
        private readonly TenantConnectionService $tenants,
    ) {}

    /**
     * @param  list<string>  $before  what the organization could use before the change
     */
    public function afterChange(Organization $organization, array $before): void
    {
        $granted = array_values(array_diff($this->modules->enabled($organization), $before));

        if ($granted === [] || ! $organization->isProvisioned() || blank($organization->database_name)) {
            return;
        }

        try {
            $this->tenants->run($organization->database_name, function () use ($organization, $granted) {
                $this->roles->grow($organization, $granted);
                $this->templates->seed($organization);
                $this->notifications->seed($organization);
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

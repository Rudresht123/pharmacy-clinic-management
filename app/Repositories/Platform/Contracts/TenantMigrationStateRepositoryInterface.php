<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\Organization;
use App\Models\Platform\TenantMigrationState;
use App\Repositories\Contracts\RepositoryInterface;

interface TenantMigrationStateRepositoryInterface extends RepositoryInterface
{
    /**
     * The schema-drift row for one organization, creating it with the given
     * defaults the first time migration tracking reaches it.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreateForOrganization(Organization $organization, array $defaults = []): TenantMigrationState;
}

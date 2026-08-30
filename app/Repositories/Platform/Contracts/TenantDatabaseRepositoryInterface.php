<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\Organization;
use App\Models\Platform\TenantDatabase;
use App\Repositories\Contracts\RepositoryInterface;

interface TenantDatabaseRepositoryInterface extends RepositoryInterface
{
    /**
     * The routing row for one organization, creating it with the given
     * defaults the first time provisioning reaches it.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreateForOrganization(Organization $organization, array $defaults = []): TenantDatabase;
}

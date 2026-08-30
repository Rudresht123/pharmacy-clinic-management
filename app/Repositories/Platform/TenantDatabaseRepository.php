<?php

namespace App\Repositories\Platform;

use App\Models\Platform\Organization;
use App\Models\Platform\TenantDatabase;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;

class TenantDatabaseRepository extends BaseRepository implements TenantDatabaseRepositoryInterface
{
    public function __construct(TenantDatabase $model)
    {
        parent::__construct($model);
    }

    public function firstOrCreateForOrganization(Organization $organization, array $defaults = []): TenantDatabase
    {
        return $this->query()->firstOrCreate(['organization_id' => $organization->id], $defaults);
    }
}

<?php

namespace App\Repositories\Platform;

use App\Models\Platform\Organization;
use App\Models\Platform\TenantMigrationState;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\TenantMigrationStateRepositoryInterface;

class TenantMigrationStateRepository extends BaseRepository implements TenantMigrationStateRepositoryInterface
{
    public function __construct(TenantMigrationState $model)
    {
        parent::__construct($model);
    }

    public function firstOrCreateForOrganization(Organization $organization, array $defaults = []): TenantMigrationState
    {
        return $this->query()->firstOrCreate(['organization_id' => $organization->id], $defaults);
    }
}

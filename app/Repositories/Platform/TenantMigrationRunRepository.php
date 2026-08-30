<?php

namespace App\Repositories\Platform;

use App\Models\Platform\TenantMigrationRun;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\TenantMigrationRunRepositoryInterface;

class TenantMigrationRunRepository extends BaseRepository implements TenantMigrationRunRepositoryInterface
{
    public function __construct(TenantMigrationRun $model)
    {
        parent::__construct($model);
    }
}

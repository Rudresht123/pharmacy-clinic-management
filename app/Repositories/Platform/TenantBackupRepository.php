<?php

namespace App\Repositories\Platform;

use App\Models\Platform\TenantBackup;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\TenantBackupRepositoryInterface;

class TenantBackupRepository extends BaseRepository implements TenantBackupRepositoryInterface
{
    public function __construct(TenantBackup $model)
    {
        parent::__construct($model);
    }
}

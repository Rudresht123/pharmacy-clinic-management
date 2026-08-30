<?php

namespace App\Repositories\Platform;

use App\Models\Platform\DbCluster;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\DbClusterRepositoryInterface;

class DbClusterRepository extends BaseRepository implements DbClusterRepositoryInterface
{
    public function __construct(DbCluster $model)
    {
        parent::__construct($model);
    }

    public function findDefault(): ?DbCluster
    {
        return $this->query()->where('is_default', true)->first();
    }
}

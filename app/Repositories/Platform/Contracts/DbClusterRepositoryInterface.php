<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\DbCluster;
use App\Repositories\Contracts\RepositoryInterface;

interface DbClusterRepositoryInterface extends RepositoryInterface
{
    /** The single cluster new tenants provision onto today. */
    public function findDefault(): ?DbCluster;
}

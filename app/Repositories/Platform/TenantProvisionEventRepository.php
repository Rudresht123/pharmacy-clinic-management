<?php

namespace App\Repositories\Platform;

use App\Models\Platform\TenantProvisionEvent;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\TenantProvisionEventRepositoryInterface;

class TenantProvisionEventRepository extends BaseRepository implements TenantProvisionEventRepositoryInterface
{
    public function __construct(TenantProvisionEvent $model)
    {
        parent::__construct($model);
    }
}

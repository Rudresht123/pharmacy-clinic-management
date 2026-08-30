<?php

namespace App\Repositories\Platform;

use App\Models\Platform\OrganizationDomain;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\OrganizationDomainRepositoryInterface;

class OrganizationDomainRepository extends BaseRepository implements OrganizationDomainRepositoryInterface
{
    public function __construct(OrganizationDomain $model)
    {
        parent::__construct($model);
    }
}

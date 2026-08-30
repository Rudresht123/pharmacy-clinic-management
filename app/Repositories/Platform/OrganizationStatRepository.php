<?php

namespace App\Repositories\Platform;

use App\Models\Platform\OrganizationStat;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\OrganizationStatRepositoryInterface;

class OrganizationStatRepository extends BaseRepository implements OrganizationStatRepositoryInterface
{
    public function __construct(OrganizationStat $model)
    {
        parent::__construct($model);
    }
}

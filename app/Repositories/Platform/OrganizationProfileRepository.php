<?php

namespace App\Repositories\Platform;

use App\Models\Platform\OrganizationProfile;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\OrganizationProfileRepositoryInterface;

class OrganizationProfileRepository extends BaseRepository implements OrganizationProfileRepositoryInterface
{
    public function __construct(OrganizationProfile $model)
    {
        parent::__construct($model);
    }
}

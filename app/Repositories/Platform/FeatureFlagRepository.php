<?php

namespace App\Repositories\Platform;

use App\Models\Platform\FeatureFlag;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\FeatureFlagRepositoryInterface;

class FeatureFlagRepository extends BaseRepository implements FeatureFlagRepositoryInterface
{
    public function __construct(FeatureFlag $model)
    {
        parent::__construct($model);
    }
}

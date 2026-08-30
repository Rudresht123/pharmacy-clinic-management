<?php

namespace App\Repositories\Platform;

use App\Models\Platform\PlatformSetting;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\PlatformSettingRepositoryInterface;

class PlatformSettingRepository extends BaseRepository implements PlatformSettingRepositoryInterface
{
    public function __construct(PlatformSetting $model)
    {
        parent::__construct($model);
    }
}

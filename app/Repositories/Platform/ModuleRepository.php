<?php

namespace App\Repositories\Platform;

use App\Models\Platform\Module;
use App\Repositories\BaseRepository;
use App\Repositories\Platform\Contracts\ModuleRepositoryInterface;

class ModuleRepository extends BaseRepository implements ModuleRepositoryInterface
{
    public function __construct(Module $model)
    {
        parent::__construct($model);
    }
}

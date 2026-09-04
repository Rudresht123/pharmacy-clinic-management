<?php

namespace App\Repositories;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;

class GlobalSettingRepo
{
    public function getOrgTypes($search = null)
    {

        return OrganizationType::query()->search($search)->get();
    }

    // Getting organizations
    public function getOrganizations($search = null)
    {
        return Organization::with('organizationType')->search($search)->get();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Middleware\EnsurePortalPatient;
use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Services\Permissions\Permission;
use Illuminate\Http\Request;

/**
 * What every portal endpoint needs: whose data, and which clinic.
 *
 * Both come from middleware that has already checked them — the patient from
 * EnsurePortalPatient, the clinic from the X-Organization header — and never
 * from anything in the request body.
 */
abstract class PortalController extends BaseApiController
{
    protected function patient(Request $request): Customer
    {
        return $request->attributes->get(EnsurePortalPatient::ATTRIBUTE);
    }

    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('tenant.organization');
    }

    /**
     * Whose single records this login may open: their own, and their family's
     * where the role lets them read those.
     *
     * @return list<int>
     */
    protected function readableIds(Request $request): array
    {
        $patient = $this->patient($request);
        $permission = app(Permission::class);
        $organization = $this->organization($request);

        $mayRead = $permission->allows($organization, $request->user(), 'portal.family')
            && $permission->allows($organization, $request->user(), 'portal.family.history');

        return $mayRead ? $patient->familyIds() : [(int) $patient->getKey()];
    }
}

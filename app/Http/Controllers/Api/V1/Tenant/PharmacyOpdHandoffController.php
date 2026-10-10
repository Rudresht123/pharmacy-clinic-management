<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Broadcasting\BranchPharmacyChannel;
use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\PharmacyStore;
use App\Services\Permissions\Permission;
use App\Services\Pharmacy\OpdHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Patients from OPD" — the counter's view of who the doctor just finished.
 *
 * Addressed by STORE, because that is what the counter works from: the POS
 * screen has a store chosen, and the store knows its branch. Asking by branch
 * would let a client pair a store with somebody else's branch.
 *
 * Three gates, each a different question:
 *
 *   route      `module:pharmacy`, `module:appointments`, `pharmacy.view` at the
 *              acting branch — the cheap refusals, before anything is loaded
 *   policy     PharmacyStorePolicy::view — this store's own branch, which the
 *              caller must work at and hold `pharmacy.view` at
 *   feature    `opd_pharmacy_handoff` at the STORE'S branch, because the route
 *              gate asked about the caller's acting branch and the two need
 *              not be the same one
 *
 * The answer carries the channel to listen on, so the client never builds a
 * channel name — the server that authorizes the subscription also names it.
 *
 * @group Tenant > Pharmacy > Stores & Sales
 */
class PharmacyOpdHandoffController extends BaseApiController
{
    use AuthorizesTenantUser;

    public function __construct(
        private readonly Permission $permission,
        private readonly OpdHandoff $handoff,
    ) {}

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $organization = $request->attributes->get('tenant.organization');
        $locationId = $store->location_id === null ? null : (int) $store->location_id;

        if ($organization === null || $locationId === null
            || ! ($this->permission->featuresAt($organization, $locationId)['opd_pharmacy_handoff'] ?? false)) {
            return $this->fail('Patients from OPD need both the OPD and the pharmacy running at this branch.', 403);
        }

        return $this->ok([
            'location_id' => $locationId,
            'channel' => 'private-'.BranchPharmacyChannel::name($organization->uuid, $locationId),
            'patients' => $this->handoff->forBranch($locationId),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Laboratory\LabCatalog;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What can be ordered, priced for the branch it is ordered at.
 *
 * The picker behind the doctor's Investigations and the lab desk's new order.
 * Tests today; packages join in Phase 3. Each row says its price at the branch
 * the person is acting at (or the one named), so a screen never works a price
 * out for itself.
 *
 * @group Tenant > Laboratory
 */
class LabOrderableController extends BaseApiController
{
    public function __construct(
        private readonly LabCatalog $catalog,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * @queryParam search string Code or name. Example: lipid
     * @queryParam location_id integer Price at this branch instead of the acting one.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $locationId = isset($validated['location_id'])
            ? (int) $validated['location_id']
            : $request->attributes->get('tenant.branch');

        if ($locationId !== null && ! $this->branches->canUse(Auth::guard('web')->user(), (int) $locationId)) {
            abort(403, 'You are not permitted at that branch.');
        }

        return $this->ok($this->catalog->orderables(
            $validated['search'] ?? null,
            $locationId === null ? null : (int) $locationId,
        ));
    }
}

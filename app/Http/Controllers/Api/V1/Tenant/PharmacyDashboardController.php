<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\PharmacyStore;
use App\Services\Pharmacy\PharmacyDashboard;
use Illuminate\Http\JsonResponse;

/**
 * The pharmacy's own first screen, for one store.
 *
 * Read-only, and `view` on the store — the same question the stock and bill
 * screens ask, asked about the store's own branch through PharmacyStorePolicy
 * rather than the branch the request happens to be acting at. Nothing here is
 * a figure somebody could not already reach by opening those screens one at a
 * time; this saves them the walk.
 */
class PharmacyDashboardController extends BaseApiController
{
    use AuthorizesTenantUser;

    public function __construct(
        private readonly PharmacyDashboard $dashboard,
    ) {}

    public function show(PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        return $this->ok($this->dashboard->for($store->load('location')));
    }
}

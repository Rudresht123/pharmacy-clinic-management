<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Laboratory\LabDashboard;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The laboratory dashboard: tests ordered, completed, pending and cancelled,
 * the trend, the split by department and the latest requests.
 *
 * At the branch the person is acting at; across every branch they may see when
 * they are not acting at one (the owner, head office). Behind
 * `laboratory.dashboard`. Money is not on it: a lab bill is shown only to
 * people who handle money, on the billing screens.
 *
 * @group Tenant > Laboratory
 */
class LabDashboardController extends BaseApiController
{
    public function __construct(
        private readonly LabDashboard $dashboard,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * @queryParam range string 7d, 30d, 3m or 1y. Example: 30d
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(LabDashboard::RANGES))],
        ]);

        $acting = $request->attributes->get('tenant.branch');

        $branches = $acting !== null
            ? [(int) $acting]
            : $this->branches->allowed(Auth::guard('web')->user());

        return $this->ok($this->dashboard->summary($branches, $validated['range'] ?? '30d'));
    }
}

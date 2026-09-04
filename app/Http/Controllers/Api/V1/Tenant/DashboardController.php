<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\User;
use App\Services\Tenant\DashboardSummary;
use App\Services\Tenant\DemoDashboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The first screen inside an organization's workspace.
 *
 * Ungated on the route on purpose: anybody who may sign in may open their own
 * dashboard. What it CONTAINS is gated panel by panel, so the answer is
 * already narrowed to what this person may see and to the branches they work
 * at — see App\Services\Tenant\DashboardSummary.
 *
 * A signed-in person holding nothing gets a valid, nearly empty summary rather
 * than a 403, which is the honest answer: they may be here, there is just
 * nothing yet to show them.
 */
class DashboardController extends BaseApiController
{
    public function index(
        Request $request,
        DashboardSummary $summary,
        DemoDashboardData $demo,
    ): JsonResponse {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        if (! $organization || ! $user instanceof User) {
            return $this->ok(['scope' => ['branches' => [], 'label' => '']]);
        }

        $data = $summary->for($organization, $user);

        /*
         * The one place demo data enters the application.
         *
         * Off, this method is exactly what it was: real, branch-scoped and
         * capability-gated. On, sample figures fill the panels that have
         * nothing real behind them yet — see config/hms.php and
         * App\Services\Tenant\DemoDashboardData, which is a file somebody can
         * delete rather than fake numbers threaded through the real service.
         */
        if (config('hms.dashboard_demo')) {
            $data = $demo->mergeInto($data, $summary->permittedKeys($organization, $user));
        }

        return $this->ok($data);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantBranchAccess;
use App\Services\Tenant\DashboardSummary;
use App\Services\Tenant\DashboardWindow;
use App\Services\Tenant\DemoDashboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
 *
 * @group Tenant > Dashboard
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

        $data = $summary->for($organization, $user, $this->window($request));

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

    /**
     * One card, re-asked with its own filter — the revenue chart by week,
     * the visit chart for one branch, last year's profit and loss.
     *
     * Its own endpoint rather than parameters on index(): changing one card's
     * filter must not recompute every other card on the screen.
     *
     * A panel this person may not see answers 404, exactly as one that does
     * not exist would. A branch they may not see is refused outright.
     */
    public function panel(
        Request $request,
        string $panel,
        DashboardSummary $summary,
        DemoDashboardData $demo,
        TenantBranchAccess $branches,
    ): JsonResponse {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        $accepts = DashboardSummary::FILTERABLE[$panel] ?? null;

        if (! $organization || ! $user instanceof User || $accepts === null) {
            return $this->fail('There is no such panel.', 404);
        }

        $validated = $request->validate([
            'granularity' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            // Rule::exists on the model, so it asks the tenant's own database.
            'location_id' => ['nullable', 'integer', Rule::exists(Location::class, 'id')],
            'period' => ['nullable', Rule::in(['this_month', 'last_month', 'this_year', 'last_year'])],
        ]);

        $options = array_filter(
            array_intersect_key($validated, array_flip($accepts)),
            fn ($value) => $value !== null,
        );

        if (isset($options['location_id'])) {
            $options['location_id'] = (int) $options['location_id'];

            if (! $branches->canUse($user, $options['location_id'])) {
                return $this->fail('You are not permitted at that branch.', 403);
            }
        }

        $data = $summary->panel($organization, $user, $panel, $this->window($request), $options);

        if ($data === null) {
            return $this->fail('There is no such panel.', 404);
        }

        // Sample figures on the full screen must not turn real the moment
        // one card's filter is touched.
        if (config('hms.dashboard_demo')) {
            $data = $demo->panel($panel, $options) ?? $data;
        }

        return $this->ok($data);
    }

    /**
     * The days the dashboard is about — the current month unless asked.
     *
     * Capped at two years: the charts fall back to months past two of them,
     * but a ten-year window is a mistyped date, not a question.
     */
    private function window(Request $request): DashboardWindow
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->date('from')) : null;
        $to = $request->filled('to') ? Carbon::parse($request->date('to')) : null;

        // Half a range is the month it falls in, from that end.
        if ($from && ! $to) {
            $to = $from->copy()->endOfMonth();
        } elseif ($to && ! $from) {
            $from = $to->copy()->startOfMonth();
        }

        if ($from && $to && $from->diffInDays($to) > 731) {
            throw ValidationException::withMessages(['to' => 'Choose a range of two years or less.']);
        }

        return new DashboardWindow($from, $to);
    }
}

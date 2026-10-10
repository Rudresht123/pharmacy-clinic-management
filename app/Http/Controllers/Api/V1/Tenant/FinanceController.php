<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Services\Billing\FinanceReports;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Finance — every department's money, read together.
 *
 * `billing.finance` at the route; the branches are the ones this person may
 * act at (TenantBranchAccess), so a branch manager reads their branch and
 * head office reads the network. Nothing here takes or moves money: the OPD,
 * the lab and the pharmacy keep their own counters.
 *
 * Department figures are reported whether or not that department's module is
 * still switched on — a lab switched off last month still has last month's
 * bills — and `departments` says which ones run now, so a screen can choose
 * what to put first.
 *
 * @group Tenant > Billing
 */
class FinanceController extends BaseApiController
{
    public function __construct(
        private readonly FinanceReports $reports,
        private readonly TenantBranchAccess $branches,
        private readonly Permission $permission,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        $branches = $this->branchesFor($request);

        $organization = $request->attributes->get('tenant.organization');

        return $this->ok([
            ...$this->reports->summary($from, $to, $branches),
            'departments' => [
                'opd' => true,
                'laboratory' => $organization instanceof Organization
                    && $this->permission->hasModule($organization, 'laboratory'),
                'pharmacy' => $organization instanceof Organization
                    && $this->permission->hasModule($organization, 'pharmacy'),
            ],
        ]);
    }

    /** Every bill from every department, one list. */
    public function documents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', Rule::in(array_keys(FinanceReports::MODULES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'outstanding_only' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->reports->documents($this->branchesFor($request), [
            'module' => $validated['module'] ?? null,
            'from' => isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            'to' => isset($validated['to']) ? Carbon::parse($validated['to']) : null,
            'outstanding' => (bool) ($validated['outstanding_only'] ?? false),
            'search' => $validated['search'] ?? null,
        ], (int) ($validated['per_page'] ?? 25));

        return $this->ok([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * The branches to read: the ones this person may see, narrowed to one
     * when the screen asks — and never widened past what they may see.
     *
     * @return list<int>|null null means every branch
     */
    private function branchesFor(Request $request): ?array
    {
        $allowed = $this->branches->allowed($request->user());

        if (! $request->filled('location_id')) {
            return $allowed;
        }

        $branch = $request->integer('location_id');

        if ($allowed !== null && ! in_array($branch, $allowed, true)) {
            abort(403, 'You do not have access to that branch.');
        }

        return [$branch];
    }

    /**
     * The window: this month by default, a year at most.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = $request->filled('to') ? Carbon::parse($request->date('to'))->endOfDay() : Carbon::today()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->date('from'))->startOfDay() : $to->copy()->startOfMonth();

        if ($from->diffInDays($to) > 366) {
            abort(422, 'Pick a window of a year or less.');
        }

        return [$from, $to];
    }
}

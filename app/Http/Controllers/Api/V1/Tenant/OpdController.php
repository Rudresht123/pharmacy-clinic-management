<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Opd\OpdBoard;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The OPD department as a whole, rather than one doctor's list.
 *
 * Two endpoints, both branch-wide: where this person may work, and what is
 * happening there today. Both sit behind `appointments.view` — the same
 * question the queue asks — so somebody who runs the desk can use them
 * without also holding `branches.view`, which they have no reason to.
 */
class OpdController extends BaseApiController
{
    public function __construct(
        private readonly OpdBoard $board,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The branches this person may run an OPD day at.
     *
     * Its own endpoint rather than reusing /tenant/locations, for two reasons
     * that are really one: that route needs `branches.view`, and it returns
     * every branch in the organization — including the ones this person would
     * be refused at the moment they picked one.
     */
    public function branches(): JsonResponse
    {
        $branches = $this->board->branchesFor(Auth::guard('web')->user());

        return $this->ok(
            $branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ])->all()
        );
    }

    /** Counts, who has waited longest, and what each doctor is doing. */
    public function today(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $locationId = (int) $request->input('location_id');

        // A branch id from the client is just a number until somebody checks
        // it — the same guard the queue applies, for the same reason.
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return $this->ok(
            $this->board->for(
                $locationId,
                $request->filled('date') ? Carbon::parse($request->input('date')) : now(),
            )
        );
    }
}

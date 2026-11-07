<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveLabMasterRequest;
use App\Http\Resources\Tenant\LabMasterResource;
use App\Services\Laboratory\LabMasterService;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Laboratory\LabMasters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The laboratory's short lists: departments, test categories, methods, units,
 * containers, sample types, rejection reasons, equipment and partner labs.
 *
 * One controller for nine lists because they share one life — add, edit,
 * switch off, remove with a reason — and LabMasters describes each one's
 * fields. Read by every lab screen that picks from them; written under
 * `laboratory.settings_manage`, an organisation-wide tick, because a chain
 * calls a serum tube a serum tube at every branch.
 *
 * `{master}` is one of the list keys (`departments`, `sample-types`, …); any
 * other name is a 404.
 *
 * @group Tenant > Laboratory
 */
class LabMasterController extends BaseApiController
{
    public function __construct(
        private readonly LabMasterService $masters,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * What each list is: its words, fields and choices — what the settings
     * screen draws its tabs and forms from.
     */
    public function catalogue(): JsonResponse
    {
        return $this->ok(LabMasters::describe());
    }

    /**
     * One list, in its own order.
     *
     * @queryParam search string Matches any text column. Example: serum
     * @queryParam active_only boolean Only rows switched on — what a picker offers. Example: 1
     */
    public function index(Request $request, string $master): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        return $this->ok(LabMasterResource::listOf($master, $this->masters->list($master, $validated)));
    }

    public function store(SaveLabMasterRequest $request, string $master): JsonResponse
    {
        $data = $request->validated();
        $this->mayUseBranch($data['location_id'] ?? null);

        $record = $this->masters->create($master, $data);

        return $this->created(
            LabMasterResource::for($master, $record),
            ucfirst(LabMasters::get($master)['singular'])." {$record->label()} added",
        );
    }

    public function update(SaveLabMasterRequest $request, string $master, int $id): JsonResponse
    {
        $record = $this->masters->find($master, $id);
        $data = $request->validated();
        $this->mayUseBranch($data['location_id'] ?? null);

        $record = $this->masters->update($master, $record, $data);

        return $this->ok(LabMasterResource::for($master, $record), "{$record->label()} saved");
    }

    /** Remove a row, with a reason. Refused (409) while anything still uses it. */
    public function destroy(Request $request, string $master, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $record = $this->masters->find($master, $id);
        $this->masters->remove($master, $record, $validated['reason']);

        return $this->ok(null, "{$record->label()} removed");
    }

    /**
     * Add the common names this list does not have yet. Pressing it twice adds
     * nothing the second time; rows somebody edited are never touched.
     */
    public function starter(string $master): JsonResponse
    {
        $added = $this->masters->loadStarter($master);
        $definition = LabMasters::get($master);

        return $this->ok(
            LabMasterResource::listOf($master, $this->masters->list($master)),
            $added === 0
                ? "{$definition['plural']} already has everything on the starter list."
                : sprintf('%d %s added from the starter list.', $added, $added === 1 ? $definition['singular'] : mb_strtolower($definition['plural'])),
            ['added' => $added],
        );
    }

    /**
     * Equipment names the branch it sits at. Somebody whose reach is a few
     * branches may only place it at one of theirs.
     */
    private function mayUseBranch(mixed $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        if (! $this->branches->canUse(Auth::guard('web')->user(), (int) $locationId)) {
            abort(403, 'You are not permitted at that branch.');
        }
    }
}

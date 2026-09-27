<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\BillableService;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Named things an invoice can charge for — the catalogue.
 *
 * Reading is `billing.view` (an invoice screen picks from this list), and
 * writing is `billing.manage_services` — organisation-scoped, so the
 * catalogue reads the same at every branch. A branch-level override row
 * (with `location_id` set) is allowed for a price that varies by branch.
 */
class BillableServiceController extends BaseApiController
{
    public function __construct(
        private readonly TenantBranchAccess $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->integer('location_id');

        $query = BillableService::query()
            ->when(
                $locationId,
                fn ($q) => $q->forBranch($locationId),
            )
            ->when(
                $request->boolean('active_only', false),
                fn ($q) => $q->active(),
            )
            ->orderBy('position')
            ->orderBy('name');

        return $this->ok($query->get()->map(fn (BillableService $service) => [
            'id' => $service->id,
            'location_id' => $service->location_id,
            'name' => $service->name,
            'code' => $service->code,
            'kind' => $service->kind,
            'default_price' => (float) $service->default_price,
            'tax_percent' => (float) $service->tax_percent,
            'active' => (bool) $service->active,
            'position' => $service->position,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $service = BillableService::create($data);

        return $this->created($service, "{$service->name} added");
    }

    public function update(Request $request, BillableService $service): JsonResponse
    {
        $data = $this->validated($request);

        $service->fill($data)->save();

        return $this->ok($service, "{$service->name} updated");
    }

    public function destroy(BillableService $service): JsonResponse
    {
        $service->delete();

        return $this->ok(null, "{$service->name} removed");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:24'],
            'kind' => ['required', 'string', Rule::in(BillableService::KINDS)],
            'default_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($data['location_id']) && ! $this->branches->canUse($request->user(), $data['location_id'])) {
            abort(403, 'You are not permitted at this branch.');
        }

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\LabTestCatalog;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The tests a lab offers, and what each one costs.
 *
 * READING is `laboratory.order` — the doctor's order screen picks from this
 * list, so anybody who may order a test may see what it is called and what
 * it costs. WRITING is `billing.manage_services`, the same organisation-wide
 * key that owns the rest of the price list: what the clinic charges is a
 * billing decision, not a clinical one, and a technician who may sign off a
 * result has no business repricing it.
 *
 * A branch may hold its own row for the same test at a different price; the
 * order resolver prefers it over the organisation default.
 */
class LabTestCatalogController extends BaseApiController
{
    public function __construct(
        private readonly TenantBranchAccess $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->integer('location_id');

        $query = LabTestCatalog::query()
            ->when($locationId, fn ($q) => $q->forBranch($locationId))
            ->when($request->boolean('active_only', false), fn ($q) => $q->active())
            ->orderBy('position')
            ->orderBy('name');

        return $this->ok($query->get()->map(fn (LabTestCatalog $test) => [
            'id' => $test->id,
            'location_id' => $test->location_id,
            'name' => $test->name,
            'code' => $test->code,
            'specimen' => $test->specimen,
            'price' => (float) $test->price,
            'tax_percent' => (float) $test->tax_percent,
            'active' => (bool) $test->active,
            'position' => $test->position,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $test = LabTestCatalog::create($this->validated($request));

        return $this->created($test, "{$test->name} added");
    }

    public function update(Request $request, LabTestCatalog $test): JsonResponse
    {
        $test->fill($this->validated($request))->save();

        return $this->ok($test, "{$test->name} updated");
    }

    /**
     * Remove a test from the list.
     *
     * Soft-deleted, because order lines point at it — and they keep their own
     * price snapshot regardless, so a bill printed afterwards still reads as
     * it did.
     */
    public function destroy(LabTestCatalog $test): JsonResponse
    {
        $test->delete();

        return $this->ok(null, "{$test->name} removed");
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
            'specimen' => ['nullable', Rule::in(\App\Models\Tenant\LabOrderItem::SPECIMENS)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
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

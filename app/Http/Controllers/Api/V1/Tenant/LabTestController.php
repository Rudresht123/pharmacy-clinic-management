<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveLabTestPricesRequest;
use App\Http\Requests\Api\V1\Tenant\SaveLabTestRequest;
use App\Http\Resources\Tenant\LabTestResource;
use App\Models\Tenant\LabTest;
use App\Services\Laboratory\LabCatalog;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The test master: the tests a lab can run, each with its parameters and its
 * branch prices.
 *
 * Organisation-wide, read by whoever opens the Lab tests screen and written
 * under `laboratory.catalog_manage`. A test and its parameters are saved in one
 * request; reference ranges have their own endpoint and tick.
 *
 * @group Tenant > Laboratory
 */
class LabTestController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly LabCatalog $catalog,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The test list, a page at a time.
     *
     * @queryParam search string Code, name or short name. Example: cbc
     * @queryParam department_id integer Only this department's tests.
     * @queryParam category_id integer Only this category's tests.
     * @queryParam active boolean 1 for tests in use, 0 for those switched off.
     * @queryParam outsourced boolean 1 for tests sent out, 0 for in-house.
     * @queryParam sort string name, code, price, position, created_at. Example: name
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
            'outsourced' => ['nullable', 'boolean'],
        ]);

        $query = LabTest::query()
            ->with(['department', 'category', 'sampleType'])
            ->withCount(['parameters', 'branchPrices'])
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when(isset($filters['active']), fn (Builder $q) => $q->where('is_active', (bool) $filters['active']))
            ->when(isset($filters['outsourced']), fn (Builder $q) => $q->where('is_outsourced', (bool) $filters['outsourced']));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['code', 'name', 'short_name'],
                sortable: ['name', 'code', 'price', 'position', 'created_at'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            ),
            LabTestResource::class,
        );
    }

    /** One test, with its parameters, their ranges and its branch prices. */
    public function show(LabTest $test): JsonResponse
    {
        return $this->ok(LabTestResource::make($test->load(LabCatalog::DETAIL)));
    }

    public function store(SaveLabTestRequest $request): JsonResponse
    {
        $test = $this->catalog->create($request->validated());

        return $this->created(LabTestResource::make($test), "{$test->name} added");
    }

    public function update(SaveLabTestRequest $request, LabTest $test): JsonResponse
    {
        $test = $this->catalog->update($test, $request->validated());

        return $this->ok(LabTestResource::make($test), "{$test->name} saved");
    }

    /** Take a test off the menu, with a reason. Orders already placed keep its name and price. */
    public function destroy(Request $request, LabTest $test): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->catalog->remove($test, $validated['reason']);

        return $this->ok(null, "{$test->name} removed");
    }

    /**
     * Replace the test's branch prices. A branch left out charges the test's
     * own price; somebody whose reach is some branches may only price those.
     */
    public function savePrices(SaveLabTestPricesRequest $request, LabTest $test): JsonResponse
    {
        $rows = $request->validated()['prices'];
        $actor = Auth::guard('web')->user();

        $reach = $this->branches->allowed($actor);

        /*
         * Somebody whose reach is some branches must send every other branch's
         * price back exactly as it is: they may change only their own.
         */
        if ($reach !== null) {
            $sent = collect($rows)->keyBy(fn (array $row) => (int) $row['location_id']);
            $stored = $test->branchPrices()->get()->keyBy('location_id');
            $tax = fn ($value) => $value === null || $value === '' ? null : round((float) $value, 2);

            foreach ($sent->keys()->merge($stored->keys())->unique() as $locationId) {
                if (in_array((int) $locationId, $reach, true)) {
                    continue;
                }

                $before = $stored->get($locationId);
                $after = $sent->get($locationId);

                $unchanged = ($before === null && $after === null) || ($before !== null && $after !== null
                    && round((float) $before->price, 2) === round((float) $after['price'], 2)
                    && $tax($before->tax_percent) === $tax($after['tax_percent'] ?? null));

                if (! $unchanged) {
                    abort(403, 'You can only set prices at your own branches.');
                }
            }
        }

        $this->catalog->savePrices($test, $rows);

        return $this->ok(LabTestResource::make($test->fresh(LabCatalog::DETAIL)), 'Branch prices saved');
    }
}

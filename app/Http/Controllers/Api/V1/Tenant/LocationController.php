<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreLocationRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateLocationRequest;
use App\Http\Resources\Tenant\LocationResource;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Repositories\Tenant\Contracts\LocationRepositoryInterface;
use App\Services\Fields\FieldSchema;
use App\Support\Fields\LocationFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An organization's own locations. Reads are open to any signed-in tenant
 * user; writes sit behind `tenant.owner` on the route.
 */
class LocationController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly LocationRepositoryInterface $locations,
    ) {
    }

    /**
     * The field definitions the form and table render from — the code
     * registry with this organization's own preferences applied, plus any
     * fields it added itself.
     */
    public function fields(FieldSchema $schema): JsonResponse
    {
        return $this->ok(
            $schema->for(EntityFieldSetting::ENTITY_LOCATION, LocationFields::all())
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->locations->listing(
            type: $request->string('type')->toString() ?: null,
            activeOnly: $request->boolean('active_only'),
        );

        // `all=1` is the dropdown case — every active location, unpaginated.
        if ($request->boolean('all')) {
            return $this->ok(
                LocationResource::collection($this->locations->activeOrdered())
            );
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['name', 'code', 'city'],
                sortable: ['name', 'code', 'type', 'is_active', 'created_at'],
                defaultSort: 'created_at',
            ),
            LocationResource::class,
        );
    }

    public function show(Location $location): JsonResponse
    {
        return $this->ok(LocationResource::make($location));
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->locations->create($request->validated());

        return $this->created(
            LocationResource::make($location),
            'Location created successfully.'
        );
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $updated = $this->locations->update($location, $request->validated());

        return $this->ok(
            LocationResource::make($updated),
            'Location updated successfully.'
        );
    }

    public function destroy(Location $location): JsonResponse
    {
        // Soft delete: stock, sales and purchases will reference locations,
        // and a hard delete would orphan them. The code becomes reusable
        // immediately, via the partial unique index.
        $this->locations->delete($location);

        return $this->noContent('Location deleted successfully.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\StoreOrganizationTypeRequest;
use App\Http\Requests\Api\V1\Platform\UpdateOrganizationTypeRequest;
use App\Http\Resources\Platform\OrganizationTypeResource;
use App\Models\Platform\OrganizationType;
use App\Repositories\Platform\Contracts\OrganizationTypeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationTypeController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly OrganizationTypeRepositoryInterface $types,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->types->listing(
            activeOnly: $request->boolean('active_only')
        );

        // `all=1` is the dropdown case — every type at once, unpaginated.
        if ($request->boolean('all')) {
            return $this->ok(
                OrganizationTypeResource::collection($this->types->activeOrdered())
            );
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['name', 'slug'],
                sortable: ['name', 'slug', 'is_active', 'created_at'],
                defaultSort: 'created_at',
            ),
            OrganizationTypeResource::class,
        );
    }

    public function show(OrganizationType $organizationType): JsonResponse
    {
        return $this->ok(OrganizationTypeResource::make($organizationType));
    }

    public function store(StoreOrganizationTypeRequest $request): JsonResponse
    {
        $type = $this->types->create($request->validated());

        return $this->created(
            OrganizationTypeResource::make($type),
            'Organization type created successfully.'
        );
    }

    public function update(
        UpdateOrganizationTypeRequest $request,
        OrganizationType $organizationType
    ): JsonResponse {
        $type = $this->types->update($organizationType, $request->validated());

        return $this->ok(
            OrganizationTypeResource::make($type),
            'Organization type updated successfully.'
        );
    }

    public function destroy(OrganizationType $organizationType): JsonResponse
    {
        // Removing a type that organizations still reference would leave them
        // pointing at nothing, so it is refused rather than cascaded.
        if ($this->types->isInUse($organizationType)) {
            return $this->fail('This organization type is in use and cannot be deleted.', 422);
        }

        $this->types->delete($organizationType);

        return $this->noContent('Organization type deleted successfully.');
    }

    public function toggleStatus(OrganizationType $organizationType): JsonResponse
    {
        $type = $this->types->toggleStatus($organizationType);

        return $this->ok(
            OrganizationTypeResource::make($type),
            'Status updated successfully.'
        );
    }
}

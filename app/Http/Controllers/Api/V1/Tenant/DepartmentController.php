<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveDepartmentRequest;
use App\Http\Resources\Tenant\DepartmentResource;
use App\Models\Tenant\Department;
use App\Services\Tenant\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Departments and their sub-departments.
 *
 * Read by anyone signed in — the doctor and staff forms and the booking
 * screens pick from the tree. Changed under `settings.manage`, the
 * capability that kept the department list before it became a tree. The
 * rules of the tree are DepartmentService's.
 */
class DepartmentController extends BaseApiController
{
    /** What each row counts: the doctors and the staff in it. */
    private const COUNTS = ['doctors', 'staff'];

    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

    /** The whole tree: each department with its sub-departments nested under it. */
    public function index(): JsonResponse
    {
        $tree = Department::query()
            ->topLevel()
            ->with(['children' => fn ($query) => $query->withCount(self::COUNTS)])
            ->withCount([...self::COUNTS, 'children'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->ok(DepartmentResource::collection($tree));
    }

    public function store(SaveDepartmentRequest $request): JsonResponse
    {
        $department = $this->departments->create($request->validated());

        return $this->created(
            DepartmentResource::make($department->load('parent')->loadCount([...self::COUNTS, 'children'])),
            $department->isTopLevel() ? "{$department->name} added" : "{$department->path()} added",
        );
    }

    public function update(SaveDepartmentRequest $request, Department $department): JsonResponse
    {
        $updated = $this->departments->update($department, $request->validated());

        return $this->ok(
            DepartmentResource::make($updated->load('parent')->loadCount([...self::COUNTS, 'children'])),
            'Department updated',
        );
    }

    /** Remove it, with a reason. Refused (409) while it has sub-departments, doctors or staff. */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->departments->remove($department, $validated['reason']);

        return $this->ok(null, 'Department removed');
    }
}

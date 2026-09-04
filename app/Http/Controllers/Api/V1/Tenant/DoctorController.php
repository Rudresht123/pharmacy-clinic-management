<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreDoctorRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateDoctorRequest;
use App\Http\Resources\Tenant\DoctorResource;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\EntityFieldSetting;
use App\Repositories\Tenant\Contracts\DoctorRepositoryInterface;
use App\Services\Fields\FieldSchema;
use App\Support\Fields\DoctorFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The doctors an organization's patients are seen by.
 *
 * Readable by anyone signed in — the booking screen needs the list — and
 * writable only by the owner, which the route says rather than this class.
 * The whole controller sits behind `module:appointments`.
 */
class DoctorController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
    ) {}

    /** The field definitions the form and table render from. */
    public function fields(FieldSchema $schema): JsonResponse
    {
        return $this->ok(
            array_values($schema->for(EntityFieldSetting::ENTITY_DOCTOR, DoctorFields::all()))
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->doctors->listing(
            status: $request->string('status')->toString() ?: null,
            locationId: $request->filled('location_id')
                ? (int) $request->input('location_id')
                : null,
        )->withCount('schedules');

        // `all=1` is the dropdown case — every active doctor, unpaginated.
        if ($request->boolean('all')) {
            return $this->ok(DoctorResource::collection($this->doctors->activeOrdered()));
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['name', 'code', 'specialisation', 'phone'],
                sortable: ['name', 'code', 'specialisation', 'is_active', 'created_at'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            ),
            DoctorResource::class,
        );
    }

    public function show(Doctor $doctor): JsonResponse
    {
        // The schedules answer "which branches", which a doctor row cannot.
        return $this->ok(DoctorResource::make($doctor->load('schedules.location')));
    }

    public function store(StoreDoctorRequest $request): JsonResponse
    {
        $doctor = $this->doctors->create($request->validated());

        return $this->created(DoctorResource::make($doctor), 'Doctor added');
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor): JsonResponse
    {
        $doctor = $this->doctors->update($doctor, $request->validated());

        return $this->ok(DoctorResource::make($doctor), 'Doctor updated');
    }

    public function destroy(Doctor $doctor): JsonResponse
    {
        $this->doctors->delete($doctor);

        return $this->ok(null, 'Doctor removed');
    }
}

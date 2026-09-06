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
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantConnectionService;
use App\Services\Tenant\DoctorAccountProvisioner;
use App\Support\Fields\DoctorFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        private readonly Permission $permission,
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
        // The schedules answer "which branches", which a doctor row cannot;
        // the user says whether the edit form should show a login section.
        return $this->ok(DoctorResource::make($doctor->load(['schedules.location', 'user'])));
    }

    public function store(
        StoreDoctorRequest $request,
        DoctorAccountProvisioner $accounts,
    ): JsonResponse {
        $data = $request->validated();
        $account = $data['account'] ?? null;
        unset($data['account']);

        /*
         * Both in one transaction. A doctor saved with a half-made login is
         * worse than one with none: the email is taken, so the second attempt
         * fails against an account nobody can see.
         */
        $doctor = DB::connection(TenantConnectionService::CONNECTION)
            ->transaction(function () use ($data, $account, $request, $accounts) {
                $doctor = $this->doctors->create($data);

                if ($account) {
                    $accounts->open($doctor, $account, $this->modules($request));
                }

                return $doctor;
            });

        return $this->created(
            DoctorResource::make($doctor->load('user')),
            $account ? 'Doctor added, and they can now sign in.' : 'Doctor added',
        );
    }

    public function update(
        UpdateDoctorRequest $request,
        Doctor $doctor,
        DoctorAccountProvisioner $accounts,
    ): JsonResponse {
        $data = $request->validated();
        $account = $data['account'] ?? null;
        unset($data['account']);

        $updated = DB::connection(TenantConnectionService::CONNECTION)
            ->transaction(function () use ($doctor, $data, $account, $request, $accounts) {
                $updated = $this->doctors->update($doctor, $data);

                if ($account) {
                    $accounts->open($updated, $account, $this->modules($request));
                } else {
                    /*
                     * The section was switched off, so the login goes. Sent
                     * every time the form is saved, an absent block can only
                     * mean "they should not have one" — which is what the
                     * switch says.
                     */
                    $accounts->close($updated);
                }

                return $updated;
            });

        return $this->ok(DoctorResource::make($updated->load('user')), 'Doctor updated');
    }

    /**
     * What this organization runs, for bounding the doctor role.
     *
     * @return list<string>
     */
    private function modules(Request $request): array
    {
        $organization = $request->attributes->get('tenant.organization');

        return $organization ? $this->permission->modulesAt($organization, null) : [];
    }

    public function destroy(Doctor $doctor): JsonResponse
    {
        $this->doctors->delete($doctor);

        return $this->ok(null, 'Doctor removed');
    }
}

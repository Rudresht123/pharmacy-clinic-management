<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Requests\Api\V1\Tenant\Portal\SaveFamilyMemberRequest;
use App\Http\Resources\Tenant\Portal\PortalAppointmentResource;
use App\Http\Resources\Tenant\Portal\PortalFamilyMemberResource;
use App\Http\Resources\Tenant\Portal\PortalLabReportResource;
use App\Http\Resources\Tenant\Portal\PortalPrescriptionResource;
use App\Services\Permissions\Permission;
use App\Services\Portal\PatientAppointments;
use App\Services\Portal\PatientFamily;
use App\Services\Portal\PatientRecords;
use App\Services\Portal\PatientVitals;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The people a patient books for.
 *
 * @group Patient Portal > Family
 */
class FamilyController extends PortalController
{
    public function __construct(
        private readonly PatientFamily $family,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(PortalFamilyMemberResource::collection($this->family->members($this->patient($request))));
    }

    /**
     * Everything the clinic holds about one of them that a patient may read,
     * in one request — the same things their own screens show about themselves.
     *
     * A record the clinic does not run comes back null rather than empty, so
     * the app can leave its tab out instead of saying there is nothing yet.
     */
    public function history(
        Request $request,
        int $member,
        PatientAppointments $appointments,
        PatientRecords $records,
        PatientVitals $vitals,
        Permission $permission,
    ): JsonResponse {
        try {
            $link = $this->family->find($this->patient($request), $member);
        } catch (ModelNotFoundException) {
            return $this->fail('That family member was not found.', 404);
        }

        $person = $link->member;
        $organization = $this->organization($request);

        return $this->ok([
            'member' => PortalFamilyMemberResource::make($link),
            'summary' => $appointments->seen($person),
            'upcoming' => PortalAppointmentResource::collection($appointments->upcomingOf($person)),
            'past' => PortalAppointmentResource::collection($appointments->pastOf($person)),
            'prescriptions' => $permission->hasModule($organization, 'prescriptions')
                ? PortalPrescriptionResource::collection($records->prescriptionsOf($person))
                : null,
            'lab_reports' => $permission->hasModule($organization, 'laboratory')
                ? PortalLabReportResource::collection($records->labReportsOf($person))
                : null,
            'vitals' => $vitals->readings($person),
        ]);
    }

    public function store(SaveFamilyMemberRequest $request): JsonResponse
    {
        try {
            $link = $this->family->add($this->patient($request), $request->validated());
        } catch (RuntimeException $exception) {
            // Already in the family, or the family is full.
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->created(PortalFamilyMemberResource::make($link), 'Family member added');
    }

    public function update(SaveFamilyMemberRequest $request, int $member): JsonResponse
    {
        try {
            $link = $this->family->find($this->patient($request), $member);
        } catch (ModelNotFoundException) {
            return $this->fail('That family member was not found.', 404);
        }

        return $this->ok(
            PortalFamilyMemberResource::make($this->family->update($link, $request->validated())),
            'Family member updated',
        );
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        try {
            $this->family->remove($this->family->find($this->patient($request), $member));
        } catch (ModelNotFoundException) {
            // Caught first: it is itself a RuntimeException, and somebody
            // else's relative must read as missing, not as refused.
            return $this->fail('That family member was not found.', 404);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(null, 'Removed from your family');
    }
}

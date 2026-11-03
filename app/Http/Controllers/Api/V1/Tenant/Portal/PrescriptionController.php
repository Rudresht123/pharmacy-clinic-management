<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalPrescriptionResource;
use App\Models\Tenant\Prescription;
use App\Services\Portal\PatientRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's own prescriptions. The list is theirs alone; one opened from
 * a family member's history comes through show() too.
 *
 * @group Patient Portal > Prescriptions
 */
class PrescriptionController extends PortalController
{
    public function index(Request $request, PatientRecords $records): JsonResponse
    {
        return $this->ok(PortalPrescriptionResource::collection($records->prescriptionsOf($this->patient($request))));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $prescription = Prescription::on('organization')
            ->whereIn('customer_id', $this->readableIds($request))
            ->where('status', '!=', Prescription::DRAFT)
            ->with(PatientRecords::PRESCRIPTION_RELATIONS)
            ->findOrFail($id);

        return $this->ok(PortalPrescriptionResource::make($prescription));
    }
}

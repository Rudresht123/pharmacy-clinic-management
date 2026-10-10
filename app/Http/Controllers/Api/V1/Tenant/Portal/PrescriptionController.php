<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalPrescriptionResource;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's own prescriptions.
 *
 * @group Patient Portal > Prescriptions
 */
class PrescriptionController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $patient = $this->patient($request);

        $prescriptions = Prescription::on('organization')
            ->where('customer_id', $patient->id ?? 0)
            // A draft is still the doctor's own working copy — not yet
            // signed off, and not this patient's to see.
            ->where('status', '!=', Prescription::DRAFT)
            ->with(['doctor', 'location', 'items', 'consultation'])
            ->latest('prescription_date')
            ->get();

        return $this->ok(PortalPrescriptionResource::collection($prescriptions));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $patient = $this->patient($request);

        $prescription = Prescription::on('organization')
            ->where('customer_id', $patient->id ?? 0)
            ->where('status', '!=', Prescription::DRAFT)
            ->with(['doctor', 'location', 'items', 'consultation'])
            ->findOrFail($id);

        return $this->ok(PortalPrescriptionResource::make($prescription));
    }
}

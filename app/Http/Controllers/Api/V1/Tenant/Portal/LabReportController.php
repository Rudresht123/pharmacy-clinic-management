<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalLabReportResource;
use App\Models\Tenant\LabOrder;
use App\Services\Portal\PatientRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's own lab orders and results. The list is theirs alone; one
 * opened from a family member's history comes through show() too.
 *
 * @group Patient Portal > Lab Reports
 */
class LabReportController extends PortalController
{
    public function index(Request $request, PatientRecords $records): JsonResponse
    {
        return $this->ok(PortalLabReportResource::collection($records->labReportsOf($this->patient($request))));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = LabOrder::on('organization')
            ->whereIn('customer_id', $this->readableIds($request))
            ->with(PatientRecords::LAB_RELATIONS)
            ->findOrFail($id);

        return $this->ok(PortalLabReportResource::make($order));
    }
}

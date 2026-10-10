<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalLabReportResource;
use App\Models\Tenant\LabOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's own lab orders and results.
 *
 * @group Patient Portal > Lab Reports
 */
class LabReportController extends PortalController
{
    private const WITH = ['doctor', 'location', 'items'];

    public function index(Request $request): JsonResponse
    {
        $patient = $this->patient($request);

        $orders = LabOrder::on('organization')
            ->where('customer_id', $patient->id ?? 0)
            ->with(self::WITH)
            ->latest('order_date')
            ->get();

        return $this->ok(PortalLabReportResource::collection($orders));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $patient = $this->patient($request);

        $order = LabOrder::on('organization')
            ->where('customer_id', $patient->id ?? 0)
            ->with(self::WITH)
            ->findOrFail($id);

        return $this->ok(PortalLabReportResource::make($order));
    }
}

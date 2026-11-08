<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveLabReferenceRangesRequest;
use App\Http\Resources\Tenant\LabReferenceRangeResource;
use App\Models\Tenant\LabTestParameter;
use App\Services\Laboratory\ReferenceRanges;
use Illuminate\Http\JsonResponse;

/**
 * What normal looks like for one parameter — replaced as a set.
 *
 * Read with the test master; written only under `laboratory.ranges_manage`,
 * because a range is a clinical decision for qualified staff, apart from
 * whoever maintains names and prices. Nothing here is ever pre-filled.
 *
 * @group Tenant > Laboratory
 */
class LabReferenceRangeController extends BaseApiController
{
    public function __construct(
        private readonly ReferenceRanges $ranges,
    ) {}

    public function index(LabTestParameter $parameter): JsonResponse
    {
        return $this->ok(LabReferenceRangeResource::collection($parameter->ranges()->get()));
    }

    public function update(SaveLabReferenceRangesRequest $request, LabTestParameter $parameter): JsonResponse
    {
        if ($parameter->test === null || $parameter->test->trashed()) {
            abort(404);
        }

        $saved = $this->ranges->replace($parameter, $request->validated()['ranges']);

        return $this->ok(
            LabReferenceRangeResource::collection($saved),
            "Reference ranges for {$parameter->name} saved",
        );
    }
}

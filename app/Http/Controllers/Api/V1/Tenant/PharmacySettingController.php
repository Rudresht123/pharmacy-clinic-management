<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\UpdatePharmacySettingRequest;
use App\Http\Resources\Tenant\PharmacySettingResource;
use App\Models\Tenant\PharmacySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The organisation's pharmacy settings — one record, read by every counter.
 *
 * Read with `pharmacy.view` because the POS needs them to price and warn;
 * changed with `pharmacy.stores`, the organisation-wide setup capability.
 */
class PharmacySettingController extends BaseApiController
{
    public function show(): JsonResponse
    {
        return $this->ok(PharmacySettingResource::make(PharmacySetting::current()->load('editor')));
    }

    public function update(UpdatePharmacySettingRequest $request): JsonResponse
    {
        $settings = PharmacySetting::current();

        $settings->fill($request->validated());
        $settings->updated_by = Auth::guard('web')->id();
        $settings->save();

        return $this->ok(
            PharmacySettingResource::make($settings->load('editor')),
            'Pharmacy settings saved',
        );
    }
}

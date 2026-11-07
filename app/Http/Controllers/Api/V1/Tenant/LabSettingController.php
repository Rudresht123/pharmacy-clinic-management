<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\UpdateLabSettingRequest;
use App\Http\Resources\Tenant\LabSettingResource;
use App\Models\Tenant\LabSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The organisation's laboratory settings — one record, read by every lab screen.
 *
 * Read with any tick that opens the Laboratory settings screen; changed with
 * `laboratory.settings_manage`, the organisation-wide setup tick. The allowed
 * values travel in `meta` so the screen never hard-codes them.
 *
 * @group Tenant > Laboratory
 */
class LabSettingController extends BaseApiController
{
    public function show(): JsonResponse
    {
        return $this->ok(
            LabSettingResource::make(LabSetting::current()->load('editor')),
            null,
            $this->choices(),
        );
    }

    public function update(UpdateLabSettingRequest $request): JsonResponse
    {
        $settings = LabSetting::current();

        $settings->fill($request->validated());
        $settings->updated_by = Auth::guard('web')->id();
        $settings->save();

        return $this->ok(
            LabSettingResource::make($settings->load('editor')),
            'Laboratory settings saved',
        );
    }

    /** @return array<string, mixed> */
    private function choices(): array
    {
        return [
            'approval_levels' => LabSetting::APPROVAL_LEVELS,
            'label_sizes' => LabSetting::LABEL_SIZES,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\AppointmentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How appointments run across the organization: who moves a patient through
 * the queue. Who is TOLD about anything — a booking, a reschedule request —
 * is the communication automation rules' (AutomationRuleController).
 *
 * Reading is open to anybody who sees appointments — the screens draw their
 * buttons from it. Changing it is `appointments.settings`.
 *
 * @group Tenant > Appointments & OPD
 */
class AppointmentSettingController extends BaseApiController
{
    public function show(): JsonResponse
    {
        return $this->ok($this->shape(AppointmentSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'check_in_by' => ['sometimes', Rule::in(AppointmentSetting::OPTIONS)],
            'call_by' => ['sometimes', Rule::in(AppointmentSetting::OPTIONS)],
            'consult_start_by' => ['sometimes', Rule::in(AppointmentSetting::OPTIONS)],
        ]);

        $settings = AppointmentSetting::current();
        $settings->fill($validated)->save();

        return $this->ok($this->shape($settings), 'Appointment settings saved');
    }

    /** @return array<string, string> */
    private function shape(AppointmentSetting $settings): array
    {
        return [
            'check_in_by' => $settings->check_in_by,
            'call_by' => $settings->call_by,
            'consult_start_by' => $settings->consult_start_by,
        ];
    }
}

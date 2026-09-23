<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalAppointmentResource;
use App\Services\Permissions\Permission;
use App\Services\Portal\PatientAppointments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's first screen, in one request.
 *
 * Open to every signed-in patient; what is IN it depends on what they may do.
 * A patient whose role cannot see appointments gets a home screen without
 * them — `null`, not a 403 — because the screen itself is theirs either way.
 */
class HomeController extends PortalController
{
    public function show(Request $request, PatientAppointments $appointments, Permission $permission): JsonResponse
    {
        $patient = $this->patient($request);

        $canSee = $permission->allows(
            $this->organization($request),
            $request->user(),
            'portal.appointments.view',
        );

        $upcoming = $canSee ? $appointments->upcoming($patient, 3) : collect();

        return $this->ok([
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'code' => $patient->code,
            ],
            'next_appointment' => $upcoming->isEmpty()
                ? null
                : PortalAppointmentResource::make($upcoming->first()),
            'upcoming' => PortalAppointmentResource::collection($upcoming),
            'upcoming_count' => $canSee ? $appointments->upcomingCount($patient) : 0,
        ]);
    }
}

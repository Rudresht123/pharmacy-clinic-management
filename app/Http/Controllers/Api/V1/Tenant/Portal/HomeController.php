<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalAppointmentResource;
use App\Http\Resources\Tenant\Portal\PortalLabReportSummaryResource;
use App\Http\Resources\Tenant\Portal\PortalPrescriptionSummaryResource;
use App\Services\Permissions\Permission;
use App\Services\Portal\PatientAppointments;
use App\Services\Portal\PatientNotifications;
use App\Services\Portal\PatientRecords;
use App\Services\Portal\PatientVitals;
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
    public function show(
        Request $request,
        PatientAppointments $appointments,
        PatientVitals $vitals,
        PatientRecords $records,
        PatientNotifications $notifications,
        Permission $permission,
    ): JsonResponse {
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

            /*
             * The rest of their clinical history, gated the same way: there is
             * no standalone "view my records" capability yet, and inventing one
             * here would grant it to every existing patient's already-created
             * role without the backfill that should come with it. The dedicated
             * prescriptions and lab reports screens are the right place to add
             * that properly; until then this rides on the capability a patient
             * portal is never provisioned without.
             */
            'vitals' => $canSee ? $vitals->latest($patient) : null,
            'recent_prescriptions' => $canSee
                ? PortalPrescriptionSummaryResource::collection($records->recentPrescriptions($patient))
                : [],
            'recent_lab_reports' => $canSee
                ? PortalLabReportSummaryResource::collection($records->recentLabReports($patient))
                : [],

            // Ungated: a notification is about them either way.
            'unread_notifications' => $notifications->unreadCount($patient),
        ]);
    }
}

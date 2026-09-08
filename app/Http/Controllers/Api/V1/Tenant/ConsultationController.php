<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveConsultationRequest;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Doctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing up a visit.
 *
 * Addressed by the appointment rather than by a consultation id: there is one
 * per visit, and the doctor writing it has an appointment in front of them,
 * not a record they first have to find.
 */
class ConsultationController extends BaseApiController
{
    /** What has been written for this visit, if anything. */
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        return $this->ok(
            $this->shape($appointment->consultation()->first(), $appointment)
        );
    }

    /**
     * Write it, or write over it.
     *
     * One row per visit, so this is an upsert rather than a create: a doctor
     * adding a diagnosis after the prescription is editing the same
     * consultation, not starting a second one.
     */
    public function save(SaveConsultationRequest $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        $consultation = Consultation::on('organization')->updateOrCreate(
            ['appointment_id' => $appointment->id],
            [
                ...$request->validated(),

                // Taken from the visit, never the payload — which patient was
                // seen by which doctor is not the client's to assert.
                'customer_id' => $appointment->customer_id,
                'doctor_id' => $appointment->doctor_id,
            ],
        );

        return $this->ok($this->shape($consultation, $appointment), 'Saved');
    }

    /**
     * A doctor writes up their own visits and no one else's.
     *
     * The capability on the route says somebody may work a queue; it does not
     * say whose. Without this, any doctor could write a diagnosis into another
     * doctor's consultation — under a permission every doctor login holds.
     */
    private function assertTheirs(Request $request, Appointment $appointment): void
    {
        $user = $request->user();

        if ($user?->userable_type !== Doctor::class) {
            abort(403, 'Only the doctor who saw the patient can write up the visit.');
        }

        if ((int) $user->userable_id !== (int) $appointment->doctor_id) {
            abort(403, 'That visit belongs to another doctor.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(?Consultation $consultation, Appointment $appointment): array
    {
        return [
            'appointment_id' => $appointment->id,
            'customer_id' => $appointment->customer_id,

            // Null until somebody writes something, so the screen can tell
            // "nothing recorded" from "recorded as empty".
            'id' => $consultation?->id,

            'chief_complaint' => $consultation?->chief_complaint,
            'diagnoses' => $consultation?->diagnoses ?? [],
            'vitals' => $consultation?->vitals ?? [],
            'prescription' => $consultation?->prescription ?? [],
            'investigations' => $consultation?->investigations ?? [],
            'advice' => $consultation?->advice,
            'notes' => $consultation?->notes,
            'follow_up_days' => $consultation?->follow_up_days,

            'updated_at' => $consultation?->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveConsultationRequest;
use App\Http\Resources\Tenant\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Doctor;
use App\Services\Opd\VisitWorkflow;
use App\Services\Opd\WorkflowConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The consultation: starting it, writing it, and finishing it.
 *
 * THE DOCTOR'S CONTROLLER. Every route here is behind one of the two
 * consultation capabilities AND the check that the visit belongs to whoever
 * is signed in — a capability says somebody may consult, it cannot say whose
 * patient this is.
 *
 * Addressed by the appointment rather than by a consultation id: there is one
 * per visit, and the doctor writing it has an appointment in front of them,
 * not a record they first have to find.
 *
 * Starting and completing used to be AppointmentController's `start` and
 * `complete`, behind `appointments.queue` — the receptionist's key. That is
 * the arrangement this file exists to replace.
 */
class ConsultationController extends BaseApiController
{
    public function __construct(
        private readonly VisitWorkflow $workflow,
    ) {}

    /** What has been written for this visit, if anything. */
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        return $this->ok(
            $this->shape($appointment->consultation()->first(), $appointment)
        );
    }

    /**
     * Take the patient in.
     *
     * Refused unless reception has called them, refused if it is already
     * under way, and refused if it has already been completed — each with a
     * sentence saying which, because "cannot start" tells a doctor with a
     * patient in front of them nothing.
     */
    public function start(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        return $this->transition(
            fn () => $this->workflow->startConsultation($appointment, $request->user()),
            'Consultation started',
        );
    }

    /**
     * Finished — and the visit is not, unless nothing came of it.
     *
     * The response carries the recomputed visit, so the screen knows at once
     * whether to send the patient to the pharmacy, the lab, the till, or
     * home. Nothing about that is decided here.
     */
    public function complete(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        return $this->transition(
            fn () => $this->workflow->completeConsultation($appointment, $request->user()),
            'Consultation completed',
        );
    }

    /**
     * Put a finished visit back in the room — today's only.
     *
     * Behind `appointments.consult_complete`, so whoever may close a
     * consultation may reopen one and nobody else can. It used to sit with
     * the desk's queue capability, which meant a receptionist could reopen a
     * clinical record.
     */
    public function reopen(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertTheirs($request, $appointment);

        return $this->transition(
            fn () => $this->workflow->reopenConsultation($appointment, $request->user()),
            'Back in the room',
        );
    }

    /**
     * Run a workflow move and answer with the visit it produced.
     *
     * A refusal is 422 with the service's own sentence: a doctor pressing
     * Start on somebody reception has not called yet is a normal thing to
     * happen on a busy morning, not a fault.
     */
    private function transition(callable $move, string $message): JsonResponse
    {
        try {
            $appointment = $move();
        } catch (WorkflowConflict $conflict) {
            return $this->fail($conflict->getMessage(), 422);
        }

        return $this->ok(
            AppointmentResource::make($appointment->load(['customer', 'doctor', 'location'])),
            $message,
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
            // From the structured prescription, in the shape this screen has always read.
            'prescription' => $consultation?->prescriptionLines() ?? [],
            'investigations' => $consultation?->investigations ?? [],
            'advice' => $consultation?->advice,
            'notes' => $consultation?->notes,
            'follow_up_days' => $consultation?->follow_up_days,

            'updated_at' => $consultation?->updated_at?->toIso8601String(),
        ];
    }
}

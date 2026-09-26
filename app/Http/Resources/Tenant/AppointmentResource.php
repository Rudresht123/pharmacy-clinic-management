<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_code' => $this->whenLoaded('customer', fn () => $this->customer?->code),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),

            /*
             * Age is worked out on the screen rather than sent, because it is
             * a fact about today and a cached response would be wrong on
             * somebody's birthday. The date of birth is the thing that does
             * not change.
             */
            'customer_dob' => $this->whenLoaded(
                'customer',
                fn () => $this->customer?->date_of_birth?->toDateString(),
            ),

            'customer_gender' => $this->whenLoaded('customer', fn () => $this->customer?->gender),

            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            /*
             * What the doctor does, standing in for a department.
             *
             * There is no departments table, and a speciality answers the same
             * question without a second place for the truth to live. It moves
             * to a real column the day departments exist.
             */
            'doctor_specialisation' => $this->whenLoaded(
                'doctor',
                fn () => $this->doctor?->specialisation,
            ),

            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),

            // Provenance: which sitting produced this. Null once that sitting
            // is deleted, and for an extra session that never had one.
            'doctor_schedule_id' => $this->doctor_schedule_id,

            'appointment_date' => $this->appointment_date?->toDateString(),
            'type' => $this->type,

            /*
             * Three columns, three questions, and the screens read whichever
             * one their job is about.
             *
             * `status` is the VISIT — where the whole episode has got to,
             * including the three waiting rooms downstream of the doctor.
             * `queue_status` is the reception desk. `consultation_status` is
             * the doctor. A screen that mixed them is what produced a Done
             * button on the receptionist's queue.
             */
            'status' => $this->status,
            'queue_status' => $this->queue_status,
            'consultation_status' => $this->consultation_status,

            // Where to send the patient, once the doctor has finished.
            'next_action' => $this->next_action,

            // Postgres returns "10:30:00"; the screen shows "10:30".
            'slot_at' => $this->slot_at ? substr((string) $this->slot_at, 0, 5) : null,
            'token_no' => $this->token_no,

            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'called_at' => $this->called_at?->toIso8601String(),

            // `started_at` and `completed_at` are the CONSULTATION's clocks
            // and always have been; the visit's own closing time is separate.
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'visit_completed_at' => $this->visit_completed_at?->toIso8601String(),

            /*
             * Who did each thing.
             *
             * Sent as names rather than ids because the only consumer is a
             * sentence on a screen — "Completed 4:35 PM · Dr Amit" — and a
             * client that had to resolve three user ids to render a queue row
             * would make three more requests per row.
             *
             * `whenLoaded` throughout: a forty-row queue does not load them,
             * and the one-visit reads that do are the screens that show them.
             */
            'called_by_name' => $this->whenLoaded('caller', fn () => $this->caller?->name),
            'consultation_started_by_name' => $this->whenLoaded(
                'consultationStarter',
                fn () => $this->consultationStarter?->name,
            ),
            'consultation_completed_by_name' => $this->whenLoaded(
                'consultationCompleter',
                fn () => $this->consultationCompleter?->name,
            ),

            /*
             * How long they have been waiting, in minutes — the number the
             * desk actually looks at. Computed here rather than in the
             * browser so every screen agrees on when the clock started.
             */
            'waiting_minutes' => $this->checked_in_at && ! $this->started_at
                /*
                 * Cast, because Carbon 3 returns a float here where Carbon 2
                 * returned an int — so this reached the queue as
                 * "100.86940301666667m" and ran straight through the column
                 * beside it. Nothing about a wait is worth a fifteenth decimal
                 * place.
                 */
                ? (int) $this->checked_in_at->diffInMinutes(now())
                : null,

            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,

            // What the VISIT may become next. Kept for the screens that read
            // it, but no longer the thing buttons are built from — a visit
            // state cannot say who is allowed to cause the change.
            'next_states' => Appointment::TRANSITIONS[$this->status] ?? [],

            /*
             * Which workflow verbs this row's STATE allows, one per button.
             *
             * The other half of button visibility, and deliberately only half:
             * this says what is possible, the client's own capability check
             * says who may. Both have to be true to render, which is what
             * stops a receptionist being shown Start Consultation and stops a
             * doctor being shown it on somebody who has not been called.
             *
             * Sent rather than derived in the browser because the state
             * machine is the server's and there must be exactly one copy of
             * it. A screen that worked out for itself when Complete applies
             * is a second implementation that will drift from this one.
             */
            'available' => [
                'check_in' => $this->canMoveTo(Appointment::STATUS_CHECKED_IN),
                'call' => $this->queueCanMoveTo(Appointment::QUEUE_CALLED),

                // Called, and not already seen. The doctor's entry point.
                'consult_start' => $this->isCalled()
                    && $this->consultation_status === Appointment::CONSULT_NOT_STARTED,

                'consult_complete' => $this->consultation_status === Appointment::CONSULT_IN_PROGRESS,

                // Today's only — reopening an older visit would move it into
                // a day it did not happen in.
                'consult_reopen' => $this->consultationCompleted()
                    && (bool) $this->appointment_date?->isToday(),

                'cancel' => $this->canMoveTo(Appointment::STATUS_CANCELLED),
                'no_show' => $this->canMoveTo(Appointment::STATUS_NO_SHOW),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

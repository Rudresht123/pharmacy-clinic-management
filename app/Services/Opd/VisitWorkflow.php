<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who may move a visit where, and what the visit becomes as a result.
 *
 * THE ONE PLACE. Every transition in the clinic day goes through a method
 * here, every one of them inside a transaction with the row locked, and
 * nothing else writes `queue_status`, `consultation_status`, `next_action` or
 * `visit_completed_at`. Scattering these across controllers is how two
 * receptionists come to call the same patient and how a visit ends up
 * completed with medicines still on the shelf.
 *
 * THE THREE AXES, AND WHO OWNS EACH
 *
 *   queue_status         the RECEPTION DESK. waiting → called. And that is
 *                        the whole of their authority over a consultation.
 *   consultation_status  the DOCTOR. not_started → in_progress → completed,
 *                        and the queue moves to `with_doctor` as a
 *                        consequence of the first of those, never on its own.
 *   status               the SYSTEM. Nobody clicks it. It is recomputed from
 *                        the prescription, the lab and the till every time
 *                        one of them changes, and it is the only thing that
 *                        decides a visit is over.
 *
 * WHY `settle()` IS CALLED FROM DOWNSTREAM CODE. A dispensing, a finished lab
 * order and a payment all have to ask "does that close the visit". They call
 * refresh(); they do not work it out themselves. If they did, three modules
 * would each hold a copy of the completion rule and the first one to change
 * would be the one nobody remembered.
 *
 * WHAT IS DELIBERATELY NOT HERE. Booking, check-in, cancellation and no-show
 * stay in BookingService: they are about the appointment as an intent — the
 * diary, the slot, the token — rather than about the visit's progress through
 * the department. Check-in is the seam, and it sets `queue_status` to
 * `waiting` there because that is the moment somebody joins the queue.
 */
class VisitWorkflow
{
    /**
     * Where a patient goes next, in the order the department asks.
     *
     * Pharmacy before lab before billing. A visit can be waiting on more than
     * one of them at once and `status` holds only one, so the order matters:
     * it is what the screen tells the patient to do FIRST. Medicines are
     * usually handed over in minutes and bloods are not, so sending somebody
     * to the counter while the lab works is the order that empties the
     * waiting room fastest.
     *
     * Everything still outstanding is reported separately, in `pending()`, so
     * no screen has to pretend the single value is the whole picture.
     */
    private const ORDER = [
        // what is outstanding => [the visit state it produces, what to tell the patient]
        'pharmacy' => [Appointment::STATUS_AWAITING_PHARMACY, Appointment::NEXT_PHARMACY],
        'laboratory' => [Appointment::STATUS_AWAITING_LAB, Appointment::NEXT_LABORATORY],
        'billing' => [Appointment::STATUS_AWAITING_PAYMENT, Appointment::NEXT_BILLING],
    ];

    /* ===================================================================
     | THE RECEPTION DESK
     |===================================================================*/

    /**
     * Call the patient through.
     *
     * The desk's one act on a consultation, and the reason this redesign
     * happened: before it, "Call in" moved the visit to `in_consultation`,
     * so a receptionist was starting the doctor's consultation for them.
     *
     * TWO RECEPTIONISTS, ONE TOKEN. The row is locked before its state is
     * read, so the second click waits for the first to commit and then finds
     * the patient already called. It gets a refusal naming who called them
     * and when, which is what somebody standing at the other counter needs
     * to hear — not a silent success that calls the same person twice.
     */
    public function call(Appointment $appointment, ?User $actor = null): Appointment
    {
        return $this->transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);

            if ($locked->isCalled()) {
                throw WorkflowConflict::because($this->alreadyCalled($locked));
            }

            if (! $locked->queueCanMoveTo(Appointment::QUEUE_CALLED)) {
                throw WorkflowConflict::because(match (true) {
                    $locked->isWithDoctor() => 'That patient is already with the doctor.',
                    $locked->queue_status === null && $locked->consultationCompleted() => 'That consultation is finished, so there is nobody to call.',
                    $locked->queue_status === null => 'That patient has not checked in yet. Check them in first.',
                    default => 'That patient cannot be called from here.',
                });
            }

            $locked->forceFill([
                'queue_status' => Appointment::QUEUE_CALLED,
                'called_at' => now(),
                'called_by' => $this->actorId($actor),
            ])->save();

            return $locked;
        });
    }

    /* ===================================================================
     | THE DOCTOR
     |===================================================================*/

    /**
     * Take the patient in and start writing up.
     *
     * Two columns move together and neither makes sense alone: the queue says
     * the patient is in the room, the consultation says the record is open.
     * Doing them in one transaction is what stops a crash leaving a patient
     * in a room with no consultation to write into.
     *
     * A CONSULTATION ROW IS CREATED HERE. It used to appear the first time
     * the doctor pressed Save, which meant `consultations.created_at` was
     * when somebody finished typing rather than when the visit began, and a
     * doctor who saw a patient and wrote nothing left no record that they had.
     *
     * THE SAME DOCTOR CANNOT START TWICE. Not because a flag is checked after
     * the fact, but because the second call reads `in_progress` under the
     * lock the first one held.
     */
    public function startConsultation(Appointment $appointment, ?User $actor = null): Appointment
    {
        return $this->transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);

            if ($locked->consultation_status === Appointment::CONSULT_IN_PROGRESS) {
                throw WorkflowConflict::because(
                    'This consultation is already under way. Open it rather than starting it again.'
                );
            }

            if ($locked->consultationCompleted()) {
                throw WorkflowConflict::because(
                    'This consultation has already been completed. Reopen it if it needs changing.'
                );
            }

            /*
             * Called first, always.
             *
             * A doctor who can start on somebody the desk has not called has
             * taken a patient out of turn, and the queue on the screen at
             * reception stops describing the room.
             */
            if (! $locked->isCalled()) {
                throw WorkflowConflict::because(match ($locked->queue_status) {
                    Appointment::QUEUE_WAITING => 'This patient has not been called yet. Reception calls them through first.',
                    Appointment::QUEUE_WITH_DOCTOR => 'This patient is already in a consultation.',
                    default => 'This patient has not checked in yet.',
                });
            }

            $this->assertVisitCanMoveTo($locked, Appointment::STATUS_IN_CONSULTATION);

            Consultation::on('organization')->firstOrCreate(
                ['appointment_id' => $locked->id],
                ['customer_id' => $locked->customer_id, 'doctor_id' => $locked->doctor_id],
            );

            $locked->forceFill([
                'status' => Appointment::STATUS_IN_CONSULTATION,
                'queue_status' => Appointment::QUEUE_WITH_DOCTOR,
                'consultation_status' => Appointment::CONSULT_IN_PROGRESS,
                'started_at' => now(),
                'consultation_started_by' => $this->actorId($actor),
            ])->save();

            return $locked;
        });
    }

    /**
     * The doctor is finished.
     *
     * AND THE VISIT IS NOT, unless nothing came of it. This is the whole
     * point of the change: completing a consultation records that the doctor
     * is done and then asks what they produced. A prescription sends the
     * patient to the counter; a lab order sends them to the lab; an unpaid
     * bill sends them to the till; nothing at all closes the visit.
     *
     * The patient leaves the queue here — `queue_status` goes back to null —
     * because they are no longer waiting for a room. They are still on the
     * day's list, under whichever `awaiting_*` the system has just decided.
     */
    public function completeConsultation(Appointment $appointment, ?User $actor = null): Appointment
    {
        return $this->transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);

            if ($locked->consultationCompleted()) {
                throw WorkflowConflict::because(
                    'This consultation was already completed'
                    .($locked->completed_at ? ' at '.$locked->completed_at->format('g:i A') : '').'.'
                );
            }

            if ($locked->consultation_status !== Appointment::CONSULT_IN_PROGRESS) {
                throw WorkflowConflict::because(
                    'This consultation has not been started, so there is nothing to complete.'
                );
            }

            $this->assertWorthRecording($locked);

            $locked->forceFill([
                'queue_status' => null,
                'consultation_status' => Appointment::CONSULT_COMPLETED,
                'completed_at' => now(),
                'consultation_completed_by' => $this->actorId($actor),
            ])->save();

            // What the visit becomes is decided from what the doctor made,
            // never from what anybody chose.
            $this->settle($locked);

            return $locked;
        });
    }

    /**
     * Put a finished visit back in the room — today's only.
     *
     * THE DOCTOR'S, not the desk's. It was behind the same capability as
     * working the queue, which meant a receptionist could reopen a clinical
     * record; it now sits with whoever may complete one in the first place.
     *
     * Only today's. Reopening an older visit would move it into a day it did
     * not happen in, put it back on a queue nobody is working, and let
     * somebody rewrite a record the day has closed on.
     */
    public function reopenConsultation(Appointment $appointment, ?User $actor = null): Appointment
    {
        return $this->transaction(function () use ($appointment, $actor) {
            $locked = $this->lock($appointment);

            if (! $locked->consultationCompleted()) {
                throw WorkflowConflict::because('This consultation is not finished, so there is nothing to reopen.');
            }

            if (! $locked->appointment_date?->isToday()) {
                throw WorkflowConflict::because('Only a visit from today can be reopened.');
            }

            $this->assertVisitCanMoveTo($locked, Appointment::STATUS_IN_CONSULTATION);

            $locked->forceFill([
                'status' => Appointment::STATUS_IN_CONSULTATION,
                'queue_status' => Appointment::QUEUE_WITH_DOCTOR,
                'consultation_status' => Appointment::CONSULT_IN_PROGRESS,

                /*
                 * The clock restarts because the only thing reading it is the
                 * "in the room for N minutes" timer, and one counting from
                 * this morning helps nobody. When the visit was FIRST written
                 * up survives on the consultation row.
                 */
                'started_at' => now(),
                'consultation_started_by' => $this->actorId($actor),

                'completed_at' => null,
                'consultation_completed_by' => null,
                'visit_completed_at' => null,
                'next_action' => null,
            ])->save();

            return $locked;
        });
    }

    /* ===================================================================
     | THE SYSTEM
     |===================================================================*/

    /**
     * Ask again whether this visit is finished.
     *
     * Called by the pharmacy after a dispensing, by the lab after a result,
     * and by the till after a payment. They report what they did; this
     * decides what it means.
     *
     * Safe to call at any time and from anywhere: it is idempotent, it takes
     * its own lock, and it does nothing at all to a visit whose consultation
     * has not finished.
     */
    public function refresh(Appointment $appointment): Appointment
    {
        return $this->transaction(function () use ($appointment) {
            $locked = $this->lock($appointment);

            $this->settle($locked);

            return $locked;
        });
    }

    /**
     * What is still outstanding on this visit, as three plain answers.
     *
     * Reported alongside the single `status`, because a visit can be waiting
     * on the pharmacy AND the lab and the column holds one of them. A screen
     * that showed only the column would tell a patient to collect their
     * medicines and say nothing about the bloods.
     *
     * @return array{pharmacy: bool, laboratory: bool, billing: bool}
     */
    public function pending(Appointment $appointment): array
    {
        return [
            /*
             * A live prescription with anything left to hand over. `expired`
             * and `dispensed` are finished; `cancelled` is excluded by the
             * scope, because a withdrawn prescription is not work anybody owes.
             */
            'pharmacy' => Prescription::on('organization')
                ->where('appointment_id', $appointment->id)
                ->whereIn('status', [
                    Prescription::DRAFT,
                    Prescription::ISSUED,
                    Prescription::PARTIALLY_DISPENSED,
                ])
                ->exists(),

            'laboratory' => LabOrder::on('organization')
                ->where('appointment_id', $appointment->id)
                ->outstanding()
                ->exists(),

            /*
             * Money owed on a bill that stands.
             *
             * A cancelled bill owes nothing — the stock went back and the
             * charge with it — so only `completed` sales are asked. A visit
             * with no bill at all is not awaiting payment: what it is
             * awaiting is the pharmacy, which is a different queue and a
             * different screen.
             */
            'billing' => PharmacySale::on('organization')
                ->where('appointment_id', $appointment->id)
                ->where('status', PharmacySale::COMPLETED)
                ->whereIn('payment_status', [PharmacySale::UNPAID, PharmacySale::PARTIAL])
                ->exists(),
        ];
    }

    /**
     * Work out what the visit is now, and write it.
     *
     * Expects the row to be locked and inside a transaction — every caller
     * above satisfies that, and it is private so nothing else can fail to.
     */
    private function settle(Appointment $locked): void
    {
        /*
         * A cancelled visit and a no-show are already over, whatever is
         * outstanding. Nothing downstream should be able to drag one of them
         * back onto the day's list.
         */
        if (in_array($locked->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            return;
        }

        // Nothing to decide until the doctor has finished. A prescription
        // drafted mid-consultation must not push the visit to the pharmacy.
        if (! $locked->consultationCompleted()) {
            return;
        }

        $pending = $this->pending($locked);

        // The first thing still outstanding, in the order the department
        // asks. Null when nothing is.
        $blocking = null;

        foreach (self::ORDER as $what => $outcome) {
            if ($pending[$what]) {
                $blocking = $outcome;

                break;
            }
        }

        [$status, $action] = $blocking ?? [
            Appointment::STATUS_COMPLETED,
            $this->closingAction($locked),
        ];

        $finished = $status === Appointment::STATUS_COMPLETED;

        $locked->forceFill([
            'status' => $status,
            'next_action' => $action,

            /*
             * Stamped once and kept. A visit that closes, is reopened and
             * closes again is closed at the later time; a visit recomputed
             * twice in a row keeps the first, because nothing happened in
             * between. Reopening is the only thing that clears it.
             */
            'visit_completed_at' => $finished ? ($locked->visit_completed_at ?? now()) : null,
        ])->save();
    }

    /**
     * What a finished visit leaves the patient with.
     *
     * A follow-up does not hold the visit open — there is nothing to do
     * today — but it is the thing to tell them on the way out, so it is the
     * next action of a completed visit rather than a state of an unfinished
     * one.
     */
    private function closingAction(Appointment $locked): string
    {
        $followUp = Consultation::on('organization')
            ->where('appointment_id', $locked->id)
            ->value('follow_up_days');

        return $followUp ? Appointment::NEXT_FOLLOW_UP : Appointment::NEXT_NONE;
    }

    /**
     * A consultation has to say something before it can be called finished.
     *
     * The write-up's fields are all optional — a diagnosis is the doctor's
     * words and the software has no business insisting on any particular one
     * — but a visit closed with an entirely blank record is a patient who was
     * seen and about whom nothing is known. Anything at all satisfies this:
     * a complaint, a diagnosis, vitals, advice, a note, a prescription or a
     * lab order.
     */
    private function assertWorthRecording(Appointment $locked): void
    {
        $consultation = Consultation::on('organization')
            ->where('appointment_id', $locked->id)
            ->first();

        $written = $consultation && (
            filled($consultation->chief_complaint)
            || filled($consultation->diagnoses)
            || filled($consultation->vitals)
            || filled($consultation->investigations)
            || filled($consultation->advice)
            || filled($consultation->notes)
            || $consultation->follow_up_days !== null
        );

        if ($written) {
            return;
        }

        $produced = Prescription::on('organization')->where('appointment_id', $locked->id)->exists()
            || LabOrder::on('organization')->where('appointment_id', $locked->id)->exists();

        if ($produced) {
            return;
        }

        throw WorkflowConflict::because(
            'Write something up before finishing — a complaint, a diagnosis, vitals or a note is enough.'
        );
    }

    /* ---------------------------------------------------------- plumbing */

    private function assertVisitCanMoveTo(Appointment $locked, string $status): void
    {
        if (! $locked->canMoveTo($status)) {
            throw WorkflowConflict::because(
                "A visit that is {$locked->status} cannot become {$status}."
            );
        }
    }

    /** "Token 12 was called at 10:32 by Priya." */
    private function alreadyCalled(Appointment $locked): string
    {
        $who = $locked->caller?->name;
        $when = $locked->called_at?->format('g:i A');

        return trim(sprintf(
            '%s has already been called%s%s.',
            $locked->token_no ? "Token {$locked->token_no}" : 'That patient',
            $when ? " at {$when}" : '',
            $who ? " by {$who}" : '',
        ));
    }

    private function actorId(?User $actor): ?int
    {
        $actor ??= Auth::guard('web')->user();

        return $actor instanceof User ? $actor->id : null;
    }

    /** The row, held against everybody else until this transaction commits. */
    private function lock(Appointment $appointment): Appointment
    {
        return Appointment::on('organization')
            ->whereKey($appointment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return (new Appointment)->getConnection()->transaction($callback);
    }
}

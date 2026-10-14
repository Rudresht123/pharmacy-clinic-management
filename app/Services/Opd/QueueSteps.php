<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\AppointmentSetting;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;

/**
 * Who may move a patient through the queue at this clinic.
 *
 * Three steps — check in, call through, start the consultation — and for each
 * the organization chooses the desk, the doctor, or either
 * (AppointmentSetting). A clinic with a receptionist leaves the first two at
 * the desk; a doctor working alone does all three.
 *
 * ON TOP OF THE ROLE, NOT INSTEAD OF IT. The route's capability still says
 * whether somebody works the queue at all; this says whether, at this clinic,
 * this step is theirs. A doctor taking a step only ever takes it for their
 * own patients.
 *
 * "Doctor" is a login linked to a doctor's record; everybody else who works
 * the queue — receptionist, branch manager, nurse — is the desk.
 */
class QueueSteps
{
    public const CHECK_IN = 'check_in';

    public const CALL = 'call';

    public const CONSULT_START = 'consult_start';

    /** `doctor` or `desk`. */
    public function sideOf(User $user): string
    {
        return $user->userable_type === Doctor::class ? AppointmentSetting::DOCTOR : AppointmentSetting::DESK;
    }

    /**
     * Whether, at this clinic, [$step] is [$user]'s side's to take.
     *
     * The settings are read fresh every time rather than kept: the router
     * keeps a controller — and so this — alive between requests in a
     * long-running process, and a kept copy would go on answering with what
     * the clinic had set before somebody changed it.
     */
    public function permits(User $user, string $step, ?AppointmentSetting $settings = null): bool
    {
        $who = ($settings ?? AppointmentSetting::current())->whoFor($step);

        return $who === AppointmentSetting::BOTH || $who === $this->sideOf($user);
    }

    /**
     * Refuse, with the clinic's own arrangement in the sentence, unless
     * [$user] may take [$step] for [$visit].
     */
    public function authorize(User $user, string $step, Appointment $visit): void
    {
        $settings = AppointmentSetting::current();

        if (! $this->permits($user, $step, $settings)) {
            $who = $settings->whoFor($step) === AppointmentSetting::DOCTOR ? 'the doctor' : 'the front desk';

            abort(403, match ($step) {
                self::CHECK_IN => "At this clinic, patients are checked in by {$who}.",
                self::CALL => "At this clinic, patients are called in by {$who}.",
                default => "At this clinic, consultations are started by {$who}.",
            });
        }

        if ($this->sideOf($user) === AppointmentSetting::DOCTOR && (int) $user->userable_id !== (int) $visit->doctor_id) {
            abort(403, 'That visit belongs to another doctor.');
        }
    }

    /**
     * The three answers for one person — what the session hands the apps, so
     * a button is only drawn where the server will honour it.
     *
     * @return array<string, bool>
     */
    public function forUser(User $user): array
    {
        $settings = AppointmentSetting::current();

        return [
            self::CHECK_IN => $this->permits($user, self::CHECK_IN, $settings),
            self::CALL => $this->permits($user, self::CALL, $settings),
            self::CONSULT_START => $this->permits($user, self::CONSULT_START, $settings),
        ];
    }
}

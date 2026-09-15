<?php

namespace App\Policies;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Policies\Concerns\AsksAboutBranch;
use Illuminate\Auth\Access\Response;

/**
 * May this person read, write or cancel this prescription?
 *
 * Reading and cancelling are capabilities at the prescription's branch.
 * Writing is narrower: only the doctor who saw the patient, because the
 * capability every doctor login holds says somebody may prescribe, not for
 * whom. Whether the prescription is still a draft is PrescriptionService's
 * question (409), not this one's.
 */
class PrescriptionPolicy
{
    use AsksAboutBranch;

    public function view(User $user, Prescription $prescription): bool
    {
        return $this->mayAt($user, $prescription->location_id, 'prescriptions.view');
    }

    /** A visit's prescription, whether or not one has been started yet. */
    public function viewVisit(User $user, Appointment $appointment): bool
    {
        return $this->mayAt($user, $appointment->location_id, 'prescriptions.view');
    }

    public function create(User $user, Appointment $appointment): Response
    {
        return $this->writer($user, $appointment->doctor_id, $appointment->location_id);
    }

    public function update(User $user, Prescription $prescription): Response
    {
        return $this->writer($user, $prescription->doctor_id, $prescription->location_id);
    }

    public function issue(User $user, Prescription $prescription): Response
    {
        return $this->writer($user, $prescription->doctor_id, $prescription->location_id);
    }

    /** Removing a draft. */
    public function delete(User $user, Prescription $prescription): Response
    {
        return $this->writer($user, $prescription->doctor_id, $prescription->location_id);
    }

    public function cancel(User $user, Prescription $prescription): bool
    {
        return $this->mayAt($user, $prescription->location_id, 'prescriptions.cancel');
    }

    private function writer(User $user, int $doctorId, int $locationId): Response
    {
        if ($user->userable_type !== Doctor::class) {
            return Response::deny('Only the doctor who saw the patient can write their prescription.');
        }

        if ((int) $user->userable_id !== $doctorId) {
            return Response::deny('That visit belongs to another doctor.');
        }

        return $this->mayAt($user, $locationId, 'prescriptions.write')
            ? Response::allow()
            : Response::deny('Writing prescriptions is not open to you at this branch.');
    }
}

<?php

namespace App\Policies;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\User;
use App\Policies\Concerns\AsksAboutBranch;
use Illuminate\Auth\Access\Response;

/**
 * May this person read, order, work or sign off this lab order?
 *
 * The same shape as PrescriptionPolicy, because the two records are the same
 * shape: reading and bench work are capabilities at the order's branch, and
 * ORDERING is narrower — only the doctor who saw the patient, because the
 * capability every doctor login holds says somebody may order tests, not for
 * whom.
 *
 * Whether the ORDER is in a state that allows the change is LabOrders'
 * question (409), not this one's. This answers only "may you".
 */
class LabOrderPolicy
{
    use AsksAboutBranch;

    public function view(User $user, LabOrder $order): bool
    {
        return $this->mayAt($user, $order->location_id, 'laboratory.view');
    }

    /** A visit's lab orders, whether or not any have been raised yet. */
    public function viewVisit(User $user, Appointment $appointment): bool
    {
        return $this->mayAt($user, $appointment->location_id, 'laboratory.view');
    }

    public function create(User $user, Appointment $appointment): Response
    {
        return $this->orderer($user, $appointment->doctor_id, $appointment->location_id);
    }

    public function update(User $user, LabOrder $order): Response
    {
        return $this->orderer($user, $order->doctor_id, $order->location_id);
    }

    /**
     * Take the order on, and enter results against it.
     *
     * The bench's, not the ordering doctor's — and deliberately no
     * doctor-identity check, because whoever runs the sample is not the
     * person who asked for it. That asymmetry is the point of having two
     * capabilities.
     */
    public function process(User $user, LabOrder $order): bool
    {
        return $this->mayAt($user, $order->location_id, 'laboratory.process');
    }

    /**
     * Sign the order off, or withdraw it.
     *
     * Held apart from `process` so a lab can put signing off with somebody
     * senior to whoever ran the sample: a completed order is what the doctor
     * will act on, and it is what lets the visit close.
     */
    public function complete(User $user, LabOrder $order): bool
    {
        return $this->mayAt($user, $order->location_id, 'laboratory.complete');
    }

    private function orderer(User $user, int $doctorId, int $locationId): Response
    {
        if ($user->userable_type !== Doctor::class) {
            return Response::deny('Only the doctor who saw the patient can order tests for them.');
        }

        if ((int) $user->userable_id !== $doctorId) {
            return Response::deny('That visit belongs to another doctor.');
        }

        return $this->mayAt($user, $locationId, 'laboratory.order')
            ? Response::allow()
            : Response::deny('Ordering tests is not open to you at this branch.');
    }
}

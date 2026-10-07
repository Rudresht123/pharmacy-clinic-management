<?php

namespace App\Services\Realtime;

use App\Events\Realtime\ConsultationCompleted;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Services\Clinic\ClinicEvent;
use App\Services\Permissions\Permission;
use App\Services\Pharmacy\OpdHandoff;
use App\Support\Clinic\ClinicEvents;

/**
 * Turns a clinic event into something a screen hears live, over Reverb.
 *
 * Called by ClinicEventDispatcher after commit, beside the document
 * automation — the same seam, so a consultation that rolled back is never
 * announced. Laravel decides everything here: whether the branch runs the
 * features the event is for, and what the payload says. Reverb only carries
 * it, and BranchPharmacyChannel decides who may listen.
 *
 * THE ORGANIZATION IS READ FROM THE EVENT'S DATABASE, not from the request.
 * The event records which tenant database it was raised against, and the
 * dispatcher has already refused to run if that is no longer the one
 * connected. Looking the organization up by that name keeps one source of
 * truth for "which clinic", and works the same from a queue or a console
 * command as from a request.
 */
class RealtimeBroadcaster
{
    public function __construct(
        private readonly Permission $permission,
        private readonly OpdHandoff $handoff,
    ) {}

    public function handle(ClinicEvent $event): void
    {
        match ($event->key) {
            ClinicEvents::CONSULTATION_COMPLETED => $this->consultationCompleted($event),
            default => null,
        };
    }

    /**
     * Tell that branch's pharmacy counter a patient is on their way.
     *
     * Only where the branch runs BOTH the OPD and the pharmacy. A branch that
     * runs OPD with no counter has nobody to tell, and the counter at a
     * branch with the pharmacy switched off must not be sent patients.
     */
    private function consultationCompleted(ClinicEvent $event): void
    {
        if ($event->locationId === null || $event->subjectType !== (new Appointment)->getMorphClass()) {
            return;
        }

        $organization = Organization::query()
            ->where('database_name', $event->database)
            ->first();

        if ($organization === null) {
            return;
        }

        $features = $this->permission->featuresAt($organization, $event->locationId);

        if (! ($features['opd_pharmacy_handoff'] ?? false)) {
            return;
        }

        $visit = Appointment::query()->find($event->subjectId);

        if ($visit === null) {
            return;
        }

        event(new ConsultationCompleted(
            $organization->uuid,
            $event->locationId,
            $this->handoff->row($visit),
        ));
    }
}

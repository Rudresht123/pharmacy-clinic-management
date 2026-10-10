<?php

namespace App\Services\Clinic;

use App\Services\Communication\AutomationSender;
use App\Services\Communication\DoctorNotifier;
use App\Services\Documents\DocumentAutomation;
use App\Services\Realtime\RealtimeBroadcaster;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raises clinic events once the work that caused them is safely on disk.
 *
 * WHY THIS IS NOT A LARAVEL EVENT.
 *
 * There is no event bus in this codebase — business logic is synchronous
 * service calls inside transactions, and the one existing trigger
 * (BillingTriggerResolver) is invoked directly by VisitWorkflow. Adding
 * Illuminate's dispatcher here would mean two mechanisms for one job, and the
 * thing it would buy — decoupling — it cannot actually deliver: a queued
 * listener serializes away from the `organization` connection, so it would
 * have to carry the tenant and reconnect by hand, which is exactly what this
 * class does without the indirection. The existing queued jobs
 * (SendWhatsAppMessage) already take an organisation id for that reason.
 *
 * The call sites call `dispatch()`. If the bus ever becomes the right answer,
 * this method becomes its adapter and no service changes.
 *
 * AFTER COMMIT, ALWAYS.
 *
 * Registered on the tenant connection, which is the one the callers open
 * their transactions on. Laravel runs the callback immediately when there is
 * no transaction open, after COMMIT when there is, and discards it on
 * rollback — which is the whole requirement: a payment that rolled back must
 * not have sent the patient a receipt for it.
 */
class ClinicEventDispatcher
{
    public function __construct(
        private readonly DocumentAutomation $documents,
        private readonly RealtimeBroadcaster $realtime,
        private readonly AutomationSender $automation,
        private readonly DoctorNotifier $doctorNotifier,
    ) {}

    public function dispatch(ClinicEvent $event): void
    {
        DB::connection(TenantConnectionService::CONNECTION)
            ->afterCommit(fn () => $this->run($event));
    }

    /**
     * NEVER THROWS.
     *
     * By the time this runs the money is already committed. An exception here
     * would surface at the caller of the transaction that has already
     * succeeded — so a WhatsApp outage, a missing template or a bug in a rule
     * would read to the receptionist as "the payment failed", and they would
     * take it again. The financial record is critical; the paperwork is not.
     */
    private function run(ClinicEvent $event): void
    {
        /*
         * THE TENANT GUARD.
         *
         * Between raising this event and running it the connection may have
         * been repointed — a queued worker moving to the next job, a console
         * command walking every tenant, a test switching organisations. Acting
         * now would write one clinic's document into another clinic's
         * database, which is the one failure this architecture exists to make
         * impossible. Refuse loudly rather than guess.
         */
        $current = ClinicEvent::currentDatabase();

        if ($current !== $event->database) {
            Log::warning('clinic-event.tenant-mismatch', $event->forLog() + [
                'connected_to' => $current,
            ]);

            return;
        }

        Log::info('clinic-event.received', $event->forLog());

        try {
            $this->documents->handle($event);
        } catch (Throwable $e) {
            report($e);

            Log::error('clinic-event.failed', $event->forLog() + [
                'reason' => $e->getMessage(),
            ]);
        }

        /*
         * Realtime in its own try, after the paperwork: a Reverb that is down
         * must not cost anybody a receipt, and a document rule that throws
         * must not stop the pharmacy counter hearing about the patient.
         */
        try {
            $this->realtime->handle($event);
        } catch (Throwable $e) {
            report($e);

            Log::error('clinic-event.realtime-failed', $event->forLog() + [
                'reason' => $e->getMessage(),
            ]);
        }

        /*
         * The clinic's automation rules — a confirmation email, a WhatsApp
         * receipt — each only if that channel, rule and template are all on.
         * Its own try again: an SMTP account that is misconfigured must not
         * stop the receipt PDF or the pharmacy counter.
         */
        try {
            $this->automation->handle($event);
        } catch (Throwable $e) {
            report($e);

            Log::error('clinic-event.automation-failed', $event->forLog() + [
                'reason' => $e->getMessage(),
            ]);
        }

        /*
         * The doctor's own copy — a push to their app login, an email on
         * file — unconditional on the clinic's communication switches, which
         * are about the patient's wording, not the doctor's. Its own try:
         * a doctor with no login and no email on file must not stop the
         * patient's confirmation above.
         */
        try {
            $this->doctorNotifier->handle($event);
        } catch (Throwable $e) {
            report($e);

            Log::error('clinic-event.doctor-notify-failed', $event->forLog() + [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}

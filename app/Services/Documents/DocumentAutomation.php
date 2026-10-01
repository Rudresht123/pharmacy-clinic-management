<?php

namespace App\Services\Documents;

use App\Models\Platform\Organization;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Location;
use App\Services\Clinic\ClinicEvent;
use App\Services\Permissions\Permission;
use App\Support\Documents\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a clinic event into whatever paperwork the organisation asked for.
 *
 * NO WORKFLOW IS HARD-CODED HERE, and that is the whole point of the class.
 * There is no line that says "a payment produces a receipt". A payment
 * produces whatever this organisation's rules say a payment produces, which
 * for most clinics on day one is nothing at all. A clinic that prints a
 * receipt and a clinic that prints nothing are the same code path with
 * different rows.
 *
 * SEPARATION OF THE THREE ACTS. The record was created by the service that
 * committed it. Generating a document is a second thing that may or may not
 * happen. Delivering it is a third. Collapsing any two of those is how a
 * failed WhatsApp send ends up rolling back a payment.
 *
 * GENERATED IN THE REQUEST, after the commit — not queued. A queued document
 * appears only where a worker is running, and a clinic with no worker would
 * set a rule and see nothing happen, with nothing on the screen saying why.
 * A receipt is one small page; the counter waits a moment longer for it.
 * Delivery, when it arrives, is the part that goes on the queue.
 */
class DocumentAutomation
{
    public function __construct(
        private readonly DocumentRules $rules,
        private readonly DocumentService $documents,
        private readonly Permission $permissions,
    ) {}

    /**
     * Called once per event, already after commit and already tenant-checked.
     *
     * Throwing is safe — ClinicEventDispatcher catches and logs — but this
     * method still isolates each rule, so one misconfigured document does not
     * stop the rest of them running.
     */
    public function handle(ClinicEvent $event): void
    {
        $rules = $this->rules->for($event);

        if ($rules === []) {
            // Not a warning. "This clinic automates nothing on payments" is a
            // valid configuration, and logging it as a problem would fill the
            // log with every event every clinic ever raises.
            Log::debug('document-automation.no-rules', $event->forLog());

            return;
        }

        Log::info('document-automation.rules-found', $event->forLog() + ['rules' => count($rules)]);

        $failed = 0;

        foreach ($rules as $rule) {
            try {
                $this->apply($event, $rule);
            } catch (Throwable $e) {
                // The receipt failing is no reason to skip the prescription
                // copy configured beside it.
                report($e);
                $failed++;

                Log::error('document-automation.failed', $event->forLog() + [
                    'document_type' => $rule->documentType,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        Log::info('document-automation.completed', $event->forLog() + [
            'rules' => count($rules),
            'failed' => $failed,
        ]);
    }

    private function apply(ClinicEvent $event, DocumentRule $rule): void
    {
        $context = $event->forLog() + ['document_type' => $rule->documentType];

        if (! $rule->autoGenerate) {
            // Configured, but as a button rather than a reflex. The rule still
            // exists so the screen can offer "generate" on the record.
            Log::debug('document-automation.manual-only', $context);

            return;
        }

        $type = DocumentTypes::find($rule->documentType);

        // The event names the database; the organisation is looked up by it
        // rather than carried, so the two can never disagree.
        $organization = Organization::query()->where('database_name', $event->database)->first();

        if ($type === null || $organization === null) {
            Log::warning('document-automation.unresolvable', $context);

            return;
        }

        /*
         * A rule outlives the module it was made under. A clinic that has
         * since dropped Documents — or the module the document needs — at
         * this branch gets nothing, quietly: they switched it off.
         */
        $modules = $this->permissions->modulesAt($organization, $event->locationId);

        if (! in_array('documents', $modules, true) || array_diff($type['requires'], $modules) !== []) {
            Log::info('document-automation.module-off', $context);

            return;
        }

        $subject = DocumentSubjects::fromEvent($event, $type['subject']);

        if ($subject === null) {
            Log::warning('document-automation.no-subject', $context);

            return;
        }

        Log::info('document-automation.started', $context);

        $document = $this->documents->generate(
            $rule->documentType,
            $subject,
            $organization,
            $event->locationId === null ? null : Location::find($event->locationId),
            $this->keyFor($event, $rule->documentType, $subject),
        );

        Log::info(
            $document->wasRecentlyCreated ? 'document-automation.generated' : 'document-automation.already-filed',
            $context + ['document_id' => $document->getKey()],
        );
    }

    /**
     * What makes this document this document, and not a second copy of it.
     *
     * THE RECORD, not the event. A receipt is one receipt per payment however
     * many rules asked for it — "payment received" and "settled in full" both
     * fire on the payment that settles a bill, and a clinic that ticked both
     * wants one receipt, not two. The same goes for a prescription, a refund
     * and a counter sale: one record, one automatic copy.
     *
     * A BILL is the exception, because a bill changes as it is paid. A copy
     * printed when it was drawn and another when it was settled are two
     * different sheets — UNPAID and PAID — so each payment or refund that
     * prints one is its own copy.
     */
    private function keyFor(ClinicEvent $event, string $documentType, Model $subject): string
    {
        $key = DocumentService::recordKey($documentType, $subject);

        if ($subject instanceof Invoice) {
            foreach (['payment_id', 'refund_id'] as $discriminator) {
                if (isset($event->meta[$discriminator])) {
                    $key .= '|'.$discriminator.':'.$event->meta[$discriminator];
                }
            }
        }

        return $key;
    }
}

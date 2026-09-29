<?php

namespace App\Services\Documents;

use App\Services\Clinic\ClinicEvent;
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
 */
class DocumentAutomation
{
    public function __construct(
        private readonly DocumentRules $rules,
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

        Log::info('document-automation.started', $context);

        /*
         * STEP 2 AND STEP 3 LAND HERE, in this order:
         *
         *   1. DocumentService::generate() for $rule->documentType, keyed by
         *      $event->idempotencyKey() so a replayed event reuses the
         *      document the first one filed rather than filing a second.
         *   2. A delivery pass per enabled action, queued — never inline, and
         *      never inside a transaction. WhatsAppManager and EmailManager
         *      already queue and already log to `message_logs`; what is
         *      missing is the join from a document to its sends.
         *
         * Not stubbed with a half-working generate() on purpose. A document
         * produced without the idempotency constraint that Step 3 adds would
         * duplicate receipts on the first retried request, and a duplicated
         * receipt is a financial record that has to be voided by hand.
         */
        Log::info('document-automation.pending-implementation', $context + [
            'idempotency_key' => $event->idempotencyKey(),
            'delivers' => $rule->delivers(),
        ]);
    }
}

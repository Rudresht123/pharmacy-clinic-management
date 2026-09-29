<?php

namespace App\Services\Documents;

use App\Services\Clinic\ClinicEvent;

/**
 * What an organisation has decided should happen on an event.
 *
 * THE SEAM, AND DELIBERATELY EMPTY IN STEP 1.
 *
 * `document_rules` does not exist yet — that table and the screen that fills
 * it are Step 3. What exists now is the question being asked in the right
 * place: every event already arrives here and is answered "this clinic has
 * configured nothing", which is the correct answer for a clinic that has
 * configured nothing.
 *
 * WHY A CLASS RATHER THAN A `return []` INSIDE THE AUTOMATION.
 *
 * Because it is the injection point the tests need. A test that wants to
 * prove automation failure leaves a payment intact swaps this one class; a
 * test of the wiring itself swaps it for a recorder. Making it an interface
 * with a null implementation would be one more file for the same seam.
 *
 * WHY THE RULES LIVE IN THE TENANT DATABASE (when they arrive).
 *
 * They are an organisation's own configuration, and every record they point
 * at — a branch, a template, a message template — is a tenant row. Keeping
 * them in the master database would mean a rule holding foreign keys into a
 * database it cannot join against.
 */
class DocumentRules
{
    /**
     * The rules this organisation has set for an event, in the order they run.
     *
     * @return list<DocumentRule>
     */
    public function for(ClinicEvent $event): array
    {
        /*
         * Step 3 replaces this body with a query against `document_rules`,
         * scoped to the event key and to the event's branch (a rule with a
         * null location_id applying organisation-wide). The signature is
         * already the one that query needs, so nothing above this line moves.
         */
        return [];
    }
}

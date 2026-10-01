<?php

namespace App\Services\Documents;

use App\Models\Tenant\DocumentRule as DocumentRuleRow;
use App\Services\Clinic\ClinicEvent;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an organisation has decided should happen on an event.
 *
 * Read from `document_rules` in the tenant database — the organisation's own
 * configuration, beside every branch and template it points at. A rule with
 * no branch applies at every branch; a rule for a branch applies there only.
 *
 * WHY A CLASS RATHER THAN A QUERY INSIDE THE AUTOMATION.
 *
 * Because it is the injection point the tests need. A test that wants to
 * prove automation failure leaves a payment intact swaps this one class; a
 * test of the wiring itself swaps it for a recorder.
 */
class DocumentRules
{
    /**
     * The rules this organisation has set for an event, in the order they run.
     *
     * ONE PER DOCUMENT TYPE. A clinic that says "receipt on every payment"
     * for all branches and again for Gurgaon has said it twice, not asked for
     * two receipts — so the list is de-duplicated here, and the idempotency
     * key on the document catches anything that slips past.
     *
     * Only types the event can actually make are read. A row naming anything
     * else — saved before the catalogue changed, or written by hand — would
     * be a rule that fails on every payment, so it is simply not a rule.
     *
     * @return list<DocumentRule>
     */
    public function for(ClinicEvent $event): array
    {
        $offered = ClinicEvents::documentsFor($event->key);

        if ($offered === []) {
            return [];
        }

        return DocumentRuleRow::query()
            ->where('event_key', $event->key)
            ->whereIn('document_type', $offered)
            ->where(function (Builder $query) use ($event) {
                $query->whereNull('location_id');

                if ($event->locationId !== null) {
                    $query->orWhere('location_id', $event->locationId);
                }
            })
            ->orderBy('id')
            ->pluck('document_type')
            ->unique()
            ->map(fn (string $type) => new DocumentRule($type))
            ->values()
            ->all();
    }
}

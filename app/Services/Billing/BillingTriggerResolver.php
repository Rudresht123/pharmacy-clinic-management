<?php

namespace App\Services\Billing;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillableService;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\PharmacySale;
use App\Services\Permissions\Permission;

/**
 * One visit, one invoice.
 *
 * A visit produces charges at different moments and from different modules —
 * the doctor finishes, the lab signs off, the counter dispenses — and a
 * patient should be handed ONE document at the end, not one per department.
 *
 * THE SHAPE OF IT
 *
 *   Every billable event calls its handler here. Each handler does the same
 *   two things in the same order:
 *
 *     1. make sure the visit has a draft invoice (drawing one if this is the
 *        first charge), and append everything the visit has produced SO FAR;
 *     2. ask whether the organisation's trigger says this event is the one
 *        that completes the bill — and finalize only then.
 *
 *   So the lines accumulate regardless of trigger, and the trigger decides
 *   only WHEN the patient may pay. A clinic billing after consultation and a
 *   clinic billing after pharmacy produce the same invoice; they present it
 *   at different moments.
 *
 * WHY COLLECT EVERYTHING EVERY TIME rather than appending just the new
 * charge: events do not arrive in a fixed order, and some arrive twice. A
 * lab order completed before the consultation, a dispensing reversed and
 * redone, a consultation reopened — collecting the visit's whole current
 * state and letting `BillingService::addLines()` skip what is already there
 * is idempotent by construction. Appending deltas would need every caller to
 * know what had already been billed, which is exactly the knowledge that
 * goes stale.
 *
 * WHAT IS NOT SWEPT IN. A registration fee is taken at the desk before there
 * is a visit, and a manual bill is the exception hatch — both are their own
 * invoice `kind` and neither is ever folded into a visit.
 */
class BillingTriggerResolver
{
    public function __construct(
        private readonly BillingService $billing,
    ) {}

    /* ===================================================================
     | THE EVENTS
     |===================================================================*/

    /** The doctor has finished writing up. */
    public function onConsultationCompleted(Appointment $appointment): ?Invoice
    {
        return $this->handle($appointment, BillingSetting::TRIGGER_CONSULTATION);
    }

    /** The counter has dispensed against this visit's prescription. */
    public function onPharmacyDispensed(Appointment $appointment): ?Invoice
    {
        return $this->handle($appointment, BillingSetting::TRIGGER_PHARMACY);
    }

    /** The bench has signed a lab order off. */
    public function onLaboratoryCompleted(Appointment $appointment): ?Invoice
    {
        return $this->handle($appointment, BillingSetting::TRIGGER_LABORATORY);
    }

    /** The patient has arrived and been checked in. */
    public function onCheckedIn(Appointment $appointment): ?Invoice
    {
        return $this->handle($appointment, BillingSetting::TRIGGER_CHECKIN);
    }

    /**
     * The counter is drawing (or completing) this visit's bill by hand.
     *
     * The `manual` escape: collects whatever the visit has produced and
     * finalizes it immediately, whatever the configured trigger says. This
     * is what the "Finalize bill" button on the billing screen calls.
     */
    public function finalizeForVisit(Appointment $appointment): ?Invoice
    {
        if (! $this->billingIsOn($appointment)) {
            throw BillingConflict::because('Billing is not enabled at this branch.');
        }

        $invoice = $this->gather($appointment, BillingSetting::current());

        if ($invoice === null) {
            return null;
        }

        return $this->billing->finalize($invoice);
    }

    /**
     * A patient's record has just been opened, and the desk charges for it.
     *
     * ITS OWN INVOICE, not part of any visit. Registration happens before
     * there is an appointment to attach it to — often days before — and the
     * patient walks away from the desk with the receipt in hand. Folding it
     * into a visit that may never happen would be holding somebody's money
     * against a document they cannot see.
     *
     * Null when the organisation charges nothing to register, which is most
     * of them.
     */
    public function onPatientRegistered(Customer $customer, int $locationId): ?Invoice
    {
        if (! $this->billingIsOnAt($locationId)) {
            return null;
        }

        $settings = BillingSetting::current();

        $services = BillableService::query()
            ->active()
            ->forBranch($locationId)
            ->where('kind', BillableService::KIND_REGISTRATION)
            ->orderBy('position')
            ->get();

        $lines = [];

        foreach ($services as $service) {
            $price = round((float) $service->default_price, 2);

            if ($price <= 0) {
                continue;
            }

            $lines[] = [
                'source_type' => InvoiceItem::SOURCE_SERVICE,
                'source_id' => $service->id,
                'billable_service_id' => $service->id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => $price,
                'tax_percent' => (float) $service->tax_percent,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->billing->draw([
            'location_id' => $locationId,
            'customer_id' => $customer->id,
            'kind' => Invoice::KIND_REGISTRATION,
            'trigger' => BillingSetting::TRIGGER_CHECKIN,
        ], $lines);
    }

    /* ===================================================================
     | THE ENGINE
     |===================================================================*/

    /**
     * Accumulate this visit's charges, then finalize if this event is the
     * configured trigger.
     *
     * `$event` is which trigger the CALLER represents. Finalization happens
     * only when that matches the organisation's setting — every other event
     * still contributes its lines and leaves the bill open.
     */
    private function handle(Appointment $appointment, string $event): ?Invoice
    {
        if (! $this->billingIsOn($appointment)) {
            return null;
        }

        $settings = BillingSetting::current();

        $invoice = $this->gather($appointment, $settings);

        if ($invoice === null) {
            return null;
        }

        if ($settings->default_trigger !== $event) {
            // Charges banked; somebody else closes the bill.
            return $invoice;
        }

        /*
         * The configured moment has arrived — but not if the visit is still
         * obviously producing charges. A clinic that bills after consultation
         * while a lab order is outstanding would hand the patient a bill and
         * then another one an hour later, which is the thing consolidation
         * exists to stop.
         */
        if ($this->stillProducingCharges($appointment, $event)) {
            return $invoice;
        }

        return $this->billing->finalize($invoice);
    }

    /**
     * The visit's draft invoice, carrying everything it has produced so far.
     *
     * Draws one if this is the first charge. Null when the visit has produced
     * nothing billable at all — a clinic that has priced nothing, which is a
     * real state and not an error.
     */
    private function gather(Appointment $appointment, BillingSetting $settings): ?Invoice
    {
        $lines = [
            ...$this->consultationLines($appointment, $settings),
            ...$this->laboratoryLines($appointment),
            ...$this->pharmacyLines($appointment),
        ];

        $existing = Invoice::query()->liveForVisit($appointment->id)->first();

        if ($existing) {
            // Already finalized — the bill has been presented, so nothing
            // more is appended to it. Later charges are the counter's problem
            // to raise as a separate bill, deliberately.
            if (! $existing->acceptsMoreLines()) {
                return $existing;
            }

            return $this->billing->addLines($existing, $lines);
        }

        if ($lines === []) {
            return null;
        }

        $consultation = Consultation::query()
            ->where('appointment_id', $appointment->id)
            ->first();

        return $this->billing->draw([
            'location_id' => $appointment->location_id,
            'customer_id' => $appointment->customer_id,
            'appointment_id' => $appointment->id,
            'consultation_id' => $consultation?->id,
            'doctor_id' => $appointment->doctor_id,
            'kind' => Invoice::KIND_VISIT,
            'trigger' => $settings->default_trigger,
        ], $lines);
    }

    /**
     * Whether finalizing now would be premature.
     *
     * Only the departments that are actually mid-work hold the bill open.
     * The event that fired is excluded — a pharmacy trigger firing on a
     * dispensing must not wait for the prescription it has just filled.
     */
    private function stillProducingCharges(Appointment $appointment, string $event): bool
    {
        if ($event !== BillingSetting::TRIGGER_LABORATORY) {
            $labOutstanding = LabOrder::query()
                ->where('appointment_id', $appointment->id)
                ->outstanding()
                ->exists();

            if ($labOutstanding) {
                return true;
            }
        }

        return false;
    }

    /* ===================================================================
     | WHERE THE CHARGES COME FROM
     |===================================================================*/

    /**
     * The doctor's fee, and anything the organisation charges per visit.
     *
     * @return list<array<string, mixed>>
     */
    private function consultationLines(Appointment $appointment, BillingSetting $settings): array
    {
        $consultation = Consultation::query()
            ->where('appointment_id', $appointment->id)
            ->first();

        if (! $consultation) {
            return [];
        }

        $lines = [];

        $doctor = $appointment->doctor_id
            ? Doctor::query()->find($appointment->doctor_id)
            : null;

        $fee = $doctor?->default_consultation_fee !== null
            ? round((float) $doctor->default_consultation_fee, 2)
            : 0.0;

        if ($fee > 0) {
            $lines[] = [
                'source_type' => InvoiceItem::SOURCE_CONSULTATION,
                'source_id' => $consultation->id,
                'description' => sprintf('Consultation — %s', $doctor?->name ?? 'Doctor'),
                'quantity' => 1,
                'unit_price' => $fee,
                'tax_percent' => (float) $settings->default_tax_percent,
            ];
        }

        $services = BillableService::query()
            ->active()
            ->forBranch($appointment->location_id)
            ->whereIn('kind', [BillableService::KIND_CONSULTATION, BillableService::KIND_PROCEDURE])
            ->orderBy('position')
            ->get();

        foreach ($services as $service) {
            $price = round((float) $service->default_price, 2);

            if ($price <= 0) {
                continue;
            }

            $lines[] = [
                'source_type' => $service->kind === BillableService::KIND_PROCEDURE
                    ? InvoiceItem::SOURCE_PROCEDURE
                    : InvoiceItem::SOURCE_SERVICE,
                'source_id' => $service->id,
                'billable_service_id' => $service->id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => $price,
                'tax_percent' => (float) $service->tax_percent,
            ];
        }

        return $lines;
    }

    /**
     * Tests ordered at this visit that carry a price.
     *
     * Priced from the SNAPSHOT taken when the test was ordered, never from
     * the catalogue as it reads now — a test repriced next month must not
     * rewrite what this patient was charged. A freehand line (no catalogue
     * entry, no snapshot) is ordered and run exactly as before and bills
     * nothing.
     *
     * Cancelled orders are excluded; a withdrawn test is not work anybody
     * owes for.
     *
     * @return list<array<string, mixed>>
     */
    private function laboratoryLines(Appointment $appointment): array
    {
        $orders = LabOrder::query()
            ->where('appointment_id', $appointment->id)
            ->whereNot('status', LabOrder::CANCELLED)
            ->with('items')
            ->get();

        $lines = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! $item->isBillable()) {
                    continue;
                }

                $lines[] = [
                    'source_type' => InvoiceItem::SOURCE_LAB_TEST,
                    'source_id' => $item->id,
                    'description' => sprintf('Lab — %s', $item->test_name),
                    'quantity' => 1,
                    'unit_price' => round((float) $item->price_snapshot, 2),
                    'tax_percent' => (float) ($item->tax_percent_snapshot ?? 0),
                ];
            }
        }

        return $lines;
    }

    /**
     * Medicines dispensed against this visit.
     *
     * Read off `pharmacy_sale_items`, which keeps its own name, batch and
     * price snapshots — so the invoice says what was actually handed over,
     * at the price it was handed over for, however the catalogue reads
     * afterwards.
     *
     * The sale row stays where it is: it owns the stock movement and the
     * prescription crediting, and the pharmacy's own reports read it. What
     * moves here is only the MONEY, and `SalesService` marks the sale
     * `billed_via_invoice` so the till stops asking for it too.
     *
     * Cancelled sales are excluded — the stock went back and the charge with
     * it.
     *
     * @return list<array<string, mixed>>
     */
    private function pharmacyLines(Appointment $appointment): array
    {
        $sales = PharmacySale::query()
            ->where('appointment_id', $appointment->id)
            ->where('status', PharmacySale::COMPLETED)
            ->with('items')
            ->get();

        $lines = [];

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $lines[] = [
                    'source_type' => InvoiceItem::SOURCE_PHARMACY_SALE_ITEM,
                    'source_id' => $item->id,
                    'description' => sprintf(
                        '%s%s',
                        $item->item_name_snapshot,
                        $item->batch_number_snapshot ? " (batch {$item->batch_number_snapshot})" : '',
                    ),
                    /* Carried across so the clinic's tax invoice can name the
                       code the medicine was actually billed under. */
                    'hsn_code' => $item->hsn_code_snapshot,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    /*
                     * The tax the sale already worked out, as a rate. The
                     * pharmacy prices tax-inclusive or exclusive per its own
                     * settings and the line total already reflects that;
                     * carrying the RATE lets the invoice re-derive the same
                     * figure under its own settings rather than double-taxing.
                     */
                    'tax_percent' => (float) $item->tax_rate,
                ];
            }
        }

        return $lines;
    }

    /* ---------------------------------------------------------- plumbing */

    private function billingIsOn(Appointment $appointment): bool
    {
        return $this->billingIsOnAt($appointment->location_id);
    }

    /**
     * Whether billing runs at this branch.
     *
     * Reads the organisation off the request that started this — every
     * trigger fires inside a tenant HTTP request, where
     * ResolveTenantFromSession has already put it. Outside one (a test, an
     * artisan job) the schema is trusted: the tables are per-organisation, so
     * one that migrated at all has the module.
     */
    private function billingIsOnAt(?int $locationId): bool
    {
        $organization = request()->attributes->get('tenant.organization');

        if ($organization instanceof Organization) {
            return app(Permission::class)->hasModule($organization, 'billing', $locationId);
        }

        return true;
    }
}

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
use App\Models\Tenant\LabOrderItem;
use App\Services\Permissions\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * When a department's work becomes a bill — each department for itself.
 *
 * THE OPD bills a visit: the doctor's fee (or the follow-up fee, when the
 * patient is back inside the follow-up period), and the per-visit and
 * procedure charges from the price list. Drawn at the organisation's OPD
 * timing — check-in, after the consultation, or only when the desk bills it
 * — as one invoice per visit, made payable the moment it is drawn.
 *
 * THE LAB bills an order: its priced tests, at the lab's own timing — when
 * the tests are ordered, when the lab signs them off, or only by hand. One
 * invoice per order.
 *
 * THE PHARMACY bills nothing here. Its bill is the sale, settled at its own
 * counter, and medicines never appear on an OPD or lab invoice — which is
 * what stops a patient being asked to pay for the same strip twice.
 *
 * IDEMPOTENT EVERYWHERE. Every entry point looks for the live bill first and
 * answers with it; the partial unique indexes (one live visit invoice per
 * appointment, one live lab invoice per order) make the loser of a race read
 * the winner's row rather than draw a second bill. A visit or order billed,
 * reopened and completed again is still billed once.
 *
 * NEVER A DRAFT LEFT BEHIND. A visit invoice is drawn and finalized in one
 * transaction, so the visit moves straight to "awaiting payment" (or closes)
 * instead of waiting on a running tab nobody will close.
 */
class BillingTriggerResolver
{
    public function __construct(
        private readonly BillingService $billing,
    ) {}

    /* ===================================================================
     | OPD
     |===================================================================*/

    /** The patient has arrived and been checked in. */
    public function onCheckedIn(Appointment $appointment): ?Invoice
    {
        return $this->opd($appointment, BillingSetting::TRIGGER_CHECKIN);
    }

    /** The doctor has finished writing up. */
    public function onConsultationCompleted(Appointment $appointment): ?Invoice
    {
        return $this->opd($appointment, BillingSetting::TRIGGER_CONSULTATION);
    }

    /**
     * The desk bills this visit by hand.
     *
     * Whatever the OPD timing says — this is how a `manual` clinic bills
     * every visit, and how anybody bills a visit whose timing passed with
     * nothing priced. Answers with the visit's bill if it already has one.
     */
    public function billVisit(Appointment $appointment): ?Invoice
    {
        if (! $this->runs('billing', $appointment->location_id)) {
            throw BillingConflict::because('Billing is not enabled at this branch.');
        }

        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            throw BillingConflict::because('This visit did not happen, so there is nothing to bill.');
        }

        return $this->billOpd($appointment, BillingSetting::TRIGGER_MANUAL);
    }

    /** Kept for callers that finalize a visit's bill: the same act. */
    public function finalizeForVisit(Appointment $appointment): ?Invoice
    {
        return $this->billVisit($appointment);
    }

    /**
     * Bill the visit if this event is the organisation's OPD timing.
     *
     * Silent when it is not, when billing does not run at the branch, and
     * when the visit has nothing priced — a doctor with no fee and no
     * per-visit charges is a real configuration, not an error.
     */
    private function opd(Appointment $appointment, string $event): ?Invoice
    {
        if (! $this->runs('billing', $appointment->location_id)) {
            return null;
        }

        if (BillingSetting::current()->opdTrigger() !== $event) {
            return null;
        }

        return $this->billOpd($appointment, $event);
    }

    private function billOpd(Appointment $appointment, string $trigger): ?Invoice
    {
        $existing = Invoice::query()->liveForVisit($appointment->id)->first();

        if ($existing) {
            /*
             * A running tab drawn before each department billed for itself:
             * close it with the OPD's own charges on it, rather than draw a
             * second visit bill beside it — which the index would refuse.
             */
            if ($existing->isDraft()) {
                $this->billing->addLines($existing, $this->opdLines($appointment));

                return $this->billing->finalize($existing);
            }

            return $existing;
        }

        $lines = $this->opdLines($appointment);

        if ($lines === []) {
            return null;
        }

        $consultation = Consultation::query()->where('appointment_id', $appointment->id)->first();

        try {
            return (new Invoice)->getConnection()->transaction(fn () => $this->billing->finalize(
                $this->billing->draw([
                    'location_id' => $appointment->location_id,
                    'customer_id' => $appointment->customer_id,
                    'appointment_id' => $appointment->id,
                    'consultation_id' => $consultation?->id,
                    'doctor_id' => $appointment->doctor_id,
                    'kind' => Invoice::KIND_VISIT,
                    'trigger' => $trigger,
                ], $lines),
            ));
        } catch (UniqueConstraintViolationException) {
            // Another event billed this visit a moment ago — that bill is the answer.
            return Invoice::query()->liveForVisit($appointment->id)->first();
        }
    }

    /**
     * What the OPD charges for this visit.
     *
     *   1. The doctor's fee — or, when this visit is a follow-up inside the
     *      period the doctor set last time and the branch prices follow-ups,
     *      the follow-up fee instead (which may be nothing at all).
     *   2. Every per-visit and procedure charge on the price list, the
     *      branch's own price winning over the organisation's for the same
     *      service. The price list screen says these join every visit's bill.
     *
     * Medicines and tests are never here: their departments bill them.
     *
     * @return list<array<string, mixed>>
     */
    private function opdLines(Appointment $appointment): array
    {
        $settings = BillingSetting::current();
        $consultation = Consultation::query()->where('appointment_id', $appointment->id)->first();
        $doctor = $appointment->doctor_id ? Doctor::query()->find($appointment->doctor_id) : null;

        $services = $this->priceList($appointment->location_id, [
            BillableService::KIND_FOLLOW_UP,
            BillableService::KIND_CONSULTATION,
            BillableService::KIND_PROCEDURE,
        ]);

        $lines = [];

        $followUp = $this->isFollowUp($appointment)
            ? $services->firstWhere('kind', BillableService::KIND_FOLLOW_UP)
            : null;

        if ($followUp) {
            $price = round((float) $followUp->default_price, 2);

            if ($price > 0) {
                $lines[] = [
                    'source_type' => InvoiceItem::SOURCE_CONSULTATION,
                    'source_id' => $consultation?->id,
                    'billable_service_id' => $followUp->id,
                    'description' => sprintf('%s — %s', $followUp->name, $doctor?->name ?? 'Doctor'),
                    'quantity' => 1,
                    'unit_price' => $price,
                    'tax_percent' => (float) $followUp->tax_percent,
                ];
            }
        } else {
            $fee = $doctor?->default_consultation_fee !== null
                ? round((float) $doctor->default_consultation_fee, 2)
                : 0.0;

            if ($fee > 0) {
                $lines[] = [
                    'source_type' => InvoiceItem::SOURCE_CONSULTATION,
                    // Null at check-in: there is no write-up yet, and the
                    // visit's one-bill index is what keeps it single.
                    'source_id' => $consultation?->id,
                    'description' => sprintf('Consultation — %s', $doctor?->name ?? 'Doctor'),
                    'quantity' => 1,
                    'unit_price' => $fee,
                    'tax_percent' => (float) $settings->default_tax_percent,
                ];
            }
        }

        foreach ($services as $service) {
            if ($service->kind === BillableService::KIND_FOLLOW_UP) {
                continue;
            }

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
     * Whether the patient is back inside the follow-up period the same
     * doctor set at an earlier visit.
     *
     * Read from what the doctor actually wrote — `follow_up_days` on the
     * earlier consultation — counted from that visit's date.
     */
    private function isFollowUp(Appointment $appointment): bool
    {
        if (! $appointment->customer_id || ! $appointment->doctor_id || ! $appointment->appointment_date) {
            return false;
        }

        $today = $appointment->appointment_date->copy()->startOfDay();

        return Consultation::query()
            ->where('customer_id', $appointment->customer_id)
            ->where('doctor_id', $appointment->doctor_id)
            ->where('appointment_id', '!=', $appointment->id)
            ->where('follow_up_days', '>', 0)
            ->whereHas('appointment', fn ($visit) => $visit
                ->whereDate('appointment_date', '<', $today)
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]))
            ->with('appointment')
            ->get()
            ->contains(fn (Consultation $earlier) => $earlier->appointment
                && $today->lessThanOrEqualTo(
                    $earlier->appointment->appointment_date->copy()->startOfDay()->addDays((int) $earlier->follow_up_days),
                ));
    }

    /**
     * The active price-list rows of some kinds, as one branch sees them.
     *
     * A branch's own row replaces the organisation's row for the same
     * service — matched by code, or by name where there is no code — rather
     * than being charged beside it.
     *
     * @param  list<string>  $kinds
     * @return Collection<int, BillableService>
     */
    private function priceList(?int $locationId, array $kinds): Collection
    {
        return BillableService::query()
            ->active()
            ->forBranch($locationId)
            ->whereIn('kind', $kinds)
            // The branch's rows first, so they are the ones kept below.
            ->orderByRaw('location_id IS NULL')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->unique(fn (BillableService $service) => $service->kind.':'.mb_strtolower(trim((string) ($service->code ?: $service->name))))
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();
    }

    /* ===================================================================
     | LABORATORY
     |===================================================================*/

    /** The doctor has ordered tests. */
    public function onLabOrdered(LabOrder $order): ?Invoice
    {
        return $this->lab($order, BillingSetting::TRIGGER_LAB_ORDER);
    }

    /** The lab has signed the order off. */
    public function onLabCompleted(LabOrder $order): ?Invoice
    {
        return $this->lab($order, BillingSetting::TRIGGER_LABORATORY);
    }

    /**
     * The lab counter bills this order by hand — whatever the lab timing.
     *
     * Answers with the order's bill if it already has one; null when nothing
     * on it is priced.
     */
    public function billLabOrder(LabOrder $order): ?Invoice
    {
        if (! $this->runs('laboratory', $order->location_id) || ! $this->runs('billing', $order->location_id)) {
            throw BillingConflict::because('The laboratory is not enabled at this branch.');
        }

        if ($order->status === LabOrder::CANCELLED) {
            throw BillingConflict::because("{$order->order_number} is cancelled, so there is nothing to bill.");
        }

        return $this->billLab($order, BillingSetting::TRIGGER_MANUAL);
    }

    /**
     * The order's tests changed — added, removed, or one cancelled.
     *
     * A bill already drawn follows the order while nothing has been paid
     * against it; once money has moved it stands, and the difference is the
     * counter's to settle. An order billed at ordering time that had nothing
     * priced and now does is billed now.
     */
    public function onLabOrderChanged(LabOrder $order): ?Invoice
    {
        $invoice = Invoice::query()->liveForLabOrder($order->id)->first();

        if (! $invoice) {
            return $order->status === LabOrder::CANCELLED
                ? null
                : $this->lab($order, BillingSetting::TRIGGER_LAB_ORDER);
        }

        $lines = $this->labLines($order);

        if ($lines === []) {
            if (! $invoice->hasMoneyMoved()) {
                return $this->billing->cancel($invoice, "Nothing left to charge on {$order->order_number}.");
            }

            return $invoice;
        }

        return $this->billing->resync($invoice, $lines, "Tests on {$order->order_number} changed.") ?? $invoice;
    }

    /**
     * The order was withdrawn.
     *
     * Its bill is cancelled with it while nothing has been paid. A bill that
     * has taken money is left standing: handing money back is a refund,
     * which somebody has to make and which leaves its own record.
     */
    public function onLabOrderCancelled(LabOrder $order, string $reason): ?Invoice
    {
        $invoice = Invoice::query()->liveForLabOrder($order->id)->first();

        if (! $invoice || $invoice->hasMoneyMoved()) {
            return $invoice;
        }

        return $this->billing->cancel($invoice, mb_substr("{$order->order_number} cancelled: {$reason}", 0, 500));
    }

    private function lab(LabOrder $order, string $event): ?Invoice
    {
        if (! $this->runs('laboratory', $order->location_id) || ! $this->runs('billing', $order->location_id)) {
            return null;
        }

        if (BillingSetting::current()->labTrigger() !== $event) {
            return null;
        }

        return $this->billLab($order, $event);
    }

    private function billLab(LabOrder $order, string $trigger): ?Invoice
    {
        $existing = Invoice::query()->liveForLabOrder($order->id)->first();

        if ($existing) {
            return $existing;
        }

        $lines = $this->labLines($order);

        if ($lines === []) {
            return null;
        }

        try {
            return $this->billing->draw([
                'location_id' => $order->location_id,
                'customer_id' => $order->customer_id,
                'appointment_id' => $order->appointment_id,
                'consultation_id' => $order->consultation_id,
                'lab_order_id' => $order->id,
                'doctor_id' => $order->doctor_id,
                'kind' => Invoice::KIND_LAB,
                'trigger' => $trigger,
            ], $lines);
        } catch (UniqueConstraintViolationException) {
            return Invoice::query()->liveForLabOrder($order->id)->first();
        }
    }

    /**
     * The order's priced tests, at the price taken when they were ordered.
     *
     * Never the catalogue as it reads now — a test repriced next month must
     * not rewrite what this patient was charged. Freehand lines carry no
     * price and bill nothing; cancelled tests are not work anybody owes for.
     *
     * @return list<array<string, mixed>>
     */
    private function labLines(LabOrder $order): array
    {
        return LabOrderItem::query()
            ->where('lab_order_id', $order->id)
            ->whereNot('status', LabOrderItem::CANCELLED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (LabOrderItem $item) => $item->isBillable())
            ->map(fn (LabOrderItem $item) => [
                'source_type' => InvoiceItem::SOURCE_LAB_TEST,
                'source_id' => $item->id,
                'description' => sprintf('Lab — %s', $item->test_name),
                'quantity' => 1,
                'unit_price' => round((float) $item->price_snapshot, 2),
                'tax_percent' => (float) ($item->tax_percent_snapshot ?? 0),
            ])
            ->values()
            ->all();
    }

    /* ===================================================================
     | REGISTRATION
     |===================================================================*/

    /**
     * A patient's record has just been opened, and the desk charges for it.
     *
     * ITS OWN INVOICE, not part of any visit. Null when the organisation
     * charges nothing to register, which is most of them.
     */
    public function onPatientRegistered(Customer $customer, int $locationId): ?Invoice
    {
        if (! $this->runs('billing', $locationId)) {
            return null;
        }

        $lines = [];

        foreach ($this->priceList($locationId, [BillableService::KIND_REGISTRATION]) as $service) {
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

    /* ---------------------------------------------------------- plumbing */

    /**
     * Whether a module runs at this branch.
     *
     * Reads the organisation off the request that started this — every
     * trigger fires inside a tenant HTTP request, where
     * ResolveTenantFromSession has already put it. Outside one (a test, an
     * artisan job) the schema is trusted: the tables are per-organisation, so
     * one that migrated at all has the module.
     */
    private function runs(string $module, ?int $locationId): bool
    {
        $organization = request()->attributes->get('tenant.organization');

        if ($organization instanceof Organization) {
            return app(Permission::class)->hasModule($organization, $module, $locationId);
        }

        return true;
    }
}

<?php

namespace App\Services\Documents;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillableService;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\Prescription;
use App\Support\Documents\DocumentCategories;
use App\Support\Documents\DocumentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * What a document's placeholders actually become.
 *
 * Reads the record, not a cache of it: a prescription's medicines come from
 * `prescription_items` at the moment of printing, and a bill's lines from the
 * sale. Nothing is precomputed, because a document printed from stale figures
 * is worse than one that is slow.
 *
 * SNAPSHOT COLUMNS WIN where they exist. `prescription_items` keeps
 * `medicine_name_snapshot` and `pharmacy_sale_items` keeps
 * `item_name_snapshot` precisely so a document reprinted after the catalogue
 * was renamed still says what was actually prescribed or sold. Following the
 * live relation instead would quietly rewrite history — the same mistake this
 * whole module's versioning exists to prevent.
 *
 * Returns two things: `values` (a token → string map) and `tables` (token →
 * rows), because one placeholder that becomes many lines is the difference
 * between a prescription and a letter.
 */
class DocumentPayload
{
    /**
     * @return array{values: array<string, string>, tables: array<string, array{columns: list<string>, rows: list<list<string>>}>}
     */
    public function build(
        string $documentType,
        Model|int $subject,
        ?Location $branch,
        Organization $organization,
    ): array {
        $type = DocumentTypes::find($documentType);

        if ($type === null) {
            return ['values' => [], 'tables' => []];
        }

        $values = [
            ...$this->organizationValues($organization),
            ...$this->branchValues($branch),
            ...$this->documentValues(),
        ];

        $tables = [];

        match ($type['subject']) {
            DocumentTypes::SUBJECT_CUSTOMER => $this->fromCustomer($subject, $documentType, $values, $tables),
            DocumentTypes::SUBJECT_APPOINTMENT => $this->fromAppointment($subject, $values),
            DocumentTypes::SUBJECT_PRESCRIPTION => $this->fromPrescription($subject, $values, $tables),
            DocumentTypes::SUBJECT_SALE => $this->fromSale($subject, $values, $tables),
            DocumentTypes::SUBJECT_INVOICE => $this->fromInvoice($subject, $values, $tables),
            default => null,
        };

        return ['values' => $values, 'tables' => $tables];
    }

    /** @param array<string, string> $values */
    private function organizationValues(Organization $organization): array
    {
        return ['organization_name' => (string) $organization->organization_name];
    }

    /** @return array<string, string> */
    private function branchValues(?Location $branch): array
    {
        if (! $branch) {
            return [];
        }

        $address = collect([
            $branch->address,
            $branch->city,
            $branch->state,
            $branch->pincode,
        ])->filter()->implode(', ');

        return [
            'branch_name' => (string) $branch->name,
            'branch_address' => $address,
            'branch_phone' => (string) ($branch->phone ?? ''),
            'branch_email' => (string) ($branch->email ?? ''),
            'branch_gstin' => (string) ($branch->gstin ?? ''),
            'branch_licence_no' => (string) ($branch->drug_license_no ?? ''),
        ];
    }

    /** @return array<string, string> */
    private function documentValues(): array
    {
        return [
            'generated_on' => now()->format('d M Y, g:i A'),
            'generated_by' => (string) (Auth::guard('web')->user()?->name ?? ''),
            // Filled in by DocumentService once the number is allocated.
            'document_number' => '',
        ];
    }

    /** @return array<string, string> */
    private function patientValues(?Customer $patient): array
    {
        if (! $patient) {
            return [];
        }

        $address = collect([
            $patient->address,
            $patient->city,
            $patient->district,
            $patient->state,
            $patient->pincode,
        ])->filter()->implode(', ');

        return [
            'patient_name' => (string) $patient->name,
            'patient_id' => (string) ($patient->code ?? ''),
            'patient_mobile' => (string) ($patient->phone ?? ''),
            // Worked out per print rather than stored: a stored age is wrong
            // on a birthday, and a document carries the date it was printed.
            'patient_age' => (string) ($patient->date_of_birth?->age ?? ''),
            'patient_gender' => (string) ($patient->gender ?? ''),
            'patient_address' => $address,
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function fromCustomer(mixed $subject, string $type, array &$values, array &$tables): void
    {
        $patient = $subject instanceof Customer
            ? $subject
            : Customer::find($subject);

        $values = [...$values, ...$this->patientValues($patient)];

        if ($type !== 'document_cover' || ! $patient) {
            return;
        }

        /*
         * A cover sheet lists what is on file. Administrative documents are
         * listed by name; the clinical ones are COUNTED rather than named —
         * a cover sheet that spells out "psychiatric discharge summary" is
         * itself a disclosure, and it is handed across a counter.
         */
        $documents = PatientDocument::query()
            ->where('customer_id', $patient->getKey())
            ->latest()
            ->get();

        $rows = [];

        foreach ($documents as $document) {
            $clinical = DocumentCategories::isClinical((string) $document->category);

            $rows[] = [
                $clinical ? DocumentCategories::name((string) $document->category) : $document->title,
                DocumentCategories::name((string) $document->category),
                $document->created_at?->format('d M Y') ?? '',
            ];
        }

        $tables['document_list'] = [
            'columns' => ['Document', 'Kind', 'Added'],
            'rows' => $rows,
        ];
    }

    /** @param array<string, string> $values */
    private function fromAppointment(mixed $subject, array &$values): void
    {
        $appointment = $subject instanceof Appointment
            ? $subject
            : Appointment::with(['customer', 'doctor', 'consultation'])->find($subject);

        if (! $appointment) {
            return;
        }

        $appointment->loadMissing(['customer', 'doctor', 'consultation']);

        $values = [...$values, ...$this->patientValues($appointment->customer)];

        $values['visit_id'] = (string) $appointment->getKey();
        $values['visit_date'] = $appointment->appointment_date?->format('d M Y') ?? '';
        $values['doctor_name'] = (string) ($appointment->doctor?->name ?? '');
        $values['doctor_registration_number'] = (string) ($appointment->doctor?->registration_no ?? '');

        $consultation = $appointment->consultation;

        if (! $consultation) {
            return;
        }

        $values['symptoms'] = (string) ($consultation->chief_complaint ?? '');
        $values['diagnosis'] = implode(', ', $consultation->diagnoses ?? []);
        $values['advice'] = (string) ($consultation->advice ?? '');
        $values['vitals'] = $this->vitals($consultation->vitals ?? []);
    }

    /** "BP 118/76 · Pulse 82 · Temp 37.4°C" — only what was actually measured. */
    private function vitals(array $vitals): string
    {
        $parts = [];

        if (($vitals['bp_systolic'] ?? null) && ($vitals['bp_diastolic'] ?? null)) {
            $parts[] = "BP {$vitals['bp_systolic']}/{$vitals['bp_diastolic']}";
        }

        foreach ([
            'pulse' => ['Pulse', '/min'],
            'temperature' => ['Temp', '°C'],
            'spo2' => ['SpO₂', '%'],
            'weight' => ['Weight', 'kg'],
            'height' => ['Height', 'cm'],
        ] as $key => [$label, $unit]) {
            if (($vitals[$key] ?? null) !== null && $vitals[$key] !== '') {
                $parts[] = "{$label} {$vitals[$key]}{$unit}";
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function fromPrescription(mixed $subject, array &$values, array &$tables): void
    {
        $prescription = $subject instanceof Prescription
            ? $subject
            : Prescription::with(['customer', 'doctor', 'items', 'appointment.consultation'])->find($subject);

        if (! $prescription) {
            return;
        }

        $prescription->loadMissing(['customer', 'doctor', 'items', 'appointment.consultation']);

        $values = [...$values, ...$this->patientValues($prescription->customer)];

        $values['prescription_number'] = (string) ($prescription->prescription_number ?? '');
        $values['prescription_date'] = $prescription->prescription_date?->format('d M Y') ?? '';
        $values['doctor_name'] = (string) ($prescription->doctor?->name ?? '');
        $values['doctor_registration_number'] = (string) ($prescription->doctor?->registration_no ?? '');
        $values['visit_id'] = (string) ($prescription->appointment_id ?? '');
        $values['visit_date'] = $prescription->prescription_date?->format('d M Y') ?? '';

        $consultation = $prescription->appointment?->consultation;

        if ($consultation) {
            $values['symptoms'] = (string) ($consultation->chief_complaint ?? '');
            $values['diagnosis'] = implode(', ', $consultation->diagnoses ?? []);
            $values['advice'] = (string) ($consultation->advice ?? '');
            $values['vitals'] = $this->vitals($consultation->vitals ?? []);
        }

        $rows = [];

        foreach ($prescription->items as $item) {
            $rows[] = [
                // The SNAPSHOT, not the live catalogue name — see the header.
                trim(($item->medicine_name_snapshot ?? '').' '.($item->strength_snapshot ?? '')),
                $this->dosage($item),
                (string) ($item->frequency ?? ''),
                trim(($item->duration ?? '').' '.($item->duration_unit ?? '')),
                (string) ($item->instructions ?? $item->food_timing ?? ''),
            ];
        }

        $tables['prescription_items'] = [
            'columns' => ['Medicine', 'Dose', 'Frequency', 'Duration', 'Notes'],
            'rows' => $rows,
        ];
    }

    /** "1-0-1" where the four slots are set, otherwise the written dose. */
    private function dosage(mixed $item): string
    {
        $slots = [$item->morning, $item->afternoon, $item->evening, $item->night];

        if (collect($slots)->filter(fn ($slot) => $slot !== null && $slot !== '')->isNotEmpty()) {
            return collect($slots)->map(fn ($slot) => $slot ?: '0')->implode('-');
        }

        return trim(($item->dose_amount ?? '').' '.($item->dose_unit ?? ''));
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function fromSale(mixed $subject, array &$values, array &$tables): void
    {
        $sale = $subject instanceof PharmacySale
            ? $subject
            : PharmacySale::with(['customer', 'items', 'payments', 'doctor'])->find($subject);

        if (! $sale) {
            return;
        }

        $sale->loadMissing(['customer', 'items.service', 'payments', 'doctor']);

        $values = [...$values, ...$this->patientValues($sale->customer)];

        // A walk-in has no customer record, and the bill still has to name
        // whoever it was made out to.
        if (! $sale->customer && $sale->walk_in_name) {
            $values['patient_name'] = (string) $sale->walk_in_name;
            $values['patient_mobile'] = (string) ($sale->walk_in_phone ?? '');
        }

        $paid = (float) $sale->paid_amount;
        $total = (float) $sale->total_amount;

        $values['invoice_number'] = (string) ($sale->sale_number ?? '');
        $values['invoice_date'] = $sale->sale_date?->format('d M Y') ?? '';
        $values['doctor_name'] = (string) ($sale->doctor?->name ?? '');
        $values['subtotal'] = $this->money($sale->subtotal);
        $values['discount'] = $this->money($sale->discount_amount);
        $values['tax'] = $this->money($sale->tax_amount);
        $values['total'] = $this->money($total);
        $values['amount_paid'] = $this->money($paid);
        $values['balance'] = $this->money(max(0, $total - $paid));

        $rows = [];

        foreach ($sale->items as $item) {
            $rows[] = [
                (string) $item->item_name_snapshot,
                (string) ($item->batch_number_snapshot ?? ''),
                (string) $item->quantity,
                $this->money($item->unit_price),
                $this->money($item->line_total),
            ];
        }

        $tables['invoice_items'] = [
            'columns' => ['Item', 'Batch', 'Qty', 'Rate', 'Amount'],
            'rows' => $rows,
        ];

        /*
         * HOW IT WAS SETTLED — the same panel a clinic invoice prints.
         *
         * A pharmacy bill carried none of this, so the two documents the
         * counter actually hands over were the two with no payment mode, no
         * reference and no PAID stamp on them. The state is worked out from
         * what was tendered against what was owed rather than read from a
         * column, because a sale has no `payment_status` of its own.
         */
        $settled = $sale->payments->sortByDesc('paid_at')->first();

        if ($settled) {
            $values['payment_method'] = ucfirst(str_replace('_', ' ', (string) $settled->method));
            $values['payment_reference'] = (string) ($settled->reference ?? '');
            $values['payment_date'] = $settled->paid_at?->format('d M Y, g:i A') ?? '';
        }

        $values['payment_state'] = match (true) {
            $paid >= $total && $total > 0 => 'PAID',
            $paid > 0 => 'PART PAID',
            default => 'UNPAID',
        };
    }

    /**
     * Fill in a CLINIC invoice — a consultation, a procedure, a service.
     *
     * A different subject from a pharmacy sale: the same `invoice`
     * placeholder group, but the row is `invoices` (with `invoice_items` on
     * it), and the columns on the printed table are what a doctor's bill
     * shows — no batch, no medicine, just what was charged for.
     *
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $tables
     */
    private function fromInvoice(mixed $subject, array &$values, array &$tables): void
    {
        $invoice = $subject instanceof Invoice
            ? $subject
            : Invoice::with(['customer', 'items', 'payments', 'doctor'])->find($subject);

        if (! $invoice) {
            return;
        }

        $invoice->loadMissing(['customer', 'items.service', 'payments', 'doctor']);

        $values = [...$values, ...$this->patientValues($invoice->customer)];

        // A walk-in with no register record — the bill still names them.
        if (! $invoice->customer && $invoice->walk_in_name) {
            $values['patient_name'] = (string) $invoice->walk_in_name;
            $values['patient_mobile'] = (string) ($invoice->walk_in_phone ?? '');
        }

        $paid = (float) $invoice->paid_amount;
        $total = (float) $invoice->total_amount;

        $values['invoice_number'] = (string) ($invoice->invoice_number ?? '');
        $values['invoice_date'] = $invoice->invoice_date?->format('d M Y') ?? '';
        $values['doctor_name'] = (string) ($invoice->doctor?->name ?? '');
        $values['subtotal'] = $this->money($invoice->subtotal);
        $values['discount'] = $this->money($invoice->discount_amount);
        $values['tax'] = $this->money($invoice->tax_amount);
        $values['total'] = $this->money($total);
        $values['amount_paid'] = $this->money($paid);
        $values['balance'] = $this->money(max(0, $total - $paid));

        /*
         * Lines GROUPED BY WHERE THEY CAME FROM, with a heading per group.
         *
         * A consolidated bill carries a consultation, a test and a strip of
         * tablets, and a flat list of them reads as a shopping receipt. The
         * headings are what let somebody check "was I charged for the lab?"
         * without reading every row — which is the question a patient
         * actually asks at the counter.
         *
         * A group with nothing in it is absent rather than empty: a clinic
         * with no pharmacy should not print a "Pharmacy" heading over
         * nothing.
         */
        $groups = [
            'registration' => ['title' => 'Registration', 'rows' => []],
            'consultation' => ['title' => 'Consultation', 'rows' => []],
            'laboratory' => ['title' => 'Laboratory', 'rows' => []],
            'procedure' => ['title' => 'Procedures', 'rows' => []],
            'pharmacy' => ['title' => 'Pharmacy', 'rows' => []],
            'other' => ['title' => 'Other services', 'rows' => []],
        ];

        $index = 0;

        foreach ($invoice->items as $item) {
            $index++;

            $key = match ($item->source_type) {
                InvoiceItem::SOURCE_CONSULTATION => 'consultation',
                InvoiceItem::SOURCE_LAB_TEST => 'laboratory',
                InvoiceItem::SOURCE_PHARMACY_SALE_ITEM => 'pharmacy',
                InvoiceItem::SOURCE_PROCEDURE => 'procedure',
                default => $item->service?->kind === BillableService::KIND_REGISTRATION
                    ? 'registration'
                    : 'other',
            };

            $groups[$key]['rows'][] = [
                (string) $index,
                (string) $item->description,
                /* HSN/SAC where the line has one. Pharmacy lines carry it
                   from the sale; a consultation has no such code, and an
                   invented one on a tax document is worse than a blank. */
                (string) ($item->hsn_code ?? ''),
                rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                $this->money($item->unit_price),
                (float) $item->tax_percent > 0
                    ? rtrim(rtrim(number_format((float) $item->tax_percent, 2, '.', ''), '0'), '.').'%'
                    : '0%',
                $this->money($item->line_total),
            ];
        }

        $tables['invoice_items'] = [
            'columns' => ['#', 'Service / item', 'HSN/SAC', 'Qty', 'Rate', 'Tax', 'Amount'],
            'rows' => array_merge(...array_map(
                fn (array $group) => $group['rows'],
                array_values($groups),
            )),
            /* The renderer draws a heading row before each group's first
               line; a flat `rows` is still there for anything that cannot. */
            'groups' => array_values(array_filter(
                $groups,
                fn (array $group) => $group['rows'] !== [],
            )),
        ];

        /* The payment panel: how it was settled, and whether it is. */
        $settled = $invoice->payments->where('is_refund', false)->sortByDesc('paid_at')->first();

        if ($settled) {
            $values['payment_method'] = ucfirst(str_replace('_', ' ', (string) $settled->method));
            $values['payment_reference'] = (string) ($settled->reference ?? '');
            $values['payment_date'] = $settled->paid_at?->format('d M Y, g:i A') ?? '';
        }

        $values['payment_state'] = match ($invoice->payment_status) {
            Invoice::PAID => 'PAID',
            Invoice::PARTIAL => 'PART PAID',
            default => 'UNPAID',
        };
    }

    private function money(mixed $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}

<?php

namespace App\Support\Documents;

/**
 * The documents this software can print, and what each one is made of.
 *
 * Code is authoritative, the same way ModuleRegistry is about capabilities and
 * DocumentCategories is about who may read a file. A document type is a
 * property of what the software can actually assemble — a template editor
 * offering "Lab report" in a system with no lab module would be offering a
 * document that can never be generated, which is how a settings screen starts
 * lying.
 *
 * So each type declares `requires`, and a type whose module is not running
 * here does not appear at all. The remaining ones the brief asked for are
 * DELIBERATELY ABSENT until their modules exist:
 *
 *   Lab report         needs a lab module
 *   Discharge summary  needs an admissions concept
 *
 * Adding them later is adding an entry here, not a rewrite — which is the
 * point of the registry.
 *
 * `clinic_invoice` was added when Billing shipped: it is the clinic's own
 * invoice — a consultation, a lab charge, a procedure — printed against an
 * Invoice row rather than a PharmacySale. `pharmacy_invoice` and
 * `payment_receipt` are the shop's own bills and stay pointed at
 * PharmacySale; the two document types coexist for the same visit if both
 * modules run.
 *
 * `category` is the OTHER half, and a different question: it decides who may
 * READ the generated file, through DocumentCategories. A generated
 * prescription files under the `prescription` category and needs
 * `documents.view_clinical` to open, exactly as a scanned one does — the fact
 * that this system produced it does not make it less medical.
 */
class DocumentTypes
{
    /** What a document hangs off — the record its data is read from. */
    public const SUBJECT_CUSTOMER = 'customer';

    public const SUBJECT_APPOINTMENT = 'appointment';

    public const SUBJECT_PRESCRIPTION = 'prescription';

    public const SUBJECT_SALE = 'pharmacy_sale';

    /** The clinic's own invoice — an Invoice row from the billing module. */
    public const SUBJECT_INVOICE = 'invoice';

    /** Paper sizes the renderer knows. */
    public const PAPER_A4 = 'a4';

    public const PAPER_A5 = 'a5';

    public const PAPER_RECEIPT = 'receipt';

    public const PAPERS = [self::PAPER_A4, self::PAPER_A5, self::PAPER_RECEIPT];

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     icon: string,
     *     subject: string,
     *     category: string,
     *     paper: string,
     *     requires: list<string>,
     *     groups: list<string>,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'patient_registration',
                'name' => 'Patient registration',
                'description' => 'The form a patient signs when their record is opened.',
                'icon' => 'ti ti-user-plus',
                'subject' => self::SUBJECT_CUSTOMER,
                /*
                 * Administrative: it carries who somebody is and how to reach
                 * them, and nothing about their health — which is why a desk
                 * may print and re-read it.
                 */
                'category' => 'registration',
                'paper' => self::PAPER_A4,
                'requires' => ['customers'],
                'groups' => ['organization', 'branch', 'patient'],
            ],
            [
                'key' => 'prescription',
                'name' => 'Prescription',
                'description' => 'What the doctor prescribed, to hand to the patient.',
                'icon' => 'ti ti-prescription',
                'subject' => self::SUBJECT_PRESCRIPTION,
                'category' => 'prescription',
                'paper' => self::PAPER_A5,
                'requires' => ['prescriptions'],
                'groups' => ['organization', 'branch', 'patient', 'visit', 'clinical', 'prescription'],
            ],
            [
                'key' => 'consultation_summary',
                'name' => 'Consultation summary',
                'description' => 'What was found in the room — complaint, diagnosis, advice.',
                'icon' => 'ti ti-clipboard-text',
                'subject' => self::SUBJECT_APPOINTMENT,
                'category' => 'clinical_note',
                'paper' => self::PAPER_A4,
                'requires' => ['appointments'],
                'groups' => ['organization', 'branch', 'patient', 'visit', 'clinical'],
            ],
            [
                /*
                 * The CLINIC's invoice — an OPD consultation, a procedure, an
                 * added service. Requires the billing module (a core module,
                 * so effectively always available); prints from an Invoice
                 * row rather than a PharmacySale.
                 *
                 * Placed BEFORE pharmacy_invoice in the registry so the
                 * template list shows the clinic bill first, which is the
                 * common case in an OPD.
                 */
                'key' => 'clinic_invoice',
                'name' => 'Clinic invoice',
                'description' => 'The bill for a consultation, procedure or service — for the patient.',
                'icon' => 'ti ti-receipt',
                'subject' => self::SUBJECT_INVOICE,
                'category' => 'invoice',
                /*
                 * A4, unlike the pharmacy's own bill.
                 *
                 * A consolidated clinic invoice is a tax document carrying
                 * seven columns and a heading per department — registration,
                 * consultation, laboratory, pharmacy. On A5 a six-line bill
                 * already spills onto a second sheet that holds the payment
                 * panel and nothing else, and a real one runs longer. A5
                 * suits a prescription slip; this is not one.
                 */
                'paper' => self::PAPER_A4,
                'requires' => ['billing'],
                'groups' => ['organization', 'branch', 'patient', 'visit', 'invoice'],
            ],
            [
                'key' => 'pharmacy_invoice',
                'name' => 'Pharmacy invoice',
                'description' => 'The bill for a counter sale.',
                'icon' => 'ti ti-receipt',
                'subject' => self::SUBJECT_SALE,
                'category' => 'invoice',
                'paper' => self::PAPER_A5,
                'requires' => ['pharmacy'],
                'groups' => ['organization', 'branch', 'patient', 'invoice'],
            ],
            [
                'key' => 'payment_receipt',
                'name' => 'Payment receipt',
                'description' => 'Proof that money was taken, for the counter.',
                'icon' => 'ti ti-cash',
                'subject' => self::SUBJECT_SALE,
                'category' => 'invoice',
                /*
                 * A till roll, not a page. The renderer treats this as a
                 * narrow continuous strip rather than a paper size with
                 * margins — see PdfRenderer.
                 */
                'paper' => self::PAPER_RECEIPT,
                'requires' => ['pharmacy'],
                'groups' => ['organization', 'branch', 'patient', 'invoice'],
            ],
            [
                'key' => 'document_cover',
                'name' => 'Document cover sheet',
                'description' => 'A front sheet listing what is on a patient’s file.',
                'icon' => 'ti ti-file-stack',
                'subject' => self::SUBJECT_CUSTOMER,
                /*
                 * Clinical, because a LIST of somebody's medical documents is
                 * itself medical information — "this patient has a psychiatric
                 * discharge summary on file" is the thing a cover sheet says
                 * out loud.
                 */
                'category' => 'clinical_note',
                'paper' => self::PAPER_A4,
                'requires' => ['customers'],
                'groups' => ['organization', 'branch', 'patient'],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $type) {
            if ($type['key'] === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The types usable where these modules are running.
     *
     * @param  list<string>  $modules
     * @return list<array<string, mixed>>
     */
    public static function available(array $modules): array
    {
        return array_values(array_filter(
            self::all(),
            fn (array $type) => array_diff($type['requires'], $modules) === [],
        ));
    }

    public static function supports(string $key): bool
    {
        return self::find($key) !== null;
    }

    /** Which category a document of this type files under once generated. */
    public static function categoryFor(string $key): string
    {
        return self::find($key)['category'] ?? 'other';
    }
}

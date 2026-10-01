<?php

namespace App\Support\Clinic;

/**
 * The things that happen in a clinic which something else may want to react to.
 *
 * CODE-AUTHORITATIVE, exactly like ModuleRegistry and DocumentTypes. An event
 * key is not data an organisation can add: every key here exists because a
 * service somewhere calls it at a known point in a known transaction. A row in
 * a table naming an event nothing dispatches would be a rule that silently
 * never fires, which is worse than a rule that cannot be made at all.
 *
 * KEYS ARE A PUBLIC CONTRACT. Once an organisation has a rule pointing at
 * `payment_received`, that string is stored in their tenant database. Rename
 * the constant freely; never rename its value.
 *
 * ONLY WHAT IS ACTUALLY WIRED. Every key below has a real call site in a real
 * service — see the `at` note on each. The tempting thing is to declare the
 * full vocabulary up front (lab_report_finalized, appointment_confirmed, and
 * the rest) so the configuration screen looks complete; that would offer
 * clinics switches connected to nothing.
 */
final class ClinicEvents
{
    /** A bill became payable — drawn already final, or a draft finalized. */
    public const INVOICE_CREATED = 'invoice_created';

    /** Money was taken against an invoice. Always fires on a payment. */
    public const PAYMENT_RECEIVED = 'payment_received';

    /** ...and the invoice still owes something afterwards. */
    public const PAYMENT_PARTIALLY_RECEIVED = 'payment_partially_received';

    /** ...and the invoice now owes nothing. */
    public const PAYMENT_FULLY_RECEIVED = 'payment_fully_received';

    /** Money went back to the patient. */
    public const REFUND_PROCESSED = 'refund_processed';

    /**
     * A prescription was signed off and is the pharmacy's to dispense.
     *
     * DELIBERATELY NOT `prescription_created`. Creating one opens a draft the
     * doctor is still typing into; issuing it is the act that makes a
     * document worth printing or sending. A rule on "created" would WhatsApp
     * a patient a half-written prescription.
     */
    public const PRESCRIPTION_ISSUED = 'prescription_issued';

    /** A counter sale was rung up. */
    public const PHARMACY_SALE_COMPLETED = 'pharmacy_sale_completed';

    /**
     * Every event, with the module it belongs to and where it is dispatched.
     *
     * `module` is what a later configuration screen groups by, and what
     * decides whether an organisation is even offered the event: a clinic
     * without the pharmacy module has no use for a sale rule.
     *
     * `documents` is what a rule on the event may make — the document types
     * whose record the event actually carries. A payment carries its bill and
     * its own row, so it can make a receipt or a copy of the bill; it carries
     * no prescription, so it cannot make one. Offering anything else would be
     * a rule that fails every time it fires.
     *
     * @return array<string, array{label: string, module: string, at: string, documents: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::INVOICE_CREATED => [
                'label' => 'Invoice created',
                'module' => 'billing',
                'at' => 'BillingService::draw() / finalize()',
                'documents' => ['clinic_invoice'],
            ],
            self::PAYMENT_RECEIVED => [
                'label' => 'Payment received',
                'module' => 'billing',
                'at' => 'BillingService::recordPayment()',
                'documents' => ['clinic_receipt', 'clinic_invoice'],
            ],
            self::PAYMENT_PARTIALLY_RECEIVED => [
                'label' => 'Payment received, balance outstanding',
                'module' => 'billing',
                'at' => 'BillingService::recordPayment()',
                'documents' => ['clinic_receipt', 'clinic_invoice'],
            ],
            self::PAYMENT_FULLY_RECEIVED => [
                'label' => 'Invoice settled in full',
                'module' => 'billing',
                'at' => 'BillingService::recordPayment()',
                'documents' => ['clinic_receipt', 'clinic_invoice'],
            ],
            self::REFUND_PROCESSED => [
                'label' => 'Refund processed',
                'module' => 'billing',
                'at' => 'BillingService::refund()',
                'documents' => ['clinic_refund'],
            ],
            self::PRESCRIPTION_ISSUED => [
                'label' => 'Prescription issued',
                'module' => 'prescriptions',
                'at' => 'PrescriptionService::issue()',
                'documents' => ['prescription'],
            ],
            self::PHARMACY_SALE_COMPLETED => [
                'label' => 'Pharmacy sale completed',
                'module' => 'pharmacy',
                'at' => 'SalesService::sell()',
                'documents' => ['pharmacy_invoice', 'payment_receipt'],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function module(string $key): ?string
    {
        return self::all()[$key]['module'] ?? null;
    }

    /**
     * The document types a rule on this event may make.
     *
     * @return list<string>
     */
    public static function documentsFor(string $key): array
    {
        return self::all()[$key]['documents'] ?? [];
    }
}

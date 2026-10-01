<?php

namespace App\Support\Documents;

/**
 * The blanks a document template may leave, and what each one becomes.
 *
 * One list, used four ways: the chips that insert a placeholder, the sample
 * values a preview renders with, the tooltip that says what it will become,
 * and — the part that does not exist anywhere else in this codebase — the
 * VALIDATION that refuses a template naming a placeholder this document type
 * cannot fill.
 *
 * That last one is the point. `message_templates` has no such check, so a
 * WhatsApp template containing `{{patinet_name}}` saves happily and sends the
 * literal text to a patient. On a prescription that would be worse: a blank
 * where the dose should be is a document somebody acts on.
 *
 * Placeholders are grouped, and a document type declares which groups it has
 * (see DocumentTypes). An invoice has no `{{diagnosis}}` — not because
 * diagnosis is secret, but because nothing in a pharmacy sale knows it, and a
 * template that asked for it could only ever render a blank.
 */
class Placeholders
{
    /**
     * Every placeholder, by group.
     *
     * @return array<string, list<array{token: string, label: string, sample: string}>>
     */
    public static function groups(): array
    {
        return [
            'organization' => [
                ['token' => 'organization_name', 'label' => 'Organisation', 'sample' => 'CarePlus Healthcare'],
            ],

            'branch' => [
                ['token' => 'branch_name', 'label' => 'Branch', 'sample' => 'Gurgaon'],
                ['token' => 'branch_address', 'label' => 'Branch address', 'sample' => '14 Sector 44, Gurgaon, Haryana 122003'],
                ['token' => 'branch_phone', 'label' => 'Branch phone', 'sample' => '+91 124 456 7890'],
                ['token' => 'branch_email', 'label' => 'Branch email', 'sample' => 'gurgaon@careplus.in'],
                ['token' => 'branch_gstin', 'label' => 'GSTIN', 'sample' => '06AABCU9603R1ZM'],
                ['token' => 'branch_licence_no', 'label' => 'Drug licence', 'sample' => 'HR-GGN-20B-4471'],
            ],

            'patient' => [
                ['token' => 'patient_name', 'label' => 'Patient', 'sample' => 'Asha Verma'],
                ['token' => 'patient_id', 'label' => 'Patient ID', 'sample' => 'PT-00241'],
                ['token' => 'patient_mobile', 'label' => 'Mobile', 'sample' => '+91 98765 43210'],
                ['token' => 'patient_age', 'label' => 'Age', 'sample' => '34'],
                ['token' => 'patient_gender', 'label' => 'Gender', 'sample' => 'Female'],
                ['token' => 'patient_address', 'label' => 'Patient address', 'sample' => '22 Nehru Nagar, Noida'],
            ],

            'visit' => [
                ['token' => 'visit_id', 'label' => 'Visit', 'sample' => 'APT-01184'],
                ['token' => 'visit_date', 'label' => 'Visit date', 'sample' => '24 Sep 2026'],
                ['token' => 'doctor_name', 'label' => 'Doctor', 'sample' => 'Dr Neha Rao'],
                ['token' => 'doctor_registration_number', 'label' => 'Doctor reg. no.', 'sample' => 'DMC/R/2014/06621'],
            ],

            'clinical' => [
                ['token' => 'diagnosis', 'label' => 'Diagnosis', 'sample' => 'Acute pharyngitis'],
                ['token' => 'symptoms', 'label' => 'Complaint', 'sample' => 'Sore throat for four days, mild fever'],
                ['token' => 'vitals', 'label' => 'Vitals', 'sample' => 'BP 118/76 · Pulse 82 · Temp 37.4°C'],
                ['token' => 'advice', 'label' => 'Advice', 'sample' => 'Warm saline gargles; review in five days'],
            ],

            /*
             * A TABLE, not a line of text. The renderer expands this one into
             * rows — see PdfRenderer — because "one placeholder, many lines"
             * is the whole difference between a prescription and a letter.
             */
            'prescription' => [
                ['token' => 'prescription_items', 'label' => 'Medicines', 'sample' => '(the prescribed medicines, as a table)'],
                ['token' => 'prescription_number', 'label' => 'Prescription no.', 'sample' => 'RX-00412'],
                ['token' => 'prescription_date', 'label' => 'Prescribed on', 'sample' => '24 Sep 2026'],
            ],

            'invoice' => [
                ['token' => 'invoice_items', 'label' => 'Items', 'sample' => '(the billed items, as a table)'],
                ['token' => 'invoice_number', 'label' => 'Invoice no.', 'sample' => 'INV-00981'],
                ['token' => 'invoice_date', 'label' => 'Invoice date', 'sample' => '24 Sep 2026'],
                ['token' => 'subtotal', 'label' => 'Subtotal', 'sample' => '₹1,240.00'],
                ['token' => 'discount', 'label' => 'Discount', 'sample' => '₹40.00'],
                ['token' => 'tax', 'label' => 'GST', 'sample' => '₹59.05'],
                ['token' => 'total', 'label' => 'Total', 'sample' => '₹1,200.00'],
                ['token' => 'amount_paid', 'label' => 'Paid', 'sample' => '₹1,200.00'],
                ['token' => 'balance', 'label' => 'Balance', 'sample' => '₹0.00'],
            ],

            /*
             * ONE PAYMENT. On a receipt `amount_paid` is what was handed over
             * this time and `balance` is what was still owed straight after —
             * not the bill's running figures, which move with every later
             * instalment and would make a reprinted receipt disagree with the
             * one in the patient's hand.
             */
            'receipt' => [
                ['token' => 'receipt_number', 'label' => 'Receipt no.', 'sample' => 'GGN/RCP/26-27/00043'],
                ['token' => 'receipt_date', 'label' => 'Received on', 'sample' => '24 Sep 2026, 4:16 PM'],
                ['token' => 'payment_method', 'label' => 'Payment mode', 'sample' => 'UPI'],
                ['token' => 'payment_reference', 'label' => 'Payment reference', 'sample' => 'UPI1234567890'],
            ],

            /*
             * ONE REFUND, and the receipt it went back against. Its own tokens
             * rather than the receipt's: a template that says "Received
             * {{amount_paid}}" must not quietly print a refund as money in.
             */
            'refund' => [
                ['token' => 'refund_number', 'label' => 'Refund no.', 'sample' => 'GGN/RFD/26-27/00007'],
                ['token' => 'refund_date', 'label' => 'Refunded on', 'sample' => '26 Sep 2026, 11:05 AM'],
                ['token' => 'refund_amount', 'label' => 'Amount refunded', 'sample' => '₹300.00'],
                ['token' => 'refund_method', 'label' => 'Refund mode', 'sample' => 'UPI'],
                ['token' => 'refund_reason', 'label' => 'Reason', 'sample' => 'Procedure not performed'],
                ['token' => 'refunded_receipt_number', 'label' => 'Against receipt no.', 'sample' => 'GGN/RCP/26-27/00043'],
            ],
        ];
    }

    /**
     * Everything every document carries, whatever its type.
     *
     * Not a group a type opts into: a document with no date on it and no way
     * of telling which of two printed copies is the later one is not a
     * document anybody can file.
     *
     * @return list<array{token: string, label: string, sample: string}>
     */
    public static function universal(): array
    {
        return [
            ['token' => 'document_number', 'label' => 'Document no.', 'sample' => 'DOC-00117'],
            ['token' => 'generated_on', 'label' => 'Printed on', 'sample' => '24 Sep 2026, 4:18 PM'],
            ['token' => 'generated_by', 'label' => 'Printed by', 'sample' => 'Sunita Desai'],
        ];
    }

    /**
     * The placeholders one document type may use.
     *
     * @return list<array{token: string, label: string, sample: string, group: string}>
     */
    public static function forType(string $typeKey): array
    {
        $type = DocumentTypes::find($typeKey);

        if ($type === null) {
            return [];
        }

        $groups = self::groups();
        $result = [];

        foreach ($type['groups'] as $group) {
            foreach ($groups[$group] ?? [] as $placeholder) {
                $result[] = [...$placeholder, 'group' => $group];
            }
        }

        foreach (self::universal() as $placeholder) {
            $result[] = [...$placeholder, 'group' => 'document'];
        }

        return $result;
    }

    /**
     * Just the token names this type may use.
     *
     * @return list<string>
     */
    public static function tokensFor(string $typeKey): array
    {
        return array_column(self::forType($typeKey), 'token');
    }

    /**
     * Sample values, for rendering a preview with nobody's real data.
     *
     * Deliberately obvious samples rather than plausible ones — "Asha Verma"
     * against a real patient's name would be the one thing a preview of a
     * medical document must never look like.
     *
     * @return array<string, string>
     */
    public static function samplesFor(string $typeKey): array
    {
        $samples = [];

        foreach (self::forType($typeKey) as $placeholder) {
            $samples[$placeholder['token']] = $placeholder['sample'];
        }

        return $samples;
    }

    /**
     * Every `{{token}}` written anywhere in a template's config.
     *
     * Walks the whole structure rather than a named list of fields, so a
     * placeholder typed into a footer, a table caption or a custom line is
     * found the same way one in the header is — and adding a config field
     * later does not need this to be updated.
     *
     * @return list<string> token names, without the braces, in first-seen order
     */
    public static function used(mixed $config): array
    {
        $found = [];

        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (is_array($node)) {
                foreach ($node as $value) {
                    $walk($value);
                }

                return;
            }

            if (! is_string($node)) {
                return;
            }

            // Whitespace inside the braces is tolerated: somebody typing
            // `{{ patient_name }}` meant the placeholder, not literal text.
            if (preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $node, $matches)) {
                foreach ($matches[1] as $token) {
                    $found[] = mb_strtolower($token);
                }
            }
        };

        $walk($config);

        return array_values(array_unique($found));
    }

    /**
     * Which placeholders in this config the document type cannot fill.
     *
     * The check a template must pass before it can be activated. A blank
     * where a dose should be is a document somebody acts on.
     *
     * @return list<string>
     */
    public static function unknownIn(mixed $config, string $typeKey): array
    {
        $allowed = self::tokensFor($typeKey);

        return array_values(array_diff(self::used($config), $allowed));
    }
}

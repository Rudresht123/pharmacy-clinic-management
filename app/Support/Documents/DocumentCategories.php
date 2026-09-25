<?php

namespace App\Support\Documents;

/**
 * What a patient document can be, and who that makes it readable by.
 *
 * The categories are the point, not decoration. "Do not expose all patient
 * medical information to every role" cannot be enforced by a single
 * `documents.view` capability: a pharmacist needs the prescription in front of
 * them and has no business reading a psychiatric discharge summary, and both
 * are files hanging off the same patient.
 *
 * So each category declares its SENSITIVITY, and that decides which capability
 * a reader needs:
 *
 *   clinical        what the patient was found to have or was given —
 *                   `documents.view_clinical`
 *   administrative  who they are, who pays, what they consented to —
 *                   `documents.view`
 *
 * Code is authoritative, the same way ModuleRegistry is about capabilities:
 * a category's sensitivity is a property of what the document IS, not data an
 * administrator can lower. Sensitivity is therefore NOT stored on the row —
 * the row keeps the category and this class answers the rest, so a
 * re-classification applies to the files already uploaded rather than only to
 * the next ones.
 *
 * An unrecognised category reads as clinical. A category this class has never
 * heard of is one nobody has reasoned about, and guessing "administrative"
 * would be guessing in the direction that leaks.
 */
class DocumentCategories
{
    public const CLINICAL = 'clinical';

    public const ADMINISTRATIVE = 'administrative';

    /**
     * Every category, in the order a form should offer them.
     *
     * @return list<array{key: string, name: string, sensitivity: string, icon: string, tone: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'lab_report',
                'name' => 'Lab report',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-flask',
                'tone' => 'rose',
            ],
            [
                'key' => 'imaging',
                'name' => 'Scan or X-ray',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-photo',
                'tone' => 'blue',
            ],
            [
                'key' => 'prescription',
                'name' => 'Prescription',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-prescription',
                'tone' => 'emerald',
            ],
            [
                'key' => 'discharge_summary',
                'name' => 'Discharge summary',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-file-text',
                'tone' => 'amber',
            ],
            [
                'key' => 'referral_letter',
                'name' => 'Referral letter',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-file-description',
                'tone' => 'amber',
            ],
            [
                'key' => 'clinical_note',
                'name' => 'Clinical note',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-notes',
                'tone' => 'slate',
            ],

            /*
             * Administrative. None of these say anything about the patient's
             * health, which is exactly why a desk and a billing clerk may read
             * them while the six above stay shut.
             */
            [
                /*
                 * The form a patient signs when their record is opened — who
                 * they are and how to reach them, and nothing about their
                 * health. Administrative for exactly that reason: a desk has
                 * to be able to reprint it.
                 */
                'key' => 'registration',
                'name' => 'Registration form',
                'sensitivity' => self::ADMINISTRATIVE,
                'icon' => 'ti ti-user-plus',
                'tone' => 'slate',
            ],
            [
                'key' => 'id_proof',
                'name' => 'ID proof',
                'sensitivity' => self::ADMINISTRATIVE,
                'icon' => 'ti ti-id',
                'tone' => 'slate',
            ],
            [
                'key' => 'insurance',
                'name' => 'Insurance',
                'sensitivity' => self::ADMINISTRATIVE,
                'icon' => 'ti ti-shield-check',
                'tone' => 'blue',
            ],
            [
                'key' => 'consent_form',
                'name' => 'Consent form',
                'sensitivity' => self::ADMINISTRATIVE,
                'icon' => 'ti ti-writing-sign',
                'tone' => 'slate',
            ],
            [
                'key' => 'invoice',
                'name' => 'Bill or receipt',
                'sensitivity' => self::ADMINISTRATIVE,
                'icon' => 'ti ti-receipt',
                'tone' => 'emerald',
            ],

            /*
             * Last, and CLINICAL — see the note at the top. Somebody who could
             * not find the right category has told us nothing about what is in
             * the file, and the safe reading of nothing is "medical".
             */
            [
                'key' => 'other',
                'name' => 'Other',
                'sensitivity' => self::CLINICAL,
                'icon' => 'ti ti-file',
                'tone' => 'slate',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return array{key: string, name: string, sensitivity: string, icon: string, tone: string}|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $category) {
            if ($category['key'] === $key) {
                return $category;
            }
        }

        return null;
    }

    /** Unknown reads as clinical, deliberately. */
    public static function sensitivity(string $key): string
    {
        return self::find($key)['sensitivity'] ?? self::CLINICAL;
    }

    public static function isClinical(string $key): bool
    {
        return self::sensitivity($key) === self::CLINICAL;
    }

    public static function name(string $key): string
    {
        return self::find($key)['name'] ?? 'Other';
    }

    /**
     * The categories somebody may read, given whether they hold the clinical
     * capability.
     *
     * Used to narrow the QUERY rather than to filter a result set: a clinical
     * document must not be counted, paginated or its name returned to somebody
     * who may not open it.
     *
     * @return list<string>
     */
    public static function readableBy(bool $clinical): array
    {
        if ($clinical) {
            return self::keys();
        }

        return array_values(array_column(
            array_filter(self::all(), fn (array $c) => $c['sensitivity'] === self::ADMINISTRATIVE),
            'key',
        ));
    }
}

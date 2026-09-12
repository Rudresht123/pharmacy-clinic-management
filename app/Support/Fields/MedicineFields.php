<?php

namespace App\Support\Fields;

use App\Models\Tenant\Medicine;

/**
 * What a medicine record is made of.
 *
 * Locked: the generic name, the dosage form and the base unit — duplicate
 * detection, prescribing and stock counting all depend on them. Everything
 * else an organization can hide, relabel, reorder or make mandatory.
 *
 * The lists marked `fixed_options` mirror CHECK constraints on the table.
 * Their labels are the organization's to change; their values are not,
 * because a value outside the list is one the database refuses.
 */
class MedicineFields
{
    public const GROUP_IDENTITY = 'identity';

    public const GROUP_CLINICAL = 'clinical';

    public const GROUP_STOCK = 'stock';

    public const GROUP_SUPPLY = 'supply';

    public const GROUP_OTHER = 'other';

    private const DOSAGE_FORM_LABELS = [
        'tablet' => 'Tablet', 'capsule' => 'Capsule', 'syrup' => 'Syrup',
        'suspension' => 'Suspension', 'injection' => 'Injection', 'ointment' => 'Ointment',
        'cream' => 'Cream', 'drops' => 'Drops', 'inhaler' => 'Inhaler', 'powder' => 'Powder',
        'gel' => 'Gel', 'lotion' => 'Lotion', 'patch' => 'Patch',
        'suppository' => 'Suppository', 'other' => 'Other',
    ];

    private const ROUTE_LABELS = [
        'oral' => 'Oral', 'iv' => 'Intravenous (IV)', 'im' => 'Intramuscular (IM)',
        'sc' => 'Subcutaneous (SC)', 'topical' => 'Topical', 'inhalation' => 'Inhalation',
        'ophthalmic' => 'Eye (ophthalmic)', 'otic' => 'Ear (otic)', 'nasal' => 'Nasal',
        'rectal' => 'Rectal', 'vaginal' => 'Vaginal', 'sublingual' => 'Sublingual',
        'other' => 'Other',
    ];

    private const BASE_UNIT_LABELS = [
        'tablet' => 'Tablet', 'capsule' => 'Capsule', 'bottle' => 'Bottle', 'vial' => 'Vial',
        'ampoule' => 'Ampoule', 'tube' => 'Tube', 'sachet' => 'Sachet', 'unit' => 'Unit',
    ];

    private const SCHEDULE_LABELS = [
        'OTC' => 'OTC (over the counter)', 'G' => 'Schedule G', 'H' => 'Schedule H',
        'H1' => 'Schedule H1', 'X' => 'Schedule X',
    ];

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $values, array $labels): array
    {
        return array_map(
            fn (string $value) => ['value' => $value, 'label' => $labels[$value] ?? $value],
            $values,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'generic_name',
                'label' => 'Generic name',
                'placeholder' => 'Paracetamol',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'brand_name',
                'label' => 'Brand name',
                'placeholder' => 'Dolo 650',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'strength',
                'label' => 'Strength',
                'placeholder' => '650 mg',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'dosage_form',
                'label' => 'Dosage form',
                'placeholder' => 'Choose a form',
                'type' => 'select',
                'options' => self::options(Medicine::DOSAGE_FORMS, self::DOSAGE_FORM_LABELS),
                'fixed_options' => true,
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'medicine_code',
                'label' => 'Code',
                'placeholder' => 'MED-0001',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'route',
                'label' => 'Default route',
                'placeholder' => 'Choose a route',
                'type' => 'select',
                'options' => self::options(Medicine::ROUTES, self::ROUTE_LABELS),
                'fixed_options' => true,
                'group' => self::GROUP_CLINICAL,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'schedule',
                'label' => 'Drug schedule',
                'placeholder' => 'Choose a schedule',
                'type' => 'select',
                'options' => self::options(Medicine::SCHEDULES, self::SCHEDULE_LABELS),
                'fixed_options' => true,
                'group' => self::GROUP_CLINICAL,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'prescription_required',
                'label' => 'Prescription required',
                'type' => 'boolean',
                'group' => self::GROUP_CLINICAL,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            /*
             * What stock is counted and dispensed in, and how many of those a
             * purchase pack holds. Locked, because every quantity in the
             * pharmacy is a number of these.
             */
            [
                'key' => 'base_unit',
                'label' => 'Base unit',
                'placeholder' => 'Choose a unit',
                'type' => 'select',
                'options' => self::options(Medicine::BASE_UNITS, self::BASE_UNIT_LABELS),
                'fixed_options' => true,
                'group' => self::GROUP_STOCK,
                'required' => true,
                'locked' => true,
                'in_table' => false,
            ],
            [
                'key' => 'pack_size',
                'label' => 'Units per pack',
                'placeholder' => '10',
                'type' => 'number',
                'group' => self::GROUP_STOCK,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'manufacturer',
                'label' => 'Manufacturer',
                'placeholder' => 'Micro Labs',
                'type' => 'text',
                'group' => self::GROUP_SUPPLY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'category',
                'label' => 'Category',
                'placeholder' => 'Analgesic',
                'type' => 'text',
                'group' => self::GROUP_SUPPLY,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'description',
                'label' => 'Description',
                'placeholder' => 'Anything worth knowing about this medicine',
                'type' => 'textarea',
                'group' => self::GROUP_OTHER,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'is_active',
                'label' => 'Active',
                'type' => 'boolean',
                'group' => self::GROUP_OTHER,
                'required' => false,
                'locked' => true,
                'in_table' => true,
            ],
        ];
    }
}

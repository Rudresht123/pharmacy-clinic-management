<?php

namespace App\Support\Fields;

use App\Models\Tenant\Location;

/**
 * What a location is made of, described as data rather than as markup.
 *
 * The form and the table both render from this list instead of hardcoding
 * their fields, which is what lets a later pass put per-organization
 * configuration (hide a field, make one mandatory, add one of your own)
 * behind it without rewriting either screen.
 *
 * `locked` marks the fields the application itself depends on. An
 * organization may reorder or relabel the rest, but a location with no name
 * or no type is not a location, so those can never be hidden or made
 * optional.
 */
class LocationFields
{
    public const GROUP_IDENTITY = 'identity';
    public const GROUP_ADDRESS = 'address';
    public const GROUP_COMPLIANCE = 'compliance';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     placeholder?: string,
     *     type: string,
     *     group: string,
     *     required: bool,
     *     locked: bool,
     *     in_table: bool,
     *     options?: list<array{value: string, label: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Location name',
                'placeholder' => 'Main Street Pharmacy',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'code',
                'label' => 'Code',
                'placeholder' => 'MSP-01',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'type',
                'label' => 'Type',
                'type' => 'select',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
                'options' => self::typeOptions(),
            ],
            [
                'key' => 'is_active',
                'label' => 'Active',
                'type' => 'boolean',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => true,
                'in_table' => true,
            ],

            [
                'key' => 'address',
                'label' => 'Address',
                'placeholder' => 'Shop 12, Ground Floor, MG Road',
                'type' => 'textarea',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'city',
                'label' => 'City',
                'placeholder' => 'Pune',
                'type' => 'text',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'state',
                'label' => 'State',
                'placeholder' => 'Maharashtra',
                'type' => 'text',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'pincode',
                'label' => 'PIN code',
                'placeholder' => '411001',
                'type' => 'text',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'placeholder' => '98765 43210',
                'type' => 'text',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'placeholder' => 'store@yourpharmacy.in',
                'type' => 'email',
                'group' => self::GROUP_ADDRESS,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'gstin',
                'label' => 'GSTIN',
                'placeholder' => '27ABCDE1234F1Z5',
                'type' => 'text',
                'group' => self::GROUP_COMPLIANCE,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'drug_license_no',
                'label' => 'Drug licence number',
                'placeholder' => 'MH-PUN-123456',
                'type' => 'text',
                'group' => self::GROUP_COMPLIANCE,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'drug_license_expiry_date',
                'label' => 'Drug licence expiry',
                'type' => 'date',
                'group' => self::GROUP_COMPLIANCE,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
        ];
    }

    /**
     * The types a user may pick, as select options.
     *
     * Built from Location::SELECTABLE_TYPES, so the reserved CLINIC value the
     * database accepts never reaches a dropdown.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        $labels = [
            Location::RETAIL_STORE => 'Retail store',
            Location::WHOLESALE_STORE => 'Wholesale store',
            Location::WAREHOUSE => 'Warehouse',
            Location::DOCTOR_VISITING_LOCATION => 'Doctor visiting location',
        ];

        return array_map(
            fn (string $type) => ['value' => $type, 'label' => $labels[$type] ?? $type],
            Location::SELECTABLE_TYPES
        );
    }
}

<?php

namespace App\Support\Fields;

use App\Models\Tenant\Customer;

/**
 * What a customer record is made of.
 *
 * Only the name is locked — everything else an organization can hide,
 * relabel, reorder or make mandatory. A pharmacy that always takes a phone
 * number and a clinic that always needs a date of birth are configuring the
 * same screen differently, which is the point.
 */
class CustomerFields
{
    public const GROUP_IDENTITY = 'identity';
    public const GROUP_CONTACT = 'contact';
    public const GROUP_OTHER = 'other';

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Full name',
                'placeholder' => 'Rahul Verma',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'placeholder' => '98765 43210',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'date_of_birth',
                'label' => 'Date of birth',
                'type' => 'date',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'gender',
                'label' => 'Gender',
                'type' => 'select',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => false,
                'options' => self::genderOptions(),
            ],

            [
                'key' => 'registered_location_id',
                'label' => 'Registered at',
                'type' => 'select',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
                'options' => LocationOptions::all(),
            ],

            [
                'key' => 'email',
                'label' => 'Email',
                'placeholder' => 'rahul@example.com',
                'type' => 'email',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'address',
                'label' => 'Address',
                'placeholder' => 'Flat 4B, Green Residency',
                'type' => 'textarea',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'city',
                'label' => 'City',
                'placeholder' => 'Pune',
                'type' => 'text',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'state',
                'label' => 'State',
                'placeholder' => 'Maharashtra',
                'type' => 'text',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                'key' => 'pincode',
                'label' => 'PIN code',
                'placeholder' => '411001',
                'type' => 'text',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'notes',
                'label' => 'Notes',
                'placeholder' => 'Anything worth remembering about this customer',
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

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function genderOptions(): array
    {
        return [
            ['value' => Customer::MALE, 'label' => 'Male'],
            ['value' => Customer::FEMALE, 'label' => 'Female'],
            ['value' => Customer::OTHER, 'label' => 'Other'],
        ];
    }
}

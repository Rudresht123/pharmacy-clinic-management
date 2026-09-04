<?php

namespace App\Support\Fields;

/**
 * What a doctor record is made of.
 *
 * Only the name is locked. A single-doctor clinic that never records a
 * council number and a hospital that insists on one are configuring the same
 * screen differently, which is the point of the settings layer.
 *
 * Deliberately absent: anything about where the doctor works. That is the
 * schedule's business, and a field here could only ever hold one branch.
 */
class DoctorFields
{
    public const GROUP_IDENTITY = 'identity';

    public const GROUP_CONTACT = 'contact';

    public const GROUP_PRACTICE = 'practice';

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
                'placeholder' => 'Dr. Anjali Sharma',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'code',
                'label' => 'Code',
                'placeholder' => 'DR-01',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'specialisation',
                'label' => 'Specialisation',
                'placeholder' => 'General Physician',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'qualification',
                'label' => 'Qualification',
                'placeholder' => 'MBBS, MD',
                'type' => 'text',
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'phone',
                'label' => 'Phone',
                'placeholder' => '98765 43210',
                'type' => 'text',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'placeholder' => 'anjali@example.com',
                'type' => 'email',
                'group' => self::GROUP_CONTACT,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],

            [
                'key' => 'registration_no',
                'label' => 'Registration number',
                'placeholder' => 'Medical council registration',
                'type' => 'text',
                'group' => self::GROUP_PRACTICE,
                'required' => false,
                'locked' => false,
                'in_table' => false,
            ],
            [
                /*
                 * The fallback, not the price. Branch and visit-type pricing
                 * arrives with billing as rules that end at this value.
                 */
                'key' => 'default_consultation_fee',
                'label' => 'Default consultation fee',
                'placeholder' => '500',
                'type' => 'number',
                'group' => self::GROUP_PRACTICE,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],

            [
                'key' => 'notes',
                'label' => 'Notes',
                'placeholder' => 'Anything worth remembering about this doctor',
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

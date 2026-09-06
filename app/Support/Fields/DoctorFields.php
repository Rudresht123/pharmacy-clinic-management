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
    /**
     * A starting set of departments, not a closed one.
     *
     * Every organization edits these under Settings; they exist so a clinic
     * that has just signed up is not asked to invent a taxonomy before it can
     * add its first doctor.
     *
     * @var list<array{value: string, label: string}>
     */
    private const DEPARTMENTS = [
        ['value' => 'General Medicine', 'label' => 'General Medicine'],
        ['value' => 'General Physician', 'label' => 'General Physician'],
        ['value' => 'Paediatrics', 'label' => 'Paediatrics'],
        ['value' => 'Cardiology', 'label' => 'Cardiology'],
        ['value' => 'Orthopaedics', 'label' => 'Orthopaedics'],
        ['value' => 'Dermatology', 'label' => 'Dermatology'],
        ['value' => 'Gynaecology', 'label' => 'Gynaecology'],
        ['value' => 'ENT', 'label' => 'ENT'],
        ['value' => 'Ophthalmology', 'label' => 'Ophthalmology'],
        ['value' => 'Dentistry', 'label' => 'Dentistry'],
        ['value' => 'Psychiatry', 'label' => 'Psychiatry'],
    ];

    /**
     * The qualifications a clinic in this market actually writes down.
     *
     * @var list<array{value: string, label: string}>
     */
    private const QUALIFICATIONS = [
        ['value' => 'MBBS', 'label' => 'MBBS'],
        ['value' => 'MD', 'label' => 'MD'],
        ['value' => 'MS', 'label' => 'MS'],
        ['value' => 'DM', 'label' => 'DM'],
        ['value' => 'MCh', 'label' => 'MCh'],
        ['value' => 'DNB', 'label' => 'DNB'],
        ['value' => 'BDS', 'label' => 'BDS'],
        ['value' => 'MDS', 'label' => 'MDS'],
        ['value' => 'DCH', 'label' => 'DCH'],
        ['value' => 'DGO', 'label' => 'DGO'],
        ['value' => 'DO', 'label' => 'DO'],
        ['value' => 'BAMS', 'label' => 'BAMS'],
        ['value' => 'BHMS', 'label' => 'BHMS'],
    ];

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
            /*
             * The department, chosen from a list the organization keeps.
             *
             * Free text meant "Cardiology", "cardiology" and "Cardio" were
             * three departments as far as every count and filter was
             * concerned — and the OPD board groups the day by exactly this
             * field. The starter list is a default, not a fixture: it is
             * editable under Settings like any other option list.
             */
            [
                'key' => 'specialisation',
                'label' => 'Department',
                'placeholder' => 'Choose a department',
                'type' => 'select',
                'options' => self::DEPARTMENTS,
                'group' => self::GROUP_IDENTITY,
                'required' => false,
                'locked' => false,
                'in_table' => true,
            ],

            /*
             * Qualifications are a list. A doctor holds MBBS and MD and
             * sometimes a DM, and joining them into one string was why nobody
             * could filter on any of them.
             */
            [
                'key' => 'qualifications',
                'label' => 'Qualifications',
                'placeholder' => 'MBBS, MD',
                'type' => 'multiselect',
                'options' => self::QUALIFICATIONS,
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

<?php

namespace App\Support\Fields;

use App\Models\Tenant\User;

/**
 * What a person in the organization is made of.
 *
 * Almost everything here is locked, and deliberately: a user with no email
 * cannot sign in, and one with no role cannot be authorized. The value of
 * configuring this screen is in the fields an organization adds for itself
 * — employee code, phone, department, joining date — which is what the
 * `custom_fields` column is for.
 *
 * Password is absent on purpose. It is not a field like the others: it is
 * write-only, hashed, optional on edit, and validated by its own rules.
 */
class UserFields
{
    public const GROUP_PERSON = 'person';

    public const GROUP_ACCESS = 'access';

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Full name',
                'placeholder' => 'Priya Sharma',
                'type' => 'text',
                'group' => self::GROUP_PERSON,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'placeholder' => 'priya@yourpharmacy.in',
                'type' => 'email',
                'group' => self::GROUP_PERSON,
                'required' => true,
                'locked' => true,
                'in_table' => true,
            ],
            [
                'key' => 'role',
                'label' => 'Role',
                'type' => 'select',
                'group' => self::GROUP_ACCESS,
                'required' => true,
                'locked' => true,
                'in_table' => true,
                'options' => self::roleOptions(),
            ],
            [
                'key' => 'location_id',
                'label' => 'Branch',
                'type' => 'select',
                'group' => self::GROUP_ACCESS,
                'required' => false,
                'locked' => false,
                'in_table' => true,
                'options' => LocationOptions::all(),
            ],
            [
                'key' => 'is_active',
                'label' => 'Active',
                'type' => 'boolean',
                'group' => self::GROUP_ACCESS,
                'required' => false,
                'locked' => true,
                'in_table' => true,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function roleOptions(): array
    {
        return [
            ['value' => User::STAFF, 'label' => 'Staff — can use the workspace'],
            ['value' => User::OWNER, 'label' => 'Owner — can also manage settings and people'],
        ];
    }
}

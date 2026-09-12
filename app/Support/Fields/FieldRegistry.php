<?php

namespace App\Support\Fields;

use App\Models\Tenant\EntityFieldSetting;
use InvalidArgumentException;

/**
 * Which built-in fields each configurable screen has.
 *
 * One place that knows the entity keys, so adding a screen to the field
 * settings is a line here rather than a branch in every controller.
 */
class FieldRegistry
{
    /** entity => the class declaring its built-in fields. */
    private const REGISTRIES = [
        EntityFieldSetting::ENTITY_LOCATION => LocationFields::class,
        EntityFieldSetting::ENTITY_USER => UserFields::class,
        EntityFieldSetting::ENTITY_CUSTOMER => CustomerFields::class,
        EntityFieldSetting::ENTITY_DOCTOR => DoctorFields::class,
        EntityFieldSetting::ENTITY_MEDICINE => MedicineFields::class,
    ];

    /** Human labels for the settings screens. */
    private const LABELS = [
        EntityFieldSetting::ENTITY_LOCATION => 'Locations',
        EntityFieldSetting::ENTITY_USER => 'People',
        EntityFieldSetting::ENTITY_CUSTOMER => 'Customers',
        EntityFieldSetting::ENTITY_DOCTOR => 'Doctors',
        EntityFieldSetting::ENTITY_MEDICINE => 'Medicines',
    ];

    /** Singular names, for buttons and page titles. */
    private const SINGULAR = [
        EntityFieldSetting::ENTITY_LOCATION => 'Location',
        EntityFieldSetting::ENTITY_USER => 'Person',
        EntityFieldSetting::ENTITY_CUSTOMER => 'Customer',
        EntityFieldSetting::ENTITY_DOCTOR => 'Doctor',
        EntityFieldSetting::ENTITY_MEDICINE => 'Medicine',
    ];

    public static function singular(string $entity): string
    {
        return self::SINGULAR[$entity] ?? $entity;
    }

    /** @return list<string> */
    public static function entities(): array
    {
        return array_keys(self::REGISTRIES);
    }

    public static function supports(string $entity): bool
    {
        return array_key_exists($entity, self::REGISTRIES);
    }

    public static function label(string $entity): string
    {
        return self::LABELS[$entity] ?? $entity;
    }

    /**
     * The built-in fields for one entity, straight from code.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(string $entity): array
    {
        if (! self::supports($entity)) {
            throw new InvalidArgumentException("No field registry for [{$entity}].");
        }

        return self::REGISTRIES[$entity]::all();
    }
}

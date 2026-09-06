<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * One organization's preference about one field.
 *
 * For a built-in field this is an override layered over the code registry;
 * for a custom field it is the whole definition. Which of the two it is, is
 * `is_custom`.
 */
class EntityFieldSetting extends Model
{
    use RecordsHistory;

    public const ENTITY_LOCATION = 'location';

    public const ENTITY_USER = 'user';

    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_DOCTOR = 'doctor';

    /** Entities that can be configured. Mirrors the database CHECK. */
    public const ENTITIES = [
        self::ENTITY_LOCATION,
        self::ENTITY_USER,
        self::ENTITY_CUSTOMER,
        self::ENTITY_DOCTOR,
    ];

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_SELECT = 'select';

    /**
     * Several of the same list at once.
     *
     * Distinct from a select because the value is an array, which changes how
     * it is stored, validated and rendered — a doctor holds MBBS and MD, and
     * one column holding "MBBS, MD" is a sentence nobody can filter on.
     */
    public const TYPE_MULTISELECT = 'multiselect';

    /** Types an organization may give a field it adds itself. */
    public const CUSTOM_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
    ];

    protected $connection = 'organization';

    protected $table = 'entity_field_settings';

    protected $fillable = [
        'entity',
        'field_key',
        'label',
        'placeholder',
        'is_custom',
        'data_type',
        'options',
        'is_required',
        'show_in_form',
        'show_in_table',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'is_required' => 'boolean',
            'show_in_form' => 'boolean',
            'show_in_table' => 'boolean',
            'sort_order' => 'integer',
            'options' => 'array',
        ];
    }
}

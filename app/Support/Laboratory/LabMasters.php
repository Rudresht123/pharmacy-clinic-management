<?php

namespace App\Support\Laboratory;

use App\Models\Tenant\LabContainer;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabEquipment;
use App\Models\Tenant\LabMethod;
use App\Models\Tenant\LabOutsourceLab;
use App\Models\Tenant\LabRejectionReason;
use App\Models\Tenant\LabSampleType;
use App\Models\Tenant\LabTestCategory;
use App\Models\Tenant\LabUnit;
use App\Models\Tenant\Location;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The laboratory's short lists, described once.
 *
 * Nine lists with the same life — add, edit, switch off, remove with a reason —
 * and different fields. Writing nine controllers, nine requests and nine screens
 * for that would be nine places to keep in step. Instead each list is described
 * here, and the one controller, the one request and the one screen read the
 * description: the API serves it (`GET lab/masters`), so the form a person
 * fills in and the rules the server checks are the same words.
 *
 * Field types:
 *   text, textarea, email, phone   strings with a max
 *   code       short, stored upper-case, letters/digits/. _ -
 *   number     with min, max, step
 *   switch     a yes/no
 *   choice     one of a closed list (`options`)
 *   select     a row of another list (`source`: a master key, or `locations`)
 *
 * `unique` is `ci` (ignoring case) or `cs` (with case); `unique_within` names a
 * column the uniqueness is counted inside (a category's department).
 *
 * `used_by` lists what points at a row, so removing one that is still in use is
 * refused with a sentence rather than left to a foreign key that never fires on
 * a soft delete. Later phases add the test master here.
 */
final class LabMasters
{
    public const DEPARTMENTS = 'departments';

    public const CATEGORIES = 'test-categories';

    public const METHODS = 'methods';

    public const UNITS = 'units';

    public const CONTAINERS = 'containers';

    public const SAMPLE_TYPES = 'sample-types';

    public const REJECTION_REASONS = 'rejection-reasons';

    public const EQUIPMENT = 'equipment';

    public const OUTSOURCE_LABS = 'outsource-labs';

    /** A `select` field may also point at the organisation's branches. */
    public const SOURCE_LOCATIONS = 'locations';

    /**
     * Every list, in the order the settings screen shows its tabs.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     singular: string,
     *     plural: string,
     *     icon: string,
     *     description: string,
     *     label: string,
     *     starter: bool,
     *     fields: list<array<string, mixed>>,
     *     used_by: list<array{model: class-string<Model>, column: string, one: string, many: string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            self::DEPARTMENTS => [
                'model' => LabDepartment::class,
                'singular' => 'department',
                'plural' => 'Departments',
                'icon' => 'ti ti-building-hospital',
                'description' => 'The sections of the lab. Tests are filed under them, worklists are split by them and reports group results by them.',
                'label' => 'name',
                'starter' => true,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => 'ci', 'span' => 8, 'placeholder' => 'Haematology'],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'code', 'max' => 24, 'unique' => 'ci', 'span' => 4, 'placeholder' => 'HAEM'],
                    ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 1000, 'span' => 12, 'list' => false],
                ],
                'used_by' => [
                    ['model' => LabTestCategory::class, 'column' => 'department_id', 'one' => 'test category', 'many' => 'test categories'],
                    ['model' => LabEquipment::class, 'column' => 'department_id', 'one' => 'piece of equipment', 'many' => 'pieces of equipment'],
                ],
            ],

            self::CATEGORIES => [
                'model' => LabTestCategory::class,
                'singular' => 'test category',
                'plural' => 'Test categories',
                'icon' => 'ti ti-category',
                'description' => 'Groupings of tests inside a department, such as Liver function or Hormones. Optional.',
                'label' => 'name',
                'starter' => false,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => 'ci', 'unique_within' => 'department_id', 'span' => 8],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'code', 'max' => 24, 'span' => 4],
                    ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => self::DEPARTMENTS, 'span' => 12, 'hint' => 'Leave empty for a category that spans departments.'],
                ],
                'used_by' => [],
            ],

            self::METHODS => [
                'model' => LabMethod::class,
                'singular' => 'method',
                'plural' => 'Methods',
                'icon' => 'ti ti-microscope',
                'description' => 'How a test is run. A report can print the method beside the result.',
                'label' => 'name',
                'starter' => true,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => 'ci', 'span' => 12, 'placeholder' => 'Chemiluminescence immunoassay (CLIA)'],
                    ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 1000, 'span' => 12, 'list' => false],
                ],
                'used_by' => [],
            ],

            self::UNITS => [
                'model' => LabUnit::class,
                'singular' => 'unit',
                'plural' => 'Units',
                'icon' => 'ti ti-ruler-measure',
                'description' => 'What results are measured in. Symbols keep their case: mU/L and MU/L are different units.',
                'label' => 'symbol',
                'starter' => true,
                'fields' => [
                    ['name' => 'symbol', 'label' => 'Symbol', 'type' => 'text', 'required' => true, 'max' => 32, 'unique' => 'cs', 'span' => 5, 'placeholder' => 'g/dL'],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'max' => 80, 'span' => 7, 'placeholder' => 'Grams per decilitre'],
                ],
                'used_by' => [],
            ],

            self::CONTAINERS => [
                'model' => LabContainer::class,
                'singular' => 'container',
                'plural' => 'Containers',
                'icon' => 'ti ti-test-pipe',
                'description' => 'What samples are drawn into. The cap colour is what a collector reaches for.',
                'label' => 'name',
                'starter' => true,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => 'ci', 'span' => 8, 'placeholder' => 'EDTA tube'],
                    ['name' => 'cap_colour', 'label' => 'Cap colour', 'type' => 'choice', 'options' => self::options(LabContainer::CAP_COLOURS), 'span' => 4],
                    ['name' => 'additive', 'label' => 'Additive', 'type' => 'text', 'max' => 60, 'span' => 8, 'placeholder' => 'K2 EDTA'],
                    ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'min' => 0.01, 'max' => 1000, 'step' => 0.01, 'span' => 4],
                ],
                'used_by' => [
                    ['model' => LabSampleType::class, 'column' => 'default_container_id', 'one' => 'sample type', 'many' => 'sample types'],
                ],
            ],

            self::SAMPLE_TYPES => [
                'model' => LabSampleType::class,
                'singular' => 'sample type',
                'plural' => 'Sample types',
                'icon' => 'ti ti-droplet',
                'description' => 'What is taken from the patient. Its usual container is what collection suggests.',
                'label' => 'name',
                'starter' => true,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => 'ci', 'span' => 8, 'placeholder' => 'Serum'],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'code', 'max' => 24, 'unique' => 'ci', 'span' => 4, 'placeholder' => 'SER'],
                    ['name' => 'default_container_id', 'label' => 'Usual container', 'type' => 'select', 'source' => self::CONTAINERS, 'span' => 12],
                    ['name' => 'handling_notes', 'label' => 'Handling notes', 'type' => 'textarea', 'max' => 2000, 'span' => 12, 'list' => false, 'hint' => 'Storage and transport, e.g. "Separate within 2 hours, keep at 2–8 °C".'],
                ],
                'used_by' => [],
            ],

            self::REJECTION_REASONS => [
                'model' => LabRejectionReason::class,
                'singular' => 'rejection reason',
                'plural' => 'Rejection reasons',
                'icon' => 'ti ti-circle-x',
                'description' => 'Why a sample can be turned away at collection or receiving.',
                'label' => 'reason',
                'starter' => true,
                'fields' => [
                    ['name' => 'reason', 'label' => 'Reason', 'type' => 'text', 'required' => true, 'max' => 191, 'unique' => 'ci', 'span' => 12, 'placeholder' => 'Haemolysed sample'],
                    ['name' => 'requires_recollection', 'label' => 'Needs a fresh sample', 'type' => 'switch', 'default' => true, 'span' => 12, 'hint' => 'Puts the test back in the collection queue when a sample is rejected for this reason.'],
                ],
                'used_by' => [],
            ],

            self::EQUIPMENT => [
                'model' => LabEquipment::class,
                'singular' => 'piece of equipment',
                'plural' => 'Equipment',
                'icon' => 'ti ti-device-desktop-analytics',
                'description' => 'Analysers and instruments a result can name, so every reading from one run can be traced.',
                'label' => 'name',
                'starter' => false,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 120, 'span' => 8, 'placeholder' => 'Haematology analyser 1'],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'code', 'max' => 24, 'unique' => 'ci', 'span' => 4],
                    ['name' => 'manufacturer', 'label' => 'Manufacturer', 'type' => 'text', 'max' => 120, 'span' => 6],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'text', 'max' => 120, 'span' => 6],
                    ['name' => 'serial_no', 'label' => 'Serial number', 'type' => 'text', 'max' => 80, 'span' => 6, 'list' => false],
                    ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => self::DEPARTMENTS, 'span' => 6],
                    ['name' => 'location_id', 'label' => 'Branch', 'type' => 'select', 'source' => self::SOURCE_LOCATIONS, 'span' => 12, 'hint' => 'Where it sits.'],
                ],
                'used_by' => [],
            ],

            self::OUTSOURCE_LABS => [
                'model' => LabOutsourceLab::class,
                'singular' => 'partner lab',
                'plural' => 'Partner labs',
                'icon' => 'ti ti-building-community',
                'description' => 'Laboratories tests are sent out to.',
                'label' => 'name',
                'starter' => false,
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 160, 'unique' => 'ci', 'span' => 8],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'code', 'max' => 24, 'unique' => 'ci', 'span' => 4],
                    ['name' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'max' => 120, 'span' => 6],
                    ['name' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'max' => 30, 'span' => 6],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'max' => 191, 'span' => 12, 'list' => false],
                    ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'max' => 1000, 'span' => 12, 'list' => false],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'max' => 1000, 'span' => 12, 'list' => false],
                ],
                'used_by' => [],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One list's description. 404 for a name that is not one, so a URL cannot
     * be used to ask whether a table exists.
     *
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new NotFoundHttpException('There is no such laboratory list.');
    }

    /** @return class-string<Model> */
    public static function model(string $key): string
    {
        return self::get($key)['model'];
    }

    /**
     * Relations to load so a row can name what it points at.
     *
     * @return list<string>
     */
    public static function relations(string $key): array
    {
        $relations = [];

        foreach (self::get($key)['fields'] as $field) {
            if ($field['type'] === 'select') {
                $relations[] = self::relationFor($field['name']);
            }
        }

        return $relations;
    }

    /** `department_id` → `department`, `default_container_id` → `defaultContainer`. */
    public static function relationFor(string $column): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', preg_replace('/_id$/', '', $column)))));
    }

    /**
     * The validation rules for one list, built from its fields.
     *
     * @param  array<string, mixed>  $input  the incoming values (for scoped uniqueness)
     * @return array<string, mixed>
     */
    public static function rules(string $key, ?Model $record, array $input): array
    {
        $definition = self::get($key);
        $rules = [];

        foreach ($definition['fields'] as $field) {
            $rules[$field['name']] = self::fieldRules($definition, $field, $record, $input);
        }

        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['position'] = ['sometimes', 'integer', 'min:0', 'max:9999'];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $input
     * @return list<mixed>
     */
    private static function fieldRules(array $definition, array $field, ?Model $record, array $input): array
    {
        $presence = ! empty($field['required'])
            ? ($record ? 'sometimes' : 'required')
            : 'nullable';

        $rules = match ($field['type']) {
            'text', 'textarea', 'phone' => ['string', 'max:'.$field['max']],
            'email' => ['email', 'max:'.$field['max']],
            'code' => ['string', 'max:'.$field['max'], 'regex:/^[A-Za-z0-9._-]+$/'],
            'number' => ['numeric', 'min:'.$field['min'], 'max:'.$field['max']],
            'switch' => ['boolean'],
            'choice' => [Rule::in(array_column($field['options'], 'value'))],
            'select' => ['integer', $field['source'] === self::SOURCE_LOCATIONS
                // Through the model: branches live in the tenant's database.
                ? Rule::exists(Location::class, 'id')
                : Rule::exists(self::model($field['source']), 'id')->whereNull('deleted_at')],
        };

        if (! empty($field['required'])) {
            // A required string must say something once trimmed.
            $rules[] = 'filled';
        }

        if (! empty($field['unique'])) {
            $rules[] = self::uniqueRule($definition, $field, $record, $input);
        }

        return [$presence, ...$rules];
    }

    /**
     * Unique among the live rows, ignoring case unless the field says
     * otherwise, and inside `unique_within` where it names a column.
     *
     * A closure rather than Rule::unique, which compares with `=` and so would
     * let "Serum" and "serum" both in. The partial unique index on lower() is
     * the backstop; this is what turns a collision into a sentence.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $input
     */
    private static function uniqueRule(array $definition, array $field, ?Model $record, array $input): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($definition, $field, $record, $input): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $column = $field['name'];
            $query = $definition['model']::query();

            $field['unique'] === 'cs'
                ? $query->where($column, $value)
                : $query->whereRaw("lower({$column}) = ?", [mb_strtolower($value)]);

            if ($record) {
                $query->whereKeyNot($record->getKey());
            }

            if (! empty($field['unique_within'])) {
                $within = $field['unique_within'];
                $scope = array_key_exists($within, $input) ? $input[$within] : $record?->getAttribute($within);

                $scope === null || $scope === ''
                    ? $query->whereNull($within)
                    : $query->where($within, (int) $scope);
            }

            if ($query->exists()) {
                $fail(sprintf(
                    'Another %s %s %s.',
                    $definition['singular'],
                    $field['type'] === 'code' ? 'already has the code' : 'is already called',
                    $value,
                ));
            }
        };
    }

    /**
     * What the screen needs to draw a list: its words, its fields and the
     * options for each closed choice. Selects name their source; the screen
     * reads that list itself.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $described = [];

        foreach (self::all() as $key => $definition) {
            $described[] = [
                'key' => $key,
                'singular' => $definition['singular'],
                'plural' => $definition['plural'],
                'icon' => $definition['icon'],
                'description' => $definition['description'],
                'label' => $definition['label'],
                'starter' => $definition['starter'],
                'fields' => array_map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'required' => ! empty($field['required']),
                    'max' => $field['max'] ?? null,
                    'min' => $field['min'] ?? null,
                    'step' => $field['step'] ?? null,
                    'options' => $field['options'] ?? null,
                    'source' => $field['source'] ?? null,
                    'default' => $field['default'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'hint' => $field['hint'] ?? null,
                    'span' => $field['span'] ?? 12,
                    'list' => $field['list'] ?? true,
                ], $definition['fields']),
            ];
        }

        return $described;
    }

    /**
     * `light_blue` → Light blue, for a closed list a person picks from.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $values): array
    {
        return array_map(
            fn (string $value) => ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))],
            $values,
        );
    }
}

<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\EntityFieldSetting;
use App\Support\Fields\FieldRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One organization saving how it wants a form to behave.
 *
 * Authorization is the `tenant.owner` middleware on the route.
 */
class SaveFieldSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array'],

            // What this organization calls the record. Optional —
            // absent means keep whatever is already set.
            'label' => ['nullable', 'array'],
            'label.singular' => ['required_with:label', 'string', 'max:60'],
            'label.plural' => ['required_with:label', 'string', 'max:60'],

            'fields.*.field_key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['nullable', 'string', 'max:120'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:191'],
            'fields.*.is_custom' => ['required', 'boolean'],

            // Built-ins take their type from the registry, so it may be absent.
            'fields.*.data_type' => [
                'nullable', 'string', Rule::in(EntityFieldSetting::CUSTOM_TYPES),
            ],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*.value' => ['required', 'string', 'max:100'],
            'fields.*.options.*.label' => ['required', 'string', 'max:100'],

            'fields.*.is_required' => ['required', 'boolean'],
            'fields.*.show_in_form' => ['required', 'boolean'],
            'fields.*.show_in_table' => ['required', 'boolean'],
            'fields.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * The rules a per-field array cannot express on its own.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $entity = (string) $this->route('entity');

            if (! FieldRegistry::supports($entity)) {
                return;
            }

            $registry = FieldRegistry::for($entity);

            // key => whether the registry itself marks it mandatory. Locked
            // fields must keep that answer, whatever it is: `is_active` is
            // locked but optional, so "locked" cannot simply mean "required".
            $locked = array_column(
                array_filter($registry, fn ($field) => $field['locked']),
                'required',
                'key'
            );

            $builtInKeys = array_column($registry, 'key');
            $seen = [];

            // key => its sorted values, for the lists the code fixes.
            $fixed = [];

            foreach ($registry as $definition) {
                if (! empty($definition['fixed_options'])) {
                    $values = array_column($definition['options'] ?? [], 'value');
                    sort($values);
                    $fixed[$definition['key']] = $values;
                }
            }

            foreach ((array) $this->input('fields', []) as $index => $field) {
                $key = $field['field_key'] ?? null;

                if (! $key) {
                    continue;
                }

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "fields.{$index}.field_key",
                        'This field appears more than once.'
                    );
                }

                $seen[$key] = true;

                $isCustom = (bool) ($field['is_custom'] ?? false);

                /*
                 * A locked field is one the application depends on — hiding
                 * it or making it optional would leave a location that cannot
                 * be identified. FieldSchema also refuses to honour such a
                 * row when reading, so this is the friendly half of a rule
                 * enforced in both directions.
                 */
                if (array_key_exists($key, $locked)) {
                    if (! ($field['show_in_form'] ?? true)) {
                        $validator->errors()->add(
                            "fields.{$index}.show_in_form",
                            'The system depends on this field, so it cannot be hidden.'
                        );
                    }

                    if ((bool) ($field['is_required'] ?? false) !== (bool) $locked[$key]) {
                        $validator->errors()->add(
                            "fields.{$index}.is_required",
                            $locked[$key]
                                ? 'The system depends on this field, so it cannot be made optional.'
                                : 'Whether this field is required is fixed by the system.'
                        );
                    }
                }

                if ($isCustom) {
                    // Its whole definition lives in the row, so it needs one.
                    if (empty($field['data_type'])) {
                        $validator->errors()->add(
                            "fields.{$index}.data_type",
                            'Choose what kind of value this field holds.'
                        );
                    }

                    if (in_array($key, $builtInKeys, true)) {
                        $validator->errors()->add(
                            "fields.{$index}.field_key",
                            'This name is already used by a built-in field.'
                        );
                    }

                    if (
                        ($field['data_type'] ?? null) === EntityFieldSetting::TYPE_SELECT
                        && empty($field['options'])
                    ) {
                        $validator->errors()->add(
                            "fields.{$index}.options",
                            'A dropdown needs at least one option.'
                        );
                    }
                } elseif (! in_array($key, $builtInKeys, true)) {
                    $validator->errors()->add(
                        "fields.{$index}.field_key",
                        'There is no built-in field with this name.'
                    );
                } elseif (isset($fixed[$key]) && ! empty($field['options'])) {
                    /*
                     * A fixed list mirrors a CHECK constraint. Its labels
                     * may change; adding or removing a value would offer
                     * something the database refuses.
                     */
                    $values = array_column((array) $field['options'], 'value');
                    sort($values);

                    if ($values !== $fixed[$key]) {
                        $validator->errors()->add(
                            "fields.{$index}.options",
                            'This list is fixed by the system. Its labels can change, its values cannot.'
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.*.field_key.regex' => 'Use lowercase letters, numbers and underscores only.',
        ];
    }
}

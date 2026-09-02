<?php

namespace App\Services\Fields;

use App\Models\Tenant\EntityFieldSetting;
use Illuminate\Support\Collection;

/**
 * The effective shape of a form, once an organization's preferences are laid
 * over the fields the code declares.
 *
 * The registry (App\Support\Fields\*) stays authoritative about which
 * built-in fields exist, what type each is, and which are locked. This
 * service only applies what the organization asked for on top — and refuses
 * to apply anything that would break a locked field, so a stray settings row
 * can never leave a location with no name.
 */
class FieldSchema
{
    /**
     * Built-in fields merged with their overrides, followed by whatever the
     * organization added itself.
     *
     * @param  list<array<string, mixed>>  $registry
     * @return list<array<string, mixed>>
     */
    public function for(string $entity, array $registry): array
    {
        $settings = EntityFieldSetting::on('organization')
            ->where('entity', $entity)
            ->get()
            ->keyBy('field_key');

        $builtIn = [];

        foreach (array_values($registry) as $index => $field) {
            $override = $settings->get($field['key']);

            $builtIn[] = $this->applyOverride($field, $override, $index);
        }

        return array_merge(
            $this->sorted($builtIn),
            $this->sorted($this->customFields($settings))
        );
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function applyOverride(array $field, ?EntityFieldSetting $override, int $index): array
    {
        $field['is_custom'] = false;
        $field['sort_order'] = $index;
        // Built-ins are shown unless an override says otherwise.
        $field['show_in_form'] = true;

        if (! $override) {
            return $field;
        }

        $field['label'] = $override->label ?: $field['label'];
        $field['placeholder'] = $override->placeholder ?: ($field['placeholder'] ?? null);
        $field['sort_order'] = $override->sort_order;
        $field['in_table'] = $override->show_in_table;

        /*
         * A locked field is one the application itself depends on. Its
         * visibility and its required-ness are not negotiable, however the
         * settings row reads — validating on write is not enough, because a
         * row written before a field was locked would still be sitting there.
         */
        if ($field['locked']) {
            $field['show_in_form'] = true;

            return $field;
        }

        $field['show_in_form'] = $override->show_in_form;
        $field['required'] = $override->is_required;

        return $field;
    }

    /**
     * @param  Collection<string, EntityFieldSetting>  $settings
     * @return list<array<string, mixed>>
     */
    private function customFields(Collection $settings): array
    {
        return $settings
            ->filter(fn (EntityFieldSetting $setting) => $setting->is_custom)
            ->map(fn (EntityFieldSetting $setting) => [
                'key' => $setting->field_key,
                'label' => $setting->label ?: $setting->field_key,
                'placeholder' => $setting->placeholder,
                'type' => $setting->data_type,
                'group' => 'custom',
                'required' => $setting->is_required,
                'locked' => false,
                'in_table' => $setting->show_in_table,
                'show_in_form' => $setting->show_in_form,
                'is_custom' => true,
                'sort_order' => $setting->sort_order,
                'options' => $setting->options ?: [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function sorted(array $fields): array
    {
        usort($fields, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $fields;
    }

    /**
     * Validation rules for the organization's own fields, keyed for the
     * `custom_fields` jsonb column they are stored in.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<int, mixed>>
     */
    public function customRules(array $fields): array
    {
        $rules = ['custom_fields' => ['nullable', 'array']];

        foreach ($fields as $field) {
            if (! ($field['is_custom'] ?? false) || ! ($field['show_in_form'] ?? true)) {
                continue;
            }

            $rule = [$field['required'] ? 'required' : 'nullable'];

            $rules['custom_fields.'.$field['key']] = array_merge(
                $rule,
                $this->typeRules($field)
            );
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private function typeRules(array $field): array
    {
        return match ($field['type']) {
            EntityFieldSetting::TYPE_NUMBER => ['numeric'],
            EntityFieldSetting::TYPE_DATE => ['date'],
            EntityFieldSetting::TYPE_BOOLEAN => ['boolean'],
            EntityFieldSetting::TYPE_SELECT => [
                'string',
                // Options are stored as {value,label} pairs, same as the
                // registry's own selects.
                'in:'.implode(',', array_column($field['options'] ?? [], 'value')),
            ],
            default => ['string', 'max:1000'],
        };
    }

    /**
     * Built-in fields the organization has made mandatory.
     *
     * Returned separately because their base rules live in the FormRequest —
     * this only says which of them lose their `nullable`.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>
     */
    public function requiredBuiltIns(array $fields): array
    {
        return array_values(array_map(
            fn ($field) => $field['key'],
            array_filter(
                $fields,
                fn ($field) => ! ($field['is_custom'] ?? false)
                    && ! ($field['locked'] ?? false)
                    && ($field['required'] ?? false)
            )
        ));
    }
}

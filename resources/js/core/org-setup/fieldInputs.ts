import type { ConfigurableField, FieldSettingInput } from '@/core/field-settings/types';

/**
 * A screen's whole field set, in the shape its save endpoint takes.
 *
 * PUT /tenant/settings/fields/{entity} replaces rather than merges — a field
 * left out is a field the organization deleted — so the setup's small edits
 * (the department list, what patients are called) send every field back
 * exactly as FieldSettingsPage would, with only the change applied.
 */
export function toFieldInputs(
    fields: ConfigurableField[],
    change?: (field: ConfigurableField) => Partial<FieldSettingInput> | undefined,
): FieldSettingInput[] {
    return fields.map((field) => ({
        field_key: field.key,
        label: field.label,
        placeholder: field.placeholder ?? null,
        is_custom: field.is_custom,
        data_type: field.is_custom ? field.type : null,
        options: field.options ?? null,
        is_required: field.required,
        show_in_form: field.show_in_form,
        show_in_table: field.in_table,
        sort_order: field.sort_order,
        ...change?.(field),
    }));
}

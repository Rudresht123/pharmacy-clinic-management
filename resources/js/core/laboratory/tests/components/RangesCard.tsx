import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { cn } from '@/shared/utils/cn';
import { useSaveRanges } from '../api';
import { AGE_UNIT_LABELS, SEX_LABELS, type AgeUnit, type LabTest, type LabParameter, type Sex } from '../types';
import { FormSection } from './FormSection';

interface RangeDraft {
    key: string;
    id?: number;
    sex: Sex;
    age_min: string;
    age_max: string;
    age_unit: AgeUnit | '';
    low: string;
    high: string;
    critical_low: string;
    critical_high: string;
    normal_text: string;
    display_text: string;
}

let next = 0;

const blank = (parameter: LabParameter): RangeDraft => {
    next += 1;

    return {
        key: `range-${next}`,
        sex: 'any',
        age_min: '',
        age_max: '',
        age_unit: '',
        low: '',
        high: '',
        critical_low: '',
        critical_high: '',
        normal_text: parameter.result_type === 'positive_negative' ? (parameter.normal_value ?? 'Negative') : '',
        display_text: '',
    };
};

const fromServer = (parameter: LabParameter): RangeDraft[] =>
    (parameter.ranges ?? []).map((range) => ({
        key: `range-${range.id}`,
        id: range.id,
        sex: range.sex,
        age_min: range.age_min === null ? '' : String(range.age_min),
        age_max: range.age_max === null ? '' : String(range.age_max),
        age_unit: range.age_unit ?? '',
        low: range.low ?? '',
        high: range.high ?? '',
        critical_low: range.critical_low ?? '',
        critical_high: range.critical_high ?? '',
        normal_text: range.normal_text ?? '',
        display_text: range.display_text ?? '',
    }));

/**
 * What normal looks like, parameter by parameter.
 *
 * A clinical decision: only `laboratory.ranges_manage` can save here, and the
 * software fills nothing in. Within one sex each age has one range; a range
 * for "Anyone" covers whoever has no range of their own.
 */
export function RangesCard({ test, canEdit }: { test: LabTest; canEdit: boolean }) {
    const parameters = test.parameters ?? [];
    const [selected, setSelected] = useState<number | undefined>(parameters[0]?.id);
    const parameter = parameters.find((p) => p.id === selected) ?? parameters[0];

    const [rows, setRows] = useState<RangeDraft[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const save = useSaveRanges();

    // A different parameter, or fresh data from the server, starts the rows again.
    const serverKey = useMemo(() => JSON.stringify(parameter?.ranges ?? []), [parameter]);

    useEffect(() => {
        setRows(parameter ? fromServer(parameter) : []);
        setErrors({});
        setFailure(null);
    }, [parameter?.id, serverKey]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!parameter) {
        return null;
    }

    const numeric = parameter.result_type === 'numeric';
    const update = (index: number, patch: Partial<RangeDraft>) =>
        setRows(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const error = (index: number, field: string) => errors[`ranges.${index}.${field}`];

    const onSave = async () => {
        setErrors({});
        setFailure(null);

        const nullable = (value: string) => (value.trim() === '' ? null : value.trim());

        try {
            await save.mutateAsync({
                parameterId: parameter.id,
                ranges: rows.map((row) => ({
                    id: row.id,
                    sex: row.sex,
                    age_min: nullable(row.age_min),
                    age_max: nullable(row.age_max),
                    age_unit: row.age_min || row.age_max ? row.age_unit || null : null,
                    low: numeric ? nullable(row.low) : null,
                    high: numeric ? nullable(row.high) : null,
                    critical_low: numeric ? nullable(row.critical_low) : null,
                    critical_high: numeric ? nullable(row.critical_high) : null,
                    normal_text: numeric ? null : nullable(row.normal_text),
                    display_text: nullable(row.display_text),
                })),
            });
        } catch (thrown) {
            const validation = getValidationErrors(thrown);

            if (validation) {
                setErrors(Object.fromEntries(Object.entries(validation).map(([field, messages]) => [field, messages[0]])));
            } else {
                setFailure(resolveErrorMessage(thrown));
            }
        }
    };

    const rowMessages = (index: number) =>
        Object.entries(errors)
            .filter(([field]) => field.startsWith(`ranges.${index}.`))
            .map(([, message]) => message);

    return (
        <FormSection
            icon="ti ti-chart-line"
            title="Reference Ranges"
            caption="What normal looks like for each parameter, by sex and age. Entered by qualified staff — nothing is filled in for you."
            actions={
                canEdit ? (
                    <Button size="sm" icon="ti ti-device-floppy" loading={save.isPending} onClick={onSave}>
                        Save ranges
                    </Button>
                ) : undefined
            }
        >
            <div className="lab-range-tabs" role="tablist" aria-label="Parameters">
                {parameters.map((p) => (
                    <button
                        key={p.id}
                        type="button"
                        role="tab"
                        aria-selected={p.id === parameter.id}
                        className={cn('lab-range-tab', p.id === parameter.id && 'is-active')}
                        onClick={() => setSelected(p.id)}
                    >
                        {p.name}
                        <span>{p.ranges?.length ?? 0}</span>
                    </button>
                ))}
            </div>

            {!canEdit && (
                <p className="lab-notice">
                    <i className="ti ti-lock" aria-hidden="true" />
                    Read only. Setting ranges needs the “Set reference ranges and critical limits” permission.
                </p>
            )}

            {failure && <div className="alert alert-danger py-2 px-3 fs-13">{failure}</div>}

            <div className="lab-ranges-wrap">
                <table className="lab-ranges">
                    <thead>
                        <tr>
                            <th>Applies to</th>
                            <th>Age from</th>
                            <th>Age to</th>
                            <th>In</th>
                            {numeric ? (
                                <>
                                    <th>Low</th>
                                    <th>High</th>
                                    <th className="is-critical">Critical low</th>
                                    <th className="is-critical">Critical high</th>
                                </>
                            ) : (
                                <th>Normal answer</th>
                            )}
                            <th>Prints as</th>
                            {canEdit && <th aria-label="Remove" />}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={numeric ? 10 : 7} className="lab-ranges-empty">
                                    No range for {parameter.name} yet. Without one, its results carry no high or low flag.
                                </td>
                            </tr>
                        )}

                        {rows.map((row, index) => {
                            const input = (field: keyof RangeDraft, placeholder = '', type = 'text') => (
                                <input
                                    className={cn('form-control', error(index, field) && 'is-invalid')}
                                    type={type}
                                    inputMode={type === 'number' ? 'decimal' : undefined}
                                    placeholder={placeholder}
                                    value={String(row[field] ?? '')}
                                    disabled={!canEdit}
                                    onChange={(event) => update(index, { [field]: event.target.value } as Partial<RangeDraft>)}
                                />
                            );

                            const messages = rowMessages(index);

                            return [
                                <tr key={row.key} className={cn(messages.length > 0 && 'has-error')}>
                                    <td className="is-sex">
                                        <SearchableSelect
                                            compact
                                            ariaLabel="Applies to"
                                            value={row.sex}
                                            disabled={!canEdit}
                                            onChange={(value) => update(index, { sex: (value || 'any') as Sex })}
                                            options={Object.entries(SEX_LABELS).map(([value, label]) => ({ value, label }))}
                                        />
                                    </td>
                                    <td className="is-age">{input('age_min', 'Any', 'number')}</td>
                                    <td className="is-age">{input('age_max', 'Any', 'number')}</td>
                                    <td className="is-unit">
                                        <SearchableSelect
                                            compact
                                            ariaLabel="Age unit"
                                            value={row.age_unit}
                                            disabled={!canEdit}
                                            invalid={Boolean(error(index, 'age_unit'))}
                                            placeholder="—"
                                            clearable
                                            onChange={(value) => update(index, { age_unit: value as AgeUnit | '' })}
                                            options={Object.entries(AGE_UNIT_LABELS).map(([value, label]) => ({ value, label }))}
                                        />
                                    </td>
                                    {numeric ? (
                                        <>
                                            <td>{input('low', '', 'number')}</td>
                                            <td>{input('high', '', 'number')}</td>
                                            <td className="is-critical">{input('critical_low', '', 'number')}</td>
                                            <td className="is-critical">{input('critical_high', '', 'number')}</td>
                                        </>
                                    ) : (
                                        <td>{input('normal_text', 'e.g. Negative')}</td>
                                    )}
                                    <td>{input('display_text', 'Auto')}</td>
                                    {canEdit && (
                                        <td className="is-remove">
                                            <button
                                                type="button"
                                                title="Remove range"
                                                onClick={() => setRows(rows.filter((_, i) => i !== index))}
                                            >
                                                <i className="ti ti-trash" aria-hidden="true" />
                                            </button>
                                        </td>
                                    )}
                                </tr>,
                                messages.length > 0 ? (
                                    <tr key={`${row.key}-error`} className="lab-ranges-error">
                                        <td colSpan={numeric ? 10 : 7}>{messages.join(' ')}</td>
                                    </tr>
                                ) : null,
                            ];
                        })}
                    </tbody>
                </table>
            </div>

            {canEdit && (
                <button type="button" className="lab-add-row" onClick={() => setRows([...rows, blank(parameter)])}>
                    <i className="ti ti-plus" aria-hidden="true" /> Add range
                </button>
            )}
        </FormSection>
    );
}

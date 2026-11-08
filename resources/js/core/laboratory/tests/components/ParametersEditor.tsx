import { useState } from 'react';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { cn } from '@/shared/utils/cn';
import type { MasterRow } from '../../settings/types';
import { RESULT_TYPE_LABELS, type Choice, type ResultType } from '../types';

/** A parameter as the form holds it: strings for inputs, a key for React. */
export interface ParameterDraft {
    key: string;
    id?: number;
    name: string;
    code: string;
    section: string;
    result_type: ResultType;
    unit_id: string;
    decimals: string;
    choices: Choice[];
    normal_value: string;
    is_required: boolean;
    is_printed: boolean;
    ranges_count: number;
}

let next = 0;

export function blankParameter(name = ''): ParameterDraft {
    next += 1;

    return {
        key: `new-${next}`,
        name,
        code: '',
        section: '',
        result_type: 'numeric',
        unit_id: '',
        decimals: '',
        choices: [],
        normal_value: '',
        is_required: true,
        is_printed: true,
        ranges_count: 0,
    };
}

/** A dropdown's answers as pills: click one to mark it abnormal, × to drop it. */
function ChoiceEditor({ choices, onChange, invalid }: { choices: Choice[]; onChange: (next: Choice[]) => void; invalid?: boolean }) {
    const [draft, setDraft] = useState('');

    const add = () => {
        const value = draft.trim();

        if (value && !choices.some((choice) => choice.value.toLowerCase() === value.toLowerCase())) {
            onChange([...choices, { value, abnormal: false }]);
        }

        setDraft('');
    };

    return (
        <div className={cn('lab-choices-edit', invalid && 'is-invalid')}>
            {choices.map((choice, index) => (
                <span key={choice.value} className={cn('lab-pill', choice.abnormal && 'is-abnormal')}>
                    <button
                        type="button"
                        title={choice.abnormal ? 'Abnormal answer — click to mark normal' : 'Normal answer — click to mark abnormal'}
                        onClick={() =>
                            onChange(choices.map((c, i) => (i === index ? { ...c, abnormal: !c.abnormal } : c)))
                        }
                    >
                        {choice.abnormal && <i className="ti ti-alert-triangle" aria-hidden="true" />}
                        {choice.value}
                    </button>
                    <button
                        type="button"
                        className="lab-pill-x"
                        aria-label={`Remove ${choice.value}`}
                        onClick={() => onChange(choices.filter((_, i) => i !== index))}
                    >
                        <i className="ti ti-x" aria-hidden="true" />
                    </button>
                </span>
            ))}
            <input
                className="lab-pill-input"
                placeholder={choices.length ? 'Add answer' : 'Type an answer, press Enter'}
                value={draft}
                maxLength={60}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ',') {
                        event.preventDefault();
                        add();
                    }
                }}
                onBlur={add}
            />
        </div>
    );
}

/**
 * What is read off a test, one row per parameter.
 *
 * Every test needs at least one: a TSH is one parameter, a CBC many. How each
 * is answered decides what else it needs — a number its unit and decimals, a
 * dropdown its answers (each marked normal or abnormal), a positive/negative
 * or text answer what normal reads as.
 */
export function ParametersEditor({
    parameters,
    onChange,
    units,
    errors,
    disabled,
}: {
    parameters: ParameterDraft[];
    onChange: (next: ParameterDraft[]) => void;
    units: MasterRow[];
    errors: Record<string, string>;
    disabled: boolean;
}) {
    const update = (index: number, patch: Partial<ParameterDraft>) =>
        onChange(parameters.map((parameter, i) => (i === index ? { ...parameter, ...patch } : parameter)));

    const move = (index: number, by: number) => {
        const target = index + by;

        if (target < 0 || target >= parameters.length) {
            return;
        }

        const copy = [...parameters];
        [copy[index], copy[target]] = [copy[target], copy[index]];
        onChange(copy);
    };

    const error = (index: number, field: string) => errors[`parameters.${index}.${field}`];

    return (
        <div className="lab-params">
            <div className="lab-param lab-param-head" aria-hidden="true">
                <span />
                <span>Parameter</span>
                <span>Code</span>
                <span>Section</span>
                <span>Answered as</span>
                <span>Unit · normal answer</span>
                <span />
            </div>

            {parameters.map((parameter, index) => {
                const rowErrors = Object.entries(errors)
                    .filter(([field]) => field.startsWith(`parameters.${index}.`))
                    .map(([, message]) => message);

                return (
                    <div key={parameter.key} className={cn('lab-param', rowErrors.length > 0 && 'has-error')}>
                        <span className="lab-param-no">{index + 1}</span>

                        <input
                            className={cn('form-control', error(index, 'name') && 'is-invalid')}
                            aria-label="Parameter name"
                            placeholder="e.g. Haemoglobin"
                            maxLength={120}
                            value={parameter.name}
                            disabled={disabled}
                            onChange={(event) => update(index, { name: event.target.value })}
                        />

                        <input
                            className={cn('form-control', error(index, 'code') && 'is-invalid')}
                            aria-label="Parameter code"
                            placeholder="Code"
                            maxLength={24}
                            value={parameter.code}
                            disabled={disabled}
                            onChange={(event) => update(index, { code: event.target.value.toUpperCase() })}
                        />

                        <input
                            className="form-control"
                            aria-label="Report section"
                            placeholder="Optional"
                            maxLength={60}
                            value={parameter.section}
                            disabled={disabled}
                            onChange={(event) => update(index, { section: event.target.value })}
                        />

                        <SearchableSelect
                            compact
                            ariaLabel="Answered as"
                            value={parameter.result_type}
                            disabled={disabled || parameter.ranges_count > 0}
                            invalid={Boolean(error(index, 'result_type'))}
                            onChange={(value) =>
                                update(index, { result_type: (value || 'numeric') as ResultType, normal_value: value === 'positive_negative' ? 'Negative' : '' })
                            }
                            options={Object.entries(RESULT_TYPE_LABELS).map(([value, label]) => ({ value, label }))}
                        />

                        <div className="lab-param-detail">
                            {parameter.result_type === 'numeric' && (
                                <>
                                    <SearchableSelect
                                        compact
                                        ariaLabel="Unit"
                                        value={parameter.unit_id}
                                        disabled={disabled}
                                        placeholder="Unit"
                                        clearable
                                        onChange={(value) => update(index, { unit_id: value })}
                                        options={units.map((unit) => ({ value: String(unit.id), label: unit.label }))}
                                    />
                                    <input
                                        className={cn('form-control lab-decimals', error(index, 'decimals') && 'is-invalid')}
                                        type="number"
                                        min={0}
                                        max={6}
                                        aria-label="Decimal places"
                                        title="Decimal places"
                                        placeholder="0.0"
                                        value={parameter.decimals}
                                        disabled={disabled}
                                        onChange={(event) => update(index, { decimals: event.target.value })}
                                    />
                                </>
                            )}

                            {parameter.result_type === 'choice' && (
                                <ChoiceEditor
                                    choices={parameter.choices}
                                    invalid={Boolean(error(index, 'choices'))}
                                    onChange={(choices) => update(index, { choices })}
                                />
                            )}

                            {parameter.result_type === 'positive_negative' && (
                                <SearchableSelect
                                    compact
                                    ariaLabel="Normal answer"
                                    value={parameter.normal_value}
                                    disabled={disabled}
                                    onChange={(value) => update(index, { normal_value: value })}
                                    options={[
                                        { value: 'Negative', label: 'Normal is Negative' },
                                        { value: 'Positive', label: 'Normal is Positive' },
                                    ]}
                                />
                            )}

                            {parameter.result_type === 'text' && (
                                <input
                                    className="form-control"
                                    aria-label="Normal answer"
                                    placeholder="Normal answer (optional)"
                                    maxLength={60}
                                    value={parameter.normal_value}
                                    disabled={disabled}
                                    onChange={(event) => update(index, { normal_value: event.target.value })}
                                />
                            )}
                        </div>

                        <div className="lab-param-actions">
                            <button type="button" title="Move up" disabled={disabled || index === 0} onClick={() => move(index, -1)}>
                                <i className="ti ti-arrow-up" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                title="Move down"
                                disabled={disabled || index === parameters.length - 1}
                                onClick={() => move(index, 1)}
                            >
                                <i className="ti ti-arrow-down" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                className="is-danger"
                                title="Remove parameter"
                                disabled={disabled}
                                onClick={() => onChange(parameters.filter((_, i) => i !== index))}
                            >
                                <i className="ti ti-trash" aria-hidden="true" />
                            </button>
                        </div>

                        {rowErrors.length > 0 && <p className="lab-param-error">{rowErrors.join(' ')}</p>}
                        {parameter.ranges_count > 0 && (
                            <p className="lab-param-note">
                                {parameter.ranges_count} reference range{parameter.ranges_count === 1 ? '' : 's'} set — how it is
                                answered is fixed while they exist.
                            </p>
                        )}
                    </div>
                );
            })}

            {!disabled && (
                <button type="button" className="lab-add-row" onClick={() => onChange([...parameters, blankParameter()])}>
                    <i className="ti ti-plus" aria-hidden="true" /> Add parameter
                </button>
            )}
        </div>
    );
}

import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { cn } from '@/shared/utils/cn';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useLabMaster } from '../../settings/api';
import type { MasterRow } from '../../settings/types';
import { useLabTest, useSaveLabTest } from '../api';
import { money } from '../money';
import type { LabTest, ResultLayout } from '../types';
import { BranchPricesCard } from '../components/BranchPricesCard';
import { Field, FormSection } from '../components/FormSection';
import { ParametersEditor, blankParameter, type ParameterDraft } from '../components/ParametersEditor';
import { RangesCard } from '../components/RangesCard';
import { CATALOGUE_PATH } from '../catalogue';

interface Draft {
    code: string;
    name: string;
    short_name: string;
    department_id: string;
    category_id: string;
    method_id: string;
    description: string;
    sample_type_id: string;
    container_id: string;
    sample_volume: string;
    collection_instructions: string;
    processing_instructions: string;
    result_layout: ResultLayout;
    report_template: string;
    tat_hours: string;
    price: string;
    tax_percent: string;
    hsn_code: string;
    is_outsourced: boolean;
    outsource_lab_id: string;
    outsource_cost: string;
    is_active: boolean;
    parameters: ParameterDraft[];
}

const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

function draftOf(test?: LabTest): Draft {
    return {
        code: test?.code ?? '',
        name: test?.name ?? '',
        short_name: text(test?.short_name),
        department_id: text(test?.department_id),
        category_id: text(test?.category_id),
        method_id: text(test?.method_id),
        description: text(test?.description),
        sample_type_id: text(test?.sample_type_id),
        container_id: text(test?.container_id),
        sample_volume: text(test?.sample_volume),
        collection_instructions: text(test?.collection_instructions),
        processing_instructions: text(test?.processing_instructions),
        result_layout: test?.result_layout ?? 'parameters',
        report_template: text(test?.report_template),
        tat_hours: text(test?.tat_hours),
        price: test ? String(test.price) : '',
        tax_percent: test ? String(test.tax_percent) : '0',
        hsn_code: text(test?.hsn_code),
        is_outsourced: test?.is_outsourced ?? false,
        outsource_lab_id: text(test?.outsource_lab_id),
        outsource_cost: text(test?.outsource_cost),
        is_active: test?.is_active ?? true,
        parameters: test
            ? (test.parameters ?? []).map((parameter) => ({
                  key: `p-${parameter.id}`,
                  id: parameter.id,
                  name: parameter.name,
                  code: text(parameter.code),
                  section: text(parameter.section),
                  result_type: parameter.result_type,
                  unit_id: text(parameter.unit_id),
                  decimals: text(parameter.decimals),
                  choices: parameter.choices ?? [],
                  normal_value: text(parameter.normal_value),
                  is_required: parameter.is_required,
                  is_printed: parameter.is_printed,
                  ranges_count: parameter.ranges?.length ?? 0,
              }))
            : [blankParameter()],
    };
}

const orNull = (value: string) => (value.trim() === '' ? null : value.trim());
const idOrNull = (value: string) => (value === '' ? null : Number(value));

function payloadOf(draft: Draft): Record<string, unknown> {
    return {
        code: draft.code.trim(),
        name: draft.name.trim(),
        short_name: orNull(draft.short_name),
        department_id: idOrNull(draft.department_id),
        category_id: idOrNull(draft.category_id),
        method_id: idOrNull(draft.method_id),
        description: orNull(draft.description),
        sample_type_id: idOrNull(draft.sample_type_id),
        container_id: idOrNull(draft.container_id),
        sample_volume: orNull(draft.sample_volume),
        collection_instructions: orNull(draft.collection_instructions),
        processing_instructions: orNull(draft.processing_instructions),
        result_layout: draft.result_layout,
        report_template: draft.result_layout === 'narrative' ? orNull(draft.report_template) : null,
        tat_hours: orNull(draft.tat_hours),
        price: draft.price.trim() === '' ? null : draft.price.trim(),
        tax_percent: draft.tax_percent.trim() === '' ? 0 : draft.tax_percent.trim(),
        hsn_code: orNull(draft.hsn_code),
        is_outsourced: draft.is_outsourced,
        outsource_lab_id: draft.is_outsourced ? idOrNull(draft.outsource_lab_id) : null,
        outsource_cost: draft.is_outsourced ? orNull(draft.outsource_cost) : null,
        is_active: draft.is_active,
        parameters: draft.parameters.map((parameter) => ({
            id: parameter.id,
            name: parameter.name.trim(),
            code: orNull(parameter.code),
            section: orNull(parameter.section),
            result_type: parameter.result_type,
            unit_id: parameter.result_type === 'numeric' ? idOrNull(parameter.unit_id) : null,
            decimals: parameter.result_type === 'numeric' ? orNull(parameter.decimals) : null,
            choices: parameter.result_type === 'choice' ? parameter.choices : null,
            normal_value: parameter.result_type === 'numeric' ? null : orNull(parameter.normal_value),
            is_required: parameter.is_required,
            is_printed: parameter.is_printed,
        })),
    };
}

/** A select over one of the lab's lists. */
function ListSelect({
    rows,
    value,
    onChange,
    placeholder,
    invalid,
    disabled,
    required,
}: {
    rows: MasterRow[] | undefined;
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    invalid?: boolean;
    disabled?: boolean;
    required?: boolean;
}) {
    return (
        <SearchableSelect
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            invalid={invalid}
            disabled={disabled}
            clearable={!required}
            options={(rows ?? [])
                .filter((row) => row.is_active || String(row.id) === value)
                .map((row) => ({ value: String(row.id), label: row.label }))}
        />
    );
}

/**
 * One test on its own page: what it is, what it takes, what is read off it,
 * what it costs — then, once it exists, its reference ranges and branch prices.
 *
 * The left is the form in sections; the right a sticky summary with Save, so
 * the button is in sight on a long test.
 */
export default function LabTestFormPage() {
    const { id } = useParams<{ id: string }>();
    const editingId = id && id !== 'new' ? Number(id) : undefined;
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canManage = can('laboratory.catalog_manage');
    const canRanges = can('laboratory.ranges_manage');

    const { data: test, isLoading, isError, refetch } = useLabTest(editingId);
    const save = useSaveLabTest();

    const lists = {
        departments: useLabMaster('departments').data,
        categories: useLabMaster('test-categories').data,
        methods: useLabMaster('methods').data,
        sampleTypes: useLabMaster('sample-types').data,
        containers: useLabMaster('containers').data,
        units: useLabMaster('units').data,
        partners: useLabMaster('outsource-labs').data,
    };

    const [draft, setDraft] = useState<Draft>(() => draftOf());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        if (test) {
            setDraft(draftOf(test));
        }
    }, [test]);

    const set = <K extends keyof Draft>(field: K, value: Draft[K]) => setDraft((current) => ({ ...current, [field]: value }));
    const disabled = !canManage;

    // Categories that belong to the chosen department, or to none.
    const categories = useMemo(
        () =>
            (lists.categories ?? []).filter(
                (row) => !row.department_id || !draft.department_id || String(row.department_id) === draft.department_id,
            ),
        [lists.categories, draft.department_id],
    );

    if (editingId !== undefined && isLoading) {
        return <LoadingBlock label="Loading test…" />;
    }

    if (editingId !== undefined && (isError || !test)) {
        return <ErrorState onRetry={refetch} />;
    }

    const onSave = async () => {
        setErrors({});
        setFailure(null);

        try {
            const response = await save.mutateAsync({ id: editingId, payload: payloadOf(draft) });

            if (!editingId) {
                // Its own address: ranges and branch prices open once it exists.
                navigate(`/settings/lab-tests/${response.data.id}`, { replace: true });
            }
        } catch (thrown) {
            const validation = getValidationErrors(thrown);

            if (validation) {
                setErrors(Object.fromEntries(Object.entries(validation).map(([field, messages]) => [field, messages[0]])));
            } else {
                setFailure(resolveErrorMessage(thrown));
            }
        }
    };

    const numeric = draft.parameters.filter((parameter) => parameter.result_type === 'numeric').length;
    const ranged = (test?.parameters ?? []).filter((parameter) => (parameter.ranges?.length ?? 0) > 0).length;
    const departmentName = lists.departments?.find((row) => String(row.id) === draft.department_id)?.label;
    const sampleName = lists.sampleTypes?.find((row) => String(row.id) === draft.sample_type_id)?.label;
    const errorCount = Object.keys(errors).length;

    const saveButton = (block = false) =>
        canManage ? (
            <Button icon="ti ti-device-floppy" loading={save.isPending} onClick={onSave} className={block ? 'w-100' : undefined}>
                {editingId ? 'Save Test' : 'Add Test'}
            </Button>
        ) : null;

    return (
        <>
            <PageHeader
                title={test ? test.name : 'New Test'}
                subtitle={test ? `${test.code} · ${test.department_name ?? ''}` : 'What the test is, what it takes, what is read off it, and what it costs.'}
                icon="ti ti-test-pipe"
                tone="violet"
                crumbs={[
                    { label: 'Settings' },
                    { label: 'Laboratory', to: '/settings/laboratory' },
                    { label: 'Test Catalogue', to: CATALOGUE_PATH },
                    { label: test ? test.code : 'New' },
                ]}
                actions={saveButton()}
            />

            {failure && <div className="alert alert-danger">{failure}</div>}
            {errorCount > 0 && (
                <div className="alert alert-danger">Some fields need attention — they are marked below.</div>
            )}
            {test?.from_old_price_list && (
                <p className="lab-notice">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    Moved across from the old lab price list with one text parameter. Add its real parameters, department and sample.
                </p>
            )}

            <div className="lab-tf">
                <div className="lab-tf-main">
                    <FormSection icon="ti ti-id-badge-2" title="Test Details" caption="What it is called and where it is filed.">
                        <div className="lab-grid">
                            <Field label="Test name" required error={errors.name} className="is-wide">
                                <input
                                    className={cn('form-control', errors.name && 'is-invalid')}
                                    placeholder="e.g. Complete Blood Count"
                                    maxLength={191}
                                    value={draft.name}
                                    disabled={disabled}
                                    onChange={(event) => set('name', event.target.value)}
                                />
                            </Field>
                            <Field label="Code" required error={errors.code}>
                                <input
                                    className={cn('form-control', errors.code && 'is-invalid')}
                                    placeholder="CBC"
                                    maxLength={24}
                                    value={draft.code}
                                    disabled={disabled}
                                    onChange={(event) => set('code', event.target.value.toUpperCase())}
                                />
                            </Field>
                            <Field label="Short name" error={errors.short_name} hint="On labels and narrow screens.">
                                <input
                                    className="form-control"
                                    maxLength={60}
                                    value={draft.short_name}
                                    disabled={disabled}
                                    onChange={(event) => set('short_name', event.target.value)}
                                />
                            </Field>
                            <Field label="Department" required error={errors.department_id}>
                                <ListSelect
                                    rows={lists.departments}
                                    value={draft.department_id}
                                    onChange={(value) => setDraft((current) => ({ ...current, department_id: value, category_id: '' }))}
                                    placeholder="Choose a department"
                                    invalid={Boolean(errors.department_id)}
                                    disabled={disabled}
                                    required
                                />
                            </Field>
                            <Field label="Category" error={errors.category_id}>
                                <ListSelect
                                    rows={categories}
                                    value={draft.category_id}
                                    onChange={(value) => set('category_id', value)}
                                    placeholder="None"
                                    disabled={disabled}
                                />
                            </Field>
                            <Field label="Method" error={errors.method_id}>
                                <ListSelect
                                    rows={lists.methods}
                                    value={draft.method_id}
                                    onChange={(value) => set('method_id', value)}
                                    placeholder="Not stated"
                                    disabled={disabled}
                                />
                            </Field>
                            <Field label="Description" error={errors.description} className="is-wide">
                                <textarea
                                    className="form-control"
                                    rows={2}
                                    value={draft.description}
                                    disabled={disabled}
                                    onChange={(event) => set('description', event.target.value)}
                                />
                            </Field>
                        </div>
                        <label className="lab-switch">
                            <input
                                type="checkbox"
                                role="switch"
                                className="form-check-input"
                                checked={draft.is_active}
                                disabled={disabled}
                                onChange={(event) => set('is_active', event.target.checked)}
                            />
                            <span>
                                <b>Offered</b>
                                <small>Switched off, it stays on orders that already have it but cannot be ordered.</small>
                            </span>
                        </label>
                    </FormSection>

                    <FormSection icon="ti ti-droplet" title="Sample" caption="What is taken, in what, and how.">
                        <div className="lab-grid">
                            <Field label="Sample type" error={errors.sample_type_id}>
                                <ListSelect
                                    rows={lists.sampleTypes}
                                    value={draft.sample_type_id}
                                    onChange={(value) => {
                                        const usual = lists.sampleTypes?.find((row) => String(row.id) === value)?.default_container_id;
                                        setDraft((current) => ({
                                            ...current,
                                            sample_type_id: value,
                                            container_id: current.container_id || (usual ? String(usual) : ''),
                                        }));
                                    }}
                                    placeholder="Not stated"
                                    disabled={disabled}
                                />
                            </Field>
                            <Field label="Container" error={errors.container_id} hint="Filled from the sample type's usual one.">
                                <ListSelect
                                    rows={lists.containers}
                                    value={draft.container_id}
                                    onChange={(value) => set('container_id', value)}
                                    placeholder="Not stated"
                                    disabled={disabled}
                                />
                            </Field>
                            <Field label="Volume" error={errors.sample_volume}>
                                <input
                                    className="form-control"
                                    placeholder="e.g. 2 mL"
                                    maxLength={40}
                                    value={draft.sample_volume}
                                    disabled={disabled}
                                    onChange={(event) => set('sample_volume', event.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="lab-grid is-two mt-3">
                            <Field label="Collection instructions" error={errors.collection_instructions}>
                                <textarea
                                    className="form-control"
                                    rows={3}
                                    placeholder="Patient preparation, e.g. fasting"
                                    value={draft.collection_instructions}
                                    disabled={disabled}
                                    onChange={(event) => set('collection_instructions', event.target.value)}
                                />
                            </Field>
                            <Field label="Processing instructions" error={errors.processing_instructions}>
                                <textarea
                                    className="form-control"
                                    rows={3}
                                    placeholder="For the bench"
                                    value={draft.processing_instructions}
                                    disabled={disabled}
                                    onChange={(event) => set('processing_instructions', event.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection
                        icon="ti ti-list-details"
                        title="What Is Read Off It"
                        caption="Each value the report shows. A single-value test has one parameter; a panel has many."
                    >
                        <div className="lab-layouts" role="radiogroup" aria-label="Result layout">
                            {(
                                [
                                    ['parameters', 'ti ti-table', 'Values', 'Numbers and answers, parameter by parameter.'],
                                    ['narrative', 'ti ti-file-text', 'Written report', 'A pathologist writes the findings; parameters optional.'],
                                ] as const
                            ).map(([value, icon, title, caption]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={draft.result_layout === value}
                                    className={cn('lab-choice', draft.result_layout === value && 'is-active')}
                                    disabled={disabled}
                                    onClick={() => set('result_layout', value)}
                                >
                                    <i className={icon} aria-hidden="true" />
                                    <span>
                                        <b>{title}</b>
                                        <small>{caption}</small>
                                    </span>
                                </button>
                            ))}
                        </div>

                        {errors.parameters && <p className="lab-field-error mb-2">{errors.parameters}</p>}

                        <ParametersEditor
                            parameters={draft.parameters}
                            onChange={(parameters) => set('parameters', parameters)}
                            units={lists.units ?? []}
                            errors={errors}
                            disabled={disabled}
                        />

                        {draft.result_layout === 'narrative' && (
                            <Field label="Report template" error={errors.report_template} className="mt-3" hint="Where the written report starts from.">
                                <textarea
                                    className="form-control"
                                    rows={4}
                                    value={draft.report_template}
                                    disabled={disabled}
                                    onChange={(event) => set('report_template', event.target.value)}
                                />
                            </Field>
                        )}
                    </FormSection>

                    <FormSection icon="ti ti-receipt" title="Turnaround and Price" caption="When it is due and what it costs. Each branch can charge its own price.">
                        <div className="lab-grid">
                            <Field label="Turnaround (hours)" error={errors.tat_hours} hint="Routine. Urgent and STAT come from Laboratory settings.">
                                <input
                                    className={cn('form-control', errors.tat_hours && 'is-invalid')}
                                    type="number"
                                    min={1}
                                    value={draft.tat_hours}
                                    disabled={disabled}
                                    onChange={(event) => set('tat_hours', event.target.value)}
                                />
                            </Field>
                            <Field label="Price (₹)" required error={errors.price}>
                                <input
                                    className={cn('form-control', errors.price && 'is-invalid')}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={draft.price}
                                    disabled={disabled}
                                    onChange={(event) => set('price', event.target.value)}
                                />
                            </Field>
                            <Field label="Tax %" error={errors.tax_percent}>
                                <input
                                    className={cn('form-control', errors.tax_percent && 'is-invalid')}
                                    type="number"
                                    min={0}
                                    max={100}
                                    step="0.01"
                                    value={draft.tax_percent}
                                    disabled={disabled}
                                    onChange={(event) => set('tax_percent', event.target.value)}
                                />
                            </Field>
                            <Field label="HSN / SAC code" error={errors.hsn_code}>
                                <input
                                    className="form-control"
                                    maxLength={16}
                                    value={draft.hsn_code}
                                    disabled={disabled}
                                    onChange={(event) => set('hsn_code', event.target.value)}
                                />
                            </Field>
                        </div>

                        <label className="lab-switch">
                            <input
                                type="checkbox"
                                role="switch"
                                className="form-check-input"
                                checked={draft.is_outsourced}
                                disabled={disabled}
                                onChange={(event) => set('is_outsourced', event.target.checked)}
                            />
                            <span>
                                <b>Sent out to a partner lab</b>
                                <small>The sample goes to another laboratory, whose result comes back here.</small>
                            </span>
                        </label>

                        {draft.is_outsourced && (
                            <div className="lab-grid mt-3">
                                <Field label="Partner lab" error={errors.outsource_lab_id} className="is-wide">
                                    <ListSelect
                                        rows={lists.partners}
                                        value={draft.outsource_lab_id}
                                        onChange={(value) => set('outsource_lab_id', value)}
                                        placeholder="Choose a partner lab"
                                        disabled={disabled}
                                    />
                                </Field>
                                <Field label="What they charge us (₹)" error={errors.outsource_cost}>
                                    <input
                                        className="form-control"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={draft.outsource_cost}
                                        disabled={disabled}
                                        onChange={(event) => set('outsource_cost', event.target.value)}
                                    />
                                </Field>
                            </div>
                        )}
                    </FormSection>

                    {test && (test.parameters?.length ?? 0) > 0 && <RangesCard test={test} canEdit={canRanges} />}
                    {test && <BranchPricesCard test={test} canEdit={canManage} />}
                </div>

                <aside className="lab-tf-side">
                    <Card className="lab-tf-summary">
                        <h6>Summary</h6>
                        <dl>
                            <div><dt>Code</dt><dd>{draft.code || '—'}</dd></div>
                            <div><dt>Department</dt><dd>{departmentName ?? '—'}</dd></div>
                            <div><dt>Sample</dt><dd>{sampleName ?? '—'}</dd></div>
                            <div><dt>Parameters</dt><dd>{draft.parameters.length}</dd></div>
                            <div>
                                <dt>Ranges set</dt>
                                <dd>{test ? `${ranged} of ${test.parameters?.length ?? 0}` : 'After saving'}</dd>
                            </div>
                            <div><dt>Turnaround</dt><dd>{draft.tat_hours ? `${draft.tat_hours} h` : '—'}</dd></div>
                            <div><dt>Price</dt><dd>{draft.price ? money(draft.price) : '—'}</dd></div>
                            <div><dt>Status</dt><dd>{draft.is_active ? 'Offered' : 'Switched off'}</dd></div>
                        </dl>
                        {numeric > 0 && !test && (
                            <p className="lab-tf-hint">Reference ranges are set after the test is saved.</p>
                        )}
                        {!canManage && <p className="lab-tf-hint">Read only — changing tests needs the lab test permission.</p>}
                        <div className="lab-tf-buttons">{saveButton(true)}</div>
                    </Card>
                </aside>
            </div>
        </>
    );
}

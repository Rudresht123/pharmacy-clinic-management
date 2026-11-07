import { useEffect } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError, SelectField, SwitchField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { formatRelative } from '@/shared/utils/format';
import { cn } from '@/shared/utils/cn';
import { useLabSettings, useSaveLabSettings } from '../api';
import { LABEL_SIZE_LABELS, type LabSettings, type LabelSize } from '../types';

type Values = Record<string, unknown>;

/** The two ways a result can become final, as tiles rather than a dropdown. */
const APPROVAL_CHOICES = [
    {
        value: 1,
        title: 'One step',
        text: 'The technician enters the result and an approver signs it off.',
        icon: 'ti ti-circle-check',
    },
    {
        value: 2,
        title: 'Two steps',
        text: 'A technical verifier checks each result before the approver signs it off.',
        icon: 'ti ti-checks',
    },
] as const;

const blankToNull = (value: unknown) => (value === '' || value === null || value === undefined ? null : Number(value));

/**
 * How the laboratory works, for every branch: how many people a result passes,
 * whether a sample waits for its bill, and what the label printer holds.
 */
export function GeneralPanel({ editable }: { editable: boolean }) {
    const { data: settings, isLoading } = useLabSettings();
    const save = useSaveLabSettings();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        setValue,
        formState: { errors, isSubmitting, isDirty },
    } = useApiForm<Values>({ defaultValues: {} });

    useEffect(() => {
        if (settings) {
            reset({ ...settings, urgent_tat_hours: settings.urgent_tat_hours ?? '', stat_tat_minutes: settings.stat_tat_minutes ?? '' });
        }
    }, [settings, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const payload: Partial<LabSettings> = {
            approval_levels: Number(values.approval_levels) as 1 | 2,
            allow_self_review: Boolean(values.allow_self_review),
            critical_call_required: Boolean(values.critical_call_required),
            release_partial_reports: Boolean(values.release_partial_reports),
            collect_requires_payment: Boolean(values.collect_requires_payment),
            urgent_tat_hours: blankToNull(values.urgent_tat_hours),
            stat_tat_minutes: blankToNull(values.stat_tat_minutes),
            label_size: values.label_size as LabelSize,
        };

        const result = await submit(values, () => save.mutateAsync(payload));

        if (result) {
            reset({ ...result, urgent_tat_hours: result.urgent_tat_hours ?? '', stat_tat_minutes: result.stat_tat_minutes ?? '' });
        }
    });

    if (isLoading) {
        return <LoadingBlock label="Loading settings…" />;
    }

    const levels = Number(watch('approval_levels'));

    return (
        <form onSubmit={onSubmit} noValidate>
            <FormError message={errors.root?.message} />

            {!editable && (
                <p className="form-legend">
                    These are your organisation&rsquo;s settings. Ask whoever sets up the laboratory to change them.
                </p>
            )}

            <fieldset disabled={!editable} className="border-0 p-0 m-0">
                <div className="lab-general">
                    <Card
                        title="Results and Approval"
                        icon="ti ti-shield-check"
                        description="How many people a result passes before it is final."
                    >
                        <div className="lab-choices" role="radiogroup" aria-label="Approval steps">
                            {APPROVAL_CHOICES.map((choice) => (
                                <button
                                    key={choice.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={levels === choice.value}
                                    className={cn('lab-choice', levels === choice.value && 'is-active')}
                                    onClick={() =>
                                        setValue('approval_levels', choice.value, { shouldDirty: true })
                                    }
                                >
                                    <i className={choice.icon} aria-hidden="true" />
                                    <span>
                                        <b>{choice.title}</b>
                                        <small>{choice.text}</small>
                                    </span>
                                </button>
                            ))}
                        </div>
                        {errors.approval_levels?.message && (
                            <div className="invalid-feedback d-block">{String(errors.approval_levels.message)}</div>
                        )}

                        <SwitchField
                            name="allow_self_review"
                            label="Let one person enter and approve the same result"
                            register={register}
                            errors={errors}
                            layout="row"
                            hint="For a lab with one person on the bench. Off keeps a second pair of eyes on every result."
                        />

                        <SwitchField
                            name="critical_call_required"
                            label="Log the call before a critical value is approved"
                            register={register}
                            errors={errors}
                            layout="row"
                            hint="Who was told, how and when. The call protects the patient, not the report."
                        />

                        <SwitchField
                            name="release_partial_reports"
                            label="Release a report as soon as some tests are final"
                            register={register}
                            errors={errors}
                            layout="row"
                            hint="Off holds the report until every test on the order is approved."
                        />
                    </Card>

                    <Card
                        title="Samples and Turnaround"
                        icon="ti ti-test-pipe"
                        description="When a sample may be taken, and how fast the faster priorities are due."
                    >
                        <SwitchField
                            name="collect_requires_payment"
                            label="Take payment before collecting a sample"
                            register={register}
                            errors={errors}
                            layout="row"
                            hint="STAT orders and clinics that bill on credit are exempt, and the exemption is noted on the sample."
                        />

                        <div className="row mt-3">
                            <TextField
                                className="col-sm-6"
                                name="urgent_tat_hours"
                                label="Urgent turnaround (hours)"
                                type="number"
                                register={register}
                                errors={errors}
                                placeholder="Each test's own"
                                hint="Empty uses each test's own turnaround."
                            />
                            <TextField
                                className="col-sm-6"
                                name="stat_tat_minutes"
                                label="STAT turnaround (minutes)"
                                type="number"
                                register={register}
                                errors={errors}
                                placeholder="Each test's own"
                                hint="Between 5 minutes and 24 hours."
                            />
                        </div>

                        <SelectField
                            name="label_size"
                            label="Sample label stock"
                            control={control}
                            errors={errors}
                            required
                            className="mb-0"
                            options={Object.entries(LABEL_SIZE_LABELS).map(([value, label]) => ({ value, label }))}
                            hint="What the collection counter's label printer holds."
                        />
                    </Card>
                </div>
            </fieldset>

            {editable && (
                <div className="form-actions">
                    <span className="form-actions-note">
                        {/*
                         * Only once a person has saved: the row is created by the
                         * migration, so its own timestamp is not anybody's save.
                         */}
                        {isDirty
                            ? 'Unsaved changes.'
                            : settings?.updated_at && settings.updated_by_name
                              ? `Last saved ${formatRelative(settings.updated_at)} by ${settings.updated_by_name}.`
                              : 'These apply to every branch in your organisation.'}
                    </span>

                    <Button type="submit" loading={isSubmitting} disabled={!isDirty} icon="ti ti-device-floppy">
                        Save settings
                    </Button>
                </div>
            )}
        </form>
    );
}

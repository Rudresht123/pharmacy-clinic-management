import { useEffect } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SelectField, TextField, TextareaField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import type { AutomationRule, MessageTemplate } from '../types';

interface Values {
    title: string;
    description: string;
    message_template_id: string;
    lead_minutes: string;
}

/**
 * When a rule fires, and what it sends.
 *
 * The EVENT is not editable and never will be — a rule is the clinic's answer
 * to something the software already knows how to notice, and offering to
 * change that would be offering to point it at nothing. What a clinic owns is
 * the wording, the template and the timing.
 *
 * Only the rules that lead their event offer a delay. "Send the confirmation
 * forty minutes before the patient books" is not a sentence, so the field is
 * not there to be filled in wrongly.
 */
export function AutomationRuleModal({
    rule,
    templates,
    onClose,
    onSave,
    saving,
}: {
    /** Null closes it; a rule opens it. */
    rule: AutomationRule | null;
    templates: MessageTemplate[];
    onClose: () => void;
    onSave: (values: Partial<AutomationRule> & { id: number }) => Promise<unknown>;
    saving?: boolean;
}) {
    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({
        defaultValues: { title: '', description: '', message_template_id: '', lead_minutes: '' },
    });

    useEffect(() => {
        if (!rule) {
            return;
        }

        reset({
            title: rule.title,
            description: rule.description ?? '',
            message_template_id: String(rule.message_template_id ?? ''),
            lead_minutes: rule.lead_minutes === null ? '' : String(rule.lead_minutes),
        });
    }, [rule, reset]);

    /* A rule that leads its event has a delay; one that reacts to it cannot. */
    const leads = rule?.lead_minutes !== null && rule?.lead_minutes !== undefined;

    const onSubmit = handleSubmit(async (values) => {
        if (!rule) {
            return;
        }

        const result = await submit(values, () =>
            onSave({
                id: rule.id,
                title: values.title.trim(),
                description: values.description.trim() || null,
                message_template_id: values.message_template_id
                    ? Number(values.message_template_id)
                    : null,
                lead_minutes: values.lead_minutes ? Number(values.lead_minutes) : null,
            }),
        );

        if (result) {
            onClose();
        }
    });

    /*
     * Only approved templates are offered. The server refuses anything else
     * on a rule that is switched on, and offering a draft here would be
     * offering a choice that is then rejected on save.
     */
    const approved = templates.filter((template) => template.status === 'approved');

    return (
        <FormModal
            open={rule !== null}
            onClose={onClose}
            title={rule ? `Edit ${rule.title}` : 'Edit rule'}
            subtitle="What this sends, and when"
            icon={<i className="ti ti-settings-automation" />}
            size="md"
            onSubmit={onSubmit}
            submitting={isSubmitting || saving}
            submitLabel="Save rule"
        >
            {rule && (
                <>
                    {/* The trigger, stated and not offered. */}
                    <div className="comm-trigger">
                        <i className={rule.icon ?? 'ti ti-bolt'} aria-hidden="true" />

                        <div>
                            <small>Fires on</small>
                            <b>{rule.event_key}</b>
                            <span>
                                Set by the software. A rule reacts to something it already knows
                                how to notice.
                            </span>
                        </div>
                    </div>

                    <TextField
                        name="title"
                        label="Name"
                        required
                        register={register}
                        errors={errors}
                    />

                    <TextareaField
                        name="description"
                        label="What it does"
                        rows={2}
                        hint="Shown under the name on the automation list."
                        register={register}
                        errors={errors}
                    />

                    <SelectField
                        name="message_template_id"
                        label="Template it sends"
                        hint={
                            approved.length === 0
                                ? 'No approved templates yet — approve one before this rule can send.'
                                : 'Only approved templates can be automated.'
                        }
                        control={control}
                        errors={errors}
                        options={approved.map((template) => ({
                            value: String(template.id),
                            label: template.name,
                        }))}
                    />

                    {leads && (
                        <SelectField
                            name="lead_minutes"
                            label="How far ahead"
                            hint="Counted back from the appointment."
                            control={control}
                            errors={errors}
                            options={[
                                { value: '30', label: '30 minutes before' },
                                { value: '60', label: '1 hour before' },
                                { value: '120', label: '2 hours before' },
                                { value: '180', label: '3 hours before' },
                                { value: '360', label: '6 hours before' },
                                { value: '720', label: '12 hours before' },
                                { value: '1440', label: '1 day before' },
                                { value: '2880', label: '2 days before' },
                                { value: '10080', label: '1 week before' },
                            ]}
                        />
                    )}
                </>
            )}
        </FormModal>
    );
}

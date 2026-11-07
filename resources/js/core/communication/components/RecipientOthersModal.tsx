import { useEffect } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { MultiSelectField } from '@/core/field-settings/MultiSelectField';
import { useRuleRecipientOptions, useSaveRuleRecipients } from '../api';
import {
    NO_RECIPIENTS,
    RECIPIENT_LISTS,
    fromRecipientValues,
    isRoleLikeAPlace,
    recipientListError,
    recipientOptions,
    toRecipientValues,
    type RecipientField,
    type RecipientValues,
} from '../recipientForm';
import type { AutomationRule, RecipientEntry, RuleRecipients } from '../types';

/** The ones with a column of their own on the Recipients screen. */
const COLUMNS: RecipientEntry['type'][] = ['patient', 'doctor', 'front_desk', 'branch_manager'];

/**
 * Everybody on a message besides the Recipients screen's columns: another role,
 * whoever booked it, one named person, a typed address. The columns' own people are
 * carried through untouched.
 */
export function RecipientOthersModal({ rule, onClose }: { rule: AutomationRule | null; onClose: () => void }) {
    const save = useSaveRuleRecipients();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<RecipientValues>({ defaultValues: NO_RECIPIENTS });

    const byEmail = rule?.channel === 'email';
    const lists = rule ? RECIPIENT_LISTS[byEmail ? 'email' : 'push'] : [];
    const choices = useRuleRecipientOptions(rule !== null);

    useEffect(() => {
        if (!rule) {
            return;
        }

        const others = (entries: RecipientEntry[]) => entries.filter((entry) => !COLUMNS.includes(entry.type));

        reset(
            toRecipientValues({
                to: others(rule.recipients.to),
                cc: others(rule.recipients.cc),
                bcc: others(rule.recipients.bcc),
            }),
        );
    }, [rule, reset]);

    const onSubmit = handleSubmit(async (values) => {
        if (!rule) {
            return;
        }

        const chosen = fromRecipientValues(values, byEmail);
        const columns = (field: RecipientField) => rule.recipients[field].filter((entry) => COLUMNS.includes(entry.type));

        const recipients: RuleRecipients = {
            to: [...columns('to'), ...chosen.to],
            cc: [...columns('cc'), ...chosen.cc],
            bcc: [...columns('bcc'), ...chosen.bcc],
        };

        const result = await submit(values, () => save.mutateAsync({ id: rule.id, recipients }));

        if (result) {
            onClose();
        }
    });

    const options = rule
        ? recipientOptions(
              rule,
              choices.data,
              (option) => !COLUMNS.includes(option.type) && !isRoleLikeAPlace(option),
          )
        : [];

    return (
        <FormModal
            open={rule !== null}
            onClose={onClose}
            title={rule ? `Others on ${rule.template_name ?? rule.title}` : 'Others'}
            subtitle="Another role, whoever booked it, or one named person"
            icon={<i className="ti ti-users-plus" />}
            size="md"
            className="rcp-modal"
            onSubmit={onSubmit}
            submitting={isSubmitting || save.isPending}
            submitLabel="Save"
        >
            {lists.map((list) => (
                <div key={list.field}>
                    <MultiSelectField<RecipientValues>
                        name={`recipients.${list.field}`}
                        label={list.label}
                        control={control}
                        options={options}
                        placeholder={choices.isLoading ? 'Loading…' : 'Choose who…'}
                        hint={byEmail ? undefined : list.hint}
                        error={recipientListError(errors.recipients, list.field)}
                    />

                    {byEmail && (
                        <TextField
                            name={`addresses.${list.field}`}
                            label={`${list.label} — other addresses`}
                            placeholder="owner@clinic.com, accounts@clinic.com"
                            hint={list.hint}
                            register={register}
                            errors={errors}
                        />
                    )}
                </div>
            ))}
        </FormModal>
    );
}

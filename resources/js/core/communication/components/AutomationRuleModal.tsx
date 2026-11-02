import { useEffect } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SelectField, TextField, TextareaField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { MultiSelectField, type Option } from '@/core/field-settings/MultiSelectField';
import { useRuleRecipientOptions } from '../api';
import type {
    AutomationRule,
    MessageTemplate,
    RecipientEntry,
    RecipientOptions,
    RuleRecipients,
} from '../types';

type Field = keyof RuleRecipients;

interface Values {
    title: string;
    description: string;
    message_template_id: string;
    lead_minutes: string;
    /** What each list's picker holds: `doctor`, `role:3`, `user:12`. */
    recipients: Record<Field, string[]>;
    /** Addresses typed in, per list — email only. */
    addresses: Record<Field, string>;
}

const NO_ONE: Record<Field, string[]> = { to: [], cc: [], bcc: [] };

const NO_ADDRESSES: Record<Field, string> = { to: '', cc: '', bcc: '' };

/** The lists each channel has. WhatsApp has none: it is the patient's own number. */
const LISTS: Partial<Record<AutomationRule['channel'], { field: Field; label: string; hint: string }[]>> = {
    email: [
        { field: 'to', label: 'To', hint: 'Who the email is addressed to.' },
        {
            field: 'cc',
            label: 'CC',
            hint: "Everyone on To and CC sees each other's address. Use BCC to keep someone hidden.",
        },
        { field: 'bcc', label: 'BCC', hint: 'Gets a copy; nobody else on the email sees them.' },
    ],
    push: [
        {
            field: 'to',
            label: 'Send to',
            hint: 'Each person gets it on every phone they are signed in to the app on. Nobody is copied on a notification.',
        },
    ],
};

/** An entry as the picker holds it. Typed addresses live in their own box. */
function encode(entry: RecipientEntry): string | null {
    if (entry.type === 'email') {
        return null;
    }

    return entry.type === 'role' || entry.type === 'user' ? `${entry.type}:${entry.id}` : entry.type;
}

function decode(value: string): RecipientEntry {
    const [type, id] = value.split(':');

    return id
        ? { type: type as RecipientEntry['type'], id: Number(id) }
        : { type: type as RecipientEntry['type'] };
}

function addressesIn(entries: RecipientEntry[]): string {
    return entries
        .filter((entry) => entry.type === 'email' && entry.address)
        .map((entry) => entry.address)
        .join(', ');
}

function typedAddresses(text: string): RecipientEntry[] {
    return text
        .split(/[\s,;]+/)
        .map((address) => address.trim())
        .filter(Boolean)
        .map((address) => ({ type: 'email', address }));
}

/**
 * Everything a list can name — the places on a record, then roles, then
 * people. A staff alert never offers the patient: it is written for staff.
 * Whatever is already saved stays offered under the name the server gave it,
 * even a role that has since been removed, so it can be seen and taken off.
 */
function optionsFor(rule: AutomationRule, choices: RecipientOptions | undefined): Option[] {
    const byEmail = rule.channel === 'email';

    const options: Option[] = [
        ...(choices?.places ?? [])
            .filter((place) => rule.audience !== 'staff' || place.type !== 'patient')
            .map((place) => ({ value: place.type, label: place.label })),
        ...(choices?.roles ?? []).map((role) => ({ value: `role:${role.id}`, label: `${role.name} (role)` })),
        ...(choices?.staff ?? []).map((person) => ({
            value: `user:${person.id}`,
            label: byEmail && !person.has_email ? `${person.name} (no email)` : person.name,
        })),
    ];

    for (const entry of [...rule.recipients.to, ...rule.recipients.cc, ...rule.recipients.bcc]) {
        const value = encode(entry);

        if (value && !options.some((option) => option.value === value)) {
            options.push({ value, label: entry.label ?? value });
        }
    }

    return options;
}

/**
 * When a rule fires, what it sends — and who it tells.
 *
 * The EVENT is not editable and never will be — a rule is the clinic's answer
 * to something the software already knows how to notice, and offering to
 * change that would be offering to point it at nothing. What a clinic owns is
 * the wording, the template, the timing, and the people.
 *
 * Only the rules that lead their event offer a delay. "Send the confirmation
 * forty minutes before the patient books" is not a sentence, so the field is
 * not there to be filled in wrongly.
 *
 * WHO is To / CC / BCC on an email and Send to on an App notification, by
 * place wherever possible: "Doctor" is the visit's own doctor, a different
 * person on every booking.
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
        defaultValues: {
            title: '',
            description: '',
            message_template_id: '',
            lead_minutes: '',
            recipients: NO_ONE,
            addresses: NO_ADDRESSES,
        },
    });

    const lists = rule ? (LISTS[rule.channel] ?? []) : [];
    const choices = useRuleRecipientOptions(rule !== null && lists.length > 0);

    useEffect(() => {
        if (!rule) {
            return;
        }

        const picked = (entries: RecipientEntry[]) =>
            entries.map(encode).filter((value): value is string => value !== null);

        reset({
            title: rule.title,
            description: rule.description ?? '',
            message_template_id: String(rule.message_template_id ?? ''),
            lead_minutes: rule.lead_minutes === null ? '' : String(rule.lead_minutes),
            recipients: {
                to: picked(rule.recipients.to),
                cc: picked(rule.recipients.cc),
                bcc: picked(rule.recipients.bcc),
            },
            addresses: {
                to: addressesIn(rule.recipients.to),
                cc: addressesIn(rule.recipients.cc),
                bcc: addressesIn(rule.recipients.bcc),
            },
        });
    }, [rule, reset]);

    /* A rule that leads its event has a delay; one that reacts to it cannot. */
    const leads = rule?.lead_minutes !== null && rule?.lead_minutes !== undefined;
    const byEmail = rule?.channel === 'email';

    const onSubmit = handleSubmit(async (values) => {
        if (!rule) {
            return;
        }

        const listed = (field: Field): RecipientEntry[] => [
            ...(values.recipients[field] ?? []).map(decode),
            ...(byEmail ? typedAddresses(values.addresses[field] ?? '') : []),
        ];

        const result = await submit(values, () =>
            onSave({
                id: rule.id,
                title: values.title.trim(),
                description: values.description.trim() || null,
                message_template_id: values.message_template_id
                    ? Number(values.message_template_id)
                    : null,
                lead_minutes: values.lead_minutes ? Number(values.lead_minutes) : null,
                ...(lists.length > 0 && {
                    recipients: {
                        to: listed('to'),
                        cc: lists.some((list) => list.field === 'cc') ? listed('cc') : [],
                        bcc: lists.some((list) => list.field === 'bcc') ? listed('bcc') : [],
                    },
                }),
            }),
        );

        if (result) {
            onClose();
        }
    });

    /*
     * A list's problem, wherever the server put it: on the list ("the patient
     * stays on To") or on one entry in it ("that role was not found").
     */
    const listError = (field: Field): string | undefined => {
        const node = (errors.recipients as Record<string, unknown> | undefined)?.[field] as
            | { message?: string }
            | Record<string, { message?: string; address?: { message?: string } }>
            | undefined;

        if (!node) {
            return undefined;
        }

        if (typeof (node as { message?: string }).message === 'string') {
            return (node as { message: string }).message;
        }

        for (const entry of Object.values(node as Record<string, { message?: string; address?: { message?: string } }>)) {
            const message = entry?.message ?? entry?.address?.message;

            if (message) {
                return message;
            }
        }

        return undefined;
    };

    /*
     * Only approved templates are offered, and only ones written for this
     * rule's reader. The server refuses anything else on a rule that is
     * switched on, and offering it here would be offering a choice that is
     * then rejected on save.
     */
    const approved = templates.filter(
        (template) =>
            template.status === 'approved' && (template.audience ?? 'patient') === (rule?.audience ?? 'patient'),
    );

    const options = rule ? optionsFor(rule, choices.data) : [];

    return (
        <FormModal
            open={rule !== null}
            onClose={onClose}
            title={rule ? `Edit ${rule.title}` : 'Edit rule'}
            subtitle="What this sends, when, and who it tells"
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
                                ? `No approved ${rule.audience === 'staff' ? 'staff' : 'patient'} templates yet — approve one before this rule can send.`
                                : rule.audience === 'staff'
                                  ? 'Templates written for staff. Only approved ones can be automated.'
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

                    <div className="comm-recip">
                        <div className="comm-recip-head">
                            <b>Who it tells</b>
                            <span>
                                {lists.length === 0
                                    ? "Sent to the patient's own WhatsApp number, and nobody else's."
                                    : 'Doctor is the visit’s own doctor, Booked by whoever made the booking, Front desk everyone at that branch who answers reschedule requests — worked out each time it sends.'}
                            </span>
                        </div>

                        {lists.map((list) => (
                            <div key={list.field}>
                                <MultiSelectField<Values>
                                    name={`recipients.${list.field}`}
                                    label={list.label}
                                    control={control}
                                    options={options}
                                    placeholder={choices.isLoading ? 'Loading…' : 'Choose who…'}
                                    hint={byEmail ? undefined : list.hint}
                                    error={listError(list.field)}
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
                    </div>
                </>
            )}
        </FormModal>
    );
}

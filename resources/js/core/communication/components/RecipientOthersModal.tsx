import { useEffect } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { MultiSelectField, type Option } from '@/core/field-settings/MultiSelectField';
import { useRuleRecipientOptions, useSaveRuleRecipients } from '../api';
import type { AutomationRule, RecipientEntry, RecipientOptions, RuleRecipients } from '../types';

type Field = keyof RuleRecipients;

interface Values {
    /** What each list's picker holds: `creator`, `role:3`, `user:12`. */
    recipients: Record<Field, string[]>;
    /** Addresses typed in, per list — email only. */
    addresses: Record<Field, string>;
}

const NO_ONE: Record<Field, string[]> = { to: [], cc: [], bcc: [] };

const NO_ADDRESSES: Record<Field, string> = { to: '', cc: '', bcc: '' };

/** The ones with a column of their own on the Recipients screen. */
const COLUMNS: RecipientEntry['type'][] = ['patient', 'doctor', 'front_desk', 'branch_manager'];

/** Roles the columns already speak for, so nobody picks the same job twice. */
const ROLES_WITH_A_COLUMN = ['branch manager', 'receptionist', 'doctor'];

const LISTS: Record<'email' | 'push', { field: Field; label: string; hint: string }[]> = {
    email: [
        { field: 'to', label: 'To', hint: 'Who the email is addressed to.' },
        { field: 'cc', label: 'CC', hint: 'Everyone on To and CC sees each other’s address.' },
        { field: 'bcc', label: 'BCC', hint: 'Gets a copy; nobody else on the email sees them.' },
    ],
    push: [
        {
            field: 'to',
            label: 'Send to',
            hint: 'Each person gets it on every phone they are signed in to the app on.',
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
 * Whoever has no column: another role, whoever booked it, one named person. What
 * is already saved stays offered under the name the server gave it, even a role
 * that has since been removed, so it can be seen and taken off.
 */
function optionsFor(rule: AutomationRule, choices: RecipientOptions | undefined): Option[] {
    const byEmail = rule.channel === 'email';

    const options: Option[] = [
        ...(choices?.places ?? [])
            .filter((place) => !COLUMNS.includes(place.type))
            .map((place) => ({ value: place.type, label: place.label })),
        ...(choices?.roles ?? [])
            .filter((role) => !ROLES_WITH_A_COLUMN.includes(role.name.toLowerCase()))
            .map((role) => ({ value: `role:${role.id}`, label: `${role.name} (role)` })),
        ...(choices?.staff ?? []).map((person) => ({
            value: `user:${person.id}`,
            label: byEmail && !person.has_email ? `${person.name} (no email)` : person.name,
        })),
    ];

    for (const entry of [...rule.recipients.to, ...rule.recipients.cc, ...rule.recipients.bcc]) {
        const value = encode(entry);

        if (value && !COLUMNS.includes(entry.type) && !options.some((option) => option.value === value)) {
            options.push({ value, label: entry.label ?? value });
        }
    }

    return options;
}

/**
 * Everybody on a message besides the Recipients screen's columns. The columns'
 * own people are carried through untouched.
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
    } = useApiForm<Values>({ defaultValues: { recipients: NO_ONE, addresses: NO_ADDRESSES } });

    const byEmail = rule?.channel === 'email';
    const lists = rule ? LISTS[byEmail ? 'email' : 'push'] : [];
    const choices = useRuleRecipientOptions(rule !== null);

    useEffect(() => {
        if (!rule) {
            return;
        }

        const picked = (entries: RecipientEntry[]) =>
            entries
                .filter((entry) => !COLUMNS.includes(entry.type))
                .map(encode)
                .filter((value): value is string => value !== null);

        reset({
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

    const onSubmit = handleSubmit(async (values) => {
        if (!rule) {
            return;
        }

        const listed = (field: Field): RecipientEntry[] => [
            ...rule.recipients[field].filter((entry) => COLUMNS.includes(entry.type)),
            ...(values.recipients[field] ?? []).map(decode),
            ...(byEmail ? typedAddresses(values.addresses[field] ?? '') : []),
        ];

        const result = await submit(values, () =>
            save.mutateAsync({
                id: rule.id,
                recipients: { to: listed('to'), cc: listed('cc'), bcc: listed('bcc') },
            }),
        );

        if (result) {
            onClose();
        }
    });

    /*
     * A list's problem, wherever the server put it: on the list or on one entry
     * in it ("that role was not found").
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

    const options = rule ? optionsFor(rule, choices.data) : [];

    return (
        <FormModal
            open={rule !== null}
            onClose={onClose}
            title={rule ? `Others on ${rule.template_name ?? rule.title}` : 'Others'}
            subtitle="Another role, whoever booked it, or one named person"
            icon={<i className="ti ti-users-plus" />}
            size="md"
            onSubmit={onSubmit}
            submitting={isSubmitting || save.isPending}
            submitLabel="Save"
        >
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
        </FormModal>
    );
}

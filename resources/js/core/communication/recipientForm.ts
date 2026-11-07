import type { Option } from '@/core/field-settings/MultiSelectField';
import type { AutomationRule, RecipientEntry, RecipientOptions, RuleRecipients } from './types';

export type RecipientField = keyof RuleRecipients;

/** What a form holds for one message's lists. */
export interface RecipientValues {
    /** What each list's picker holds: `doctor`, `role:3`, `user:12`. */
    recipients: Record<RecipientField, string[]>;
    /** Addresses typed in, per list. Email only. */
    addresses: Record<RecipientField, string>;
}

export const NO_RECIPIENTS: RecipientValues = {
    recipients: { to: [], cc: [], bcc: [] },
    addresses: { to: '', cc: '', bcc: '' },
};

/** The lists each channel has. WhatsApp has none: it is the patient's own number. */
export const RECIPIENT_LISTS: Record<'email' | 'push', { field: RecipientField; label: string; hint: string }[]> = {
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

/** One rule's lists as form values. */
export function toRecipientValues(lists: RuleRecipients): RecipientValues {
    const picked = (entries: RecipientEntry[]) =>
        entries.map(encode).filter((value): value is string => value !== null);

    const typed = (entries: RecipientEntry[]) =>
        entries
            .filter((entry) => entry.type === 'email' && entry.address)
            .map((entry) => entry.address)
            .join(', ');

    return {
        recipients: { to: picked(lists.to), cc: picked(lists.cc), bcc: picked(lists.bcc) },
        addresses: { to: typed(lists.to), cc: typed(lists.cc), bcc: typed(lists.bcc) },
    };
}

/** Form values as the lists a rule is saved with. */
export function fromRecipientValues(values: RecipientValues, byEmail: boolean): RuleRecipients {
    const listed = (field: RecipientField): RecipientEntry[] => [
        ...(values.recipients[field] ?? []).map(decode),
        ...(byEmail
            ? (values.addresses[field] ?? '')
                  .split(/[\s,;]+/)
                  .map((address) => address.trim())
                  .filter(Boolean)
                  .map((address): RecipientEntry => ({ type: 'email', address }))
            : []),
    ];

    return { to: listed('to'), cc: byEmail ? listed('cc') : [], bcc: byEmail ? listed('bcc') : [] };
}

/**
 * Roles a place on the record already speaks for. "Doctor" beside "Doctor (role)" is
 * the same word twice, with a difference nobody picking from a list would guess: the
 * visit's own doctor, against every doctor at the branch.
 */
const ROLES_LIKE_A_PLACE = ['branch manager', 'receptionist', 'doctor'];

export function isRoleLikeAPlace(option: { type: RecipientEntry['type']; name: string }): boolean {
    return option.type === 'role' && ROLES_LIKE_A_PLACE.includes(option.name.toLowerCase());
}

/** Two sets of lists saying the same thing, whatever order they were picked in. */
export function sameRecipients(a: RuleRecipients, b: RuleRecipients): boolean {
    const key = (lists: RuleRecipients) =>
        JSON.stringify(
            (['to', 'cc', 'bcc'] as RecipientField[]).map((field) =>
                lists[field].map((entry) => encode(entry) ?? `email:${entry.address}`).sort(),
            ),
        );

    return key(a) === key(b);
}

/**
 * Everybody a list can name: the places on a record, then roles, then people. A
 * staff alert never offers the patient, since it is written for staff. A role or a
 * person already saved stays offered under the name the server gave it, even one that
 * has since been removed, so it can be seen and taken off.
 */
export function recipientOptions(
    rule: AutomationRule,
    choices: RecipientOptions | undefined,
    keep: (option: { type: RecipientEntry['type']; name: string }) => boolean = () => true,
): Option[] {
    const byEmail = rule.channel === 'email';

    const options: Option[] = [
        ...(choices?.places ?? [])
            .filter((place) => rule.audience !== 'staff' || place.type !== 'patient')
            .filter((place) => keep({ type: place.type, name: place.label }))
            .map((place) => ({ value: place.type, label: place.label })),
        ...(choices?.roles ?? [])
            .filter((role) => keep({ type: 'role', name: role.name }))
            .map((role) => ({ value: `role:${role.id}`, label: `${role.name} (role)` })),
        ...(choices?.staff ?? []).map((person) => ({
            value: `user:${person.id}`,
            label: byEmail && !person.has_email ? `${person.name} (no email)` : person.name,
        })),
    ];

    for (const entry of [...rule.recipients.to, ...rule.recipients.cc, ...rule.recipients.bcc]) {
        const value = encode(entry);

        const named = entry.type === 'role' || entry.type === 'user';

        if (value && (named || keep({ type: entry.type, name: entry.label ?? '' })) && !options.some((option) => option.value === value)) {
            options.push({ value, label: entry.label ?? value });
        }
    }

    return options;
}

/**
 * A list's problem, wherever the server put it: on the list ("Choose who…") or on
 * one entry in it ("that role was not found").
 */
export function recipientListError(errors: unknown, field: RecipientField): string | undefined {
    const node = (errors as Record<string, unknown> | undefined)?.[field] as
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
}

import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useAutomationRules, useSaveRuleRecipients } from '../api';
import { lead } from '../components/AutomationRules';
import { RecipientOthersModal } from '../components/RecipientOthersModal';
import type { AutomationRule, RecipientEntry, RecipientType, RuleRecipients } from '../types';

type Channel = 'email' | 'push';
type Field = keyof RuleRecipients;

const CHANNELS: readonly TabItem<Channel>[] = [
    { value: 'email', label: 'Email', icon: 'ti ti-mail' },
    { value: 'push', label: 'Push notifications', icon: 'ti ti-bell-ringing' },
];

/** Who has a column of their own. Everybody else is under Others. */
const COLUMNS: { type: RecipientType; label: string }[] = [
    { type: 'patient', label: 'Patient' },
    { type: 'doctor', label: 'Doctor' },
    { type: 'front_desk', label: 'Reception' },
    { type: 'branch_manager', label: 'Branch manager' },
];

const FIELD_LABEL: Record<Field, string> = { to: 'To', cc: 'CC', bcc: 'BCC' };

const FIELDS: Field[] = ['to', 'cc', 'bcc'];

/** Which list this column is on for this message, if any. */
function fieldOf(rule: AutomationRule, type: RecipientType): Field | null {
    return FIELDS.find((field) => rule.recipients[field].some((entry) => entry.type === type)) ?? null;
}

/** The message's lists with this column moved to [$field], or off them all. */
function placed(rule: AutomationRule, type: RecipientType, field: Field | null): RuleRecipients {
    const without = (entries: RecipientEntry[]) => entries.filter((entry) => entry.type !== type);

    const next: RuleRecipients = {
        to: without(rule.recipients.to),
        cc: without(rule.recipients.cc),
        bcc: without(rule.recipients.bcc),
    };

    if (field) {
        next[field] = type === 'patient' ? [{ type }, ...next[field]] : [...next[field], { type }];
    }

    return next;
}

/** "CC: Priya Sharma", for whoever has no column. */
function others(rule: AutomationRule): { key: string; label: string }[] {
    const columns = COLUMNS.map((column) => column.type);

    return FIELDS.flatMap((field) =>
        rule.recipients[field]
            .filter((entry) => !columns.includes(entry.type))
            .map((entry, index) => ({
                key: `${field}-${entry.type}-${entry.id ?? entry.address ?? index}`,
                label:
                    rule.channel === 'email'
                        ? `${FIELD_LABEL[field]}: ${entry.label ?? entry.type}`
                        : (entry.label ?? entry.type),
            })),
    );
}

/**
 * Who gets every message, in one place.
 *
 * Each row is a message the software sends by itself, each column somebody it can
 * go to: To, CC or BCC on an email, on or off on a push. Nothing else in the app
 * changes this, so nobody has to open each template to find out or change it.
 *
 * Worked out each time a message goes: Doctor is that visit's own doctor, and
 * Reception and Branch manager are whoever holds those jobs at that visit's branch.
 *
 * WhatsApp has no row. It only ever goes to the patient's own number.
 */
export default function RecipientsPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const channel: Channel = searchParams.get('channel') === 'push' ? 'push' : 'email';

    const { data: rules, isLoading, isError, refetch } = useAutomationRules();
    const save = useSaveRuleRecipients();

    const [editing, setEditing] = useState<AutomationRule | null>(null);

    const shown = (rules ?? []).filter((rule) => rule.channel === channel);

    const groups = [
        { key: 'patient', title: 'Messages written for patients', rules: shown.filter((rule) => rule.audience !== 'staff') },
        { key: 'staff', title: 'Alerts written for staff', rules: shown.filter((rule) => rule.audience === 'staff') },
    ].filter((group) => group.rules.length > 0);

    /** Refused with the server's own reason: "Choose who this rule tells…". */
    function change(rule: AutomationRule, type: RecipientType, field: Field | null) {
        save.mutate(
            { id: rule.id, recipients: placed(rule, type, field) },
            { onError: (error) => notify.error(resolveErrorMessage(error)) },
        );
    }

    return (
        <>
            <PageHeader
                title="Recipients"
                subtitle="One place for who gets every email and push notification."
                icon="ti ti-users-group"
                tone="violet"
                crumbs={[{ label: 'Communication' }, { label: 'Recipients' }]}
            />

            <div className="rcp-tabs">
                <Tabs
                    tabs={CHANNELS}
                    value={channel}
                    onChange={(next) => setSearchParams(next === 'email' ? {} : { channel: next }, { replace: true })}
                    label="Channels"
                />
            </div>

            {isLoading ? (
                <div className="card">
                    <LoadingBlock label="Loading messages…" />
                </div>
            ) : isError ? (
                <div className="card">
                    <ErrorState onRetry={() => refetch()} />
                </div>
            ) : (
                <Card
                    className="comm-card"
                    title={channel === 'email' ? 'Who gets each email' : 'Who gets each push notification'}
                    icon={channel === 'email' ? 'ti ti-mail' : 'ti ti-bell-ringing'}
                    description={
                        channel === 'email'
                            ? 'Everyone on To and CC can see each other’s address. Use BCC to keep someone hidden.'
                            : 'Each person gets it on every phone they are signed in to the app on.'
                    }
                >
                    {groups.length === 0 ? (
                        <p className="pd-quiet">
                            <i className="ti ti-mood-empty" aria-hidden="true" />
                            Nothing is sent automatically on this channel yet.
                        </p>
                    ) : (
                        <div className="pf-table-wrap">
                            <table className="pf-table rcp-table">
                                <thead>
                                    <tr>
                                        <th>Message</th>
                                        {COLUMNS.map((column) => (
                                            <th key={column.type} className="rcp-col">
                                                {column.label}
                                            </th>
                                        ))}
                                        <th>Others</th>
                                    </tr>
                                </thead>

                                {groups.map((group) => (
                                    <tbody key={group.key}>
                                        <tr className="rcp-group">
                                            <th colSpan={COLUMNS.length + 2}>{group.title}</th>
                                        </tr>

                                        {group.rules.map((rule) => {
                                            const timing = lead(rule.lead_minutes);
                                            const rest = others(rule);

                                            return (
                                                <tr key={rule.id}>
                                                    <td className="rcp-message">
                                                        <b>
                                                            {rule.template_name ?? rule.title}
                                                            {timing && <em>{timing}</em>}
                                                            {!rule.is_enabled && <em className="is-off">Off</em>}
                                                        </b>
                                                        {rule.description && <small>{rule.description}</small>}
                                                    </td>

                                                    {COLUMNS.map((column) => {
                                                        const field = fieldOf(rule, column.type);
                                                        const label = `${column.label}: ${rule.template_name ?? rule.title}`;

                                                        // Staff wording never reaches a patient, and the server refuses it.
                                                        if (column.type === 'patient' && rule.audience === 'staff') {
                                                            return (
                                                                <td key={column.type} className="rcp-col">
                                                                    <span
                                                                        className="rcp-none"
                                                                        title="Written for staff. The patient has their own message for this."
                                                                    >
                                                                        —
                                                                    </span>
                                                                </td>
                                                            );
                                                        }

                                                        return (
                                                            <td key={column.type} className="rcp-col">
                                                                {channel === 'email' ? (
                                                                    <select
                                                                        className={`rcp-select${field ? ' is-on' : ''}`}
                                                                        aria-label={label}
                                                                        value={field ?? ''}
                                                                        disabled={save.isPending}
                                                                        onChange={(event) =>
                                                                            change(rule, column.type, (event.target.value || null) as Field | null)
                                                                        }
                                                                    >
                                                                        <option value="">—</option>
                                                                        {FIELDS.map((item) => (
                                                                            <option key={item} value={item}>
                                                                                {FIELD_LABEL[item]}
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                ) : (
                                                                    <input
                                                                        type="checkbox"
                                                                        className="form-check-input rcp-check"
                                                                        aria-label={label}
                                                                        checked={field !== null}
                                                                        disabled={save.isPending}
                                                                        onChange={() => change(rule, column.type, field ? null : 'to')}
                                                                    />
                                                                )}
                                                            </td>
                                                        );
                                                    })}

                                                    <td className="rcp-others">
                                                        {rest.map((item) => (
                                                            <span key={item.key} className="rcp-tag">
                                                                {item.label}
                                                            </span>
                                                        ))}

                                                        <button
                                                            type="button"
                                                            className="rcp-add"
                                                            aria-label={`Other people for ${rule.template_name ?? rule.title}`}
                                                            onClick={() => setEditing(rule)}
                                                        >
                                                            <i className={rest.length ? 'ti ti-pencil' : 'ti ti-plus'} aria-hidden="true" />
                                                            {rest.length ? 'Edit' : 'Add'}
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                ))}
                            </table>
                        </div>
                    )}
                </Card>
            )}

            <RecipientOthersModal rule={editing} onClose={() => setEditing(null)} />
        </>
    );
}

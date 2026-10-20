import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { cn } from '@/shared/utils/cn';
import { useColumnRules, useSaveColumnRules } from '../api';
import { ColumnChips } from '../components/ColumnChips';
import type { RuleRole, RuleTab, TabKey } from '../types';

/** Hidden columns, by export — compared and saved whole. */
type Hidden = Record<string, string[]>;

function copy(hidden: Hidden | undefined): Hidden {
    return Object.fromEntries(Object.entries(hidden ?? {}).map(([key, columns]) => [key, [...columns]]));
}

function same(a: Hidden, b: Hidden): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return [...keys].every((key) => {
        const left = [...(a[key] ?? [])].sort().join(',');
        const right = [...(b[key] ?? [])].sort().join(',');

        return left === right;
    });
}

/**
 * Column access — which columns each role may take out of the Export Center.
 *
 * A role on the left; on the right, every export under a tab, each with its
 * columns as chips: on means the role's holders may download that column,
 * off means it is left out of every file they make. Switching every column
 * off takes that export away from the role altogether.
 *
 * Whether the role may export a tab at all is the role screen's — its export
 * capability. An export under a tab the role cannot reach is still editable
 * here, marked as such, so the rules are ready the day the tab is given.
 */
export default function ExportColumnsPage() {
    const navigate = useNavigate();
    const confirm = useConfirm();
    const rules = useColumnRules();
    const save = useSaveColumnRules();

    const roles = rules.data?.roles ?? [];
    const tabs = rules.data?.tabs ?? [];

    const [roleId, setRoleId] = useState<number | null>(null);
    const [tabKey, setTabKey] = useState<TabKey | null>(null);
    const [draft, setDraft] = useState<Hidden>({});

    const role = roles.find((entry) => entry.id === roleId) ?? roles[0];
    const tab = tabs.find((entry) => entry.key === tabKey) ?? tabs[0];

    /*
     * Another role opens on what it has saved. Keyed on the id rather than
     * the object, so a background refetch does not wipe changes mid-edit.
     */
    useEffect(() => {
        if (role) setDraft(copy(role.hidden));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [role?.id]);

    const dirty = role ? !same(draft, role.hidden ?? {}) : false;

    async function pick(next: RuleRole) {
        if (next.id === role?.id) return;

        if (
            dirty &&
            !(await confirm({
                title: 'Discard changes?',
                message: `Your changes to ${role?.name} have not been saved.`,
                confirmLabel: 'Discard',
                danger: true,
            }))
        ) {
            return;
        }

        setRoleId(next.id);
    }

    async function submit() {
        if (!role) return;

        try {
            await save.mutateAsync({ role: role.id, hidden: draft });
            notify.success(`Column access saved for ${role.name}`);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        }
    }

    return (
        <div className="exc-page">
            <PageHeader
                title="Column access"
                subtitle="Choose which columns each role may download from the Export Center."
                icon="ti ti-columns-3"
                tone="sky"
                crumbs={[{ label: 'Export Center', to: '/exports' }, { label: 'Column access' }]}
                actions={
                    <Button variant="outline" icon="ti ti-arrow-left" onClick={() => navigate('/exports')}>
                        Export Center
                    </Button>
                }
            />

            {rules.isLoading ? (
                <section className="exc-card">
                    <LoadingBlock label="Loading roles…" />
                </section>
            ) : rules.isError || !role || !tab ? (
                <section className="exc-card">
                    <ErrorState onRetry={() => rules.refetch()} />
                </section>
            ) : (
                <div className="exc-layout">
                    <nav className="exc-card exc-sets" aria-label="Roles">
                        <p className="exc-eyebrow">Roles</p>

                        {roles.map((entry) => {
                            const reach = tabs.filter((candidate) => entry.tabs[candidate.key]);

                            return (
                                <button
                                    key={entry.id}
                                    type="button"
                                    className={cn('exc-set', entry.id === role.id && 'is-on')}
                                    onClick={() => void pick(entry)}
                                >
                                    <span className="exc-set-icon" aria-hidden="true">
                                        <i className={entry.icon ?? 'ti ti-shield-lock'} />
                                    </span>
                                    <span className="exc-set-text">
                                        <b>{entry.name}</b>
                                        <small>
                                            {entry.scope === 'organization' ? 'Organisation' : (entry.branch ?? 'Branch')}
                                            {' · '}
                                            {reach.length === 0 ? 'exports nothing' : reach.map((candidate) => candidate.label).join(', ')}
                                        </small>
                                    </span>
                                    {Object.keys(entry.hidden ?? {}).length > 0 && (
                                        <span className="exc-set-flag" title="Some columns hidden">
                                            <i className="ti ti-eye-off" aria-hidden="true" />
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </nav>

                    <section className="exc-card exc-rules">
                        <header className="exc-config-head">
                            <span className="exc-config-icon" aria-hidden="true">
                                <i className={role.icon ?? 'ti ti-shield-lock'} />
                            </span>
                            <div>
                                <h2>{role.name}</h2>
                                <p>
                                    Columns switched off are left out of every file this role&rsquo;s holders download —
                                    from here and from the Patient Records button alike.
                                </p>
                            </div>
                        </header>

                        <Tabs
                            label="Export areas"
                            size="sm"
                            value={tab.key}
                            onChange={setTabKey}
                            tabs={tabs.map((entry) => ({ value: entry.key, label: entry.label, icon: entry.icon }))}
                        />

                        <RuleTabBody tab={tab} role={role} draft={draft} onChange={setDraft} />

                        <footer className="exc-savebar">
                            <span className={cn('exc-muted', dirty && 'is-dirty')}>
                                <i className={dirty ? 'ti ti-point-filled' : 'ti ti-circle-check'} aria-hidden="true" />
                                {dirty ? 'Unsaved changes' : 'All changes saved'}
                            </span>

                            <span className="exc-savebar-actions">
                                <Button variant="outline" onClick={() => setDraft(copy(role.hidden))} disabled={!dirty}>
                                    Discard
                                </Button>
                                <Button icon="ti ti-device-floppy" onClick={() => void submit()} loading={save.isPending} disabled={!dirty}>
                                    Save
                                </Button>
                            </span>
                        </footer>
                    </section>
                </div>
            )}
        </div>
    );
}

/** One tab's exports for one role — a block each, its columns as chips. */
function RuleTabBody({
    tab,
    role,
    draft,
    onChange,
}: {
    tab: RuleTab;
    role: RuleRole;
    draft: Hidden;
    onChange: (next: Hidden) => void;
}) {
    const reaches = role.tabs[tab.key];

    const blocks = useMemo(
        () =>
            tab.datasets.map((dataset) => ({
                dataset,
                shown: dataset.columns
                    .map((column) => column.key)
                    .filter((key) => !(draft[dataset.key] ?? []).includes(key)),
            })),
        [tab, draft],
    );

    return (
        <div className="exc-rule-list">
            {!reaches && (
                <p className="exc-note">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    {role.name} cannot export {tab.label.toLowerCase()} records yet. Give it &ldquo;Export {tab.label.toLowerCase()} records&rdquo; on
                    the Roles &amp; Permissions screen — these columns apply from then on.
                </p>
            )}

            {blocks.map(({ dataset, shown }) => (
                <div key={dataset.key} className={cn('exc-rule', !reaches && 'is-dim', shown.length === 0 && 'is-off')}>
                    <div className="exc-rule-head">
                        <span className="exc-set-icon" aria-hidden="true">
                            <i className={dataset.icon} />
                        </span>
                        <b>{dataset.label}</b>
                        {shown.length === 0 && <span className="exc-pill is-off">Not exported</span>}
                        {shown.length > 0 && shown.length < dataset.columns.length && (
                            <span className="exc-pill">{dataset.columns.length - shown.length} hidden</span>
                        )}
                    </div>

                    <ColumnChips
                        columns={dataset.columns}
                        chosen={shown}
                        allLabel="Allow all"
                        noneLabel="Block export"
                        onChange={(next) => {
                            const hidden = dataset.columns.map((column) => column.key).filter((key) => !next.includes(key));
                            const updated = { ...draft };

                            if (hidden.length === 0) delete updated[dataset.key];
                            else updated[dataset.key] = hidden;

                            onChange(updated);
                        }}
                    />
                </div>
            ))}
        </div>
    );
}

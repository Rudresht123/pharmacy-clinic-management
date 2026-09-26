import { useMemo, useState } from 'react';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { useEffectivePermissions, useGrantable } from '../api';
import type { EffectivePermissionRow, PermissionSource } from '../types';

/**
 * Why this person can — or cannot — do each thing.
 *
 * Six levels decide every answer in this software, and from outside they are
 * indistinguishable: the module was never bought, the branch switched it off,
 * no role grants it, the branch took it off the role, somebody denied it to
 * this person, or they are not a member here. Five of those are fixed by a
 * different person on a different screen, so "you do not have permission" is
 * the least useful true sentence the software can say.
 *
 * Closed until asked for, and it fetches nothing until then: this is the
 * screen somebody opens when something has already gone wrong, not part of
 * administering staff.
 */

/** What each source reads as to somebody who has to act on it. */
const SOURCE: Record<PermissionSource, { label: string; tone: string }> = {
    owner: { label: 'Account owner', tone: 'ok' },
    organization_role: { label: 'Organisation role', tone: 'ok' },
    branch_role: { label: 'Branch role', tone: 'ok' },
    branch_override: { label: 'Switched off for this branch', tone: 'warn' },
    user_denied: { label: 'Denied for this person', tone: 'warn' },
    module_off: { label: 'Module off at this branch', tone: 'off' },
    not_sold: { label: 'Not in your plan', tone: 'off' },
    no_role: { label: 'No role grants it', tone: 'off' },
};

export function EffectivePermissionsPanel({ userId }: { userId: number }) {
    const [open, setOpen] = useState(false);
    const [showRefused, setShowRefused] = useState(false);

    const { data, isLoading } = useEffectivePermissions(userId, open);

    // Only for the module names and the order they are shown in; the answers
    // themselves come from the server.
    const { data: modules } = useGrantable();

    const grouped = useMemo(() => {
        const rows = (data?.capabilities ?? []).filter(
            (row) => showRefused || row.allowed,
        );

        const names = new Map((modules ?? []).map((module) => [module.key, module]));
        const labels = new Map<string, string>();

        for (const module of modules ?? []) {
            for (const capability of module.capabilities) {
                labels.set(capability.key, capability.name);
            }
        }

        const byModule = new Map<string, EffectivePermissionRow[]>();

        for (const row of rows) {
            const key = row.module ?? 'other';
            byModule.set(key, [...(byModule.get(key) ?? []), row]);
        }

        return [...byModule.entries()].map(([key, entries]) => ({
            key,
            name: names.get(key)?.name ?? key,
            icon: names.get(key)?.icon ?? 'ti ti-puzzle',
            rows: entries,
            label: (capability: string) => labels.get(capability) ?? capability,
        }));
    }, [data, modules, showRefused]);

    return (
        <section className="ep-panel">
            <button type="button" className="ep-toggle" onClick={() => setOpen((on) => !on)}>
                <i className={open ? 'ti ti-chevron-down' : 'ti ti-chevron-right'} aria-hidden="true" />
                <b>What they can actually do</b>
                <small>and which setting decided it</small>
            </button>

            {open && (isLoading || !data ? (
                <LoadingBlock label="Working it out…" />
            ) : (
                <div className="ep-body">
                    <header className="ep-head">
                        <span>
                            {data.role.branch ? (
                                <>
                                    <i className="ti ti-building-store" aria-hidden="true" />
                                    {data.role.branch} at this branch
                                </>
                            ) : (
                                <>
                                    <i className="ti ti-user-off" aria-hidden="true" />
                                    No branch role
                                </>
                            )}
                        </span>

                        {data.role.organization && (
                            <span>
                                <i className="ti ti-building" aria-hidden="true" />
                                {data.role.organization} across the organisation
                            </span>
                        )}

                        <label className="ep-filter">
                            <input
                                type="checkbox"
                                checked={showRefused}
                                onChange={() => setShowRefused((on) => !on)}
                            />
                            Show what they cannot do
                        </label>
                    </header>

                    {grouped.length === 0 ? (
                        <p className="dpt-none">
                            Nothing at all — they hold no role that grants anything here.
                        </p>
                    ) : (
                        grouped.map((module) => (
                            <div className="ep-module" key={module.key}>
                                <h6>
                                    <i className={module.icon} aria-hidden="true" />
                                    {module.name}
                                </h6>

                                <ul>
                                    {module.rows.map((row) => (
                                        <li key={row.capability}>
                                            <i
                                                className={
                                                    row.allowed
                                                        ? 'ti ti-check ep-yes'
                                                        : 'ti ti-x ep-no'
                                                }
                                                aria-hidden="true"
                                            />

                                            <span className="ep-what">
                                                <b>{module.label(row.capability)}</b>
                                                <code>{row.capability}</code>
                                            </span>

                                            <em className={`ep-src is-${SOURCE[row.source].tone}`}>
                                                {SOURCE[row.source].label}
                                                {row.is_locked && (
                                                    <i className="ti ti-lock" title="Locked by your organisation" />
                                                )}
                                            </em>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))
                    )}
                </div>
            ))}
        </section>
    );
}

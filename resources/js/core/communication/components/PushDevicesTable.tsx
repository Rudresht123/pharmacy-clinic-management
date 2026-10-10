import { useEffect, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { Pagination } from '@/shared/components/ui/Pagination';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { usePushDevices, useRemovePushDevice, type PushDeviceFilters } from '../api';
import type { PushDevice } from '../types';

const PLATFORM: Record<PushDevice['platform'], { icon: string; label: string; device: string }> = {
    android: { icon: 'ti ti-brand-android', label: 'Android', device: 'Android phone' },
    ios: { icon: 'ti ti-brand-apple', label: 'iOS', device: 'iPhone' },
    web: { icon: 'ti ti-world', label: 'Web', device: 'Browser' },
};

/** "10 min ago", without a date library for one column. */
function ago(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    const minutes = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 60000));

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.round(hours / 24);

    return `${days} day${days === 1 ? '' : 's'} ago`;
}

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
}

/**
 * The phones registered for this clinic's App notifications.
 *
 * Nobody adds a phone here by hand — a phone registers itself when somebody
 * signs in to the app, and that is the only way its Firebase address can be
 * known. "Add device" therefore explains how, rather than offering a form
 * that could only ever be filled with something made up.
 *
 * Switching one off is for the phone somebody lost or handed on: it stops at
 * once, and comes back by itself the next time its owner signs in there.
 */
export function PushDevicesTable({
    compact = false,
    days,
    canManage,
}: {
    /** The overview's short version: five rows, same filters. */
    compact?: boolean;
    days: number;
    canManage: boolean;
}) {
    const { organization } = useTenantAuth();

    const [filters, setFilters] = useState<PushDeviceFilters>({});
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [viewing, setViewing] = useState<PushDevice | null>(null);
    const [explaining, setExplaining] = useState(false);

    // Typed, not sent per keystroke.
    const [debounced, setDebounced] = useState('');

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(search.trim()), 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => setPage(1), [filters, debounced]);

    const perPage = compact ? 5 : 15;

    const { data, isLoading } = usePushDevices({ ...filters, search: debounced || undefined, page, per_page: perPage, days });
    const remove = useRemovePushDevice();

    function narrow<K extends keyof PushDeviceFilters>(key: K, value: string) {
        setFilters((current) => ({ ...current, [key]: value === '' ? undefined : value }));
    }

    function switchOff(device: PushDevice) {
        if (
            window.confirm(
                `Stop sending notifications to ${device.user.name}'s ${PLATFORM[device.platform].device}? ` +
                    'It starts again by itself the next time they sign in to the app there.',
            )
        ) {
            remove.mutate(device.id, { onSuccess: () => setViewing(null) });
        }
    }

    const rows = data?.data ?? [];
    const meta = data?.meta;

    return (
        <Card
            className="ph-card"
            title="Device tokens"
            icon="ti ti-device-mobile"
            description="Registered devices for push notifications"
            actions={
                <Button size="sm" variant="outline" className="ph-outline" icon="ti ti-plus" onClick={() => setExplaining(true)}>
                    Add device
                </Button>
            }
        >
            <div className="ph-filters">
                <select
                    className="form-select"
                    aria-label="Who"
                    value={filters.audience ?? ''}
                    onChange={(event) => narrow('audience', event.target.value)}
                >
                    <option value="">All users</option>
                    <option value="patients">Patients</option>
                    <option value="staff">Staff</option>
                </select>

                <select
                    className="form-select"
                    aria-label="Platform"
                    value={filters.platform ?? ''}
                    onChange={(event) => narrow('platform', event.target.value)}
                >
                    <option value="">All platforms</option>
                    <option value="android">Android</option>
                    <option value="ios">iOS</option>
                </select>

                <select
                    className="form-select"
                    aria-label="Status"
                    value={filters.status ?? ''}
                    onChange={(event) => narrow('status', event.target.value)}
                >
                    <option value="">All status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <label className="ph-search">
                    <i className="ti ti-search" aria-hidden="true" />
                    <input
                        type="search"
                        placeholder="Search user name, patient code or device…"
                        value={search}
                        aria-label="Search devices"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </label>
            </div>

            {isLoading && !data ? (
                <LoadingBlock />
            ) : rows.length === 0 ? (
                <p className="ph-empty">
                    <i className="ti ti-device-mobile-off" aria-hidden="true" />
                    {meta?.summary.total === 0
                        ? 'No phone has registered yet. Phones appear here when somebody signs in to the app.'
                        : 'No device matches those filters.'}
                </p>
            ) : (
                <div className="ph-table-wrap">
                    <table className="ph-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Device</th>
                                <th>Platform</th>
                                <th>App version</th>
                                <th>Status</th>
                                <th>Last seen</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((device, index) => (
                                <tr key={device.id}>
                                    <td className="ph-num">{(meta?.from ?? 1) + index}</td>

                                    <td>
                                        <span className="ph-person">
                                            <b>{device.user.name}</b>
                                            <small>
                                                {device.user.kind === 'patient'
                                                    ? (device.user.code ?? 'Patient')
                                                    : (device.user.role ?? 'Staff')}
                                            </small>
                                        </span>
                                    </td>

                                    <td>
                                        <span className="ph-person">
                                            <span>{PLATFORM[device.platform].device}</span>
                                            <small title={device.device_id}>{device.device_id.slice(0, 10)}</small>
                                        </span>
                                    </td>

                                    <td>
                                        <span
                                            className={`ph-platform is-${device.platform}`}
                                            title={PLATFORM[device.platform].label}
                                        >
                                            <i className={PLATFORM[device.platform].icon} aria-hidden="true" />
                                            <span className="visually-hidden">{PLATFORM[device.platform].label}</span>
                                        </span>
                                    </td>

                                    <td>{device.app_version ?? '—'}</td>

                                    <td>
                                        <span className={`ph-badge ${device.is_active ? 'is-on' : 'is-bad'}`}>
                                            {device.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>

                                    <td className="ph-quiet" title={when(device.last_seen_at)}>
                                        {ago(device.last_seen_at)}
                                    </td>

                                    <td>
                                        <span className="ph-row-actions justify-content-end">
                                            <button
                                                type="button"
                                                className="ph-icon-btn is-boxed"
                                                title="Details"
                                                aria-label={`Details of ${device.user.name}'s device`}
                                                onClick={() => setViewing(device)}
                                            >
                                                <i className="ti ti-eye" />
                                            </button>

                                            {canManage && device.is_active && (
                                                <button
                                                    type="button"
                                                    className="ph-icon-btn is-boxed is-danger"
                                                    title="Stop sending to this phone"
                                                    aria-label={`Stop sending to ${device.user.name}'s device`}
                                                    disabled={remove.isPending}
                                                    onClick={() => switchOff(device)}
                                                >
                                                    <i className="ti ti-trash" />
                                                </button>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {meta && meta.total > 0 && (
                <Pagination
                    page={meta.current_page}
                    pageCount={meta.last_page}
                    total={meta.total}
                    perPage={meta.per_page}
                    onChange={setPage}
                />
            )}

            <Modal
                open={viewing !== null}
                title={viewing ? `${viewing.user.name}'s ${PLATFORM[viewing.platform].device}` : 'Device'}
                subtitle="Registered for app notifications"
                icon={<i className="ti ti-device-mobile" />}
                onClose={() => setViewing(null)}
                footer={
                    viewing && canManage && viewing.is_active ? (
                        <Button variant="danger" icon="ti ti-trash" loading={remove.isPending} onClick={() => switchOff(viewing)}>
                            Stop sending to this phone
                        </Button>
                    ) : undefined
                }
            >
                {viewing && (
                    <dl className="ph-details">
                        <div>
                            <dt>Belongs to</dt>
                            <dd>
                                {viewing.user.name}
                                {viewing.user.code ? ` · ${viewing.user.code}` : ''}
                                {viewing.user.kind === 'staff' && viewing.user.role ? ` · ${viewing.user.role}` : ''}
                            </dd>
                        </div>
                        <div>
                            <dt>Platform</dt>
                            <dd>{PLATFORM[viewing.platform].label}</dd>
                        </div>
                        <div>
                            <dt>App version</dt>
                            <dd>{viewing.app_version ?? '—'}</dd>
                        </div>
                        <div>
                            <dt>Status</dt>
                            <dd>{viewing.is_active ? 'Active' : 'Inactive — signed out or switched off'}</dd>
                        </div>
                        <div>
                            <dt>Registered</dt>
                            <dd>{when(viewing.registered_at)}</dd>
                        </div>
                        <div>
                            <dt>Last seen</dt>
                            <dd>
                                {when(viewing.last_seen_at)} ({ago(viewing.last_seen_at)})
                            </dd>
                        </div>
                        <div>
                            <dt>Device id</dt>
                            <dd className="ph-mono">{viewing.device_id}</dd>
                        </div>
                        <div>
                            <dt>Firebase token</dt>
                            <dd className="ph-mono">{viewing.token_hint} (kept on the server)</dd>
                        </div>
                    </dl>
                )}
            </Modal>

            <Modal
                open={explaining}
                title="Adding a device"
                subtitle="Phones add themselves — there is nothing to type in here"
                icon={<i className="ti ti-device-mobile-plus" />}
                onClose={() => setExplaining(false)}
                footer={<Button onClick={() => setExplaining(false)}>Got it</Button>}
            >
                <ol className="ph-howto">
                    <li>
                        <b>Install the app</b> on the phone.
                    </li>
                    <li>
                        <b>Sign in</b> with the clinic code <code>{organization?.subdomain ?? 'your clinic code'}</code> —
                        patients with their phone number, staff with their work email.
                    </li>
                    <li>
                        <b>Allow notifications</b> when the phone asks. The phone then appears in this list,
                        and signing out of the app removes it again.
                    </li>
                </ol>
            </Modal>
        </Card>
    );
}

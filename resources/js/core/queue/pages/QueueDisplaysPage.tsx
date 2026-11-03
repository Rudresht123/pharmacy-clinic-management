import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Modal } from '@/shared/components/ui/Modal';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { notify } from '@/shared/utils/notify';
import { useDeleteDisplay, useDisplayAction, useQueueDisplays, useToggleDisplay } from '../api';
import type { DisplayType, QueueDisplay } from '../types';

const TYPE_LABEL: Record<DisplayType, string> = {
    reception: 'Reception',
    department: 'Department',
    doctor: 'Doctor room',
    laboratory: 'Laboratory',
};

/** What is on it, in the fewest words. */
function shows(display: QueueDisplay): string {
    if (display.display_type === 'laboratory') {
        return 'Laboratory orders';
    }

    if (display.doctors.length === 1) {
        return display.doctors[0].name ?? '1 doctor';
    }

    if (display.doctors.length > 1) {
        return `${display.doctors.length} doctors chosen`;
    }

    return display.display_type === 'department'
        ? `Everyone in ${display.department_name ?? 'the department'}`
        : 'Everyone sitting today';
}

function Connection({ display }: { display: QueueDisplay }) {
    if (display.refusal) {
        const words = { revoked: 'Revoked', expired: 'Expired', inactive: 'Switched off' };

        return <span className="sq-state is-paused">{words[display.refusal]}</span>;
    }

    return (
        <span className={`sq-state ${display.is_online ? 'is-on' : 'is-off'}`}>
            <i aria-hidden="true" className="sq-dot" />
            {display.is_online ? 'Connected' : 'Not connected'}
        </span>
    );
}

/** The link, the one time it can be shown. The server has only the hash after this. */
function LinkDialog({ url, onClose }: { url: string | null; onClose: () => void }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => setCopied(false), [url]);

    async function copy() {
        if (!url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            notify.error('Your browser would not copy this. Select the link and copy it by hand.');
        }
    }

    return (
        <Modal
            open={url !== null}
            onClose={onClose}
            size="md"
            icon={<i className="ti ti-link" aria-hidden="true" />}
            title="Open this link on the television"
            subtitle="Copy it now. It will not be shown again."
            footer={
                <>
                    <Button variant="light" onClick={onClose}>
                        Done
                    </Button>
                    {url && (
                        <a className="btn btn-light" href={url} target="_blank" rel="noreferrer">
                            <i className="ti ti-external-link" aria-hidden="true" /> Open
                        </a>
                    )}
                    <Button icon={copied ? 'ti ti-check' : 'ti ti-copy'} onClick={copy}>
                        {copied ? 'Copied' : 'Copy link'}
                    </Button>
                </>
            }
        >
            <code className="sq-link">{url}</code>

            <p className="sq-note">
                Anybody with this link can see this display's tokens, doctors and rooms, and nothing about any
                patient. Only a secure copy of it is kept, so if it is lost, use New link to replace it.
            </p>
        </Modal>
    );
}

/**
 * Every television the organization has.
 *
 * Connected means the screen has checked in within the last couple of minutes,
 * which it does by itself all day. Nothing here shows a display's link again:
 * only its hash exists. Preview, on the other hand, needs no link at all.
 */
export default function QueueDisplaysPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const confirm = useConfirm();

    const { data = [], isLoading, isError, refetch } = useQueueDisplays();
    const action = useDisplayAction();
    const remove = useDeleteDisplay();
    const toggle = useToggleDisplay();

    const [link, setLink] = useState<string | null>(null);
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const menuBox = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (menuFor === null) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (menuBox.current && !menuBox.current.contains(event.target as Node)) {
                setMenuFor(null);
            }
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setMenuFor(null);
            }
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [menuFor]);

    // The form hands over a brand new display's link as it comes back here.
    useEffect(() => {
        const state = location.state as { link?: string } | null;

        if (state?.link) {
            setLink(state.link);
            navigate(location.pathname, { replace: true, state: null });
        }
    }, [location.pathname, location.state, navigate]);

    async function rotate(display: QueueDisplay) {
        const yes = await confirm({
            title: 'Make a new link?',
            message: `${display.display_name} stops working at its current link straight away. Open the new link on the television to bring it back.`,
            confirmLabel: 'Make new link',
        });

        if (yes) {
            action.mutate(
                { id: display.id, action: 'rotate' },
                { onSuccess: (response) => setLink(response.data.url ?? null) },
            );
        }
    }

    async function revoke(display: QueueDisplay) {
        const yes = await confirm({
            title: 'Revoke access?',
            message: `${display.display_name} goes dark within a minute. Making a new link later brings it back.`,
            confirmLabel: 'Revoke',
            danger: true,
        });

        if (yes) {
            action.mutate(
                { id: display.id, action: 'revoke' },
                { onSuccess: (response) => notify.success(response.message ?? 'Revoked') },
            );
        }
    }

    async function destroy(display: QueueDisplay) {
        const yes = await confirm({
            title: 'Remove this display?',
            message: `${display.display_name} and its link stop working for good.`,
            confirmLabel: 'Remove',
            danger: true,
        });

        if (yes) {
            remove.mutate(display.id);
        }
    }

    return (
        <>
            <PageHeader
                title="TV displays"
                subtitle="The screens in your waiting rooms and outside your doctors' rooms."
                icon="ti ti-device-tv"
                tone="sky"
                crumbs={[{ label: 'Settings' }, { label: 'TV displays' }]}
                actions={
                    <Link className="btn btn-primary" to="/settings/tv-displays/new">
                        <i className="ti ti-plus" aria-hidden="true" /> New display
                    </Link>
                }
            />

            <Card className="sq-board">
                {isLoading ? (
                    <LoadingBlock label="Loading displays…" />
                ) : isError ? (
                    <ErrorState message="Unable to load the displays." onRetry={() => refetch()} />
                ) : data.length === 0 ? (
                    <div className="org-pending">
                        <i className="ti ti-device-tv" />
                        <h6>No displays yet</h6>
                        <p>Make one for the waiting room, and one for each doctor's door if you want them.</p>
                        <Link className="btn-tone btn-tone--solid" to="/settings/tv-displays/new">
                            <i className="ti ti-plus" /> New display
                        </Link>
                    </div>
                ) : (
                    <div className="q-table-scroll tbl-cards-scroll">
                        <table className="q-table tbl-cards sq-table">
                            <thead>
                                <tr>
                                    <th>Display</th>
                                    <th>Branch</th>
                                    <th>Shows</th>
                                    <th>Look</th>
                                    <th>Connection</th>
                                    <th>Last seen</th>
                                    <th className="q-th-act">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((display) => (
                                    <tr key={display.id}>
                                        <td data-label="Display">
                                            <b className="fw-semibold">{display.display_name}</b>
                                            <small className="sq-event-detail">{TYPE_LABEL[display.display_type]}</small>
                                        </td>
                                        <td className="q-dim" data-label="Branch">{display.location_name}</td>
                                        <td className="q-dim" data-label="Shows">{shows(display)}</td>
                                        <td className="q-dim" data-label="Look">
                                            {display.layout === 'list' ? 'List' : 'Cards'}, {display.theme}
                                            {display.audio_enabled ? ', with sound' : ''}
                                        </td>
                                        <td data-label="Connection">
                                            <Connection display={display} />
                                        </td>
                                        <td className="q-dim" data-label="Last seen">
                                            {display.last_connected_at
                                                ? new Date(display.last_connected_at).toLocaleString(undefined, {
                                                      day: 'numeric',
                                                      month: 'short',
                                                      hour: '2-digit',
                                                      minute: '2-digit',
                                                  })
                                                : 'Never'}
                                        </td>
                                        <td className="q-row-act" data-label="">
                                            <div className="q-actions">
                                                <Link className="q-act" to={`/settings/tv-displays/${display.id}/preview`}>
                                                    <i className="ti ti-eye" /> Preview
                                                </Link>
                                                <Link className="q-act" to={`/settings/tv-displays/${display.id}/edit`}>
                                                    <i className="ti ti-pencil" /> Edit
                                                </Link>
                                                <button type="button" className="q-act" disabled={action.isPending} onClick={() => rotate(display)}>
                                                    <i className="ti ti-refresh" /> New link
                                                </button>

                                                <div className="q-more" ref={menuFor === display.id ? menuBox : undefined}>
                                                    <button
                                                        type="button"
                                                        className="q-act is-icon"
                                                        aria-label={`More actions for ${display.display_name}`}
                                                        aria-haspopup="menu"
                                                        aria-expanded={menuFor === display.id}
                                                        onClick={() => setMenuFor(menuFor === display.id ? null : display.id)}
                                                    >
                                                        <i className="ti ti-dots-vertical" />
                                                    </button>

                                                    {menuFor === display.id && (
                                                        <div className="q-menu" role="menu">
                                                            <button
                                                                type="button"
                                                                role="menuitem"
                                                                className="q-menu-item"
                                                                onClick={() => {
                                                                    setMenuFor(null);
                                                                    toggle.mutate({ display, active: !display.is_active });
                                                                }}
                                                            >
                                                                <i className={display.is_active ? 'ti ti-power' : 'ti ti-player-play'} />
                                                                {display.is_active ? 'Switch off' : 'Switch on'}
                                                            </button>
                                                            {!display.revoked_at && (
                                                                <button
                                                                    type="button"
                                                                    role="menuitem"
                                                                    className="q-menu-item is-danger"
                                                                    onClick={() => {
                                                                        setMenuFor(null);
                                                                        revoke(display);
                                                                    }}
                                                                >
                                                                    <i className="ti ti-ban" /> Revoke access
                                                                </button>
                                                            )}
                                                            <button
                                                                type="button"
                                                                role="menuitem"
                                                                className="q-menu-item is-danger"
                                                                onClick={() => {
                                                                    setMenuFor(null);
                                                                    destroy(display);
                                                                }}
                                                            >
                                                                <i className="ti ti-trash" /> Remove
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <LinkDialog url={link} onClose={() => setLink(null)} />
        </>
    );
}

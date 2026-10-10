import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { initials } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { countOpdTabs, opdTab, type OpdHandoffState, type OpdPatient, type OpdTab } from '../opd';

const TABS: { key: OpdTab; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'completed', label: 'Completed' },
];

const EMPTY: Record<OpdTab, string> = {
    pending: 'Nobody waiting. A patient appears here as soon as their doctor completes the consultation.',
    in_progress: 'Nobody part-way through. A prescription handed over in part waits here for the rest.',
    completed: 'Nobody finished yet today.',
};

/** Rows before "View All" — about what fits under the shelf beside the bill. */
const FOLDED = 5;

/**
 * Finished this recently, a waiting patient reads "Just consulted": they are
 * probably still walking from the doctor's room to the counter.
 */
const JUST_CONSULTED_MS = 10 * 60 * 1000;

/** A stable colour per patient, from the name, so a row keeps its colour as the list moves. */
const HUES = ['sky', 'violet', 'amber', 'emerald', 'rose', 'teal'] as const;

function hueOf(name: string): (typeof HUES)[number] {
    let hash = 0;

    for (const char of name) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return HUES[hash % HUES.length];
}

function clock(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

/** Re-renders every half minute, so "Just consulted" turns into "Waiting" on its own. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

function Status({ patient, now }: { patient: OpdPatient; now: number }) {
    const state = patient.pharmacy_state ?? (patient.pharmacy_pending ? 'pending' : 'completed');

    if (state === 'pending') {
        const fresh =
            patient.completed_at !== null &&
            now - Date.parse(patient.completed_at) < JUST_CONSULTED_MS;

        return fresh ? (
            <span className="opd-hand-status">
                <i className="opd-hand-dot" aria-hidden="true" />
                <span className="opd-hand-pill is-new">Just consulted</span>
            </span>
        ) : (
            <span className="opd-hand-status">
                <span className="opd-hand-pill is-waiting">Waiting</span>
            </span>
        );
    }

    const [label, tone] =
        state === 'in_progress'
            ? ['Part dispensed', 'is-progress']
            : state === 'completed'
              ? ['Dispensed', 'is-done']
              : ['No prescription', ''];

    return (
        <span className="opd-hand-status">
            <span className={`opd-hand-pill ${tone}`}>{label}</span>
        </span>
    );
}

/**
 * The "⋮" beside a row. Fixed to the viewport, like RowActions, because the
 * table scrolls inside its frame and would clip a menu anchored in the row.
 */
function RowMenu({ patient }: { patient: OpdPatient }) {
    const { can } = useTenantAuth();
    const [open, setOpen] = useState(false);
    const [at, setAt] = useState({ top: 0, right: 0 });
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function away(event: MouseEvent) {
            const target = event.target as Node;

            if (!button.current?.contains(target) && !menu.current?.contains(target)) {
                setOpen(false);
            }
        }

        function shut() {
            setOpen(false);
        }

        function escape(event: KeyboardEvent) {
            if (event.key === 'Escape') setOpen(false);
        }

        document.addEventListener('mousedown', away);
        document.addEventListener('keydown', escape);
        window.addEventListener('scroll', shut, true);

        return () => {
            document.removeEventListener('mousedown', away);
            document.removeEventListener('keydown', escape);
            window.removeEventListener('scroll', shut, true);
        };
    }, [open]);

    // The only thing it offers is the patient's file; without that there is no menu.
    if (patient.patient.id === null || !can('customers.view')) {
        return <span className="opd-hand-menu-gap" aria-hidden="true" />;
    }

    return (
        <>
            <button
                ref={button}
                type="button"
                className="opd-hand-menu"
                aria-label={`More for ${patient.patient.name ?? 'this patient'}`}
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => {
                    const rect = button.current?.getBoundingClientRect();

                    if (rect) setAt({ top: rect.bottom + 4, right: window.innerWidth - rect.right });

                    setOpen((was) => !was);
                }}
            >
                <i className="ti ti-dots-vertical" aria-hidden="true" />
            </button>

            {open && (
                <div
                    ref={menu}
                    className="dropdown-menu show mt-0"
                    role="menu"
                    style={{ position: 'fixed', top: at.top, right: at.right, left: 'auto' }}
                >
                    <Link
                        to={`/customers/${patient.patient.id}`}
                        className="dropdown-item"
                        role="menuitem"
                        onClick={() => setOpen(false)}
                    >
                        <i className="ti ti-id me-1 align-middle" aria-hidden="true" />
                        <span className="align-middle">Open patient file</span>
                    </Link>
                </div>
            )}
        </>
    );
}

/**
 * Patients from OPD — who the doctor has just finished with, at this
 * store's branch.
 *
 * Shown ONLY where the branch runs both the OPD and the pharmacy
 * (`opd_pharmacy_handoff`). A medical shop that was never sold appointments
 * never sees it, and nor does a counter at a branch with no OPD. Hiding it is
 * a courtesy; the endpoint and the channel refuse the same people.
 *
 * Live over Reverb when it is running, re-fetched every half minute when it
 * is not, so the counter is never silently stale. The data comes from the
 * page (useOpdHandoff), which shares it with the tiles above.
 */
export function OpdPatientsPanel({
    handoff,
    onPick,
    onBill,
}: {
    handoff: OpdHandoffState;
    /** Start the bill for this patient. */
    onPick: (patient: OpdPatient) => void;
    /** The patient the bill is already for, so their row can say so. */
    onBill?: number | null;
}) {
    const [tab, setTab] = useState<OpdTab>('pending');
    const [all, setAll] = useState(false);
    const now = useNow();

    const { shown, live, query } = handoff;

    if (!shown) {
        return null;
    }

    const patients = query.data?.patients ?? [];
    const counts = countOpdTabs(patients);
    const inTab = patients.filter((patient) => opdTab(patient) === tab);
    const rows = all ? inTab : inTab.slice(0, FOLDED);

    return (
        <Card
            className="opd-handoff"
            icon="ti ti-user"
            title={
                <span className="opd-hand-title">
                    Patients from OPD
                    <span
                        className={`opd-hand-live${live ? ' is-live' : ''}`}
                        title={live ? 'Updates the moment a doctor finishes' : 'Checks every 30 seconds'}
                    >
                        <i className={live ? 'ti ti-circle-check' : 'ti ti-refresh'} aria-hidden="true" />
                        {live ? 'Live' : 'Auto-refresh'}
                    </span>
                </span>
            }
            description="Showing patients after consultation (Today)"
            actions={
                <div className="opd-hand-tools">
                    {/* Today only, by design: yesterday's patient who never came
                        to the counter is a follow-up, not a hand-off. */}
                    <span className="opd-hand-day">
                        <i className="ti ti-calendar" aria-hidden="true" />
                        Today
                    </span>

                    <button
                        type="button"
                        className="pos-icon-btn"
                        title="Refresh"
                        aria-label="Refresh patients from OPD"
                        onClick={() => void query.refetch()}
                    >
                        <i
                            className={`ti ti-refresh${query.isFetching ? ' is-spinning' : ''}`}
                            aria-hidden="true"
                        />
                    </button>

                    {inTab.length > FOLDED && (
                        <button type="button" className="pos-ghost-btn" onClick={() => setAll((was) => !was)}>
                            {all ? 'Show less' : 'View All'}
                        </button>
                    )}
                </div>
            }
        >
            <div className="opd-hand-tabs" role="tablist" aria-label="Patients from OPD">
                {TABS.map((entry) => (
                    <button
                        type="button"
                        key={entry.key}
                        role="tab"
                        aria-selected={tab === entry.key}
                        className={`opd-hand-tab${tab === entry.key ? ' is-on' : ''}`}
                        onClick={() => {
                            setTab(entry.key);
                            setAll(false);
                        }}
                    >
                        {entry.label}
                        <em>{counts[entry.key]}</em>
                    </button>
                ))}
            </div>

            {query.isLoading ? (
                <p className="opd-empty">Loading…</p>
            ) : rows.length === 0 ? (
                <p className="opd-empty">{EMPTY[tab]}</p>
            ) : (
                <div className="opd-hand-scroll">
                    <table className="opd-hand-table">
                        <thead>
                            <tr>
                                <th className="opd-hand-n">#</th>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Consulted at</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((patient, index) => {
                                const name = patient.patient.name ?? 'Unnamed patient';
                                const onThisBill =
                                    patient.patient.id !== null && patient.patient.id === onBill;

                                return (
                                    <tr key={patient.appointment_id} className={onThisBill ? 'is-on' : undefined}>
                                        <td className="opd-hand-n">{index + 1}</td>

                                        <td>
                                            <span className="opd-hand-who">
                                                <span className={`opd-hand-av tone-${hueOf(name)}`} aria-hidden="true">
                                                    {patient.patient.name ? (
                                                        initials(patient.patient.name)
                                                    ) : (
                                                        <i className="ti ti-user" />
                                                    )}
                                                </span>

                                                <span className="opd-hand-two">
                                                    <b>{name}</b>
                                                    {(patient.patient.code || patient.patient.phone) && (
                                                        <small>
                                                            {patient.patient.code}
                                                            {patient.patient.code && patient.patient.phone && (
                                                                <span className="opd-hand-gap" />
                                                            )}
                                                            {patient.patient.phone}
                                                        </small>
                                                    )}
                                                </span>
                                            </span>
                                        </td>

                                        <td>
                                            <span className="opd-hand-two">
                                                <span>{patient.doctor.name ?? 'Doctor'}</span>
                                                {patient.doctor.specialisation && (
                                                    <small>{patient.doctor.specialisation}</small>
                                                )}
                                            </span>
                                        </td>

                                        <td>
                                            <span className="opd-hand-two">
                                                <span>{clock(patient.completed_at)}</span>
                                                <small>Today</small>
                                            </span>
                                        </td>

                                        <td>
                                            <Status patient={patient} now={now} />
                                        </td>

                                        <td>
                                            <span className="opd-hand-act">
                                                <button
                                                    type="button"
                                                    className="opd-hand-add"
                                                    disabled={patient.patient.id === null || onThisBill}
                                                    onClick={() => onPick(patient)}
                                                    title={`Start a bill for ${name}`}
                                                >
                                                    {onThisBill ? (
                                                        <>
                                                            <i className="ti ti-check" aria-hidden="true" />
                                                            On bill
                                                        </>
                                                    ) : (
                                                        'Add to Bill'
                                                    )}
                                                </button>

                                                <RowMenu patient={patient} />
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

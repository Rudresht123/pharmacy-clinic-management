import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { Pager } from '@/core/exports/components/Pager';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import { cn } from '@/shared/utils/cn';
import { PatientRecordLink, useCanContact, useCanOpenRecord } from '@/core/customers/components/PatientRecordLink';

/** One "come back in N days", as `DoctorRecords::followUps` returns it. */
interface FollowUp {
    id: number;
    seen_on: string | null;
    due_on: string | null;
    days: number;
    /** Negative once the date has passed. */
    due_in: number | null;
    /** The first later visit they turned up to; null until they do. */
    returned_on: string | null;

    customer_id: number;
    customer_name: string | null;
    customer_code: string | null;
    age: number | null;
    gender: string | null;
    phone: string | null;

    chief_complaint: string | null;
    diagnoses: string[];
}

type State = 'overdue' | 'today' | 'upcoming' | 'done';
type Filter = 'all' | State;
type SortKey = 'due' | 'patient' | 'code' | 'seen' | 'days' | 'reason' | 'status';

const PAGE_SIZES = [10, 20, 50, 100];

const STATES: Record<State, { label: string; tab: string; tone: string }> = {
    overdue: { label: 'Overdue', tab: 'Overdue', tone: 'red' },
    today: { label: 'Due today', tab: 'Today', tone: 'amber' },
    upcoming: { label: 'Upcoming', tab: 'Upcoming', tone: 'blue' },
    done: { label: 'Came back', tab: 'Completed', tone: 'green' },
};

/** The order a status column sorts in: who needs a call first. */
const RANK: Record<State, number> = { overdue: 0, today: 1, upcoming: 2, done: 3 };

const TILES: { filter: State; label: string; icon: string }[] = [
    { filter: 'overdue', label: 'Overdue', icon: 'ti ti-clock' },
    { filter: 'today', label: 'Due today', icon: 'ti ti-calendar-event' },
    { filter: 'upcoming', label: 'Upcoming', icon: 'ti ti-calendar' },
    { filter: 'done', label: 'Completed this week', icon: 'ti ti-circle-check' },
];

const COLUMNS: { key: SortKey | null; label: string }[] = [
    { key: 'due', label: 'Due date' },
    { key: 'patient', label: 'Patient' },
    { key: 'code', label: 'Patient ID' },
    { key: 'seen', label: 'Seen on' },
    { key: 'days', label: 'Follow-up in' },
    { key: 'reason', label: 'Reason' },
    { key: 'status', label: 'Status' },
    { key: null, label: 'Actions' },
];

function stateOf(row: FollowUp): State {
    if (row.returned_on) return 'done';
    if (row.due_in === null) return 'upcoming';

    return row.due_in < 0 ? 'overdue' : row.due_in === 0 ? 'today' : 'upcoming';
}

/** What they were asked back for: the diagnoses, or failing those the complaint. */
function reasonsOf(row: FollowUp): string[] {
    if (row.diagnoses.length > 0) return row.diagnoses;

    return row.chief_complaint ? [row.chief_complaint] : [];
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

/** "7 Oct 2026", in the browser's own order. */
function longDate(value: string | null | undefined): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Monday of this week, as the API writes dates. */
function weekStart(): string {
    const at = new Date();

    at.setDate(at.getDate() - ((at.getDay() + 6) % 7));

    return `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`;
}

function compare(a: FollowUp, b: FollowUp, key: SortKey): number {
    const text = (x: string | null | undefined, y: string | null | undefined) =>
        (x ?? '').localeCompare(y ?? '', undefined, { numeric: true, sensitivity: 'base' });

    switch (key) {
        case 'due':
            return text(a.due_on, b.due_on);
        case 'patient':
            return text(a.customer_name, b.customer_name);
        case 'code':
            return text(a.customer_code, b.customer_code);
        case 'seen':
            return text(a.seen_on, b.seen_on);
        case 'days':
            return a.days - b.days;
        case 'reason':
            return text(reasonsOf(a)[0], reasonsOf(b)[0]);
        case 'status':
            return RANK[stateOf(a)] - RANK[stateOf(b)] || text(a.due_on, b.due_on);
    }
}

function useFollowUps() {
    return useQuery({
        queryKey: resourceKey('tenant/opd', 'my-records', 'follow-ups'),
        queryFn: async (): Promise<FollowUp[]> => {
            const { data } = await http.get<ApiResponse<FollowUp[]>>('/tenant/opd/my-records', {
                params: { view: 'follow-ups' },
            });

            return data.data;
        },
    });
}

/**
 * Who the signed-in doctor asked back, and whether they came.
 *
 * Overdue is the reason anybody opens this, so it leads everywhere: the first
 * tile, the alert, and the top of the default sort. Somebody who has come
 * back drops to the bottom rather than off the list — "did they come?" is
 * asked of this screen as often as "who is due?".
 */
export default function FollowUpsPage() {
    const { data, isLoading, isError, refetch } = useFollowUps();

    const [filter, setFilter] = useState<Filter>('all');
    const [term, setTerm] = useState('');
    const [sort, setSort] = useState<{ key: SortKey; dir: 1 | -1 }>({ key: 'due', dir: 1 });
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [alertClosed, setAlertClosed] = useState(false);

    const all = useMemo(() => data ?? [], [data]);

    const counts = useMemo(() => {
        const since = weekStart();
        const tally = { all: all.length, overdue: 0, today: 0, upcoming: 0, done: 0, doneThisWeek: 0 };

        for (const row of all) {
            tally[stateOf(row)] += 1;

            if (row.returned_on && row.returned_on >= since) tally.doneThisWeek += 1;
        }

        return tally;
    }, [all]);

    const rows = useMemo(() => {
        const needle = term.trim().toLowerCase();

        const matched = all.filter((row) => {
            if (filter !== 'all' && stateOf(row) !== filter) return false;
            if (!needle) return true;

            return [row.customer_name, row.customer_code, row.phone, row.chief_complaint, ...row.diagnoses].some(
                (value) => value?.toLowerCase().includes(needle),
            );
        });

        return matched.sort((a, b) => {
            // Under the default order, whoever is still to come back stays above
            // whoever already has, whichever way the dates run.
            if (sort.key === 'due') {
                const settled = Number(stateOf(a) === 'done') - Number(stateOf(b) === 'done');

                if (settled !== 0) return settled;
            }

            return compare(a, b, sort.key) * sort.dir;
        });
    }, [all, filter, term, sort]);

    // Clamped rather than reset, so narrowing a search never strands somebody on an empty page.
    const pageCount = Math.max(1, Math.ceil(rows.length / perPage));
    const current = Math.min(page, pageCount);
    const shown = rows.slice((current - 1) * perPage, current * perPage);

    const choose = (next: Filter) => {
        setFilter(next);
        setPage(1);
    };

    const sortBy = (key: SortKey) => {
        setSort((was) => ({ key, dir: was.key === key ? (was.dir === 1 ? -1 : 1) : 1 }));
        setPage(1);
    };

    const resize = (size: number) => {
        setPerPage(size);
        setPage(1);
    };

    const tileCount: Record<State, number> = {
        overdue: counts.overdue,
        today: counts.today,
        upcoming: counts.upcoming,
        done: counts.doneThisWeek,
    };

    return (
        <div className="fu-page">
            <section className="fu-top">
                <PageHeader
                    title="Follow-ups"
                    subtitle="Patients you asked to come back, and whether they have."
                    icon="ti ti-calendar-repeat"
                    tone="violet"
                    crumbs={[{ label: 'My day', to: '/my-day' }, { label: 'Follow-ups' }]}
                />

                <div className="fu-stats">
                    {TILES.map((tile) => (
                        <button
                            key={tile.filter}
                            type="button"
                            className={cn(
                                'fu-stat',
                                `is-${STATES[tile.filter].tone}`,
                                filter === tile.filter && 'is-on',
                            )}
                            onClick={() => choose(filter === tile.filter ? 'all' : tile.filter)}
                            aria-pressed={filter === tile.filter}
                        >
                            <span className="fu-stat-icon" aria-hidden="true">
                                <i className={tile.icon} />
                            </span>

                            <span className="fu-stat-body">
                                <span className="fu-stat-label">{tile.label}</span>
                                <span className="fu-stat-value">
                                    <b>{isLoading ? '–' : tileCount[tile.filter]}</b>
                                    {tileCount[tile.filter] === 1 ? 'follow-up' : 'follow-ups'}
                                </span>
                            </span>

                            <i className="ti ti-chevron-right fu-stat-go" aria-hidden="true" />
                        </button>
                    ))}
                </div>
            </section>

            <section className="fu-card">
                <header className="fu-card-head">
                    <div className="fu-card-lead">
                        <h2>
                            <i className="ti ti-calendar-event" aria-hidden="true" />
                            Follow-up schedule
                        </h2>

                        <div className="fu-tabs" role="tablist" aria-label="Show">
                            {(['all', 'overdue', 'today', 'upcoming', 'done'] as Filter[]).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="tab"
                                    aria-selected={filter === value}
                                    className={cn('fu-tab', filter === value && 'is-on')}
                                    onClick={() => choose(value)}
                                >
                                    {value === 'all' ? 'All' : STATES[value].tab} ({counts[value]})
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="fu-tools">
                        <label className="fu-len">
                            <span>Rows per page</span>
                            <span className="exc-select">
                                <select value={perPage} onChange={(event) => resize(Number(event.target.value))}>
                                    {PAGE_SIZES.map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </select>
                                <i className="ti ti-chevron-down" aria-hidden="true" />
                            </span>
                        </label>

                        <label className="fu-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="search"
                                value={term}
                                placeholder="Search patient name, ID or reason…"
                                aria-label="Search follow-ups"
                                onChange={(event) => {
                                    setTerm(event.target.value);
                                    setPage(1);
                                }}
                            />
                        </label>
                    </div>
                </header>

                {/* Not over the lists that hold no overdue rows, where it would read as about them. */}
                {counts.overdue > 0 && !alertClosed && (filter === 'all' || filter === 'overdue') && (
                    <div className="fu-alert" role="alert">
                        <i className="ti ti-alert-triangle" aria-hidden="true" />
                        <p>
                            <b>
                                {plural(counts.overdue, 'follow-up')} {counts.overdue === 1 ? 'is' : 'are'}{' '}
                                overdue.
                            </b>{' '}
                            Please contact {counts.overdue === 1 ? 'the patient' : 'these patients'}.
                        </p>
                        <button type="button" onClick={() => setAlertClosed(true)} aria-label="Dismiss">
                            <i className="ti ti-x" />
                        </button>
                    </div>
                )}

                {isLoading ? (
                    <LoadingBlock label="Loading follow-ups…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : rows.length === 0 ? (
                    <div className="cn-none-yet">
                        <i className="ti ti-calendar-repeat" aria-hidden="true" />
                        <b>Nothing here</b>
                        <p>
                            {term
                                ? 'No follow-up matches that search.'
                                : all.length === 0
                                  ? 'You have not asked anybody to come back yet.'
                                  : 'No follow-ups in this view.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="fu-frame">
                            <table className="fu-table">
                                <thead>
                                    <tr>
                                        {COLUMNS.map((column) => (
                                            <th
                                                key={column.label}
                                                aria-sort={
                                                    column.key && sort.key === column.key
                                                        ? sort.dir === 1
                                                            ? 'ascending'
                                                            : 'descending'
                                                        : undefined
                                                }
                                            >
                                                {column.key ? (
                                                    <button
                                                        type="button"
                                                        className={cn('fu-sort', sort.key === column.key && 'is-on')}
                                                        onClick={() => sortBy(column.key!)}
                                                    >
                                                        {column.label}
                                                        <i
                                                            className={cn(
                                                                'ti',
                                                                sort.key !== column.key
                                                                    ? 'ti-selector'
                                                                    : sort.dir === 1
                                                                      ? 'ti-chevron-up'
                                                                      : 'ti-chevron-down',
                                                            )}
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                ) : (
                                                    column.label
                                                )}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>

                                <tbody>
                                    {shown.map((row) => (
                                        <FollowUpRow key={row.id} row={row} />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pager
                            page={current}
                            lastPage={pageCount}
                            perPage={perPage}
                            total={rows.length}
                            from={(current - 1) * perPage + 1}
                            to={Math.min(current * perPage, rows.length)}
                            onPage={setPage}
                            onPerPage={resize}
                            noun="follow-ups"
                            sizes={PAGE_SIZES}
                        />
                    </>
                )}
            </section>
        </div>
    );
}

function FollowUpRow({ row }: { row: FollowUp }) {
    const canOpenRecord = useCanOpenRecord();
    const canContact = useCanContact();
    const state = stateOf(row);
    const due = row.due_in ?? 0;
    const reasons = reasonsOf(row);

    const about = [
        row.gender ? row.gender.charAt(0).toUpperCase() + row.gender.slice(1) : null,
        row.age != null ? `${row.age} yrs` : null,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <tr className={cn(state === 'done' && 'is-done')}>
            <td className="fu-due" data-label="Due date">
                <b>{longDate(row.due_on)}</b>

                {/*
                    Days rather than a bare date: "eleven days late" is the fact
                    somebody acts on, and counting it off a calendar at the end
                    of a clinic is where a follow-up gets missed.
                */}
                <span className={`fu-when is-${STATES[state].tone}`}>
                    {state === 'done'
                        ? `Back on ${longDate(row.returned_on)}`
                        : state === 'overdue'
                          ? `${plural(Math.abs(due), 'day')} late`
                          : state === 'today'
                            ? 'Today'
                            : `in ${plural(due, 'day')}`}
                </span>
            </td>

            <td data-label="Patient">
                <PatientRecordLink className="fu-who" customerId={row.customer_id}>
                    <b>{row.customer_name ?? 'Unnamed'}</b>
                    {about && <small>{about}</small>}
                </PatientRecordLink>
            </td>

            <td className="fu-dim" data-label="Patient ID">
                {row.customer_code ?? '—'}
            </td>

            <td className="fu-dim fu-nowrap" data-label="Seen on">
                {longDate(row.seen_on)}
            </td>

            <td className="fu-dim fu-nowrap" data-label="Follow-up in">
                after {plural(row.days, 'day')}
            </td>

            <td data-label="Reason">
                {reasons.length > 0 ? (
                    <span className="fu-reasons">
                        {reasons.map((reason) => (
                            <em key={reason}>{reason}</em>
                        ))}
                    </span>
                ) : (
                    <span className="fu-dim">—</span>
                )}
            </td>

            <td data-label="Status">
                <span className={`fu-status is-${STATES[state].tone}`}>{STATES[state].label}</span>
            </td>

            <td className="fu-actions" data-label="Actions">
                {canContact && <ContactMenu row={row} />}
                {canOpenRecord && <MoreMenu row={row} />}
            </td>
        </tr>
    );
}

/**
 * A menu that opens under its button.
 *
 * Fixed to the viewport from the button's own position, as RowActions does:
 * the table scrolls sideways inside its frame, and a menu anchored inside it
 * would be clipped at the frame's edge.
 */
function useMenu() {
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!at) return;

        const onOutside = (event: MouseEvent) => {
            const target = event.target as Node;

            if (!buttonRef.current?.contains(target) && !menuRef.current?.contains(target)) setAt(null);
        };
        const onEscape = (event: KeyboardEvent) => event.key === 'Escape' && setAt(null);
        // Scrolling would leave the menu behind its button, so it closes instead.
        const onScroll = () => setAt(null);

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onEscape);
        window.addEventListener('scroll', onScroll, true);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onEscape);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [at]);

    const toggle = () => {
        const rect = buttonRef.current?.getBoundingClientRect();

        setAt((was) => (was || !rect ? null : { top: rect.bottom + 6, right: window.innerWidth - rect.right }));
    };

    return { at, toggle, close: () => setAt(null), buttonRef, menuRef };
}

function MenuPanel({
    menu,
    children,
}: {
    menu: ReturnType<typeof useMenu>;
    children: ReactNode;
}) {
    if (!menu.at) return null;

    return (
        <div
            ref={menu.menuRef}
            className="dropdown-menu show mt-0 fu-menu"
            role="menu"
            style={{ position: 'fixed', top: menu.at.top, right: menu.at.right, left: 'auto' }}
        >
            {children}
        </div>
    );
}

function ContactMenu({ row }: { row: FollowUp }) {
    const menu = useMenu();
    const [copied, setCopied] = useState(false);

    if (!row.phone) {
        return (
            <button type="button" className="fu-contact" disabled title="No phone number on file">
                <i className="ti ti-phone-off" aria-hidden="true" />
                Contact
            </button>
        );
    }

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(row.phone!);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard refused; the number is on screen to read off.
        }
    };

    return (
        <>
            <button
                ref={menu.buttonRef}
                type="button"
                className="fu-contact"
                aria-haspopup="menu"
                aria-expanded={menu.at !== null}
                onClick={menu.toggle}
            >
                <i className="ti ti-phone" aria-hidden="true" />
                Contact
            </button>

            <MenuPanel menu={menu}>
                <div className="fu-menu-head">
                    <small>{row.customer_name ?? 'Patient'}</small>
                    <b>{row.phone}</b>
                </div>

                <a className="dropdown-item" role="menuitem" href={`tel:${row.phone}`} onClick={menu.close}>
                    <i className="ti ti-phone-call me-2" aria-hidden="true" />
                    Call
                </a>

                <button type="button" className="dropdown-item" role="menuitem" onClick={copy}>
                    <i className={cn('ti me-2', copied ? 'ti-check' : 'ti-copy')} aria-hidden="true" />
                    {copied ? 'Copied' : 'Copy number'}
                </button>
            </MenuPanel>
        </>
    );
}

/** Both of its doors lead into the record, so it is offered only with that tick. */
function MoreMenu({ row }: { row: FollowUp }) {
    const menu = useMenu();

    return (
        <>
            <button
                ref={menu.buttonRef}
                type="button"
                className="fu-more"
                aria-label={`More for ${row.customer_name ?? 'this patient'}`}
                aria-haspopup="menu"
                aria-expanded={menu.at !== null}
                onClick={menu.toggle}
            >
                <i className="ti ti-dots" aria-hidden="true" />
            </button>

            <MenuPanel menu={menu}>
                <Link className="dropdown-item" role="menuitem" to={`/customers/${row.customer_id}`} onClick={menu.close}>
                    <i className="ti ti-user me-2" aria-hidden="true" />
                    Open patient record
                </Link>

                <Link
                    className="dropdown-item"
                    role="menuitem"
                    to={`/customers/${row.customer_id}?section=visits`}
                    onClick={menu.close}
                >
                    <i className="ti ti-calendar-event me-2" aria-hidden="true" />
                    Visit history
                </Link>
            </MenuPanel>
        </>
    );
}

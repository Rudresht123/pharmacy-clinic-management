import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { Pagination } from '@/shared/components/ui/Pagination';
import { Modal } from '@/shared/components/ui/Modal';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';

/** A prescription or investigation line. The two differ by one field. */
interface Line {
    drug?: string;
    test?: string;
    dose?: string | null;
    frequency?: string | null;
    duration?: string | null;
    notes?: string | null;
}

interface WriteUp {
    chief_complaint: string | null;
    diagnoses: string[];
    vitals: { [key: string]: number | null };
    prescription: Line[];
    investigations: Line[];
    advice: string | null;
    notes: string | null;
    follow_up_days: number | null;
}

/**
 * Rows to a page.
 *
 * The same twenty the queue and the audit log use — a doctor moving between
 * these screens should not have to relearn how long a page is.
 */
const PAGE_LENGTHS = [10, 20, 50, 100];

export type RecordView =
    | 'appointments'
    | 'consultations'
    | 'prescriptions'
    | 'investigations'
    | 'follow-ups';

/** Every row any of the five views returns. Shared, because they mostly are. */
interface Row {
    id: number | string;
    customer_id: number;
    customer_name: string | null;
    customer_code: string | null;

    date?: string | null;
    on?: string | null;
    slot_at?: string | null;
    token_no?: number | null;
    status?: string;
    type?: string;
    location_name?: string | null;
    written_up?: boolean;

    minutes?: number | null;

    chief_complaint?: string | null;
    diagnoses?: string[];
    advice?: string | null;
    follow_up_days?: number | null;
    prescription?: Line[];
    investigations?: Line[];
    prescription_count?: number;
    investigation_count?: number;

    /** The write-up, on an appointment row. Null when nobody wrote one. */
    consultation?: WriteUp | null;

    what?: string;
    dose?: string | null;
    frequency?: string | null;
    duration?: string | null;
    notes?: string | null;

    seen_on?: string | null;
    due_on?: string | null;
    days?: number;
    due_in?: number | null;
}

const STATUS: { [key: string]: string } = {
    booked: 'Expected',
    checked_in: 'Waiting',
    in_consultation: 'In the room',
    completed: 'Seen',
    cancelled: 'Cancelled',
    no_show: 'Did not come',
};

/** What each screen is, in one place. */
const VIEWS: Record<
    RecordView,
    { title: string; subtitle: string; icon: string; empty: string; dated: boolean }
> = {
    appointments: {
        title: 'My appointments',
        subtitle: 'Everybody booked with you, and what became of each.',
        icon: 'ti ti-calendar',
        empty: 'Nobody was booked with you in this period.',
        dated: true,
    },
    consultations: {
        title: 'My consultations',
        subtitle: 'The visits you wrote up.',
        icon: 'ti ti-clipboard-text',
        empty: 'You have not written up a visit in this period.',
        dated: true,
    },
    prescriptions: {
        title: 'My prescriptions',
        subtitle: 'Every medicine you have prescribed, one line each.',
        icon: 'ti ti-file-text',
        empty: 'You have not prescribed anything in this period.',
        dated: true,
    },
    investigations: {
        title: 'My investigations',
        subtitle: 'Every test you have ordered, one line each.',
        icon: 'ti ti-microscope',
        empty: 'You have not ordered a test in this period.',
        dated: true,
    },
    'follow-ups': {
        title: 'Follow-ups',
        subtitle: 'Who you asked back, and when they are due.',
        icon: 'ti ti-calendar-repeat',
        empty: 'You have not asked anybody to come back.',
        dated: false,
    },
};

/** "12 Aug 2026" */
function longDate(value: string | null | undefined): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function daysAgo(n: number): string {
    const at = new Date();

    at.setDate(at.getDate() - n);

    return `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`;
}

function today(): string {
    return daysAgo(0);
}

function useRecords(view: RecordView, from: string, to: string) {
    return useQuery({
        queryKey: resourceKey('tenant/opd', 'my-records', view, from, to),
        queryFn: async (): Promise<Row[]> => {
            const { data } = await http.get<ApiResponse<Row[]>>('/tenant/opd/my-records', {
                params: VIEWS[view].dated ? { view, from, to } : { view },
            });

            return data.data;
        },
    });
}

/**
 * A doctor's own records, read five ways.
 *
 * One screen rather than five near-identical ones: they are the same rows —
 * their appointments and what was written at each — asked different questions,
 * and five files would be five places for a date filter and an empty state to
 * drift apart.
 *
 * Every one is scoped to the signed-in doctor by the endpoint. The department's
 * versions of these exist already, behind capabilities a doctor does not hold.
 */
export default function MyRecordsPage({ view }: { view: RecordView }) {
    const meta = VIEWS[view];

    const [from, setFrom] = useState(daysAgo(30));
    const [to, setTo] = useState(today());
    const [term, setTerm] = useState('');

    /*
     * The row being read, in a dialog over the list.
     *
     * Opened inline it pushed every row below it down the page, so the list you
     * were scanning moved under the cursor each time you looked at something —
     * and a long write-up buried the rows either side of the one it belonged
     * to. A dialog leaves the list where it was.
     */
    const [reading, setReading] = useState<Row | null>(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);

    const { data, isLoading, isError, refetch } = useRecords(view, from, to);

    const rows = useMemo(() => {
        const needle = term.trim().toLowerCase();

        if (!needle) return data ?? [];

        return (data ?? []).filter(
            (row) =>
                row.customer_name?.toLowerCase().includes(needle) ||
                row.customer_code?.toLowerCase().includes(needle) ||
                row.what?.toLowerCase().includes(needle) ||
                row.chief_complaint?.toLowerCase().includes(needle) ||
                row.diagnoses?.some((entry) => entry.toLowerCase().includes(needle)),
        );
    }, [data, term]);

    /* Overdue first — a follow-up list is read to find who has slipped. */
    const overdue = view === 'follow-ups' ? rows.filter((row) => (row.due_in ?? 0) < 0).length : 0;

    /*
     * Clamped rather than reset.
     *
     * Narrowing a search from six pages to two used to leave somebody on page
     * five looking at an empty table and reaching for the back button.
     */
    const pageCount = Math.max(1, Math.ceil(rows.length / perPage));
    const current = Math.min(page, pageCount);
    const shown = rows.slice((current - 1) * perPage, current * perPage);

    if (isLoading) return <LoadingBlock label="Loading…" />;
    if (isError) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <PageHeader
                title={meta.title}
                subtitle={meta.subtitle}
                icon={meta.icon}
                tone="violet"
                crumbs={[{ label: 'My day', to: '/my-day' }, { label: meta.title }]}
            />

            <Card
                actions={
                    <div className="rec-tools">
                        {meta.dated && (
                            <>
                                <label className="rec-when">
                                    <span>From</span>
                                    <DatePicker
                                        value={from}
                                        onChange={(next) => {
                                            setFrom(next);
                                            setPage(1);
                                        }}
                                    />
                                </label>

                                <label className="rec-when">
                                    <span>To</span>
                                    <DatePicker
                                        value={to}
                                        onChange={(next) => {
                                            setTo(next);
                                            setPage(1);
                                        }}
                                    />
                                </label>
                            </>
                        )}

                        <label className="rec-len">
                            <span>Rows</span>
                            <SearchableSelect
                                value={String(perPage)}
                                ariaLabel="Rows per page"
                                onChange={(next) => {
                                    setPerPage(Number(next));
                                    setPage(1);
                                }}
                                options={PAGE_LENGTHS.map((n) => ({
                                    value: String(n),
                                    label: String(n),
                                }))}
                            />
                        </label>

                        <label className="md-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="text"
                                value={term}
                                placeholder="Search…"
                                aria-label={`Search ${meta.title}`}
                                onChange={(event) => {
                                    setTerm(event.target.value);
                                    setPage(1);
                                }}
                            />
                        </label>
                    </div>
                }
            >
                {overdue > 0 && (
                    <p className="rec-overdue">
                        <i className="ti ti-alert-triangle" aria-hidden="true" />
                        {overdue} {overdue === 1 ? 'person is' : 'people are'} past the date you
                        asked them back.
                    </p>
                )}

                {rows.length === 0 ? (
                    <div className="cn-none-yet">
                        <i className={meta.icon} aria-hidden="true" />
                        <b>Nothing here</b>
                        <p>{term ? 'Nothing matches that.' : meta.empty}</p>
                    </div>
                ) : (
                    <div className="rec-scroll tbl-cards-scroll">
                        <table className="md-queue tbl-cards">
                            <thead>
                                <tr>{headerFor(view)}</tr>
                            </thead>

                            <tbody>
                                {shown.map((row) => (
                                    /*
                                        Every row opens, written up or not.
                                        "Nothing was recorded" is an answer
                                        somebody is looking for as often as the
                                        notes themselves, and a row that
                                        silently refuses to open reads as
                                        broken rather than as empty.
                                    */
                                    <tr
                                        key={row.id}
                                        className="rec-row"
                                        onClick={() => setReading(row)}
                                    >
                                        {cellsFor(view, row, writeUpOf(view, row))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {rows.length > 0 && (
                    <Pagination
                        page={current}
                        pageCount={pageCount}
                        total={rows.length}
                        perPage={perPage}
                        onChange={setPage}
                    />
                )}
            </Card>

            <Modal
                open={reading !== null}
                onClose={() => setReading(null)}
                title={reading?.customer_name ?? 'Visit'}
                subtitle={visitLine(view, reading)}
                icon="ti ti-clipboard-text"
                size="lg"
                footer={
                    reading && (
                        <Link
                            className="cn-save"
                            to={`/customers/${reading.customer_id}`}
                            onClick={() => setReading(null)}
                        >
                            <i className="ti ti-arrow-right" aria-hidden="true" />
                            Open the patient record
                        </Link>
                    )
                }
            >
                {reading &&
                    (writeUpOf(view, reading) ? (
                        <WriteUpPanel of={writeUpOf(view, reading)!} />
                    ) : (
                        <div className="cn-none-yet">
                            <i className="ti ti-notes-off" aria-hidden="true" />
                            <b>Nothing was written up</b>
                            <p>
                                {reading.status === 'completed'
                                    ? 'This visit was completed without a consultation being recorded.'
                                    : 'A write-up is recorded while the patient is in the room.'}
                            </p>
                        </div>
                    ))}
            </Modal>
        </>
    );
}

/** "Seen on 9 Sep 2026 · 14 min · Noida" — the visit, in one line. */
function visitLine(view: RecordView, row: Row | null): string | undefined {
    if (!row) return undefined;

    const parts = [longDate(row.date ?? row.on ?? row.seen_on)];

    if (row.minutes != null) parts.push(took(row.minutes));
    if (row.location_name) parts.push(row.location_name);
    if (view === 'follow-ups' && row.days) parts.push(`asked back after ${row.days} days`);

    return parts.join(' · ');
}

const COLUMNS: Record<RecordView, string[]> = {
    appointments: ['Date', 'Time', 'Patient', 'Branch', 'Status', 'Took', 'Written up'],
    consultations: ['Date', 'Patient', 'Complaint', 'Diagnosis', 'Took', 'Written'],
    prescriptions: ['Date', 'Patient', 'Medicine', 'Dose', 'How often', 'For'],
    investigations: ['Date', 'Patient', 'Test', 'Note', 'For'],
    'follow-ups': ['Due', 'Patient', 'Seen on', 'Asked back', 'For'],
};

function headerFor(view: RecordView) {
    return COLUMNS[view].map((label) => <th key={label}>{label}</th>);
}

/** The write-up a row can open, where the view has one. */
function writeUpOf(view: RecordView, row: Row): WriteUp | null {
    if (view === 'appointments') return row.consultation ?? null;

    if (view === 'consultations') {
        return {
            chief_complaint: row.chief_complaint ?? null,
            diagnoses: row.diagnoses ?? [],
            vitals: {},
            prescription: row.prescription ?? [],
            investigations: row.investigations ?? [],
            advice: row.advice ?? null,
            notes: null,
            follow_up_days: row.follow_up_days ?? null,
        };
    }

    return null;
}

/** "1 h 05 m" past the hour, plain minutes below it. */
function took(minutes: number | null | undefined): string {
    if (minutes == null) return '—';
    if (minutes < 60) return `${minutes} min`;

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} m`;
}

/**
 * What was written at a visit, opened out under its row.
 *
 * Everything the doctor recorded, in the order they recorded it — a summary
 * that left the prescription out would send somebody to a second screen for
 * the one thing they opened the row to see.
 */
function WriteUpPanel({ of }: { of: WriteUp }) {
    const vitals = Object.entries(of.vitals).filter(([, value]) => value != null);

    return (
        <div className="rec-up">
            {of.chief_complaint && (
                <div className="rec-up-row">
                    <span>Complaint</span>
                    <p>{of.chief_complaint}</p>
                </div>
            )}

            {of.diagnoses.length > 0 && (
                <div className="rec-up-row">
                    <span>Diagnosis</span>
                    <p className="cn-dx">
                        {of.diagnoses.map((entry) => (
                            <em key={entry}>{entry}</em>
                        ))}
                    </p>
                </div>
            )}

            {vitals.length > 0 && (
                <div className="rec-up-row">
                    <span>Vitals</span>
                    <p className="rec-vitals">
                        {vitals.map(([key, value]) => (
                            <em key={key}>
                                {key.replace(/_/g, ' ')} {value}
                            </em>
                        ))}
                    </p>
                </div>
            )}

            {of.prescription.length > 0 && (
                <div className="rec-up-row">
                    <span>Prescribed</span>

                    <ul className="rec-lines">
                        {of.prescription.map((line, index) => (
                            <li key={index}>
                                <b>{line.drug}</b>
                                <small>
                                    {[line.dose, line.frequency, line.duration]
                                        .filter(Boolean)
                                        .join(' · ') || 'No dose recorded'}
                                </small>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {of.investigations.length > 0 && (
                <div className="rec-up-row">
                    <span>Ordered</span>

                    <ul className="rec-lines">
                        {of.investigations.map((line, index) => (
                            <li key={index}>
                                <b>{line.test}</b>
                                {line.notes && <small>{line.notes}</small>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {of.advice && (
                <div className="rec-up-row">
                    <span>Advice</span>
                    <p>{of.advice}</p>
                </div>
            )}

            {of.follow_up_days && (
                <div className="rec-up-row">
                    <span>Follow-up</span>
                    <p>After {of.follow_up_days} days</p>
                </div>
            )}

            {of.notes && (
                <div className="rec-up-row">
                    <span>Notes</span>
                    <p>{of.notes}</p>
                </div>
            )}
        </div>
    );
}

/** "2 medicines · 1 test" — what a row holds, before it is opened. */
function summarise(of: WriteUp | null): string {
    if (!of) return '—';

    const parts: string[] = [];

    if (of.prescription.length) {
        parts.push(
            `${of.prescription.length} ${of.prescription.length === 1 ? 'medicine' : 'medicines'}`,
        );
    }

    if (of.investigations.length) {
        parts.push(`${of.investigations.length} ${of.investigations.length === 1 ? 'test' : 'tests'}`);
    }

    if (of.follow_up_days) parts.push('follow-up');

    // Written up, but nothing was given or ordered — which is a normal visit
    // and must not read as an empty row.
    return parts.length ? parts.join(' · ') : 'Notes only';
}

/** The patient, as a link to their record — the same cell in every view. */
function Patient({ row }: { row: Row }) {
    return (
        <td data-label="Patient">
            <Link className="rec-who" to={`/customers/${row.customer_id}`}>
                <b>{row.customer_name ?? 'Unnamed'}</b>
                {row.customer_code && <small>{row.customer_code}</small>}
            </Link>
        </td>
    );
}

function Diagnoses({ of, label }: { of: string[] | undefined; label: string }) {
    return (
        <td data-label={label}>
            {of && of.length > 0 ? (
                <span className="cn-dx">
                    {of.map((entry) => (
                        <em key={entry}>{entry}</em>
                    ))}
                </span>
            ) : (
                <span className="md-dim">—</span>
            )}
        </td>
    );
}

function cellsFor(view: RecordView, row: Row, wroteUp: WriteUp | null) {
    if (view === 'appointments') {
        return (
            <>
                <td className="av-date" data-label="Date">
                    {longDate(row.date)}
                </td>

                <td className="md-dim" data-label="Time">
                    {row.slot_at ?? (row.token_no !== null ? `Token ${row.token_no}` : '—')}
                </td>

                <Patient row={row} />

                <td className="md-dim" data-label="Branch">
                    {row.location_name ?? '—'}
                </td>

                <td data-label="Status">
                    <span className={`pt-state is-${row.status}`}>
                        {STATUS[row.status ?? ''] ?? row.status}
                    </span>
                </td>

                <td className="md-dim" data-label="Took">
                    {took(row.minutes)}
                </td>

                <td data-label="Written up">
                    <span className="rec-open">
                        {wroteUp ? (
                            <span className="md-tag is-again">{summarise(wroteUp)}</span>
                        ) : (
                            <span className="md-dim">Not written up</span>
                        )}
                        <i className="ti ti-chevron-right" aria-hidden="true" />
                    </span>
                </td>
            </>
        );
    }

    if (view === 'consultations') {
        return (
            <>
                <td className="av-date" data-label="Date">
                    {longDate(row.on)}
                </td>

                <Patient row={row} />

                <td data-label="Complaint">
                    {row.chief_complaint || <span className="md-dim">—</span>}
                </td>

                <Diagnoses of={row.diagnoses} label="Diagnosis" />

                <td className="md-dim" data-label="Took">
                    {took(row.minutes)}
                </td>

                <td data-label="Written">
                    <span className="rec-open">
                        <span className="md-tag is-again">{summarise(wroteUp)}</span>
                        <i className="ti ti-chevron-right" aria-hidden="true" />
                    </span>
                </td>
            </>
        );
    }

    if (view === 'prescriptions') {
        return (
            <>
                <td className="av-date" data-label="Date">
                    {longDate(row.on)}
                </td>

                <Patient row={row} />

                <td data-label="Medicine">
                    <b>{row.what}</b>
                </td>

                <td className="md-dim" data-label="Dose">
                    {row.dose || '—'}
                </td>

                <td className="md-dim" data-label="How often">
                    {row.frequency || '—'}
                </td>

                <td className="md-dim" data-label="For">
                    {row.duration || '—'}
                </td>
            </>
        );
    }

    if (view === 'investigations') {
        return (
            <>
                <td className="av-date" data-label="Date">
                    {longDate(row.on)}
                </td>

                <Patient row={row} />

                <td data-label="Test">
                    <b>{row.what}</b>
                </td>

                <td className="md-dim" data-label="Note">
                    {row.notes || '—'}
                </td>

                <Diagnoses of={row.diagnoses} label="For" />
            </>
        );
    }

    /* Follow-ups */
    const due = row.due_in ?? 0;

    return (
        <>
            <td className="av-date" data-label="Due">
                <b>{longDate(row.due_on)}</b>

                {/*
                    Days rather than a bare date: "eleven days late" is the fact
                    somebody acts on, and counting it off a calendar at the end
                    of a clinic is where a follow-up gets missed.
                */}
                <span className={`rec-due${due < 0 ? ' is-late' : due <= 3 ? ' is-soon' : ''}`}>
                    {due < 0
                        ? `${Math.abs(due)} days late`
                        : due === 0
                          ? 'Today'
                          : `in ${due} days`}
                </span>
            </td>

            <Patient row={row} />

            <td className="md-dim" data-label="Seen on">
                {longDate(row.seen_on)}
            </td>

            <td className="md-dim" data-label="Asked back">
                after {row.days} days
            </td>

            <Diagnoses of={row.diagnoses} label="For" />
        </>
    );
}

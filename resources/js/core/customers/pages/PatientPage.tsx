import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { http } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useCustomerFields } from '../api';
import { customersHooks } from '../api';
import type { ApiResponse } from '@/shared/types/api';

interface Visit {
    id: number;
    date: string | null;
    status: string;
    type: string;
    slot_at: string | null;
    token_no: number | null;
    doctor_name: string | null;
    location_name: string | null;

    consultation: {
        chief_complaint: string | null;
        diagnoses: string[];
        advice: string | null;
        notes: string | null;
        follow_up_days: number | null;
        vitals: Vitals;
        prescription: {
            drug: string;
            dose?: string | null;
            frequency?: string | null;
            duration?: string | null;
        }[];
        investigations: { test: string; notes?: string | null }[];
    } | null;
}

interface PatientRecord {
    summary: {
        total_visits: number;
        seen_count: number;
        member_since: string | null;
        last_visit: { on: string | null; doctor_name: string | null } | null;
        next_visit: { on: string | null; at: string | null; doctor_name: string | null } | null;
        vitals: { on: string | null; values: Vitals } | null;
        medications: {
            on: string | null;
            lines: { drug: string; dose?: string | null; frequency?: string | null }[];
        } | null;
        diagnoses: string[];
    };
    visits: Visit[];
}

/** Whatever was measured. Every key optional; most visits record two. */
type Vitals = { [key: string]: number | null };

const STATUS: { [key: string]: string } = {
    booked: 'Expected',
    checked_in: 'Waiting',
    in_consultation: 'In the room',
    completed: 'Seen',
    cancelled: 'Cancelled',
    no_show: 'Did not come',
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

/** The vitals worth a tile, in the order a chart would list them. */
const VITALS: [string, string, string, string][] = [
    ['bp', 'BP', 'mmHg', 'is-rose'],
    ['pulse', 'Pulse', 'bpm', 'is-violet'],
    ['temperature', 'Temperature', '°C', 'is-amber'],
    ['weight', 'Weight', 'kg', 'is-blue'],
    ['height', 'Height', 'cm', 'is-green'],
    ['bmi', 'BMI', '', 'is-teal'],
];

/**
 * Read a vital, including the two that are worked out rather than measured.
 *
 * Blood pressure is one reading written as two numbers, and BMI is arithmetic
 * on weight and height — storing either would be storing something already
 * known, and a stored BMI goes stale the moment a weight is corrected.
 */
function vitalOf(values: Vitals, key: string): string | null {
    if (key === 'bp') {
        const top = values.bp_systolic;
        const bottom = values.bp_diastolic;

        return top && bottom ? `${top}/${bottom}` : null;
    }

    if (key === 'bmi') {
        const weight = values.weight;
        const height = values.height;

        if (!weight || !height) return null;

        return (weight / (height / 100) ** 2).toFixed(1);
    }

    const value = values[key];

    return value == null ? null : String(value);
}

function useRecord(id: string | undefined) {
    return useQuery({
        queryKey: ['tenant', 'customers', id, 'visits'],
        queryFn: async (): Promise<PatientRecord> => {
            const { data } = await http.get<ApiResponse<PatientRecord>>(
                `/tenant/customers/${id}/visits`,
            );

            return data.data;
        },
        enabled: Boolean(id),
    });
}

type Tab = 'overview' | 'visits' | 'vitals';

/**
 * A patient's record, to read rather than to edit.
 *
 * "Full record" used to open the edit form, which is the wrong thing twice
 * over: it is a form when somebody wants an answer, and it puts every field in
 * a state where a stray keystroke changes the record of a person's health.
 *
 * Editing is still a click away, behind its own capability — this screen needs
 * only `customers.view`, which is what a doctor holds.
 */
export default function PatientPage() {
    const { id } = useParams();
    const { capabilities } = useTenantAuth();

    const [tab, setTab] = useState<Tab>('overview');

    const { data: patient, isLoading, isError, refetch } = customersHooks.useDetail(id);
    const { data: record } = useRecord(id);
    const { data: fields } = useCustomerFields();

    if (isLoading) return <LoadingBlock label="Loading the record…" />;
    if (isError || !patient) return <ErrorState onRetry={() => refetch()} />;

    const summary = record?.summary;
    const visits = record?.visits ?? [];

    /*
     * Whatever this organization has chosen to record about a patient.
     *
     * Read from the field settings rather than a fixed list: a clinic that
     * added "Blood group" or "Aadhaar" sees them here without anybody
     * touching this screen, and one that did not is never shown an empty row
     * for a thing it does not collect.
     */
    const extra = (fields ?? [])
        .filter((field) => field.is_custom)
        .map((field) => ({
            label: field.label,
            value: (patient.custom_fields ?? {})[field.key],
        }))
        .filter((row) => row.value !== undefined && row.value !== null && row.value !== '');

    return (
        <>
            {/* Who they are, and what the record adds up to — before any tab. */}
            <div className="pr-hero">
                <div className="pr-who">
                    <PersonPhoto src={null} name={patient.name} className="pr-face" />

                    <div className="pr-id">
                        <h1>
                            {patient.name}
                            <em className={patient.is_active === false ? 'is-off' : undefined}>
                                {patient.is_active === false ? 'Inactive' : 'Active'}
                            </em>
                        </h1>

                        <small>Patient ID: {patient.code ?? '—'}</small>

                        <ul className="pr-facts">
                            {patient.age != null && (
                                <li>
                                    <i className="ti ti-cake" aria-hidden="true" />
                                    {patient.age} years
                                </li>
                            )}

                            {patient.gender && (
                                <li>
                                    <i className="ti ti-user" aria-hidden="true" />
                                    {patient.gender}
                                </li>
                            )}

                            {patient.phone && (
                                <li>
                                    <i className="ti ti-phone" aria-hidden="true" />
                                    <a href={`tel:${patient.phone}`}>{patient.phone}</a>
                                </li>
                            )}

                            {(patient.city || patient.state) && (
                                <li>
                                    <i className="ti ti-map-pin" aria-hidden="true" />
                                    {[patient.address, patient.city, patient.state]
                                        .filter(Boolean)
                                        .join(', ')}
                                </li>
                            )}
                        </ul>
                    </div>
                </div>

                <div className="pr-tally">
                    <div>
                        <i className="ti ti-history" aria-hidden="true" />
                        <span>
                            <small>Last visit</small>
                            <b>{longDate(summary?.last_visit?.on)}</b>
                            <em>{summary?.last_visit?.doctor_name ?? 'Not seen yet'}</em>
                        </span>
                    </div>

                    <div>
                        <i className="ti ti-repeat" aria-hidden="true" />
                        <span>
                            <small>Total visits</small>
                            <b>{String(summary?.total_visits ?? 0).padStart(2, '0')}</b>
                            <em>{summary?.seen_count ?? 0} seen</em>
                        </span>
                    </div>

                    <div>
                        <i className="ti ti-user-check" aria-hidden="true" />
                        <span>
                            <small>Registered</small>
                            <b>{longDate(summary?.member_since)}</b>
                            <em>{patient.registered_location?.name ?? 'This organisation'}</em>
                        </span>
                    </div>
                </div>

                {capabilities.includes('customers.edit') && (
                    <Link className="pr-edit" to={`/customers/${patient.id}/edit`}>
                        <i className="ti ti-pencil" aria-hidden="true" />
                        Edit
                    </Link>
                )}
            </div>

            <nav className="pr-tabs" role="tablist">
                {(
                    [
                        ['overview', 'Overview'],
                        ['visits', `Visit history${visits.length ? ` (${visits.length})` : ''}`],
                        ['vitals', 'Vitals'],
                    ] as [Tab, string][]
                ).map(([key, label]) => (
                    <button
                        type="button"
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        className={`pr-tab${tab === key ? ' is-on' : ''}`}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </nav>

            {tab === 'overview' && (
                <div className="pr-grid">
                    <Card title={<span className="opd-card-title">Personal information</span>}>
                        <dl className="pr-rows">
                            {(
                                [
                                    ['Full name', patient.name],
                                    ['Date of birth', patient.date_of_birth
                                        ? `${longDate(patient.date_of_birth)}${patient.age != null ? ` (${patient.age} years)` : ''}`
                                        : null],
                                    ['Gender', patient.gender],
                                    ['Phone', patient.phone],
                                    ['Email', patient.email],
                                    ['Address', [patient.address, patient.city, patient.state, patient.pincode]
                                        .filter(Boolean)
                                        .join(', ')],
                                ] as [string, string | null | undefined][]
                            ).map(([label, value]) => (
                                <div key={label}>
                                    <dt>{label}</dt>
                                    <dd>{value || <span className="cn-quiet">Not recorded</span>}</dd>
                                </div>
                            ))}

                            {/* Whatever this clinic chose to collect as well. */}
                            {extra.map((row) => (
                                <div key={row.label}>
                                    <dt>{row.label}</dt>
                                    <dd>{String(row.value)}</dd>
                                </div>
                            ))}
                        </dl>
                    </Card>

                    <div className="pr-mid">
                        <Card title={<span className="opd-card-title">Medical summary</span>}>
                            <dl className="pr-med">
                                <div>
                                    <dt>Recorded diagnoses</dt>
                                    <dd>
                                        {summary?.diagnoses.length ? (
                                            <span className="cn-dx">
                                                {summary.diagnoses.map((entry) => (
                                                    <em key={entry}>{entry}</em>
                                                ))}
                                            </span>
                                        ) : (
                                            <span className="cn-quiet">
                                                Nothing diagnosed at a visit yet
                                            </span>
                                        )}
                                    </dd>
                                </div>

                                <div>
                                    <dt>Last prescribed</dt>
                                    <dd>
                                        {summary?.medications ? (
                                            <>
                                                <span className="pr-drugs">
                                                    {summary.medications.lines.map((row, index) => (
                                                        <em key={index}>
                                                            {row.drug}
                                                            {row.dose ? ` · ${row.dose}` : ''}
                                                        </em>
                                                    ))}
                                                </span>
                                                <small>
                                                    on {longDate(summary.medications.on)}
                                                </small>
                                            </>
                                        ) : (
                                            <span className="cn-quiet">Nothing prescribed yet</span>
                                        )}
                                    </dd>
                                </div>
                            </dl>

                            {/*
                                Said plainly: allergies, chronic conditions,
                                family and social history each need a field of
                                their own on the patient. A clinic can add them
                                in Settings today and they appear above; until
                                somebody does, an empty heading would read as
                                "none", which for an allergy is dangerous.
                            */}
                            <p className="md-soon">
                                <i className="ti ti-info-circle" aria-hidden="true" />
                                Allergies, long-term conditions and family history are recorded as
                                patient fields. Add them under Settings &rarr; Fields and they show
                                here.
                            </p>
                        </Card>

                        <Card
                            title={<span className="opd-card-title">Recent vitals</span>}
                            actions={
                                summary?.vitals ? (
                                    <span className="pr-when">
                                        {longDate(summary.vitals.on)}
                                    </span>
                                ) : undefined
                            }
                        >
                            {!summary?.vitals ? (
                                <div className="cn-none-yet">
                                    <i className="ti ti-activity" aria-hidden="true" />
                                    <b>No vitals recorded</b>
                                    <p>They are taken during a consultation and appear here.</p>
                                </div>
                            ) : (
                                <div className="pr-vitals">
                                    {VITALS.map(([key, label, unit, tone]) => {
                                        const value = vitalOf(summary.vitals!.values, key);

                                        return value === null ? null : (
                                            <div className={`pr-vital ${tone}`} key={key}>
                                                <i className="ti ti-activity" aria-hidden="true" />
                                                <span>
                                                    <small>{label}</small>
                                                    <b>{value}</b>
                                                    {unit && <em>{unit}</em>}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </Card>
                    </div>

                    <div className="pr-side">
                        <Card title={<span className="opd-card-title">Quick actions</span>}>
                            <div className="md-quick">
                                <Link className="md-quick-one is-blue" to="/opd">
                                    <i className="ti ti-calendar-plus" aria-hidden="true" />
                                    Book
                                </Link>

                                {capabilities.includes('customers.edit') && (
                                    <Link
                                        className="md-quick-one is-violet"
                                        to={`/customers/${patient.id}/edit`}
                                    >
                                        <i className="ti ti-pencil" aria-hidden="true" />
                                        Edit details
                                    </Link>
                                )}

                                <button
                                    type="button"
                                    className="md-quick-one is-green"
                                    onClick={() => setTab('visits')}
                                >
                                    <i className="ti ti-history" aria-hidden="true" />
                                    Visit history
                                </button>
                            </div>
                        </Card>

                        {summary?.next_visit && (
                            <Card title={<span className="opd-card-title">Next appointment</span>}>
                                <div className="pr-next">
                                    <i className="ti ti-calendar-event" aria-hidden="true" />
                                    <span>
                                        <b>{longDate(summary.next_visit.on)}</b>
                                        <small>
                                            {summary.next_visit.at ?? 'Time not set'}
                                            {summary.next_visit.doctor_name
                                                ? ` · ${summary.next_visit.doctor_name}`
                                                : ''}
                                        </small>
                                    </span>
                                </div>
                            </Card>
                        )}

                        <Card
                            title={<span className="opd-card-title">Recent visits</span>}
                            actions={
                                visits.length > 3 ? (
                                    <button
                                        type="button"
                                        className="md-viewall"
                                        onClick={() => setTab('visits')}
                                    >
                                        View all
                                    </button>
                                ) : undefined
                            }
                        >
                            {visits.length === 0 ? (
                                <p className="cn-quiet">No visits yet.</p>
                            ) : (
                                <ul className="pr-recent">
                                    {visits.slice(0, 4).map((visit) => (
                                        <li key={visit.id}>
                                            <i className="ti ti-file-text" aria-hidden="true" />

                                            <span>
                                                <b>{longDate(visit.date)}</b>
                                                <small>
                                                    {visit.doctor_name ?? 'Unknown doctor'}
                                                    {visit.consultation?.chief_complaint
                                                        ? ` · ${visit.consultation.chief_complaint}`
                                                        : ''}
                                                </small>
                                            </span>

                                            <em className={`pt-state is-${visit.status}`}>
                                                {STATUS[visit.status] ?? visit.status}
                                            </em>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'visits' && (
                <Card title={<span className="opd-card-title">Visit history</span>}>
                    {visits.length === 0 ? (
                        <div className="cn-none-yet">
                            <i className="ti ti-calendar-off" aria-hidden="true" />
                            <b>No visits yet</b>
                            <p>This patient has not been booked in or seen at any branch.</p>
                        </div>
                    ) : (
                        <ol className="pt-visits">
                            {visits.map((visit) => (
                                <li key={visit.id}>
                                    <div className="pt-when">
                                        <b>{longDate(visit.date)}</b>
                                        <small>
                                            {visit.doctor_name ?? 'Unknown doctor'}
                                            {visit.location_name ? ` · ${visit.location_name}` : ''}
                                        </small>
                                        <em className={`pt-state is-${visit.status}`}>
                                            {STATUS[visit.status] ?? visit.status}
                                        </em>
                                    </div>

                                    <div className="pt-what">
                                        {!visit.consultation ? (
                                            <p className="cn-quiet">
                                                {visit.status === 'completed'
                                                    ? 'Seen, but nothing was written up.'
                                                    : 'Nothing written up.'}
                                            </p>
                                        ) : (
                                            <>
                                                {visit.consultation.chief_complaint && (
                                                    <p>{visit.consultation.chief_complaint}</p>
                                                )}

                                                {visit.consultation.diagnoses.length > 0 && (
                                                    <span className="cn-dx">
                                                        {visit.consultation.diagnoses.map(
                                                            (entry) => (
                                                                <em key={entry}>{entry}</em>
                                                            ),
                                                        )}
                                                    </span>
                                                )}

                                                <span className="pt-counts">
                                                    {visit.consultation.prescription.length > 0 && (
                                                        <span>
                                                            <i
                                                                className="ti ti-pill"
                                                                aria-hidden="true"
                                                            />
                                                            {visit.consultation.prescription.length}{' '}
                                                            prescribed
                                                        </span>
                                                    )}

                                                    {visit.consultation.investigations.length >
                                                        0 && (
                                                        <span>
                                                            <i
                                                                className="ti ti-flask"
                                                                aria-hidden="true"
                                                            />
                                                            {
                                                                visit.consultation.investigations
                                                                    .length
                                                            }{' '}
                                                            ordered
                                                        </span>
                                                    )}

                                                    {visit.consultation.follow_up_days && (
                                                        <span>
                                                            <i
                                                                className="ti ti-calendar-repeat"
                                                                aria-hidden="true"
                                                            />
                                                            Follow-up in{' '}
                                                            {visit.consultation.follow_up_days} days
                                                        </span>
                                                    )}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </Card>
            )}

            {tab === 'vitals' && (
                <Card title={<span className="opd-card-title">Vitals over time</span>}>
                    {visits.filter((visit) => visit.consultation?.vitals
                        && Object.keys(visit.consultation.vitals).length > 0).length === 0 ? (
                        <div className="cn-none-yet">
                            <i className="ti ti-activity" aria-hidden="true" />
                            <b>No vitals recorded</b>
                            <p>They are taken during a consultation and appear here.</p>
                        </div>
                    ) : (
                        <div className="av-table-scroll">
                            <table className="av-table tbl-cards">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        {VITALS.map(([, label, unit]) => (
                                            <th key={label}>
                                                {label}
                                                {unit ? ` (${unit})` : ''}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>

                                <tbody>
                                    {visits
                                        .filter(
                                            (visit) =>
                                                visit.consultation?.vitals &&
                                                Object.keys(visit.consultation.vitals).length > 0,
                                        )
                                        .map((visit) => (
                                            <tr key={visit.id}>
                                                <td className="av-date" data-label="Date">
                                                    {longDate(visit.date)}
                                                </td>

                                                {VITALS.map(([key, label, unit]) => (
                                                    <td
                                                        key={key}
                                                        className="av-dim"
                                                        data-label={
                                                            unit ? `${label} (${unit})` : label
                                                        }
                                                    >
                                                        {vitalOf(
                                                            visit.consultation!.vitals,
                                                            key,
                                                        ) ?? '—'}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            )}
        </>
    );
}

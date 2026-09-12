import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { cn } from '@/shared/utils/cn';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { Customer } from '../types';
import {
    ALLERGY_KEYS,
    BLOOD_GROUP_KEYS,
    DEDICATED_KEYS,
    EMERGENCY_KEYS,
    STATUS,
    VITALS,
    customText,
    longDate,
    vitalOf,
    yearOf,
    type LabRow,
    type NoteRow,
    type PatientFile,
    type PatientRecord,
    type PrescriptionRow,
    type Section,
    type Visit,
} from './patientFile';

/** Switches the file to another section. */
export type Go = (section: Section) => void;

/** How many rows a section shows on the overview before "View all". */
const PREVIEW = 5;

/*
|--------------------------------------------------------------------------
| Pieces
|--------------------------------------------------------------------------
*/

function Panel({
    title,
    icon,
    action,
    flush,
    className,
    children,
}: {
    title: string;
    icon?: string;
    action?: ReactNode;
    /** No body padding, for a table that runs to the edges. */
    flush?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card
            title={<span className="opd-card-title">{title}</span>}
            icon={icon}
            actions={action}
            className={cn('pf-panel', className)}
            bodyClassName={flush ? 'pf-flush' : undefined}
        >
            {children}
        </Card>
    );
}

function ViewAll({ to, go }: { to: Section; go: Go }) {
    return (
        <button type="button" className="md-viewall" onClick={() => go(to)}>
            View all <i className="ti ti-arrow-right" aria-hidden="true" />
        </button>
    );
}

function NoneYet({ icon, title, line }: { icon: string; title: string; line: string }) {
    return (
        <div className="cn-none-yet">
            <i className={icon} aria-hidden="true" />
            <b>{title}</b>
            <p>{line}</p>
        </div>
    );
}

function NotRecorded() {
    return <span className="cn-quiet">Not recorded</span>;
}

function capitalise(value: string | null | undefined): string | null {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : null;
}

/*
|--------------------------------------------------------------------------
| Personal information
|--------------------------------------------------------------------------
*/

export function PersonalInfo({
    patient,
    fields,
    canEdit,
}: {
    patient: Customer;
    fields: ConfigurableField[] | undefined;
    canEdit: boolean;
}) {
    const address = [
        patient.address,
        patient.city,
        patient.district,
        patient.state,
        patient.pincode,
        patient.country,
    ]
        .filter(Boolean)
        .join(', ');

    /*
     * Whatever else this clinic has chosen to record, read from its field
     * settings so a field added in Settings appears here with no change to
     * this screen. Blood group and the like have rows of their own.
     */
    const extra = (fields ?? [])
        .filter((field) => field.is_custom && !DEDICATED_KEYS.has(field.key))
        .map((field) => ({ label: field.label, value: (patient.custom_fields ?? {})[field.key] }))
        .filter((row) => row.value !== undefined && row.value !== null && row.value !== '');

    const rows: [string, ReactNode][] = [
        ['Full name', patient.name],
        [
            'Date of birth',
            patient.date_of_birth
                ? `${longDate(patient.date_of_birth)}${patient.age != null ? ` (${patient.age} years)` : ''}`
                : null,
        ],
        ['Gender', capitalise(patient.gender)],
        ['Phone', patient.phone],
        ['Email', patient.email],
        ['Address', address || null],
        ['Blood group', customText(patient, BLOOD_GROUP_KEYS)],
        ['Emergency contact', customText(patient, EMERGENCY_KEYS)],
    ];

    return (
        <Panel
            title="Personal Information"
            action={
                canEdit ? (
                    <Link className="btn btn-sm btn-light" to={`/customers/${patient.id}/edit`}>
                        Edit
                    </Link>
                ) : undefined
            }
        >
            <dl className="pf-rows">
                {rows.map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd>{value || <NotRecorded />}</dd>
                    </div>
                ))}

                {extra.map((row) => (
                    <div key={row.label}>
                        <dt>{row.label}</dt>
                        <dd>{Array.isArray(row.value) ? row.value.join(', ') : String(row.value)}</dd>
                    </div>
                ))}
            </dl>
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Medical summary
|--------------------------------------------------------------------------
*/

export function MedicalSummary({
    patient,
    record,
    file,
    go,
}: {
    patient: Customer;
    record: PatientRecord | undefined;
    file: PatientFile;
    go: Go;
}) {
    const medications = record?.summary.medications;
    const allergies = customText(patient, ALLERGY_KEYS);

    return (
        <Panel title="Medical Summary" action={<ViewAll to="allergies" go={go} />}>
            {file.diagnoses.length > 0 && (
                <div className="pf-chips">
                    {file.diagnoses.slice(0, 3).map((row) => (
                        <span key={row.name} className="pf-chip">
                            {row.name}
                        </span>
                    ))}
                </div>
            )}

            <dl className="pf-med">
                <div>
                    <dt>Recorded diagnoses</dt>
                    <dd>
                        {file.diagnoses.length === 0 ? (
                            <span className="cn-quiet">Nothing diagnosed at a visit yet</span>
                        ) : (
                            <ul>
                                {file.diagnoses.slice(0, 4).map((row) => (
                                    <li key={row.name}>
                                        {row.name}
                                        {row.since && <small> · since {yearOf(row.since)}</small>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </dd>
                </div>

                <div>
                    <dt>Last prescribed</dt>
                    <dd>
                        {!medications || medications.lines.length === 0 ? (
                            <span className="cn-quiet">Nothing prescribed yet</span>
                        ) : (
                            <ul>
                                {medications.lines.slice(0, 3).map((line, index) => (
                                    <li key={index}>
                                        {line.drug}
                                        {line.dose ? ` ${line.dose}` : ''}
                                        <small> · {longDate(medications.on)}</small>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </dd>
                </div>

                <div>
                    <dt>Allergies</dt>
                    {/*
                        "Not recorded", never "No known allergies": a blank
                        field is not a clinical statement, and reading it as
                        one is how somebody gets the drug they react to.
                    */}
                    <dd>{allergies ?? <NotRecorded />}</dd>
                </div>
            </dl>
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Vitals
|--------------------------------------------------------------------------
*/

export function VitalsLatest({ record }: { record: PatientRecord | undefined }) {
    const latest = record?.summary.vitals;

    const present = latest
        ? VITALS.map((vital) => ({ vital, value: vitalOf(latest.values, vital.key) })).filter(
              (row) => row.value !== null,
          )
        : [];

    return (
        <Panel
            title="Vitals (Latest)"
            action={latest ? <span className="pr-when">{longDate(latest.on)}</span> : undefined}
        >
            {present.length === 0 ? (
                <NoneYet
                    icon="ti ti-activity"
                    title="No vitals recorded"
                    line="They are taken during a consultation and appear here."
                />
            ) : (
                <ul className="pf-vitals">
                    {present.map(({ vital, value }) => (
                        <li key={vital.key}>
                            <i className={cn(vital.icon, `pf-tone-${vital.tone}`)} aria-hidden="true" />
                            <span>{vital.label}</span>
                            <b>
                                {value}
                                {vital.unit && <em>{vital.unit}</em>}
                            </b>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

export function VitalsHistory({ rows }: { rows: Visit[] }) {
    return (
        <Panel title="Vitals Over Time" icon="ti ti-heartbeat" flush>
            {rows.length === 0 ? (
                <div className="pf-pad">
                    <NoneYet
                        icon="ti ti-activity"
                        title="No vitals recorded"
                        line="They are taken during a consultation and appear here."
                    />
                </div>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                {VITALS.map((vital) => (
                                    <th key={vital.key}>
                                        {vital.label}
                                        {vital.unit ? ` (${vital.unit})` : ''}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((visit) => (
                                <tr key={visit.id}>
                                    <td className="pf-strong">{longDate(visit.date)}</td>
                                    {VITALS.map((vital) => (
                                        <td key={vital.key}>
                                            {vitalOf(visit.consultation?.vitals, vital.key) ?? '—'}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Visits, prescriptions, lab reports
|--------------------------------------------------------------------------
*/

export function VisitsTable({ visits, preview, go }: { visits: Visit[]; preview?: boolean; go: Go }) {
    const rows = preview ? visits.slice(0, PREVIEW) : visits;

    return (
        <Panel
            title="Visit History"
            icon={preview ? undefined : 'ti ti-calendar-event'}
            action={preview && visits.length > PREVIEW ? <ViewAll to="visits" go={go} /> : undefined}
            flush
        >
            {rows.length === 0 ? (
                <div className="pf-pad">
                    <NoneYet
                        icon="ti ti-calendar-off"
                        title="No visits yet"
                        line="This patient has not been booked in or seen at any branch."
                    />
                </div>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Doctor</th>
                                <th>Department</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((visit) => (
                                <tr key={visit.id}>
                                    <td className="pf-strong">{longDate(visit.date)}</td>
                                    <td>{visit.doctor_name ?? '—'}</td>
                                    <td>{visit.doctor_specialisation ?? '—'}</td>
                                    <td>
                                        {visit.consultation?.chief_complaint ||
                                            visit.consultation?.diagnoses[0] ||
                                            (visit.type === 'walk_in' ? 'Walk-in' : 'Booked visit')}
                                    </td>
                                    <td>
                                        <em className={`pt-state is-${visit.status}`}>
                                            {STATUS[visit.status] ?? visit.status}
                                        </em>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

export function PrescriptionsTable({
    rows,
    preview,
    go,
}: {
    rows: PrescriptionRow[];
    preview?: boolean;
    go: Go;
}) {
    const shown = preview ? rows.slice(0, PREVIEW) : rows;

    return (
        <Panel
            title={preview ? 'Recent Prescriptions' : 'Prescriptions'}
            icon={preview ? undefined : 'ti ti-prescription'}
            action={preview && rows.length > PREVIEW ? <ViewAll to="prescriptions" go={go} /> : undefined}
            flush
        >
            {shown.length === 0 ? (
                <div className="pf-pad">
                    <NoneYet
                        icon="ti ti-pill-off"
                        title="Nothing prescribed yet"
                        line="Medicines written at a consultation appear here."
                    />
                </div>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Doctor</th>
                                <th>Medicines</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((row) => (
                                <tr key={row.id}>
                                    <td className="pf-strong">{longDate(row.date)}</td>
                                    <td>{row.doctor ?? '—'}</td>
                                    <td>
                                        {preview
                                            ? row.lines.map((line) => line.drug).join(', ')
                                            : row.lines.map((line, index) => (
                                                  <div key={index} className="pf-rx">
                                                      <b>{line.drug}</b>
                                                      {[line.dose, line.frequency, line.duration]
                                                          .filter(Boolean)
                                                          .join(' · ')}
                                                      {line.notes && <small> — {line.notes}</small>}
                                                  </div>
                                              ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

export function LabsTable({ rows, preview, go }: { rows: LabRow[]; preview?: boolean; go: Go }) {
    const shown = preview ? rows.slice(0, PREVIEW) : rows;

    return (
        <Panel
            title="Lab Reports"
            icon={preview ? undefined : 'ti ti-flask'}
            action={preview && rows.length > PREVIEW ? <ViewAll to="labs" go={go} /> : undefined}
            flush
        >
            {shown.length === 0 ? (
                <div className="pf-pad">
                    <NoneYet
                        icon="ti ti-flask-off"
                        title="No tests ordered yet"
                        line="Tests ordered at a consultation appear here."
                    />
                </div>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Test Name</th>
                                <th>Ordered By</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((row) => (
                                <tr key={row.key}>
                                    <td className="pf-strong">{longDate(row.date)}</td>
                                    <td>
                                        {row.test}
                                        {row.notes && <small className="d-block cn-quiet">{row.notes}</small>}
                                    </td>
                                    <td>{row.doctor ?? '—'}</td>
                                    {/*
                                        "Ordered" until results are stored: the
                                        record knows a test was asked for, not
                                        what it found, and must not imply more.
                                    */}
                                    <td>
                                        <span className="pf-pill is-sky">Ordered</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!preview && shown.length > 0 && (
                <p className="pf-soon pf-soon-inset">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    Results and report files will appear beside each test once lab reports can be
                    uploaded.
                </p>
            )}
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Sections waiting on a module
|--------------------------------------------------------------------------
|
| Built now so the record has its full shape. Each says what will appear
| and why it is empty, rather than a row of zeroes that looks finished.
*/

export function DocumentsPanel({ preview }: { preview?: boolean }) {
    return (
        <Panel title="Files & Documents" icon={preview ? undefined : 'ti ti-files'}>
            <NoneYet
                icon="ti ti-file-off"
                title="No files yet"
                line="Reports, scans, prescriptions and ID proofs uploaded for this patient will appear here."
            />
        </Panel>
    );
}

export function BillingPanel({ preview }: { preview?: boolean }) {
    return (
        <Panel title="Billing & Payments" icon={preview ? undefined : 'ti ti-receipt'}>
            <NoneYet
                icon="ti ti-receipt-off"
                title="No bills yet"
                line="Invoices and payments appear here once billing is switched on for your clinic."
            />
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Services, notes
|--------------------------------------------------------------------------
*/

export function ServicesPanel({ file, preview }: { file: PatientFile; preview?: boolean }) {
    const slices = [
        { label: 'Consultations', value: file.seen.length },
        { label: 'Lab tests', value: file.labs.length },
        { label: 'Prescriptions', value: file.prescriptions.length },
    ];

    return (
        <Panel title="Services Used" icon={preview ? undefined : 'ti ti-stethoscope'}>
            <DonutChart
                slices={slices}
                centreLabel="Total"
                empty="Nothing used yet. Consultations, tests and prescriptions add up here."
            />
        </Panel>
    );
}

export function NotesPanel({ rows, preview, go }: { rows: NoteRow[]; preview?: boolean; go: Go }) {
    const shown = preview ? rows.slice(0, PREVIEW) : rows;

    return (
        <Panel
            title="Notes"
            icon={preview ? undefined : 'ti ti-notes'}
            action={preview && rows.length > PREVIEW ? <ViewAll to="notes" go={go} /> : undefined}
        >
            {shown.length === 0 ? (
                <NoneYet
                    icon="ti ti-notes-off"
                    title="No notes yet"
                    line="Notes and advice written at a consultation appear here."
                />
            ) : (
                <ul className="pf-notes">
                    {shown.map((row) => (
                        <li key={row.key}>
                            <time>{longDate(row.date)}</time>
                            <div>
                                <p>{row.text}</p>
                                <small>
                                    {row.kind}
                                    {row.doctor ? ` · ${row.doctor}` : ''}
                                </small>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| Allergies & conditions, record settings
|--------------------------------------------------------------------------
*/

export function AllergiesPanel({ patient, file }: { patient: Customer; file: PatientFile }) {
    const allergies = customText(patient, ALLERGY_KEYS);

    return (
        <div className="pf-grid is-2">
            <Panel title="Allergies" icon="ti ti-alert-triangle">
                {allergies ? (
                    <p className="pf-allergy">
                        <i className="ti ti-alert-triangle" aria-hidden="true" />
                        {allergies}
                    </p>
                ) : (
                    <NoneYet
                        icon="ti ti-alert-triangle"
                        title="Not recorded"
                        line="Nothing has been recorded either way, which is not the same as no allergies. Add an Allergies field under Settings → Fields to record them."
                    />
                )}
            </Panel>

            <Panel title="Recorded Conditions" icon="ti ti-clipboard-heart">
                {file.diagnoses.length === 0 ? (
                    <NoneYet
                        icon="ti ti-clipboard-off"
                        title="Nothing diagnosed yet"
                        line="Diagnoses written at a consultation appear here, with when each was first recorded."
                    />
                ) : (
                    <>
                        <ul className="pf-conditions">
                            {file.diagnoses.map((row) => (
                                <li key={row.name}>
                                    <b>{row.name}</b>
                                    <small>first recorded {longDate(row.since)}</small>
                                </li>
                            ))}
                        </ul>
                        <p className="pf-soon">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            Every diagnosis written at a visit, not only long-term ones: nothing
                            yet marks a condition as chronic.
                        </p>
                    </>
                )}
            </Panel>
        </div>
    );
}

export function RecordPanel({
    patient,
    record,
    canEdit,
    history,
}: {
    patient: Customer;
    record: PatientRecord | undefined;
    canEdit: boolean;
    /** The history button, supplied by the page. */
    history: ReactNode;
}) {
    const rows: [string, ReactNode][] = [
        ['Patient ID', patient.code ?? '—'],
        ['Status', patient.is_active === false ? 'Inactive' : 'Active'],
        ['Registered at', patient.registered_location?.name ?? 'This organisation'],
        ['Registered on', longDate(record?.summary.member_since ?? patient.created_at?.slice(0, 10))],
        ['Last updated', longDate(patient.updated_at?.slice(0, 10))],
    ];

    return (
        <Panel title="Record Settings" icon="ti ti-settings">
            <dl className="pf-rows">
                {rows.map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>

            <div className="pf-record-actions">
                {canEdit && (
                    <Link className="btn btn-light" to={`/customers/${patient.id}/edit`}>
                        <i className="ti ti-pencil me-1" aria-hidden="true" />
                        Edit details
                    </Link>
                )}
                {history}
            </div>
        </Panel>
    );
}

/*
|--------------------------------------------------------------------------
| The overview: a bit of every section
|--------------------------------------------------------------------------
*/

export function Overview({
    patient,
    fields,
    record,
    file,
    canEdit,
    go,
}: {
    patient: Customer;
    fields: ConfigurableField[] | undefined;
    record: PatientRecord | undefined;
    file: PatientFile;
    canEdit: boolean;
    go: Go;
}) {
    const visits = record?.visits ?? [];

    return (
        <>
            <div className="pf-grid is-3">
                <PersonalInfo patient={patient} fields={fields} canEdit={canEdit} />
                <MedicalSummary patient={patient} record={record} file={file} go={go} />
                <VitalsLatest record={record} />
            </div>

            <div className="pf-grid is-2">
                <VisitsTable visits={visits} preview go={go} />
                <PrescriptionsTable rows={file.prescriptions} preview go={go} />
            </div>

            <div className="pf-grid is-2">
                <LabsTable rows={file.labs} preview go={go} />
                <DocumentsPanel preview />
            </div>

            <div className="pf-grid is-3">
                <BillingPanel preview />
                <ServicesPanel file={file} preview />
                <NotesPanel rows={file.notes} preview go={go} />
            </div>
        </>
    );
}

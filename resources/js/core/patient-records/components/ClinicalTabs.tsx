import { Fragment, useState } from 'react';
import { VITALS, vitalOf } from '@/core/customers/components/patientFile';
import { cn } from '@/shared/utils/cn';
import { usePatientAppointments, usePatientLabOrders, usePatientPrescriptions, usePrescription } from '../api';
import { clock, day, labPill, prescriptionPill, visitPill } from '../labels';
import type { LabOrder, PatientVisit } from '../types';
import { Empty, Loading, Pager, Pill, RecordCard, TableFrame } from './Bits';

const PER_PAGE = 10;

/** A row that opens to show what is inside it, one at a time. */
function useOpenRow() {
    const [open, setOpen] = useState<number | null>(null);

    return {
        open,
        toggle: (id: number) => setOpen((current) => (current === id ? null : id)),
    };
}

function Chevron({ open }: { open: boolean }) {
    return <i className={cn('ti ti-chevron-down ptr-chevron', open && 'is-open')} aria-hidden="true" />;
}

/*
|--------------------------------------------------------------------------
| Appointments
|--------------------------------------------------------------------------
*/

/** Every booking — still to come, kept, cancelled, missed — at the branches this reader sees. */
export function AppointmentsTab({ patientId }: { patientId: number }) {
    const [view, setView] = useState<'all' | 'upcoming'>('all');
    const [page, setPage] = useState(1);

    const { data, isLoading, isFetching } = usePatientAppointments(patientId, {
        view: view === 'upcoming' ? 'upcoming' : undefined,
        page,
        per_page: PER_PAGE,
    });

    const rows = data?.data ?? [];

    return (
        <RecordCard
            title="Appointment History"
            icon="ti ti-calendar"
            action={
                <div className="ptr-seg" role="group" aria-label="Which appointments">
                    {(['all', 'upcoming'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            className={cn(view === value && 'is-on')}
                            aria-pressed={view === value}
                            onClick={() => {
                                setView(value);
                                setPage(1);
                            }}
                        >
                            {value === 'all' ? 'All' : 'Upcoming'}
                        </button>
                    ))}
                </div>
            }
        >
            {isLoading ? (
                <Loading />
            ) : rows.length === 0 ? (
                <Empty
                    icon="ti ti-calendar-off"
                    title={view === 'upcoming' ? 'Nothing booked ahead' : 'No appointments yet'}
                    line="Bookings and walk-ins at your branches appear here."
                />
            ) : (
                <div className={cn(isFetching && 'is-refreshing')}>
                    <TableFrame>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Token</th>
                                <th>Doctor</th>
                                <th>Branch</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row: PatientVisit) => (
                                <tr key={row.id}>
                                    <td className="ptr-strong">
                                        {day(row.appointment_date)}
                                        {row.slot_at && <small>{clock(row.slot_at)}</small>}
                                    </td>
                                    <td>{row.token_label ?? row.token_no ?? '—'}</td>
                                    <td>
                                        {row.doctor_name ?? '—'}
                                        {row.doctor_specialisation && <small>{row.doctor_specialisation}</small>}
                                    </td>
                                    <td>{row.location_name ?? '—'}</td>
                                    <td>
                                        {row.type === 'walk_in' ? 'Walk-in' : 'Booked'}
                                        {row.mode === 'video' && <small>Video</small>}
                                    </td>
                                    <td>
                                        <Pill pill={visitPill(row.status)} />
                                        {row.cancellation_reason && <small>{row.cancellation_reason}</small>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </TableFrame>

                    <Pager
                        page={page}
                        pageCount={data?.meta.last_page ?? 1}
                        onChange={setPage}
                        perPage={PER_PAGE}
                        total={data?.meta.total}
                    />
                </div>
            )}
        </RecordCard>
    );
}

/*
|--------------------------------------------------------------------------
| OPD visits — the clinical history
|--------------------------------------------------------------------------
*/

/** One visit, as the doctor wrote it up. */
function VisitNote({ visit }: { visit: PatientVisit }) {
    const note = visit.consultation;

    const vitals = note
        ? VITALS.map((vital) => ({ vital, value: vitalOf(note.vitals, vital.key) })).filter((row) => row.value)
        : [];

    return (
        <article className="ptr-visit">
            <header className="ptr-visit-head">
                <div className="ptr-visit-date">
                    <b>{day(visit.appointment_date)}</b>
                    <small>{clock(visit.started_at ?? visit.slot_at) ?? (visit.type === 'walk_in' ? 'Walk-in' : '')}</small>
                </div>

                <div className="ptr-visit-who">
                    <b>{visit.doctor_name ?? 'Doctor not recorded'}</b>
                    <small>
                        {[visit.doctor_specialisation, visit.location_name].filter(Boolean).join(' · ')}
                    </small>
                </div>

                <Pill pill={visitPill(visit.status)} />
            </header>

            {!note ? (
                <p className="ptr-quiet ptr-visit-none">Not written up — no consultation notes for this visit.</p>
            ) : (
                <dl className="ptr-visit-body">
                    {note.chief_complaint && (
                        <div>
                            <dt>Chief complaint</dt>
                            <dd>{note.chief_complaint}</dd>
                        </div>
                    )}

                    {note.diagnoses.length > 0 && (
                        <div>
                            <dt>Diagnosis</dt>
                            <dd className="ptr-chips">
                                {note.diagnoses.map((name) => (
                                    <span key={name}>{name}</span>
                                ))}
                            </dd>
                        </div>
                    )}

                    {vitals.length > 0 && (
                        <div>
                            <dt>Vitals</dt>
                            <dd className="ptr-vitals">
                                {vitals.map(({ vital, value }) => (
                                    <span key={vital.key}>
                                        {vital.label} <b>{value}</b>
                                        {vital.unit && <em>{vital.unit}</em>}
                                    </span>
                                ))}
                            </dd>
                        </div>
                    )}

                    {note.prescription.length > 0 && (
                        <div>
                            <dt>Prescribed</dt>
                            <dd>
                                <ul className="ptr-lines">
                                    {note.prescription.map((line, index) => (
                                        <li key={index}>
                                            <b>{line.drug}</b>
                                            {[line.dose, line.frequency, line.duration].filter(Boolean).join(' · ')}
                                        </li>
                                    ))}
                                </ul>
                            </dd>
                        </div>
                    )}

                    {note.investigations.length > 0 && (
                        <div>
                            <dt>Investigations</dt>
                            <dd>{note.investigations.map((line) => line.test).join(', ')}</dd>
                        </div>
                    )}

                    {note.advice && (
                        <div>
                            <dt>Advice</dt>
                            <dd>{note.advice}</dd>
                        </div>
                    )}

                    {note.follow_up_days ? (
                        <div>
                            <dt>Follow-up</dt>
                            <dd>In {note.follow_up_days} days</dd>
                        </div>
                    ) : null}
                </dl>
            )}
        </article>
    );
}

/** The visits they came to, newest first, with what was written at each. */
export function VisitsTab({ patientId }: { patientId: number }) {
    const [page, setPage] = useState(1);
    const { data, isLoading, isFetching } = usePatientAppointments(patientId, {
        view: 'visits',
        page,
        per_page: PER_PAGE,
    });

    const rows = data?.data ?? [];

    return (
        <RecordCard title="OPD Visits & Clinical History" icon="ti ti-stethoscope">
            {isLoading ? (
                <Loading />
            ) : rows.length === 0 ? (
                <Empty
                    icon="ti ti-stethoscope-off"
                    title="No visits yet"
                    line="Visits this patient came to at your branches appear here, with the doctor's notes."
                />
            ) : (
                <div className={cn('ptr-visits', isFetching && 'is-refreshing')}>
                    {rows.map((visit) => (
                        <VisitNote key={visit.id} visit={visit} />
                    ))}

                    <Pager
                        page={page}
                        pageCount={data?.meta.last_page ?? 1}
                        onChange={setPage}
                        perPage={PER_PAGE}
                        total={data?.meta.total}
                    />
                </div>
            )}
        </RecordCard>
    );
}

/*
|--------------------------------------------------------------------------
| Prescriptions — the prescriptions module's own list
|--------------------------------------------------------------------------
*/

function PrescriptionLines({ id }: { id: number }) {
    const { data, isLoading } = usePrescription(id);

    if (isLoading) return <Loading label="Loading medicines…" />;

    const items = data?.items ?? [];

    if (items.length === 0) return <p className="ptr-quiet mb-0">No medicines on this prescription.</p>;

    return (
        <ul className="ptr-lines">
            {items.map((item) => (
                <li key={item.id}>
                    <b>
                        {item.medicine_name}
                        {item.strength ? ` ${item.strength}` : ''}
                    </b>
                    {[item.line.dose, item.line.frequency, item.line.duration].filter(Boolean).join(' · ')}
                    {item.instructions && <small> — {item.instructions}</small>}
                </li>
            ))}
        </ul>
    );
}

export function PrescriptionsTab({ patientId }: { patientId: number }) {
    const [page, setPage] = useState(1);
    const row = useOpenRow();
    const { data, isLoading, isFetching } = usePatientPrescriptions(patientId, { page, per_page: PER_PAGE });

    const rows = data?.data ?? [];

    return (
        <RecordCard title="Prescriptions" icon="ti ti-prescription">
            {isLoading ? (
                <Loading />
            ) : rows.length === 0 ? (
                <Empty
                    icon="ti ti-pill-off"
                    title="Nothing prescribed yet"
                    line="Prescriptions written at a consultation appear here."
                />
            ) : (
                <div className={cn(isFetching && 'is-refreshing')}>
                    <TableFrame>
                        <thead>
                            <tr>
                                <th>Prescription</th>
                                <th>Date</th>
                                <th>Doctor</th>
                                <th>Branch</th>
                                <th>Medicines</th>
                                <th>Status</th>
                                <th aria-label="Open" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((rx) => (
                                <Fragment key={rx.id}>
                                    <tr className="ptr-openable" onClick={() => row.toggle(rx.id)}>
                                        <td className="ptr-strong">{rx.prescription_number}</td>
                                        <td className="ptr-date">{day(rx.prescription_date)}</td>
                                        <td>{rx.doctor_name ?? '—'}</td>
                                        <td>{rx.location_name ?? '—'}</td>
                                        <td>{rx.items_count ?? '—'}</td>
                                        <td>
                                            <Pill pill={prescriptionPill(rx.status)} />
                                        </td>
                                        <td className="ptr-end">
                                            <Chevron open={row.open === rx.id} />
                                        </td>
                                    </tr>
                                    {row.open === rx.id && (
                                        <tr className="ptr-detail-row">
                                            <td colSpan={7}>
                                                <PrescriptionLines id={rx.id} />
                                                {rx.clinical_notes && <p className="ptr-note">{rx.clinical_notes}</p>}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </TableFrame>

                    <Pager
                        page={page}
                        pageCount={data?.meta.last_page ?? 1}
                        onChange={setPage}
                        perPage={PER_PAGE}
                        total={data?.meta.total}
                    />
                </div>
            )}
        </RecordCard>
    );
}

/*
|--------------------------------------------------------------------------
| Lab reports — the laboratory's own orders
|--------------------------------------------------------------------------
*/

function LabResults({ order }: { order: LabOrder }) {
    const items = order.items ?? [];

    return (
        <TableFrame>
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Result</th>
                    <th>Reference range</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                {items.map((item) => (
                    <tr key={item.id}>
                        <td className="ptr-strong">
                            {item.test_name}
                            {item.specimen && <small>{item.specimen}</small>}
                        </td>
                        <td className={cn(item.is_abnormal && 'ptr-abnormal')}>
                            {item.result_value ?? item.result_text ?? '—'}
                            {item.result_value && item.result_unit ? ` ${item.result_unit}` : ''}
                            {item.is_abnormal && <small>Outside range</small>}
                        </td>
                        <td>{item.reference_range ?? '—'}</td>
                        <td>
                            <Pill
                                pill={
                                    item.status === 'completed'
                                        ? { label: 'Done', tone: 'green' }
                                        : item.status === 'cancelled'
                                          ? { label: 'Cancelled', tone: 'grey' }
                                          : { label: 'Awaited', tone: 'blue' }
                                }
                            />
                        </td>
                    </tr>
                ))}
            </tbody>
        </TableFrame>
    );
}

export function LabTab({ patientId }: { patientId: number }) {
    const [page, setPage] = useState(1);
    const row = useOpenRow();
    const { data, isLoading, isFetching } = usePatientLabOrders(patientId, { page, per_page: PER_PAGE });

    const rows = data?.data ?? [];

    return (
        <RecordCard title="Lab Reports" icon="ti ti-flask">
            {isLoading ? (
                <Loading />
            ) : rows.length === 0 ? (
                <Empty
                    icon="ti ti-flask-off"
                    title="No lab orders yet"
                    line="Tests ordered for this patient appear here, with their results once recorded."
                />
            ) : (
                <div className={cn(isFetching && 'is-refreshing')}>
                    <TableFrame>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Date</th>
                                <th>Tests</th>
                                <th>Ordered by</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th aria-label="Open" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((order) => (
                                <Fragment key={order.id}>
                                    <tr className="ptr-openable" onClick={() => row.toggle(order.id)}>
                                        <td className="ptr-strong">{order.order_number}</td>
                                        <td className="ptr-date">{day(order.order_date)}</td>
                                        <td>{order.tests_summary || '—'}</td>
                                        <td>{order.doctor_name ?? '—'}</td>
                                        <td>{order.location_name ?? '—'}</td>
                                        <td>
                                            <Pill pill={labPill(order.status)} />
                                        </td>
                                        <td className="ptr-end">
                                            <Chevron open={row.open === order.id} />
                                        </td>
                                    </tr>
                                    {row.open === order.id && (
                                        <tr className="ptr-detail-row">
                                            <td colSpan={7}>
                                                <LabResults order={order} />
                                                {order.cancellation_reason && (
                                                    <p className="ptr-note">Cancelled: {order.cancellation_reason}</p>
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </TableFrame>

                    <Pager
                        page={page}
                        pageCount={data?.meta.last_page ?? 1}
                        onChange={setPage}
                        perPage={PER_PAGE}
                        total={data?.meta.total}
                    />
                </div>
            )}
        </RecordCard>
    );
}

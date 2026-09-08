import { Link } from 'react-router-dom';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import type { MyDay } from '../types';

/** "12 Aug 2026" */
function longDate(value: string | null): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** The icon that goes with a recorded gender, or a neutral one. */
function genderIcon(gender: string | null): string {
    const value = (gender ?? '').toLowerCase();

    if (value.startsWith('m')) return 'ti ti-gender-male';
    if (value.startsWith('f')) return 'ti ti-gender-female';

    return 'ti ti-user';
}

/**
 * Who is in the room, beside the write-up rather than above it.
 *
 * A doctor checks the name, the age and the allergies against the person in
 * front of them while typing — a header they have scrolled past cannot be
 * checked against anything.
 */
export function PatientAside({
    current,
    onHistory,
}: {
    current: NonNullable<MyDay['current']>;
    /** Opens the History tab, which is where "previous visits" already lives. */
    onHistory: () => void;
}) {
    const last = current.history[0];

    return (
        <div className="pa">
            {/*
                Photo beside the name, not above it. Stacked, the identity took
                four rows of a narrow column before a single fact about the
                visit appeared.
            */}
            <div className="pa-head">
                <PersonPhoto src={null} name={current.customer_name} className="pa-face" />

                <div className="pa-id">
                    <b>{current.customer_name ?? 'Unnamed'}</b>
                    <small>Patient ID: {current.customer_code ?? '—'}</small>

                    <div className="pa-chips">
                        {current.age !== null && <span>{current.age} years</span>}

                        {current.gender && (
                            <span>
                                <i className={genderIcon(current.gender)} aria-hidden="true" />
                                {current.gender}
                            </span>
                        )}

                        <span>
                            <i
                                className={
                                    current.type === 'walk_in'
                                        ? 'ti ti-walk'
                                        : 'ti ti-calendar-check'
                                }
                                aria-hidden="true"
                            />
                            {current.type === 'walk_in' ? 'Walk-in' : 'Booked'}
                        </span>
                    </div>
                </div>
            </div>

            <ul className="pa-facts">
                {current.phone && (
                    <li>
                        <i className="is-green ti ti-phone" aria-hidden="true" />
                        <a href={`tel:${current.phone}`}>{current.phone}</a>
                    </li>
                )}

                {current.where && (
                    <li>
                        <i className="is-rose ti ti-map-pin" aria-hidden="true" />
                        {current.where}
                    </li>
                )}

                {current.location_name && (
                    <li>
                        <i className="is-blue ti ti-building-store" aria-hidden="true" />
                        {current.location_name}
                    </li>
                )}
            </ul>

            {/*
                Allergies say one of two things, and the difference matters.
                A recorded allergy is the line that stops a prescription; "none
                recorded" is calm but present, because a doctor needs to know
                somebody looked rather than that nobody asked.
            */}
            <Link
                className={`pa-allergy${current.allergies ? ' is-known' : ''}`}
                to={`/customers/${current.customer_id}`}
            >
                <i
                    className={
                        current.allergies ? 'ti ti-alert-triangle' : 'ti ti-shield-check'
                    }
                    aria-hidden="true"
                />

                <span>
                    <b>Known allergies</b>
                    <small>{current.allergies ?? 'None recorded'}</small>
                </span>

                <i className="ti ti-chevron-right" aria-hidden="true" />
            </Link>

            <p className="pa-heading">Quick actions</p>

            <div className="pa-quick">
                {/*
                    A colour each, so four tiles read as four things rather
                    than as a block. The tone matches the row it relates to in
                    the write-up — records are blue, history violet — so the
                    two halves of the card agree with each other.
                */}
                <Link className="pa-tile is-blue" to={`/customers/${current.customer_id}`}>
                    <i className="ti ti-file-text" aria-hidden="true" />
                    Full record
                </Link>

                <button type="button" className="pa-tile is-violet" onClick={onHistory}>
                    <i className="ti ti-history" aria-hidden="true" />
                    Previous visits
                </button>

                {/*
                    Named but inert. Printing a summary and sharing a record
                    both need the consultation rendered as a document, which is
                    its own piece — a tile that opened a blank page would be
                    worse than one that says it is not ready.
                */}
                <button
                    type="button"
                    className="pa-tile is-amber"
                    disabled
                    title="Not available yet"
                >
                    <i className="ti ti-printer" aria-hidden="true" />
                    Print summary
                </button>

                <button
                    type="button"
                    className="pa-tile is-teal"
                    disabled
                    title="Not available yet"
                >
                    <i className="ti ti-share" aria-hidden="true" />
                    Share record
                </button>
            </div>

            {/*
                Their last visit, when there was one. The date and who saw them
                is the whole of it — anything more is the History tab.
            */}
            {last && (
                <button type="button" className="pa-last" onClick={onHistory}>
                    <i className="ti ti-circle-check" aria-hidden="true" />

                    <span>
                        <b>Last visit</b>
                        <small>
                            {longDate(last.on)}
                            {last.doctor_name ? ` · ${last.doctor_name}` : ''}
                        </small>
                    </span>

                    <i className="ti ti-chevron-right" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

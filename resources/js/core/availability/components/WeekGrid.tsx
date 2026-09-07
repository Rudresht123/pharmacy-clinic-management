import type { AvailabilityWeek, DayState, WeekDoctor } from '../types';

/** How each state reads, in words as well as colour. */
const STATE: Record<DayState, { label: string; tone: string }> = {
    available: { label: 'Available', tone: 'ok' },
    changed: { label: 'Different from the usual', tone: 'moved' },
    unavailable: { label: 'Unavailable', tone: 'off' },
    none: { label: 'No sitting', tone: 'none' },
};

/**
 * A branch's week: doctors down, dates across.
 *
 * The day view answers "who is in today", which the desk needs. This answers
 * the one a practice manager actually asks — where are the gaps, who is on
 * leave on Wednesday, is anybody covering Saturday — and that is a shape
 * rather than a number, so it has to be seen across the week rather than seven
 * times in a row.
 *
 * A cancelled day is drawn differently from a day off. They look identical in
 * a diary and are opposites in practice: one is the roster working as
 * intended, the other is somebody who was due in and is not, which is the
 * thing that needs covering.
 */
export function WeekGrid({
    week,
    selected,
    onSelect,
}: {
    week: AvailabilityWeek;
    selected: number | null;
    onSelect: (doctor: WeekDoctor) => void;
}) {
    return (
        <div className="wk-scroll">
            <table className="wk">
                <thead>
                    <tr>
                        <th className="wk-who">Doctor</th>

                        {week.days.map((day) => (
                            <th key={day.date} className={day.is_today ? 'is-today' : undefined}>
                                <span>{day.label}</span>
                                <small>{day.day}</small>
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {week.doctors.map((doctor) => (
                        <tr
                            key={doctor.doctor_id}
                            className={selected === doctor.doctor_id ? 'is-open' : undefined}
                        >
                            <th scope="row" className="wk-who">
                                {/*
                                    The whole name cell selects the row. A row
                                    is scanned across and then clicked at
                                    whichever end the eye stopped, and a hit
                                    target the width of an avatar is a target
                                    people miss.
                                */}
                                <button
                                    type="button"
                                    className="wk-doc"
                                    onClick={() => onSelect(doctor)}
                                    aria-pressed={selected === doctor.doctor_id}
                                >
                                    {doctor.photo_url ? (
                                        <img src={doctor.photo_url} alt="" className="wk-face" />
                                    ) : (
                                        <span className="wk-face is-letter" aria-hidden="true">
                                            {doctor.doctor_name.replace(/^Dr\.?\s*/i, '').charAt(0)}
                                        </span>
                                    )}

                                    <span className="wk-doc-text">
                                        <b>{doctor.doctor_name}</b>
                                        {doctor.specialisation && (
                                            <small>{doctor.specialisation}</small>
                                        )}
                                    </span>
                                </button>
                            </th>

                            {doctor.days.map((cell) => (
                                <td key={cell.date}>
                                    {cell.state === 'none' ? (
                                        /*
                                            A dash, not an empty cell. Blank
                                            reads as "not loaded"; a dash says
                                            somebody looked and there is
                                            nothing.
                                        */
                                        <span className="wk-none" aria-label="No sitting">
                                            —
                                        </span>
                                    ) : cell.state === 'unavailable' ? (
                                        <span className="wk-cell is-off">
                                            <b>Unavailable</b>
                                            {cell.reason && <small>{cell.reason}</small>}
                                        </span>
                                    ) : (
                                        <span
                                            className={`wk-cell is-${STATE[cell.state].tone}`}
                                            title={
                                                cell.state === 'changed'
                                                    ? (cell.reason ?? 'Changed for this date')
                                                    : undefined
                                            }
                                        >
                                            {cell.sessions.map((session, index) => (
                                                <span className="wk-session" key={index}>
                                                    <b>
                                                        {session.starts_at} – {session.ends_at}
                                                    </b>
                                                    <small>{session.name ?? 'OPD'}</small>
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            {/*
                Named, not only coloured. Four fills that differ by hue alone
                are four fills somebody has to be told about — and the two that
                matter most, a day off and a cancelled day, are exactly the
                pair a colour-blind reader would lose.
            */}
            <ul className="wk-legend">
                {(Object.keys(STATE) as DayState[]).map((state) => (
                    <li key={state}>
                        <i className={`wk-key is-${STATE[state].tone}`} aria-hidden="true" />
                        {STATE[state].label}
                    </li>
                ))}
            </ul>
        </div>
    );
}

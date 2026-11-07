import { useEffect, useState } from 'react';
import { Avatar } from '@/shared/components/ui/Avatar';
import type { DisplayState, DisplayType, QueueCard } from '../types';

export interface PreviewDoctor {
    id: number;
    name: string | null;
    department: string | null;
}

interface DisplayTvPreviewProps {
    type: DisplayType;
    layout: 'grid' | 'list';
    theme: 'dark' | 'light';
    organization: { name: string; logo_url: string | null } | null;
    /** Who the form currently has on this screen, saved or not. */
    doctors: PreviewDoctor[];
    /** The queue today, for whoever the saved display already covers. */
    cards: QueueCard[];
    lab: DisplayState['lab'];
    /** Said on the screen when there is nobody to show yet. */
    emptyMessage: string;
}

/** Past these the real television takes turns, so the small one says how many more. */
const ROWS_SHOWN = 3;
const TILES_SHOWN = 4;

function useMinuteClock(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 15_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

/** The token on screen, or a dash: never a made-up one for a doctor with no queue. */
function tokenOf(card: QueueCard | undefined): string {
    return card?.now_calling ?? card?.serving ?? '—';
}

function Person({ doctor, card, size }: { doctor: PreviewDoctor; card?: QueueCard; size: number }) {
    return (
        <Avatar
            name={doctor.name}
            src={card?.photo_url}
            size={size}
            fallback="silhouette"
            preview={false}
        />
    );
}

function Rows({ doctors, cardOf }: { doctors: PreviewDoctor[]; cardOf(id: number): QueueCard | undefined }) {
    return (
        <div className="tvd-tv-rows">
            {doctors.slice(0, ROWS_SHOWN).map((doctor) => {
                const card = cardOf(doctor.id);

                return (
                    <div className="tvd-tv-row" key={doctor.id}>
                        <Person doctor={doctor} card={card} size={26} />

                        <div className="tvd-tv-who">
                            <b>{doctor.name}</b>
                            {doctor.department && <small>{doctor.department}</small>}
                        </div>

                        <span className="tvd-tv-token">{tokenOf(card)}</span>
                    </div>
                );
            })}
        </div>
    );
}

function Tiles({ doctors, cardOf }: { doctors: PreviewDoctor[]; cardOf(id: number): QueueCard | undefined }) {
    return (
        <div className="tvd-tv-tiles">
            {doctors.slice(0, TILES_SHOWN).map((doctor) => (
                <div className="tvd-tv-tile" key={doctor.id}>
                    <div className="tvd-tv-who">
                        <b>{doctor.name}</b>
                        {doctor.department && <small>{doctor.department}</small>}
                    </div>

                    <span className="tvd-tv-token">{tokenOf(cardOf(doctor.id))}</span>
                </div>
            ))}
        </div>
    );
}

function SingleDoctor({ doctor, card }: { doctor: PreviewDoctor; card?: QueueCard }) {
    return (
        <div className="tvd-tv-solo">
            <Person doctor={doctor} card={card} size={40} />

            <div className="tvd-tv-who">
                <b>{doctor.name}</b>
                {doctor.department && <small>{doctor.department}</small>}
            </div>

            <div className="tvd-tv-solo-now">
                <small>Now Calling</small>
                <span className="tvd-tv-token">{tokenOf(card)}</span>
            </div>
        </div>
    );
}

function LabBoard({ lab }: { lab: DisplayState['lab'] }) {
    const columns: { label: string; tokens: string[] | undefined }[] = [
        { label: 'Waiting', tokens: lab?.waiting },
        { label: 'In Progress', tokens: lab?.in_progress },
        { label: 'Ready', tokens: lab?.ready },
    ];

    return (
        <div className="tvd-tv-lab">
            {columns.map((column) => (
                <div className="tvd-tv-tile" key={column.label}>
                    <small>{column.label}</small>
                    <span className="tvd-tv-token">{column.tokens ? column.tokens.length : '—'}</span>
                </div>
            ))}
        </div>
    );
}

const HEADINGS: Record<DisplayType, string> = {
    reception: 'Live OPD Queue',
    department: 'Live OPD Queue',
    doctor: 'Live Queue',
    laboratory: 'Laboratory Queue',
};

/**
 * The television, small, drawn from what is in the form this moment.
 *
 * Not the real screen shrunk down: that is built in viewport units for a wall
 * and would be unreadable here, and it can only read what is already saved. The
 * full-size one is under Preview on the displays list. Everything shown is real:
 * the doctors chosen, the clinic's name and the live token; a doctor the saved
 * display does not cover yet shows a dash.
 */
export function DisplayTvPreview({
    type,
    layout,
    theme,
    organization,
    doctors,
    cards,
    lab,
    emptyMessage,
}: DisplayTvPreviewProps) {
    const now = useMinuteClock();
    const cardOf = (id: number) => cards.find((card) => card.doctor_id === id);

    const shown = type === 'doctor' ? 1 : layout === 'grid' ? TILES_SHOWN : ROWS_SHOWN;
    const more = type === 'laboratory' ? 0 : doctors.length - shown;

    let body;

    if (type === 'laboratory') {
        body = <LabBoard lab={lab} />;
    } else if (doctors.length === 0) {
        body = <p className="tvd-tv-empty">{emptyMessage}</p>;
    } else if (type === 'doctor') {
        body = <SingleDoctor doctor={doctors[0]} card={cardOf(doctors[0].id)} />;
    } else if (layout === 'grid') {
        body = <Tiles doctors={doctors} cardOf={cardOf} />;
    } else {
        body = <Rows doctors={doctors} cardOf={cardOf} />;
    }

    return (
        <div className="tvd-tv" aria-label="Preview of this display" role="img">
            {/* data-theme swaps the surface tokens for this subtree alone, as the TV's own theme does. */}
            <div className="tvd-tv-screen" data-theme={theme}>
                <header className="tvd-tv-head">
                    <div className="tvd-tv-brand">
                        {organization?.logo_url ? (
                            <img src={organization.logo_url} alt="" />
                        ) : (
                            <i className="ti ti-building-hospital" aria-hidden="true" />
                        )}
                        <span>{organization?.name}</span>
                    </div>

                    <span className="tvd-tv-title">{HEADINGS[type]}</span>

                    <div className="tvd-tv-clock">
                        <b>{now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()}</b>
                        <small>{now.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}</small>
                    </div>
                </header>

                {body}

                {more > 0 && <small className="tvd-tv-more">+{more} more, taking turns</small>}
            </div>

            <span className="tvd-tv-leg tvd-tv-leg--left" />
            <span className="tvd-tv-leg tvd-tv-leg--right" />
        </div>
    );
}

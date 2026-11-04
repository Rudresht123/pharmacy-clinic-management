import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Avatar } from '@/shared/components/ui/Avatar';
import type { DisplayState, QueueCard } from '../types';

/** A wall this big is read from across a room. Past it, the cards take turns. */
const MOST_ON_ONE_PAGE = 12;

/** What fits under the board's heading and over its figures, at a readable size. */
const ROWS_ON_ONE_PAGE = 5;

const SECONDS_PER_PAGE = 12;

/** Department pills shown before the rest move under "More". */
const PILLS_SHOWN = 3;

/** How many colours a token's chip takes turns through, so rows read apart. */
const CHIP_TONES = 4;

/** Columns that keep cards roughly the shape of the screen. */
function columnsFor(count: number): number {
    if (count <= 1) return 1;
    if (count === 2) return 2;
    if (count <= 4) return 2;
    if (count <= 6) return 3;
    if (count <= 9) return 3;

    return 4;
}

function useClock(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

/** Which page is showing, turning by itself while there is more than one. */
function usePages(total: number, perPage: number): { page: number; pageCount: number } {
    const pageCount = Math.max(1, Math.ceil(total / perPage));
    const [page, setPage] = useState(0);

    useEffect(() => {
        if (pageCount <= 1) {
            setPage(0);

            return;
        }

        setPage((current) => (current < pageCount ? current : 0));

        const timer = window.setInterval(() => setPage((current) => (current + 1) % pageCount), SECONDS_PER_PAGE * 1000);

        return () => window.clearInterval(timer);
    }, [pageCount]);

    return { page: Math.min(page, pageCount - 1), pageCount };
}

/**
 * An uploaded picture, from whichever address this screen was opened on.
 *
 * The server writes its links from APP_URL. A television is a different machine, so
 * a link naming `localhost` would point at the television itself. Files kept
 * elsewhere, such as cloud storage, have another path and are left alone.
 */
function picture(url: string | null | undefined): string | null {
    if (!url) {
        return null;
    }

    try {
        const { pathname } = new URL(url, window.location.origin);

        return pathname.startsWith('/storage/') ? pathname : url;
    } catch {
        return url;
    }
}

/** Tokens and rooms never wrap: a split token is a token misread. */
function Next({ tokens }: { tokens: string[] }) {
    if (tokens.length === 0) {
        return <span className="tv-next-empty">No one waiting</span>;
    }

    return (
        <span className="tv-next-tokens">
            {tokens.map((token) => (
                <b key={token}>{token}</b>
            ))}
        </span>
    );
}

/** What a card says when the doctor is not calling, instead of an empty box. */
function statusLine(card: QueueCard): string | null {
    if (card.paused) return 'On a break';
    if (card.availability === 'later') return card.session_starts_at ? `Starts at ${card.session_starts_at}` : 'Later today';
    if (card.availability === 'finished') return 'Session finished';
    if (card.availability === 'off') return 'Not in today';

    return null;
}

function Waiting({ card }: { card: QueueCard }) {
    return (
        <div className="tv-wait">
            <span>Waiting</span>
            <b>{String(card.waiting).padStart(2, '0')}</b>
            {card.waiting > 0 && !card.paused && (
                <small>about {card.estimated_wait_minutes} min</small>
            )}
        </div>
    );
}

function DoctorCard({ card, calling }: { card: QueueCard; calling: boolean }) {
    const status = statusLine(card);

    return (
        <article className={`tv-card${calling ? ' is-calling' : ''}${card.paused ? ' is-paused' : ''}`}>
            <header className="tv-card-head">
                <div className="tv-card-who">
                    <h2>{card.doctor_name}</h2>
                    {card.department && <p>{card.department}</p>}
                </div>
                {card.room && (
                    <div className="tv-room">
                        <span>Room</span>
                        <b>{card.room}</b>
                    </div>
                )}
            </header>

            <div className="tv-now">
                <span>{card.now_calling_state === 'serving' ? 'In consultation' : 'Now calling'}</span>
                <b className={card.now_calling ? undefined : 'is-empty'}>{card.now_calling ?? '—'}</b>
                {status && <em className="tv-status">{status}</em>}
            </div>

            <footer className="tv-card-foot">
                <div className="tv-next">
                    <span>Next</span>
                    <Next tokens={card.next} />
                </div>
                <Waiting card={card} />
            </footer>
        </article>
    );
}

/** The strip under the token: where to go, or why nobody is being called. */
function callLine(card: QueueCard): ReactNode {
    const status = statusLine(card);

    if (card.now_calling_state === 'called') {
        return (
            <>
                <i className="ti ti-walk" aria-hidden="true" />
                {/* One span, so a long doctor's name wraps as a sentence, not as two columns. */}
                {card.room ? (
                    <span>
                        Please proceed to <b>Room {card.room}</b>
                    </span>
                ) : (
                    <span>
                        Please go to <b className="is-name">{card.doctor_name ?? 'the doctor'}&rsquo;s room</b>
                    </span>
                )}
            </>
        );
    }

    if (card.now_calling_state === 'serving') {
        return (
            <>
                <i className="ti ti-stethoscope" aria-hidden="true" />
                With the doctor{card.room && <>&nbsp;in <b>Room {card.room}</b></>}
            </>
        );
    }

    return (
        <>
            <i className="ti ti-clock-hour-4" aria-hidden="true" />
            {status ?? 'Waiting for the next patient'}
        </>
    );
}

/**
 * One doctor, large: who, the token being called and whose it is, and who is next.
 *
 * Only the patient being called is named, so they know it is them. Everybody else
 * on this screen is a token: it hangs in a public room.
 */
function FeaturedDoctor({ card, calling, solo = false }: { card: QueueCard; calling: boolean; solo?: boolean }) {
    return (
        <section className={`tv-feature${solo ? ' is-solo' : ''}`}>
            <header className="tv-feature-who">
                <Avatar name={card.doctor_name} src={picture(card.photo_url)} preview={false} className="tv-face" />

                <div className="tv-feature-text">
                    <h2>{card.doctor_name}</h2>
                    {card.department && <p>{card.department}</p>}
                    {card.room && (
                        <span className="tv-room-chip">
                            <i className="ti ti-door" aria-hidden="true" /> Room {card.room}
                        </span>
                    )}
                </div>
            </header>

            <div className={`tv-call${calling ? ' is-calling' : ''}`}>
                <span className="tv-call-label">
                    <i className="ti ti-speakerphone" aria-hidden="true" />
                    {card.now_calling_state === 'serving' ? 'In consultation' : 'Now calling'}
                </span>

                <b className={`tv-call-token${card.now_calling ? '' : ' is-empty'}`}>{card.now_calling ?? '—'}</b>

                <div className="tv-call-strips">
                    {card.patient?.name && (
                        <div className="tv-call-patient">
                            <Avatar
                                name={card.patient.name}
                                src={picture(card.patient.photo_url)}
                                preview={false}
                                className="tv-call-face"
                            />

                            <b>{card.patient.name}</b>

                            {card.patient.age !== null && <span>Age: {card.patient.age} Yrs</span>}
                        </div>
                    )}

                    <p className="tv-call-line">{callLine(card)}</p>
                </div>
            </div>

            <div className="tv-upnext">
                <h3>
                    <i className="ti ti-stack-2" aria-hidden="true" /> Next in Queue
                    {solo && card.waiting > 0 && !card.paused && (
                        <small>
                            {String(card.waiting).padStart(2, '0')} waiting, about {card.estimated_wait_minutes} min
                        </small>
                    )}
                </h3>

                {card.next.length > 0 ? (
                    <div className="tv-upnext-tokens">
                        {card.next.map((token) => (
                            <b key={token}>{token}</b>
                        ))}
                    </div>
                ) : (
                    <p>No one waiting</p>
                )}
            </div>
        </section>
    );
}

/** A wait as somebody would say it: before a session starts it can run to hours. */
function roughly(minutes: number): string {
    if (minutes < 60) {
        return `~ ${minutes} Minutes`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest > 0 ? `~ ${hours} hr ${rest} min` : `~ ${hours} hr`;
}

/** Whole-floor figures, every one worked out from the cards on this screen. */
function BoardFigures({ cards }: { cards: QueueCard[] }) {
    const waiting = cards.reduce((sum, card) => sum + card.waiting, 0);

    const queues = cards.filter((card) => card.waiting > 0 && !card.paused);
    const average = queues.length > 0
        ? Math.round(queues.reduce((sum, card) => sum + card.estimated_wait_minutes, 0) / queues.length)
        : null;

    const active = cards.filter((card) => card.availability === 'in_session' && !card.paused).length;
    const departments = new Set(cards.map((card) => card.department).filter(Boolean)).size;

    const figures: [string, string, string, string][] = [
        ['ti ti-users', 'Total Waiting', String(waiting), 'is-blue'],
        ['ti ti-clock-hour-4', 'Avg. Waiting Time', average === null ? '—' : roughly(average), 'is-violet'],
        ['ti ti-activity-heartbeat', 'Active Doctors', String(active), 'is-green'],
        ['ti ti-building-hospital', 'Departments', String(departments), 'is-blue'],
    ];

    return (
        <dl className="tv-figures">
            {figures.map(([icon, label, value, tone]) => (
                <div className={`tv-figure ${tone}`} key={label}>
                    <i className={icon} aria-hidden="true" />
                    <span>
                        <dt>{label}</dt>
                        <dd>{value}</dd>
                    </span>
                </div>
            ))}
        </dl>
    );
}

/** The doctor list. A row is a button: choosing one puts that doctor on the left. */
function DoctorRows({
    cards,
    allCards,
    firstNumber,
    featuredId,
    calling,
    onChoose,
    pageNote,
}: {
    cards: QueueCard[];
    allCards: QueueCard[];
    firstNumber: number;
    featuredId: number;
    calling: Set<number>;
    onChoose: (doctorId: number) => void;
    pageNote: string | null;
}) {
    return (
        <div className="tv-rows">
            <div className="tv-rows-head" aria-hidden="true">
                <span>#</span>
                <span>Doctor &amp; Department</span>
                <span>Room</span>
                <span>Now Calling</span>
                <span>Next</span>
                <span>Waiting</span>
                <span />
            </div>

            {cards.map((card, index) => {
                const on = card.doctor_id === featuredId;

                // By place in the whole list, so a doctor keeps a colour across pages.
                const tone = allCards.findIndex((each) => each.doctor_id === card.doctor_id) % CHIP_TONES;
                const status = statusLine(card);

                return (
                    <button
                        type="button"
                        key={card.doctor_id}
                        aria-pressed={on}
                        className={`tv-row${on ? ' is-on' : ''}${calling.has(card.doctor_id) ? ' is-calling' : ''}`}
                        onClick={() => onChoose(card.doctor_id)}
                    >
                        <span className="tv-row-number">{firstNumber + index}</span>

                        <span className="tv-row-who">
                            <Avatar name={card.doctor_name} src={picture(card.photo_url)} preview={false} className="tv-row-face" />

                            <span className="tv-row-text">
                                <b>{card.doctor_name}</b>
                                {card.department && (
                                    <small>
                                        <i className="ti ti-stethoscope" aria-hidden="true" /> {card.department}
                                    </small>
                                )}
                            </span>
                        </span>

                        <span className="tv-row-room">
                            <i className="ti ti-door" aria-hidden="true" /> {card.room ?? '—'}
                        </span>

                        {card.now_calling ? (
                            <b className={`tv-token is-tone-${on ? 'on' : tone}`}>{card.now_calling}</b>
                        ) : (
                            <span className="tv-token is-idle">{status ?? '—'}</span>
                        )}

                        <b className="tv-row-next">{card.next[0] ?? '—'}</b>

                        <span className="tv-row-wait">
                            <i className="ti ti-users" aria-hidden="true" /> {String(card.waiting).padStart(2, '0')}
                        </span>

                        <i className="ti ti-chevron-right tv-row-go" aria-hidden="true" />
                    </button>
                );
            })}

            {pageNote && <span className="tv-pages tv-rows-page">{pageNote}</span>}
        </div>
    );
}

/**
 * The waiting room's main screen: whoever was just called, large on the left, and
 * every doctor on the right.
 *
 * A new call takes the left side by itself, because that is the one thing everybody
 * in the room is looking up for. Choosing a row does the same until the next call.
 */
function QueueBoard({ cards, calling }: { cards: QueueCard[]; calling: Set<number> }) {
    const [chosen, setChosen] = useState<number | null>(null);
    const [picked, setPicked] = useState('');

    const previous = useRef(calling);

    useEffect(() => {
        for (const doctorId of calling) {
            if (!previous.current.has(doctorId)) {
                setChosen(doctorId);
            }
        }

        previous.current = calling;
    }, [calling]);

    const featured =
        cards.find((card) => card.doctor_id === chosen) ??
        cards.find((card) => card.now_calling_state === 'called') ??
        cards.find((card) => card.now_calling !== null) ??
        cards[0];

    const departments = useMemo(
        () => [...new Set(cards.map((card) => card.department).filter((name): name is string => Boolean(name)))],
        [cards],
    );

    // A department that has since left the screen must not leave it blank.
    const department = departments.includes(picked) ? picked : '';
    const rows = useMemo(
        () => (department ? cards.filter((card) => card.department === department) : cards),
        [cards, department],
    );

    const { page, pageCount } = usePages(rows.length, ROWS_ON_ONE_PAGE);
    const start = page * ROWS_ON_ONE_PAGE;

    const pills = departments.slice(0, PILLS_SHOWN);
    const others = departments.slice(PILLS_SHOWN);

    return (
        <div className="tv-board">
            <FeaturedDoctor card={featured} calling={calling.has(featured.doctor_id)} />

            <section className="tv-doctors">
                <header className="tv-doctors-head">
                    <h2>
                        <i className="ti ti-users" aria-hidden="true" /> All Doctors &ndash; Live Queue
                    </h2>

                    <div className="tv-pills">
                        <button
                            type="button"
                            className={`tv-pill is-fixed${department === '' ? ' is-on' : ''}`}
                            onClick={() => setPicked('')}
                        >
                            All Departments
                        </button>

                        {pills.map((name) => (
                            <button
                                type="button"
                                key={name}
                                className={`tv-pill${department === name ? ' is-on' : ''}`}
                                onClick={() => setPicked(name)}
                            >
                                {name}
                            </button>
                        ))}

                        {others.length > 0 && (
                            <select
                                className={`tv-pill is-fixed tv-pill-more${others.includes(department) ? ' is-on' : ''}`}
                                aria-label="More departments"
                                value={others.includes(department) ? department : ''}
                                onChange={(event) => setPicked(event.target.value)}
                            >
                                <option value="">More</option>
                                {others.map((name) => (
                                    <option key={name} value={name}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                </header>

                <DoctorRows
                    cards={rows.slice(start, start + ROWS_ON_ONE_PAGE)}
                    allCards={cards}
                    firstNumber={start + 1}
                    featuredId={featured.doctor_id}
                    calling={calling}
                    onChoose={setChosen}
                    pageNote={pageCount > 1 ? `Page ${page + 1} of ${pageCount}` : null}
                />

                <BoardFigures cards={cards} />
            </section>
        </div>
    );
}

function LabBoard({ lab }: { lab: NonNullable<DisplayState['lab']> }) {
    const columns: [string, string[], string][] = [
        ['Waiting for sample', lab.waiting, 'is-waiting'],
        ['In progress', lab.in_progress, 'is-working'],
        ['Report ready', lab.ready, 'is-ready'],
    ];

    return (
        <div className="tv-lab">
            {columns.map(([title, tokens, tone]) => (
                <section className={`tv-lab-col ${tone}`} key={title}>
                    <h2>{title}</h2>
                    <div className="tv-lab-tokens">
                        {tokens.length === 0 ? <p>None right now</p> : tokens.map((token) => <b key={token}>{token}</b>)}
                    </div>
                </section>
            ))}
        </div>
    );
}

/**
 * One television's picture, whichever way it reached the page.
 *
 * Only what the server sent and the wall clock: no requests, no sockets. The
 * public television and the signed-in preview both draw this, so what somebody
 * checks from their desk is exactly what the waiting room sees.
 *
 * The "List" layout is the board; "Cards" is one card per doctor, sized in units
 * of the card rather than of the screen (`--tv-u`), which is what lets the same
 * card read properly at one across a wall or twelve.
 */
export function TvScreen({
    state,
    calling,
    connection,
    soundPrompt,
}: {
    state: DisplayState;
    /** Doctors whose card should pulse right now, for a token just called. */
    calling: Set<number>;
    connection: 'live' | 'reconnecting' | 'preview';
    /** Shown until somebody taps, because a browser allows no sound before that. */
    soundPrompt?: { onEnable: () => void } | null;
}) {
    const now = useClock();

    const cards = state.cards;
    const isList = state.display.layout === 'list';
    const solo = state.display.type === 'doctor' || (cards.length === 1 && !isList);
    const lab = state.display.type === 'laboratory' && state.lab;

    // Only the cards turn pages out here: the board pages its own rows.
    const { page, pageCount } = usePages(isList || solo || lab ? 0 : cards.length, MOST_ON_ONE_PAGE);

    const shown = useMemo(
        () => cards.slice(page * MOST_ON_ONE_PAGE, (page + 1) * MOST_ON_ONE_PAGE),
        [cards, page],
    );

    const columns = columnsFor(shown.length);
    const rows = Math.max(1, Math.ceil(shown.length / columns));

    return (
        <div
            className={`tv tv-theme-${state.display.theme}`}
            style={{
                ['--tv-cols' as string]: columns,
                ['--tv-rows' as string]: rows,
                ['--tv-u' as string]: `calc(min(${100 / columns}vw, ${82 / rows}vh) / 100)`,
            }}
        >
            <header className="tv-top">
                <div className="tv-brand">
                    {state.organization.logo_url ? (
                        <Avatar
                            name={state.organization.name}
                            src={picture(state.organization.logo_url)}
                            shape="rounded"
                            preview={false}
                            className="tv-logo"
                        />
                    ) : (
                        <span className="tv-logo-mark" aria-hidden="true" />
                    )}

                    <div className="tv-brand-text">
                        <h1>{state.organization.name}</h1>
                        {state.branch.name && <p>{state.branch.name}</p>}
                    </div>
                </div>

                <div className="tv-title">
                    <i className="ti ti-users" aria-hidden="true" />
                    {lab ? 'Laboratory Queue' : state.display.type === 'doctor' ? 'Live Queue' : 'OPD Queue Management'}
                </div>

                <div className="tv-clock">
                    <i className="ti ti-calendar-event" aria-hidden="true" />

                    <div>
                        <span>
                            {now.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}
                        </span>
                        <b>{now.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()}</b>
                    </div>
                </div>

                {connection === 'preview' ? (
                    <span className="tv-conn is-preview">
                        <i className="ti ti-eye" aria-hidden="true" /> Preview
                    </span>
                ) : (
                    <span className={`tv-conn ${connection === 'live' ? 'is-live' : 'is-off'}`}>
                        <i aria-hidden="true" /> {connection === 'live' ? 'Live' : 'Reconnecting'}
                    </span>
                )}
            </header>

            <main className="tv-main">
                {lab ? (
                    <LabBoard lab={lab} />
                ) : cards.length === 0 ? (
                    <div className="tv-empty">
                        <i className="ti ti-stethoscope" aria-hidden="true" />
                        <p>No doctors are sitting right now.</p>
                    </div>
                ) : solo ? (
                    <FeaturedDoctor card={cards[0]} calling={calling.has(cards[0].doctor_id)} solo />
                ) : isList ? (
                    <QueueBoard cards={cards} calling={calling} />
                ) : (
                    <div className="tv-grid">
                        {shown.map((card) => (
                            <DoctorCard key={card.doctor_id} card={card} calling={calling.has(card.doctor_id)} />
                        ))}
                    </div>
                )}
            </main>

            <footer className="tv-bottom">
                <span className="tv-bottom-text">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    <span>Please wait for your token to be called.</span>
                    <span>Keep your documents ready.</span>
                    <span>Emergency and priority patients will be seen first.</span>
                </span>

                <span className="tv-bottom-right">
                    {soundPrompt && (
                        <button type="button" className="tv-sound" onClick={soundPrompt.onEnable}>
                            <i className="ti ti-volume" aria-hidden="true" /> Tap to turn on sound
                        </button>
                    )}

                    {pageCount > 1 && <span className="tv-pages">Page {page + 1} of {pageCount}</span>}
                </span>
            </footer>
        </div>
    );
}

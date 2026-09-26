import type { AppointmentStatus, QueueStatus } from '@/core/appointments/types';

/**
 * How each visit state presents itself.
 *
 * An icon AND a word, never a colour on its own: the queue is read on a
 * tablet at arm's length, sometimes printed, and by people who do not all see
 * the same reds and greens. Colour is the third signal here, not the first.
 *
 * The three `awaiting_*` are new, and each gets its own icon rather than a
 * shared "pending" one: the whole value of the state to somebody at the desk
 * is knowing WHICH queue the patient is in, and a row of identical amber
 * clocks would make them open each one to find out.
 */
export const STATUS: Record<AppointmentStatus, { label: string; tone: string; icon: string }> = {
    booked: { label: 'Expected', tone: 'muted', icon: 'ti ti-clock' },
    checked_in: { label: 'Waiting', tone: 'sky', icon: 'ti ti-hourglass' },
    in_consultation: { label: 'With doctor', tone: 'amber', icon: 'ti ti-stethoscope' },

    awaiting_pharmacy: { label: 'Waiting for pharmacy', tone: 'teal', icon: 'ti ti-pill' },
    awaiting_lab: { label: 'Waiting for laboratory', tone: 'violet', icon: 'ti ti-flask' },
    awaiting_payment: { label: 'Payment pending', tone: 'rose', icon: 'ti ti-cash' },

    completed: { label: 'Visit completed', tone: 'emerald', icon: 'ti ti-circle-check' },
    cancelled: { label: 'Cancelled', tone: 'rose', icon: 'ti ti-ban' },
    no_show: { label: 'Did not come', tone: 'rose', icon: 'ti ti-user-x' },
};

/**
 * The desk's three, which live on a different column.
 *
 * Only `called` is genuinely new to the eye: `waiting` and `with_doctor`
 * mirror the visit states above and are toned to match, so a queue that
 * shows one column or the other does not change colour meaning.
 */
export const QUEUE: Record<QueueStatus, { label: string; tone: string; icon: string }> = {
    waiting: { label: 'Waiting', tone: 'sky', icon: 'ti ti-hourglass' },
    called: { label: 'Called', tone: 'indigo', icon: 'ti ti-bell-ringing' },
    with_doctor: { label: 'With doctor', tone: 'amber', icon: 'ti ti-stethoscope' },
};

/**
 * What is happening to this visit, in one badge.
 *
 * Shows the QUEUE state while the patient is in the department — waiting or
 * called, which the visit column cannot tell apart — and the VISIT state
 * once the doctor has finished, because that is the half that says where
 * they went. Passing `queue` is what switches it on; without it this behaves
 * exactly as it always did.
 */
export function StatusBadge({
    status,
    queue = null,
}: {
    status: AppointmentStatus;
    queue?: QueueStatus | null;
}) {
    const state = status === 'checked_in' && queue ? QUEUE[queue] : STATUS[status];

    if (!state) {
        return null;
    }

    return (
        <span className={`opd-status is-${state.tone}`}>
            <i className={state.icon} aria-hidden="true" />
            {state.label}
        </span>
    );
}

/**
 * The number the desk actually looks at.
 *
 * Three bands rather than a gradient, because the only useful question is
 * "should somebody do something about this" and a gradient makes that a
 * judgement call forty times an hour. The thresholds come from the server so
 * the board, the queue and any future alert cannot disagree.
 */
export function WaitBadge({
    minutes,
    warn,
    critical,
}: {
    minutes: number | null;
    warn: number;
    critical: number;
}) {
    if (minutes === null) {
        return <span className="opd-wait is-none">—</span>;
    }

    const band = minutes >= critical ? 'critical' : minutes >= warn ? 'warn' : 'fine';

    return (
        <span className={`opd-wait is-${band}`}>
            {band === 'critical' && <i className="ti ti-alert-triangle" aria-hidden="true" />}
            {minutes}m
        </span>
    );
}

/**
 * A token, monospaced and tabular.
 *
 * Numbers in a column somebody scans forty times an hour have to line up, and
 * a proportional face puts 11 and 33 at different widths.
 */
export function TokenBadge({ token, tone = 'muted' }: { token: number | null; tone?: string }) {
    if (token === null) {
        return (
            <span className="opd-token is-empty" title="No token yet — not checked in">
                <i className="ti ti-minus" aria-hidden="true" />
                <span className="visually-hidden">No token</span>
            </span>
        );
    }

    return <span className={`opd-token is-${tone}`}>{token}</span>;
}

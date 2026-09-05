import type { AppointmentStatus } from '@/core/appointments/types';

/**
 * How each queue state presents itself.
 *
 * An icon AND a word, never a colour on its own: the queue is read on a
 * tablet at arm's length, sometimes printed, and by people who do not all see
 * the same reds and greens. Colour is the third signal here, not the first.
 */
export const STATUS: Record<AppointmentStatus, { label: string; tone: string; icon: string }> = {
    booked: { label: 'Expected', tone: 'muted', icon: 'ti ti-clock' },
    checked_in: { label: 'Waiting', tone: 'sky', icon: 'ti ti-hourglass' },
    in_consultation: { label: 'With doctor', tone: 'amber', icon: 'ti ti-stethoscope' },
    completed: { label: 'Seen', tone: 'emerald', icon: 'ti ti-circle-check' },
    cancelled: { label: 'Cancelled', tone: 'rose', icon: 'ti ti-ban' },
    no_show: { label: 'Did not come', tone: 'rose', icon: 'ti ti-user-x' },
};

export function StatusBadge({ status }: { status: AppointmentStatus }) {
    const state = STATUS[status];

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

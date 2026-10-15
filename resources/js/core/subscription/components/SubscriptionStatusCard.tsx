import { Link } from 'react-router-dom';
import type { SubscriptionStatus } from '../types';
import { formatDay, formatPaise, plural } from '../money';

/**
 * The subscription at a glance: plan, status, how long is left, and the one
 * action that matters now. On the dashboard for the owner and billing
 * contacts, and at the top of the subscription page.
 *
 * Four states, all computed by the server from the subscription's own dates:
 * active, expiring soon (inside the warning window), in grace, locked. The
 * countdown is whole calendar days in the organization's timezone — "0"
 * reads "ends today".
 */
export function SubscriptionStatusCard({
    status,
    showActions = true,
}: {
    status: SubscriptionStatus;
    showActions?: boolean;
}) {
    const tz = status.timezone;
    const state = stateOf(status);

    return (
        <section className={`sx-status sx-status--${state.tone}`} aria-label="Subscription">
            <div className="sx-status-main">
                <div className="sx-status-head">
                    <span className={`sx-chip sx-chip--${state.tone}`}>
                        <i className={state.icon} aria-hidden="true" /> {state.label}
                    </span>
                    <h5 className="sx-status-plan">
                        {status.plan.name ?? 'Subscription'}
                        {!status.is_complimentary && status.price !== undefined && (
                            <small>
                                {' '}
                                · {formatPaise(status.price, status.currency)} /{' '}
                                {status.billing_cycle === 'annual' ? 'year' : 'month'}
                            </small>
                        )}
                    </h5>
                </div>

                <p className="sx-status-line">{state.sentence}</p>

                <dl className="sx-status-facts">
                    {status.is_complimentary ? (
                        <div>
                            <dt>Term</dt>
                            <dd>No end date</dd>
                        </div>
                    ) : (
                        <>
                            <div>
                                <dt>{status.status === 'trial' ? 'Trial ends' : status.locked ? 'Ended on' : 'Expires on'}</dt>
                                <dd>{formatDay(status.expires_at, tz)}</dd>
                            </div>
                            {status.grace_ends_at && (
                                <div>
                                    <dt>Grace ends</dt>
                                    <dd>{formatDay(status.grace_ends_at, tz)}</dd>
                                </div>
                            )}
                            {status.next_renewal_at && !status.locked && (
                                <div>
                                    <dt>Next renewal</dt>
                                    <dd>{formatDay(status.next_renewal_at, tz)}</dd>
                                </div>
                            )}
                            {status.locked && status.locked_at && (
                                <div>
                                    <dt>Locked on</dt>
                                    <dd>{formatDay(status.locked_at, tz)}</dd>
                                </div>
                            )}
                            {status.pending_plan && (
                                <div>
                                    <dt>From next term</dt>
                                    <dd>{status.pending_plan}</dd>
                                </div>
                            )}
                        </>
                    )}
                </dl>
            </div>

            <div className="sx-status-side">
                {state.count !== null && (
                    <div className="sx-countdown">
                        <b>{state.count}</b>
                        <span>{state.countLabel}</span>
                    </div>
                )}

                {showActions && status.can_manage && !status.is_complimentary && (
                    <div className="sx-status-actions">
                        <Link to="/subscription?action=renew" className="btn btn-primary">
                            <i className="ti ti-refresh me-1" aria-hidden="true" />
                            {status.locked || status.status === 'grace' || status.warning ? 'Renew now' : 'Renew subscription'}
                        </Link>
                        {!status.locked && (
                            <Link to="/subscription?action=upgrade" className="btn btn-light">
                                <i className="ti ti-arrow-up-right me-1" aria-hidden="true" />
                                Upgrade plan
                            </Link>
                        )}
                    </div>
                )}

                {showActions && status.can_manage && status.is_complimentary && (
                    <div className="sx-status-actions">
                        <Link to="/subscription" className="btn btn-light">
                            View subscription
                        </Link>
                    </div>
                )}
            </div>
        </section>
    );
}

interface StateView {
    tone: 'ok' | 'warn' | 'grace' | 'locked' | 'trial';
    icon: string;
    label: string;
    sentence: string;
    count: number | null;
    countLabel: string;
}

export function stateOf(status: SubscriptionStatus): StateView {
    const days = status.days_remaining;

    if (status.locked) {
        return {
            tone: 'locked',
            icon: 'ti ti-lock',
            label: status.lock_reason === 'billing_suspended' ? 'Suspended' : 'Expired',
            sentence:
                status.lock_reason === 'billing_suspended'
                    ? 'The subscription has been suspended. Contact us to restore access.'
                    : 'The subscription has expired and the workspace is locked. Renew to restore access — nothing has been deleted.',
            count: null,
            countLabel: '',
        };
    }

    if (status.status === 'grace') {
        const left = status.grace_days_remaining ?? 0;

        return {
            tone: 'grace',
            icon: 'ti ti-alert-triangle',
            label: 'Grace period',
            sentence: status.read_only
                ? `The subscription has expired. The workspace is read-only for ${left === 0 ? 'the rest of today' : plural(left, 'more day')}, then it locks.`
                : `The subscription has expired. Everything keeps working for ${left === 0 ? 'the rest of today' : plural(left, 'more day')}, then the workspace locks.`,
            count: left,
            countLabel: left === 1 ? 'day of grace' : 'days of grace',
        };
    }

    if (status.is_complimentary) {
        return {
            tone: 'ok',
            icon: 'ti ti-gift',
            label: 'Complimentary',
            sentence: 'Your organization is on a complimentary subscription with no end date.',
            count: null,
            countLabel: '',
        };
    }

    const ending = days === 0 ? 'today' : days === 1 ? 'tomorrow' : `in ${plural(days ?? 0, 'day')}`;

    if (status.status === 'trial') {
        return {
            tone: status.warning ? 'warn' : 'trial',
            icon: 'ti ti-hourglass',
            label: 'Trial',
            sentence: `Your free trial ends ${ending}. Choose a plan to keep working without interruption.`,
            count: days,
            countLabel: days === 1 ? 'day left' : 'days left',
        };
    }

    if (status.warning) {
        return {
            tone: 'warn',
            icon: 'ti ti-clock-exclamation',
            label: 'Expiring soon',
            sentence: `Your subscription ends ${ending}. Renew now to avoid any interruption.`,
            count: days,
            countLabel: days === 1 ? 'day left' : 'days left',
        };
    }

    return {
        tone: 'ok',
        icon: 'ti ti-circle-check',
        label: status.status === 'past_due' ? 'Payment due' : 'Active',
        sentence: status.cancelled_at
            ? `Cancelled — access continues until the end of the term.`
            : 'Your subscription is active.',
        count: days,
        countLabel: days === 1 ? 'day left' : 'days left',
    };
}

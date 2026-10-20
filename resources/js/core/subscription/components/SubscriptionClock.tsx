import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import type { SubscriptionStatus } from '../types';
import { formatShortDay } from '../money';
import { stateOf } from './SubscriptionStatusCard';

const SECOND = 1000;
const DAY = 86_400_000;

/**
 * The time left on the subscription, live, in the header — for the owner and
 * billing contacts, who can renew it. Days and a running HH:MM:SS clock; on
 * the last day the dial pulses. In grace it counts down the grace instead.
 * Everybody else gets the banner when it matters.
 */
export function SubscriptionClock() {
    const { subscription: status } = useTenantAuth();
    const target = countdownTarget(status);
    const [now, setNow] = useState(() => Date.now());
    const left = target === null ? null : Math.max(0, target - now);
    const lastDay = left !== null && left < DAY;

    useEffect(() => {
        if (target === null) {
            return;
        }

        setNow(Date.now());
        const id = window.setInterval(() => setNow(Date.now()), SECOND);

        return () => window.clearInterval(id);
    }, [target]);

    if (!status || !status.can_manage || status.is_complimentary) {
        return null;
    }

    const state = stateOf(status);

    if (status.locked) {
        return (
            <Link to="/subscription" className="sxc is-locked" title="The subscription has ended — renew to restore access">
                <span className="sxc-dial" aria-hidden="true">
                    <i className="ti ti-lock" />
                </span>
                <span className="sxc-text">
                    <b>Expired</b>
                    <small>Renew to unlock</small>
                </span>
            </Link>
        );
    }

    if (left === null || target === null) {
        return null;
    }

    const time = formatLeft(left);
    const percent = Math.max(0, Math.min(100, (left / termLength(status)) * 100));
    const radius = 15;
    const circumference = 2 * Math.PI * radius;
    const ends = status.status === 'grace' ? 'Grace ends' : status.cancelled_at ? 'Ends on' : status.status === 'trial' ? 'Trial ends' : 'Renews on';
    const caption = status.status === 'grace' ? 'grace left' : status.status === 'trial' ? 'trial left' : `left · ${status.plan.name ?? 'plan'}`;

    return (
        <Link
            to="/subscription"
            className={`sxc is-${state.tone}${lastDay ? ' is-ticking' : ''}`}
            title={`${status.plan.name ?? 'Subscription'} · ${ends} ${formatShortDay(new Date(target).toISOString(), status.timezone)}`}
            aria-label={`Subscription: ${time.spoken} ${caption}`}
        >
            <span className="sxc-dial" aria-hidden="true">
                <svg viewBox="0 0 36 36">
                    <circle className="sxc-track" cx="18" cy="18" r={radius} />
                    <circle
                        className="sxc-fill"
                        cx="18"
                        cy="18"
                        r={radius}
                        strokeDasharray={circumference}
                        strokeDashoffset={circumference * (1 - percent / 100)}
                        transform="rotate(-90 18 18)"
                    />
                </svg>
                <i className="ti ti-clock" />
            </span>
            <span className="sxc-text">
                <b>{time.text}</b>
                <small>{caption}</small>
            </span>
        </Link>
    );
}

/** The moment being counted down to, or null when nothing ends. */
function countdownTarget(status: SubscriptionStatus | null | undefined): number | null {
    if (!status || status.is_complimentary || status.locked) {
        return null;
    }

    const end = status.status === 'grace' ? status.grace_ends_at : status.expires_at;

    return end ? new Date(end).getTime() : null;
}

/** How long the thing being counted down lasts in all — the dial's full circle. */
function termLength(status: SubscriptionStatus): number {
    const [from, to] =
        status.status === 'grace'
            ? [status.expires_at, status.grace_ends_at]
            : [status.current_period_start ?? status.started_at, status.expires_at];
    const span = from && to ? new Date(to).getTime() - new Date(from).getTime() : 0;

    return span > 0 ? span : (status.billing_cycle === 'annual' ? 365 : 30) * DAY;
}

function formatLeft(ms: number): { text: string; spoken: string } {
    const total = Math.floor(ms / SECOND);
    const days = Math.floor(total / 86_400);
    const hours = Math.floor((total % 86_400) / 3_600);
    const minutes = Math.floor((total % 3_600) / 60);
    const seconds = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    const clock = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;

    if (days >= 1) {
        return { text: `${days}d ${clock}`, spoken: `${days} ${days === 1 ? 'day' : 'days'} ${hours} hours` };
    }

    return { text: clock, spoken: `${hours} hours ${minutes} minutes` };
}

import { Link } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { formatDay } from '../money';

/**
 * What a locked organization sees instead of its workspace.
 *
 * The API already refuses everything else (423), so this page is the
 * explanation, not the lock. The owner and billing contacts can go straight to
 * renewal; everybody else is told plainly who to ask. Nothing has been
 * deleted, and it says so — a locked clinic's first fear is its records.
 */
export default function SubscriptionLockedPage() {
    const { subscription, organization, logout } = useTenantAuth();
    const suspended = subscription?.lock_reason === 'billing_suspended';
    const tz = subscription?.timezone;

    return (
        <div className="org-pending sx-locked">
            <i className={suspended ? 'ti ti-lock-pause' : 'ti ti-lock'} aria-hidden="true" />

            <h6>{suspended ? 'Your subscription is suspended' : 'Your subscription has expired'}</h6>

            <p>
                {organization?.name ? <strong>{organization.name}</strong> : 'This workspace'}
                {suspended
                    ? ' has been suspended. Contact us to restore access.'
                    : ` was locked on ${formatDay(subscription?.locked_at ?? subscription?.grace_ends_at ?? subscription?.expires_at, tz)} because its subscription ended.`}
            </p>

            <p className="sx-locked-safe">
                <i className="ti ti-shield-check" aria-hidden="true" /> Your data is safe. Nothing has been
                deleted, and everything is back the moment the subscription is renewed.
            </p>

            <div className="d-flex flex-wrap gap-2 justify-content-center mt-3">
                {subscription?.can_manage && !suspended && (
                    <Link to="/subscription?action=renew" className="btn btn-primary">
                        <i className="ti ti-refresh me-1" aria-hidden="true" />
                        Renew subscription
                    </Link>
                )}

                {subscription?.can_manage && (
                    <Link to="/subscription" className="btn btn-light">
                        Invoices &amp; payments
                    </Link>
                )}

                <button type="button" className="btn btn-light" onClick={() => void logout()}>
                    <i className="ti ti-logout me-1" aria-hidden="true" />
                    Sign out
                </button>
            </div>

            {!subscription?.can_manage && (
                <p className="mt-3 text-muted">Ask your organization's owner to renew the subscription.</p>
            )}
        </div>
    );
}

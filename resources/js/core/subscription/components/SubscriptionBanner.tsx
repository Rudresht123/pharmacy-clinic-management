import { Link, useLocation } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { stateOf } from './SubscriptionStatusCard';

/**
 * A strip above every page while the subscription needs attention: ending
 * soon, in grace, or read-only. Says nothing the rest of the time.
 *
 * The owner and billing contacts get a Renew link; everybody else is told who
 * to ask, because the button would only open a screen they cannot use.
 */
export function SubscriptionBanner() {
    const { subscription } = useTenantAuth();
    const location = useLocation();

    if (!subscription || subscription.locked || !subscription.warning || location.pathname.startsWith('/subscription')) {
        return null;
    }

    const state = stateOf(subscription);

    return (
        <div className={`sx-banner sx-banner--${state.tone}`} role="status">
            <i className={state.icon} aria-hidden="true" />
            <span>{state.sentence}</span>

            {subscription.can_manage ? (
                <Link to="/subscription?action=renew" className="sx-banner-action">
                    Renew now
                </Link>
            ) : (
                <span className="sx-banner-note">Ask your organization's owner to renew it.</span>
            )}
        </div>
    );
}

import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';

/**
 * An App notification as it lands on a patient's lock screen.
 *
 * Shaped like the thing itself rather than like a form: the title is the
 * only line most people read before they swipe it away, and seeing it cut off
 * is how somebody learns to put the point first.
 *
 * The phone is drawn whole, top to bottom. A preview that crops the bottom
 * of the notification hides exactly the part somebody is checking.
 */
export function PushNotificationCard({ title, body }: { title: string; body: string | null }) {
    const { organization } = useTenantAuth();

    const today = new Date().toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return (
        <div className="push-device" aria-label="How it looks on a phone">
            <div className="push-screen">
                <span className="push-island" aria-hidden="true" />

                <div className="push-lock" aria-hidden="true">
                    <small>{today}</small>
                    <span>10:30</span>
                </div>

                <div className="push-card">
                    <div className="push-card-head">
                        <span className="push-app-icon" aria-hidden="true">
                            <i className="ti ti-heart-plus" />
                        </span>
                        <b>{organization?.name ?? 'Your clinic'}</b>
                        <small>now</small>
                    </div>

                    <b className="push-title">{title || 'Notification title'}</b>
                    <p className="push-body">{body || 'Write the message to see it here.'}</p>
                </div>

                <span className="push-home" aria-hidden="true" />
            </div>
        </div>
    );
}

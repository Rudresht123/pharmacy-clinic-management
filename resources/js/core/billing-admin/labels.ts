/** Names the screens use for the billing vocabulary. Mirrors App\Support\Subscriptions\Entitlements. */
export const LIMIT_KEYS = [
    'branches',
    'doctors',
    'employees',
    'whatsapp_messages',
    'sms_messages',
    'emails',
    'video_minutes',
    'video_sessions',
] as const;

export const RESOURCE_KEYS = ['branches', 'doctors', 'employees'] as const;

export const QUOTA_KEYS = ['whatsapp_messages', 'sms_messages', 'emails', 'video_minutes', 'video_sessions'] as const;

export const FEATURE_KEYS = ['video_consultation', 'mobile_app'] as const;

export const LABELS: Record<string, string> = {
    branches: 'Branches',
    doctors: 'Doctors',
    employees: 'Employees',
    whatsapp_messages: 'WhatsApp messages / month',
    sms_messages: 'SMS / month',
    emails: 'Emails / month',
    video_minutes: 'Video minutes / month',
    video_sessions: 'Video sessions / month',
    video_consultation: 'Video consultation',
    mobile_app: 'Mobile app',
};

export const STATUS_TONE: Record<string, string> = {
    trial: 'trial',
    active: 'ok',
    past_due: 'warn',
    grace: 'grace',
    expired: 'locked',
    suspended: 'locked',
    cancelled: 'locked',
    draft: 'muted',
    published: 'ok',
    unpublished: 'warn',
    archived: 'muted',
    issued: 'warn',
    paid: 'ok',
    void: 'muted',
};

export const EVENT_LABELS: Record<string, string> = {
    created: 'Subscription created',
    trial_started: 'Trial started',
    activated: 'Activated (paid)',
    renewed: 'Renewed',
    plan_changed: 'Plan changed',
    plan_change_scheduled: 'Plan change scheduled',
    extended: 'Extended',
    past_due: 'Payment overdue',
    grace_started: 'Grace period started',
    expired: 'Expired',
    locked: 'Locked',
    unlocked: 'Unlocked',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
    addon_added: 'Add-on added',
    addon_removed: 'Add-on removed',
    credits_granted: 'Credits granted',
    limit_overridden: 'Limit overridden',
    feature_granted: 'Feature granted',
    payment_received: 'Payment received',
    reminder_sent: 'Reminder sent',
};

/** Ten-thousandths of a rupee, for usage rates: 1150 → ₹0.1150. */
export function formatE4(e4: number): string {
    return `₹${(e4 / 10000).toFixed(4)}`;
}

/** "0.1150" → 1150, exactly. Null when it is not an amount. */
export function rupeesToE4(value: string): number | null {
    const text = value.replace(/[,\s₹]/g, '').trim();

    if (!/^\d+(\.\d{1,4})?$/.test(text)) {
        return null;
    }

    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * 10000 + Number(fraction.padEnd(4, '0'));
}

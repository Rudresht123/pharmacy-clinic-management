<?php

namespace App\Support\Subscriptions;

/**
 * The vocabulary a plan is written in: what can be limited, what is a monthly
 * allowance, and what can be switched on.
 *
 * Code, not data, for the same reason ModuleRegistry is code: each key here is
 * something the software COUNTS or CHECKS somewhere, and a key an administrator
 * invented would be a limit nothing enforces. Prices, amounts and which plan
 * grants what are data — all of that is the Super Admin's.
 */
final class Entitlements
{
    /*
    |--------------------------------------------------------------------------
    | Resources — counted live from the tenant database
    |--------------------------------------------------------------------------
    |
    | branches   active, not-removed `locations`
    | doctors    active, not-removed `doctors` — one row per doctor however
    |            many branches they sit at (`doctor_locations`)
    | employees  active, not-removed staff logins, not counting the owner,
    |            patients' portal logins, or a doctor's own login (that doctor
    |            is already counted once under `doctors`)
    */
    public const BRANCHES = 'branches';

    public const DOCTORS = 'doctors';

    public const EMPLOYEES = 'employees';

    public const RESOURCES = [self::BRANCHES, self::DOCTORS, self::EMPLOYEES];

    /*
    |--------------------------------------------------------------------------
    | Quotas — a monthly allowance, spent by usage
    |--------------------------------------------------------------------------
    */
    public const WHATSAPP_MESSAGES = 'whatsapp_messages';

    public const SMS_MESSAGES = 'sms_messages';

    public const EMAILS = 'emails';

    public const VIDEO_MINUTES = 'video_minutes';

    public const VIDEO_SESSIONS = 'video_sessions';

    public const QUOTAS = [
        self::WHATSAPP_MESSAGES,
        self::SMS_MESSAGES,
        self::EMAILS,
        self::VIDEO_MINUTES,
        self::VIDEO_SESSIONS,
    ];

    /*
    |--------------------------------------------------------------------------
    | Features — a switch, not an amount
    |--------------------------------------------------------------------------
    |
    | Video consultation and the mobile app are not modules: neither has
    | screens of its own in the registry's sense. Video is a way of holding an
    | appointment; the app is a way of reaching the same API. So they are sold
    | as features a plan (or an add-on) turns on.
    */
    public const VIDEO_CONSULTATION = 'video_consultation';

    public const MOBILE_APP = 'mobile_app';

    public const FEATURES = [self::VIDEO_CONSULTATION, self::MOBILE_APP];

    /** Which quota each usage channel spends. */
    public const CHANNEL_QUOTA = [
        'whatsapp' => self::WHATSAPP_MESSAGES,
        'sms' => self::SMS_MESSAGES,
        'email' => self::EMAILS,
        'video' => self::VIDEO_MINUTES,
    ];

    /** What the screens call each key. */
    public const LABELS = [
        self::BRANCHES => 'Branches',
        self::DOCTORS => 'Doctors',
        self::EMPLOYEES => 'Employees',
        self::WHATSAPP_MESSAGES => 'WhatsApp messages',
        self::SMS_MESSAGES => 'SMS messages',
        self::EMAILS => 'Emails',
        self::VIDEO_MINUTES => 'Video consultation minutes',
        self::VIDEO_SESSIONS => 'Video consultation sessions',
        self::VIDEO_CONSULTATION => 'Video consultation',
        self::MOBILE_APP => 'Mobile app',
    ];

    /** Unit names for the quota screens: "420 / 500 messages". */
    public const UNITS = [
        self::WHATSAPP_MESSAGES => 'messages',
        self::SMS_MESSAGES => 'messages',
        self::EMAILS => 'emails',
        self::VIDEO_MINUTES => 'minutes',
        self::VIDEO_SESSIONS => 'sessions',
    ];

    /** Every key a plan's limits table may hold. */
    public static function limitKeys(): array
    {
        return [...self::RESOURCES, ...self::QUOTAS];
    }

    public static function isResource(string $key): bool
    {
        return in_array($key, self::RESOURCES, true);
    }

    public static function isQuota(string $key): bool
    {
        return in_array($key, self::QUOTAS, true);
    }

    public static function isFeature(string $key): bool
    {
        return in_array($key, self::FEATURES, true);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}

<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\PlatformSetting;
use Illuminate\Validation\ValidationException;

/**
 * Every billing policy the platform runs on, set by Super Admin.
 *
 * Nothing here is hardcoded behaviour: the constants below are the values a
 * fresh install STARTS with, and the Billing Settings screen changes all of
 * them. Stored in `platform_settings` under one key, the same way the
 * WhatsApp and email provider screens store theirs — the readable half in
 * `value`, the gateway secrets encrypted in `secret`.
 */
class BillingSettings
{
    public const KEY = 'billing.settings';

    public const TAX_EXCLUSIVE = 'exclusive';

    public const TAX_INCLUSIVE = 'inclusive';

    public const TAX_NONE = 'none';

    public const TAX_MODES = [self::TAX_EXCLUSIVE, self::TAX_INCLUSIVE, self::TAX_NONE];

    /** Grace period: keep working with a warning, or read-only. */
    public const GRACE_FULL = 'full';

    public const GRACE_READ_ONLY = 'read_only';

    public const GRACE_ACCESS = [self::GRACE_FULL, self::GRACE_READ_ONLY];

    public const GATEWAYS = ['razorpay', 'manual'];

    /** What a fresh install starts with — every one of these is editable. */
    public const DEFAULTS = [
        'tax_mode' => self::TAX_EXCLUSIVE,
        'tax_rate_bp' => 1800,
        'tax_label' => 'GST',
        'grace_access' => self::GRACE_FULL,

        // Days before the term ends; 0 is "on the day it ends".
        'reminder_days' => [7, 3, 1, 0],

        // Days before the grace period ends.
        'grace_reminder_days' => [1],
        'reminder_channels' => ['email'],

        // How long before expiry the renewal invoice is issued.
        'renewal_invoice_days' => 7,

        // When an expiring-soon warning starts on the dashboard.
        'warning_days' => 7,

        'gateways' => ['razorpay', 'manual'],

        // The plan a new organization starts a trial on, when nobody picks
        // one. Null: new organizations are put on Complimentary until a Super
        // Admin assigns a plan, so nothing breaks before pricing is set up.
        'default_trial_plan_id' => null,

        'invoice_prefix' => 'ZV',

        // Invoice numbers run in a series per financial year; 4 = April, as
        // in India. The year in a number is the year the series began.
        'fiscal_year_start' => 4,

        // Where the platform's own invoice dates are written.
        'timezone' => 'Asia/Kolkata',
        'seller' => [
            'name' => null,
            'gstin' => null,
            'address' => null,
            'email' => null,
            'phone' => null,
        ],
    ];

    /**
     * The year an invoice series belongs to: the year the current financial
     * year began. With an April start, 15 February 2027 is in the 2026 series.
     */
    public function seriesYear(?\DateTimeInterface $at = null): int
    {
        $local = \Illuminate\Support\Carbon::instance($at ?? now())->setTimezone((string) $this->get('timezone'));
        $start = (int) $this->get('fiscal_year_start');

        return (int) $local->month >= $start ? (int) $local->year : (int) $local->year - 1;
    }

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $stored = (array) (PlatformSetting::forKey(self::KEY)->value ?? []);

        $settings = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $settings['seller'] = array_replace(self::DEFAULTS['seller'], (array) ($stored['seller'] ?? []));

        return $this->cached = $settings;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function taxMode(): string
    {
        return (string) $this->get('tax_mode');
    }

    /** Basis points: 1800 is 18%. Zero when tax is off. */
    public function taxRateBp(): int
    {
        return $this->taxMode() === self::TAX_NONE ? 0 : (int) $this->get('tax_rate_bp');
    }

    public function graceAccess(): string
    {
        return (string) $this->get('grace_access');
    }

    public function gatewayEnabled(string $gateway): bool
    {
        return in_array($gateway, (array) $this->get('gateways'), true);
    }

    /**
     * The Razorpay keys: the settings screen's encrypted values first, the
     * environment as a fallback for a deploy configured before the screen.
     *
     * @return array{key_id: ?string, key_secret: ?string, webhook_secret: ?string}
     */
    public function razorpay(): array
    {
        $secret = (array) (PlatformSetting::forKey(self::KEY)->secret ?? []);

        return [
            'key_id' => ($secret['razorpay_key_id'] ?? null) ?: config('services.razorpay.key_id'),
            'key_secret' => ($secret['razorpay_key_secret'] ?? null) ?: config('services.razorpay.key_secret'),
            'webhook_secret' => ($secret['razorpay_webhook_secret'] ?? null) ?: config('services.razorpay.webhook_secret'),
        ];
    }

    /**
     * Save what the screen sent, validated against the same rules the
     * defaults obey. Secrets are only replaced when a new one is typed, so a
     * screen that never shows a secret cannot erase it by saving.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string|null>  $secrets
     * @return array<string, mixed>
     */
    public function save(array $input, array $secrets = []): array
    {
        $current = $this->all();
        $next = array_replace($current, array_intersect_key($input, self::DEFAULTS));

        if (isset($input['seller'])) {
            $next['seller'] = array_replace($current['seller'], array_intersect_key((array) $input['seller'], self::DEFAULTS['seller']));
        }

        $this->validate($next);
        $next = $this->normalise($next);

        $setting = PlatformSetting::forKey(self::KEY);
        $setting->value = $next;

        $stored = (array) ($setting->secret ?? []);

        foreach (['razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret'] as $field) {
            if (filled($secrets[$field] ?? null)) {
                $stored[$field] = trim((string) $secrets[$field]);
            }
        }

        $setting->secret = $stored;
        $setting->save();

        $this->cached = null;

        return $this->all();
    }

    /** Which secrets are set — never their values. @return array<string, bool> */
    public function secretsPresent(): array
    {
        $keys = $this->razorpay();

        return [
            'razorpay_key_id' => filled($keys['key_id']),
            'razorpay_key_secret' => filled($keys['key_secret']),
            'razorpay_webhook_secret' => filled($keys['webhook_secret']),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function normalise(array $s): array
    {
        $days = fn ($list) => collect((array) $list)->map(fn ($d) => (int) $d)->unique()->sortDesc()->values()->all();

        $s['tax_rate_bp'] = (int) $s['tax_rate_bp'];
        $s['reminder_days'] = $days($s['reminder_days']);
        $s['grace_reminder_days'] = $days($s['grace_reminder_days']);
        $s['reminder_channels'] = array_values(array_unique((array) $s['reminder_channels']));
        $s['gateways'] = array_values(array_unique((array) $s['gateways']));
        $s['renewal_invoice_days'] = (int) $s['renewal_invoice_days'];
        $s['warning_days'] = (int) $s['warning_days'];
        $s['fiscal_year_start'] = (int) $s['fiscal_year_start'];
        $s['default_trial_plan_id'] = $s['default_trial_plan_id'] === null || $s['default_trial_plan_id'] === ''
            ? null
            : (int) $s['default_trial_plan_id'];

        return $s;
    }

    /** @param  array<string, mixed>  $s */
    private function validate(array $s): void
    {
        $errors = [];

        if (! in_array($s['tax_mode'], self::TAX_MODES, true)) {
            $errors['tax_mode'] = 'Choose exclusive, inclusive or none.';
        }

        if (! is_numeric($s['tax_rate_bp']) || (int) $s['tax_rate_bp'] < 0 || (int) $s['tax_rate_bp'] > 10000) {
            $errors['tax_rate_bp'] = 'The tax rate must be between 0% and 100%.';
        }

        if (! in_array($s['grace_access'], self::GRACE_ACCESS, true)) {
            $errors['grace_access'] = 'Choose full access or read-only.';
        }

        foreach (['reminder_days', 'grace_reminder_days'] as $field) {
            $days = (array) $s[$field];

            if (collect($days)->contains(fn ($d) => ! is_numeric($d) || (int) $d < 0 || (int) $d > 90)) {
                $errors[$field] = 'Reminder days must be between 0 and 90.';
            }
        }

        if (array_diff((array) $s['reminder_channels'], ['email', 'whatsapp']) !== []) {
            $errors['reminder_channels'] = 'Reminders go by email or WhatsApp.';
        }

        if (array_diff((array) $s['gateways'], self::GATEWAYS) !== []) {
            $errors['gateways'] = 'Unknown payment method.';
        }

        if ((int) $s['renewal_invoice_days'] < 0 || (int) $s['renewal_invoice_days'] > 60) {
            $errors['renewal_invoice_days'] = 'Issue the renewal invoice between 0 and 60 days ahead.';
        }

        if ((int) $s['warning_days'] < 0 || (int) $s['warning_days'] > 60) {
            $errors['warning_days'] = 'The warning window must be between 0 and 60 days.';
        }

        if ((int) $s['fiscal_year_start'] < 1 || (int) $s['fiscal_year_start'] > 12) {
            $errors['fiscal_year_start'] = 'Choose the month the financial year starts.';
        }

        if (! in_array((string) $s['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Choose a timezone.';
        }

        if (! preg_match('/^[A-Z0-9-]{1,10}$/', (string) $s['invoice_prefix'])) {
            $errors['invoice_prefix'] = 'Up to 10 capital letters, digits or dashes.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

<?php

namespace App\Support\Notifications;

/**
 * Every notification the software sends to a phone or the in-app bell, and
 * the blanks each one may use.
 *
 * CODE-AUTHORITATIVE, like ModuleRegistry and ClinicEvents: a notification
 * type exists because some code raises it at a known moment, and the
 * variables it offers are exactly the facts that code has to hand. A template
 * may reword a type at any level — global, organisation, branch — but cannot
 * invent a type, and cannot ask for a variable the type never fills. That is
 * what stops `{{patinet_name}}` (or `{{diagnosis}}`, on a message that knows
 * none) from ever reaching a patient: it is refused when somebody saves it.
 *
 * `title` and `body` are the CODE defaults: what is used when no level has a
 * template at all, and what the global templates are seeded with. They are
 * today's wording exactly, so introducing templates changes nothing anybody
 * sees until somebody edits one.
 *
 * KEYS ARE A PUBLIC CONTRACT, stored in master and tenant databases. Never
 * rename a value.
 */
final class NotificationTypes
{
    public const APPOINTMENT_CONFIRMED = 'appointment_confirmed';

    public const APPOINTMENT_CANCELLED = 'appointment_cancelled';

    /** Variables every appointment notification can fill. */
    private const APPOINTMENT_VARIABLES = [
        'patient_name' => 'Patient',
        'doctor_name' => 'Doctor',
        'appointment_date' => 'Appointment date',
        'appointment_time' => 'Appointment time',
        'clinic_name' => 'Clinic',
        'branch_name' => 'Branch',
    ];

    /**
     * @return array<string, array{
     *     label: string,
     *     audience: string,
     *     variables: array<string, string>,
     *     title: string,
     *     body: string,
     * }>
     */
    public static function all(): array
    {
        return [
            self::APPOINTMENT_CONFIRMED => [
                'label' => 'Appointment confirmed',
                'audience' => 'patient',
                'variables' => self::APPOINTMENT_VARIABLES,
                'title' => 'Appointment confirmed',
                'body' => 'Your appointment with {{doctor_name}} is confirmed for {{appointment_date}}.',
            ],
            self::APPOINTMENT_CANCELLED => [
                'label' => 'Appointment cancelled',
                'audience' => 'patient',
                'variables' => self::APPOINTMENT_VARIABLES,
                'title' => 'Appointment cancelled',
                'body' => 'Your appointment with {{doctor_name}} on {{appointment_date}} has been cancelled.',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> the variable names this type may use */
    public static function variables(string $key): array
    {
        return array_keys(self::find($key)['variables'] ?? []);
    }

    /**
     * Every `{{name}}` in a piece of text, in order, each once.
     *
     * @return list<string>
     */
    public static function tokensIn(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * The variables a text uses that this type cannot fill.
     *
     * @return list<string>
     */
    public static function unknownIn(string $key, string $text): array
    {
        return array_values(array_diff(self::tokensIn($text), self::variables($key)));
    }

    /**
     * Fill the blanks.
     *
     * A variable the type declares but the caller did not supply becomes empty
     * rather than showing braces — the template was validated on save, so a
     * declared variable with no value is a missing fact, not a typo, and a
     * patient should never see `{{doctor_name}}`.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            fn (array $match) => (string) ($values[$match[1]] ?? ''),
            $text,
        );
    }
}

<?php

namespace App\Support\Communication;

/**
 * The `{{variables}}` an automated or campaign message can be filled with.
 *
 * Code-authoritative: these are exactly the facts the senders hand over
 * (AutomationSender, CampaignDispatcher). A template naming anything else
 * would go out showing its braces — `Hi {{patinet_name}}` — so saving one is
 * refused, naming the variable, for the channels whose wording the clinic
 * writes itself (email and App notifications). WhatsApp's numbered
 * `{{1}}` placeholders are the provider's and are not checked here.
 */
final class MessageVariables
{
    /** @var array<string, string> name => what it becomes */
    public const ALL = [
        'patient_name' => 'Patient name',
        'doctor_name' => 'Doctor',
        'date' => 'Date',
        'time' => 'Time',
        'appointment_date' => 'Appointment date',
        'appointment_time' => 'Appointment time',
        'token_no' => 'Token number',
        'clinic_name' => 'Clinic name',
        'branch_name' => 'Branch',
        'amount' => 'Amount',
        'service' => 'Service',
        'invoice_number' => 'Invoice number',
        'payment_method' => 'Payment method',
        'phone' => 'Patient phone',
    ];

    /** @return list<string> every `{{name}}` in a text, in order, each once */
    public static function tokensIn(?string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', (string) $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return list<string> the names a text uses that nothing fills */
    public static function unknownIn(?string $text): array
    {
        return array_values(array_diff(self::tokensIn($text), array_keys(self::ALL)));
    }

    /**
     * Fill the blanks. A known variable with no value becomes empty rather
     * than braces — a patient should never read `{{doctor_name}}`.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function fill(?string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            fn (array $match) => array_key_exists($match[1], self::ALL) || array_key_exists($match[1], $values)
                ? (string) ($values[$match[1]] ?? '')
                : $match[0],
            (string) $text,
        );
    }
}

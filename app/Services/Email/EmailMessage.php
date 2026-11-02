<?php

namespace App\Services\Email;

/**
 * One email, described in the clinic's terms rather than a mailer's.
 *
 * Immutable, and named rather than positional: a constructor of six strings is
 * how a subject ends up in the body six months from now.
 *
 * `to` is the first addressee — the one the delivery log is filed under.
 * Anybody else on To rides in `alsoTo`; copies in `cc` and `bcc`, exactly as a
 * mail client means them (see App\Services\Communication\Recipients).
 */
class EmailMessage
{
    /**
     * @param  array<string, string|int|float|null>  $variables
     * @param  list<string>  $alsoTo
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    private function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $template = null,
        public readonly array $variables = [],
        public readonly array $alsoTo = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
    ) {}

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @param  list<string>  $alsoTo
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public static function make(
        string $to,
        string $subject,
        string $body,
        ?string $template = null,
        array $variables = [],
        array $alsoTo = [],
        array $cc = [],
        array $bcc = [],
    ): self {
        $clean = fn (array $addresses) => array_values(array_filter(array_map(
            fn ($address) => trim((string) $address),
            $addresses,
        ), fn (string $address) => $address !== ''));

        return new self(
            to: trim($to),
            subject: $subject,
            body: $body,
            template: $template,
            variables: $variables,
            alsoTo: $clean($alsoTo),
            cc: $clean($cc),
            bcc: $clean($bcc),
        );
    }

    /**
     * The body with its placeholders filled.
     *
     * An unknown placeholder is left exactly as written rather than blanked:
     * it is almost always a typo, and showing the braces is how somebody spots
     * that a patient received "Hi {{patientname}}".
     */
    public function rendered(): string
    {
        return $this->fill($this->body);
    }

    public function renderedSubject(): string
    {
        return $this->fill($this->subject);
    }

    private function fill(string $text): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $match) => (string) ($this->variables[$match[1]] ?? $match[0]),
            $text,
        ) ?? $text;
    }

    /**
     * What may be written to a delivery log.
     *
     * KEYS ONLY, never values. A delivery log is read by support staff, and
     * the values are patient names, amounts and appointment times.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        // How many were copied in, never who: the addresses are staff's and patients'.
        return array_filter([
            'template' => $this->template,
            'variable_keys' => array_keys($this->variables),
            'also_to' => count($this->alsoTo),
            'cc' => count($this->cc),
            'bcc' => count($this->bcc),
        ], fn ($value) => $value !== 0);
    }
}

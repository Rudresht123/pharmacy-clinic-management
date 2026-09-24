<?php

namespace App\Services\WhatsApp;

/**
 * One message, described in the application's own terms.
 *
 * This is the whole point of the abstraction. A business module says what it
 * wants sent — to whom, from which template, with which named values — and
 * never learns that Digiware calls those `field_1` and `field_2`, that Meta
 * calls them positional components, or that Twilio calls the template a
 * Content SID. Translating this object into a provider's wire format is the
 * provider adapter's only real job.
 *
 * Variables are NAMED, never positional. `['patient_name' => 'Amit']` survives
 * a template being rewritten to mention the doctor first; `['Amit', 'Dr Rahul']`
 * silently sends the doctor's name where the patient's belonged.
 */
final class WhatsAppMessage
{
    public const TEMPLATE = 'template';

    public const TEXT = 'text';

    public const MEDIA = 'media';

    /**
     * @param  array<string, string|int|float|null>  $variables  named, not positional
     * @param  array{type: string, url?: string, filename?: string, text?: string}|null  $header
     * @param  list<array{type: string, value: string}>  $buttons
     * @param  array{latitude: float|string, longitude: float|string, name?: string, address?: string}|null  $location
     */
    private function __construct(
        public readonly string $type,
        public readonly string $phone,
        public readonly ?string $template = null,
        public readonly array $variables = [],
        public readonly ?string $body = null,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $caption = null,
        public readonly ?array $header = null,
        public readonly array $buttons = [],
        public readonly ?array $location = null,
        public readonly ?string $copyCode = null,
        public readonly string $language = 'en',
        /** Overrides the account's default sender, where a provider supports it. */
        public readonly ?string $fromPhoneNumberId = null,

        /*
         * Filled in by the manager, from the template mapping, before the
         * message reaches an adapter.
         *
         * They live here rather than as extra arguments on the interface so
         * every provider takes the same one parameter. The caller never sets
         * them — it names a logical template and nothing else.
         */

        /** What THIS provider calls the template. */
        public readonly ?string $providerTemplate = null,

        /**
         * The variable names in the order the template declares them.
         *
         * Digiware and Meta are both positional underneath; this is what lets
         * a caller pass named variables in any order and still produce the
         * right message.
         *
         * @var list<string>
         */
        public readonly array $variableOrder = [],
    ) {}

    /**
     * The same message, told which template and order the provider needs.
     *
     * @param  list<string>  $order
     */
    public function resolvedFor(string $providerTemplate, array $order): self
    {
        return new self(
            type: $this->type,
            phone: $this->phone,
            template: $this->template,
            variables: $this->variables,
            body: $this->body,
            mediaUrl: $this->mediaUrl,
            caption: $this->caption,
            header: $this->header,
            buttons: $this->buttons,
            location: $this->location,
            copyCode: $this->copyCode,
            language: $this->language,
            fromPhoneNumberId: $this->fromPhoneNumberId,
            providerTemplate: $providerTemplate,
            variableOrder: $order,
        );
    }

    /**
     * The order an adapter should read variables in.
     *
     * Falls back to the order they were written, which is right for a clinic
     * that has not declared one and wrong in no worse a way than having no
     * mapping at all.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->variableOrder !== [] ? $this->variableOrder : array_keys($this->variables);
    }

    /** What the provider should ask for, falling back to the logical name. */
    public function templateName(): string
    {
        return $this->providerTemplate ?? (string) $this->template;
    }

    /**
     * The common case: an approved template with named variables.
     *
     * @param  array<string, string|int|float|null>  $variables
     * @param  array{type: string, url?: string, filename?: string, text?: string}|null  $header
     * @param  list<array{type: string, value: string}>  $buttons
     * @param  array{latitude: float|string, longitude: float|string, name?: string, address?: string}|null  $location
     */
    public static function template(
        string $phone,
        string $template,
        array $variables = [],
        ?array $header = null,
        array $buttons = [],
        ?array $location = null,
        ?string $copyCode = null,
        string $language = 'en',
        ?string $fromPhoneNumberId = null,
    ): self {
        return new self(
            type: self::TEMPLATE,
            phone: $phone,
            template: $template,
            variables: $variables,
            header: $header,
            buttons: $buttons,
            location: $location,
            copyCode: $copyCode,
            language: $language,
            fromPhoneNumberId: $fromPhoneNumberId,
        );
    }

    /**
     * Free text.
     *
     * Only legal inside an open conversation window — WhatsApp refuses a
     * business-initiated message that is not a template — so this is for
     * replying to somebody who wrote first, not for notifications.
     */
    public static function text(string $phone, string $body, ?string $fromPhoneNumberId = null): self
    {
        return new self(
            type: self::TEXT,
            phone: $phone,
            body: $body,
            fromPhoneNumberId: $fromPhoneNumberId,
        );
    }

    public static function media(
        string $phone,
        string $mediaUrl,
        ?string $caption = null,
        ?string $fromPhoneNumberId = null,
    ): self {
        return new self(
            type: self::MEDIA,
            phone: $phone,
            mediaUrl: $mediaUrl,
            caption: $caption,
            fromPhoneNumberId: $fromPhoneNumberId,
        );
    }

    /**
     * The number as providers want it: digits only, no `+`, no leading zero.
     *
     * Done once here rather than in every adapter, because every provider
     * wants the same thing and getting it wrong fails silently — the message
     * is accepted and delivered to nobody.
     */
    public function normalizedPhone(): string
    {
        $digits = preg_replace('/\D+/', '', $this->phone) ?? '';

        return ltrim($digits, '0');
    }

    /**
     * What may be written to a log.
     *
     * The recipient and the template, never the variables: those carry patient
     * names, amounts and appointment times, and a delivery log read by support
     * staff has no business holding them.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'type' => $this->type,
            'phone' => $this->normalizedPhone(),
            'template' => $this->template,
            'variable_keys' => array_keys($this->variables),
        ];
    }
}

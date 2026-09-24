<?php

namespace App\Services\WhatsApp\Providers;

use App\Services\WhatsApp\WhatsAppMessage;

/**
 * Turns an application message into Digiware's wire format.
 *
 * Every Digiware-shaped fact in the codebase lives here: that variables are
 * `field_1`..`field_5`, that the header image has its own key, that buttons
 * are `button_0` and `button_1`, that a location is four flat keys. Nothing
 * above the adapter knows any of it, which is the entire point — swapping to
 * Meta replaces this class and touches nothing else.
 *
 * Separate from the provider itself so the mapping can be tested without an
 * HTTP client, and read without wading through error handling.
 */
class DigiwarePayloadMapper
{
    /** Digiware accepts five body variables and no more. */
    public const MAX_FIELDS = 5;

    /** And two buttons. */
    public const MAX_BUTTONS = 2;

    /**
     * The provider's payload for a template send.
     *
     * @param  list<string>  $order  the variable names, in the order the
     *                               template declares them
     * @return array<string, string>
     */
    public function template(WhatsAppMessage $message, string $templateName, array $order): array
    {
        $payload = [
            'phone_number' => $message->normalizedPhone(),
            'template_name' => $templateName,
            'template_language' => $message->language,
        ];

        if ($message->fromPhoneNumberId !== null) {
            $payload['from_phone_number_id'] = $message->fromPhoneNumberId;
        }

        return $payload
            + $this->fields($message->variables, $order)
            + $this->header($message->header)
            + $this->buttons($message->buttons)
            + $this->location($message->location)
            + ($message->copyCode !== null ? ['copy_code' => $message->copyCode] : []);
    }

    /**
     * Named variables, flattened into Digiware's positional fields.
     *
     * The ORDER comes from the template mapping, not from the order the caller
     * happened to write the array in. That is the whole reason variables are
     * named upstream: a caller passing patient before doctor and another
     * passing doctor before patient must produce the same message.
     *
     * A variable the template does not declare is dropped rather than appended.
     * Appending would shift every later field by one and send the appointment
     * time where the doctor's name belonged — silently, and to a patient.
     *
     * @param  array<string, string|int|float|null>  $variables
     * @param  list<string>  $order
     * @return array<string, string>
     */
    public function fields(array $variables, array $order): array
    {
        $fields = [];
        $position = 1;

        foreach ($order as $name) {
            if ($position > self::MAX_FIELDS) {
                break;
            }

            // A declared variable with nothing supplied still takes its slot,
            // as an empty string — skipping it would shift the rest.
            $fields['field_'.$position] = (string) ($variables[$name] ?? '');
            $position++;
        }

        return $fields;
    }

    /**
     * @param  array{type: string, url?: string, filename?: string, text?: string}|null  $header
     * @return array<string, string>
     */
    public function header(?array $header): array
    {
        if ($header === null) {
            return [];
        }

        return match ($header['type'] ?? null) {
            'image' => ['header_image' => (string) ($header['url'] ?? '')],
            'video' => ['header_video' => (string) ($header['url'] ?? '')],
            'document' => array_filter([
                'header_document' => (string) ($header['url'] ?? ''),
                'header_document_name' => (string) ($header['filename'] ?? ''),
            ], static fn (string $value) => $value !== ''),
            'text' => ['header_field_1' => (string) ($header['text'] ?? '')],
            default => [],
        };
    }

    /**
     * @param  list<array{type: string, value: string}>  $buttons
     * @return array<string, string>
     */
    public function buttons(array $buttons): array
    {
        $mapped = [];

        foreach (array_slice($buttons, 0, self::MAX_BUTTONS) as $index => $button) {
            $mapped['button_'.$index] = (string) ($button['value'] ?? '');
        }

        return $mapped;
    }

    /**
     * @param  array{latitude: float|string, longitude: float|string, name?: string, address?: string}|null  $location
     * @return array<string, string>
     */
    public function location(?array $location): array
    {
        if ($location === null) {
            return [];
        }

        return array_filter([
            'location_latitude' => (string) ($location['latitude'] ?? ''),
            'location_longitude' => (string) ($location['longitude'] ?? ''),
            'location_name' => (string) ($location['name'] ?? ''),
            'location_address' => (string) ($location['address'] ?? ''),
        ], static fn (string $value) => $value !== '');
    }
}

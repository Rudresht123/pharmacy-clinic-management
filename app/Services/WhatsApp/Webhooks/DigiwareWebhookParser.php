<?php

namespace App\Services\WhatsApp\Webhooks;

/**
 * Reads Digiware's webhook payloads.
 *
 * The other half of the adapter: everything Digiware-shaped about INCOMING
 * events stops here, exactly as the payload mapper stops everything outgoing.
 *
 * Written defensively throughout. A webhook body is somebody else's data
 * arriving unannounced — a parser that assumes a shape and throws when it
 * differs turns a provider's change into 500s and lost receipts.
 */
class DigiwareWebhookParser implements WhatsAppWebhookParser
{
    public function provider(): string
    {
        return 'digiware';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<NormalizedWhatsAppEvent>
     */
    public function parse(array $payload): array
    {
        // A batch or a single event; both are seen in the wild.
        $entries = $this->isList($payload['data'] ?? null)
            ? $payload['data']
            : [$payload['data'] ?? $payload];

        $events = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $event = $this->one($entry);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function one(array $entry): ?NormalizedWhatsAppEvent
    {
        $type = $this->type((string) ($entry['status'] ?? $entry['event'] ?? ''));

        if ($type === null) {
            return null;
        }

        return new NormalizedWhatsAppEvent(
            type: $type,
            provider: $this->provider(),
            providerMessageId: $this->first($entry, ['message_id', 'messageId', 'wam_id', 'id']),
            phone: $this->first($entry, ['phone_number', 'phone', 'recipient']),
            errorCode: $this->first($entry, ['error_code', 'code']),
            errorMessage: $this->first($entry, ['error_message', 'error', 'message']),
            occurredAt: $this->first($entry, ['timestamp', 'occurred_at', 'created_at']),
        );
    }

    /**
     * Digiware's words for what happened, in ours.
     *
     * Unknown words map to null and the event is dropped rather than guessed
     * at — inventing a status from a word we do not recognise would move a
     * message somewhere it has not been.
     */
    private function type(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'sent', 'accepted' => NormalizedWhatsAppEvent::SENT,
            'delivered' => NormalizedWhatsAppEvent::DELIVERED,
            'read', 'seen' => NormalizedWhatsAppEvent::READ,
            'failed', 'error', 'undelivered' => NormalizedWhatsAppEvent::FAILED,
            'received', 'inbound' => NormalizedWhatsAppEvent::RECEIVED,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $keys
     */
    private function first(array $entry, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key]) && (string) $entry[$key] !== '') {
                return (string) $entry[$key];
            }
        }

        return null;
    }

    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [];
    }
}

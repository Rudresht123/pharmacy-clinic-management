<?php

namespace App\Services\WhatsApp\Webhooks;

use App\Models\Tenant\MessageLog;

/**
 * Something a provider told us happened, in the application's own terms.
 *
 * Every provider posts a different shape — Meta nests statuses inside entries
 * inside changes, Digiware posts something flatter — and each one gets a
 * parser that produces these. Everything downstream handles one kind of event,
 * so adding a provider never touches the code that records deliveries.
 */
final class NormalizedWhatsAppEvent
{
    public const SENT = 'message.sent';

    public const DELIVERED = 'message.delivered';

    public const READ = 'message.read';

    public const FAILED = 'message.failed';

    /** A patient wrote to the clinic. Nothing consumes this yet. */
    public const RECEIVED = 'message.received';

    public function __construct(
        public readonly string $type,
        public readonly string $provider,
        /** The provider's id for the message, which is how the row is found. */
        public readonly ?string $providerMessageId = null,
        public readonly ?string $phone = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $occurredAt = null,
    ) {}

    /**
     * The delivery status this event moves a message to.
     *
     * Null for events that are not about an outgoing message's progress — an
     * inbound message has no status to advance.
     */
    public function status(): ?string
    {
        return match ($this->type) {
            self::SENT => MessageLog::SENT,
            self::DELIVERED => MessageLog::DELIVERED,
            self::READ => MessageLog::READ,
            self::FAILED => MessageLog::FAILED,
            default => null,
        };
    }
}

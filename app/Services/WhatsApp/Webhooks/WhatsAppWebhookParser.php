<?php

namespace App\Services\WhatsApp\Webhooks;

/**
 * Reads one provider's webhook payloads into events the application knows.
 *
 * The incoming counterpart of WhatsAppProviderInterface. A provider is fully
 * described by two classes — one that speaks to it, one that listens — and
 * neither is visible above this namespace.
 */
interface WhatsAppWebhookParser
{
    /** The provider key these payloads belong to. */
    public function provider(): string;

    /**
     * @param  array<string, mixed>  $payload  the raw body, untrusted
     * @return list<NormalizedWhatsAppEvent>   empty when nothing is recognised
     */
    public function parse(array $payload): array;
}

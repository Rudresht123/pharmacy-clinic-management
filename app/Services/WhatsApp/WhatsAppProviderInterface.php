<?php

namespace App\Services\WhatsApp;

/**
 * What the application needs a WhatsApp provider to be able to do.
 *
 * Written in the application's terms, not any provider's. Every method takes a
 * WhatsAppMessage and answers with a WhatsAppResponse — an adapter's job is the
 * translation in between, and nothing above this line ever sees a `field_1`, a
 * vendor UID or a Content SID.
 *
 * Deliberately narrow. Four capabilities is what the clinic actually uses;
 * a wider interface would be one every future adapter has to implement, and
 * most of it would be stubs that throw.
 */
interface WhatsAppProviderInterface
{
    /** The key this provider is configured under: `digiware`, `meta`, `log`. */
    public function key(): string;

    /**
     * A pre-approved template with named variables.
     *
     * The one that matters: WhatsApp will not carry a business-initiated
     * message any other way, so every notification the clinic sends is this.
     */
    public function sendTemplate(WhatsAppMessage $message): WhatsAppResponse;

    /**
     * Free text, legal only inside an open conversation window.
     *
     * A provider that cannot do this should say so in its response rather than
     * throwing — "this channel cannot reply in free text" is an answer the
     * caller can log, and an exception is not.
     */
    public function sendText(WhatsAppMessage $message): WhatsAppResponse;

    public function sendMedia(WhatsAppMessage $message): WhatsAppResponse;

    /**
     * Whether the configured credentials actually work.
     *
     * Must not send anything to a patient. The settings screen calls this, and
     * a "test" that messages somebody real is not a test.
     */
    public function testConnection(): WhatsAppResponse;
}

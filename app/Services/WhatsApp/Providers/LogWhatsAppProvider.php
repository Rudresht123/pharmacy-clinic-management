<?php

namespace App\Services\WhatsApp\Providers;

use App\Services\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use App\Services\WhatsApp\WhatsAppResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes the message to the log instead of sending it.
 *
 * The default, and the same bargain LogOtpSender strikes: development and
 * staging read messages out of storage/logs, and production refuses outright.
 * A clinic whose provider is misconfigured must find out loudly, not discover
 * in a month that no patient has been reminded of anything.
 *
 * The variables are NOT logged — they carry patient names and appointment
 * times, and a log file is the wrong place for both.
 */
class LogWhatsAppProvider implements WhatsAppProviderInterface
{
    public function key(): string
    {
        return 'log';
    }

    public function sendTemplate(WhatsAppMessage $message): WhatsAppResponse
    {
        return $this->record('template', $message);
    }

    public function sendText(WhatsAppMessage $message): WhatsAppResponse
    {
        return $this->record('text', $message);
    }

    public function sendMedia(WhatsAppMessage $message): WhatsAppResponse
    {
        return $this->record('media', $message);
    }

    public function testConnection(): WhatsAppResponse
    {
        if (app()->isProduction()) {
            return WhatsAppResponse::failed(
                $this->key(),
                'No WhatsApp provider is configured (WHATSAPP_PROVIDER=log).',
                'config',
            );
        }

        return WhatsAppResponse::ok(
            $this->key(),
            'Messages are written to the application log; nothing is sent.',
        );
    }

    private function record(string $type, WhatsAppMessage $message): WhatsAppResponse
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'No WhatsApp provider is configured (WHATSAPP_PROVIDER=log).'
            );
        }

        Log::info('[whatsapp] would send', $message->toLogContext());

        // A believable id, so anything that stores or matches one has
        // something of the right shape to work with in development.
        return WhatsAppResponse::sent(
            $this->key(),
            'log-'.bin2hex(random_bytes(8)),
            'Written to the log; nothing was sent.',
        );
    }
}

<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * Writes the push to the log instead of sending it.
 *
 * The default, the same bargain LogWhatsAppProvider strikes: development and
 * staging read pushes out of storage/logs. The wording is NOT logged — it can
 * carry a patient's name and their appointment — only the kind of message and
 * how many devices it was for.
 *
 * Unlike the WhatsApp log provider this does not throw in production. A push
 * is never the only way somebody is told something (the in-app notification
 * is written regardless), and a queued job failing over and over for want of
 * configuration helps nobody. It reports nothing as sent, and says so.
 */
class LogPushSender implements PushSender
{
    public function send(PushMessage $message, array $tokens): PushResult
    {
        if (app()->isProduction()) {
            Log::warning('[push] not sent: no push driver is configured (PUSH_DRIVER=log)', [
                'devices' => count($tokens),
            ] + $message->forLog());

            return new PushResult(failed: $tokens);
        }

        Log::info('[push] would send', ['devices' => count($tokens)] + $message->forLog());

        return new PushResult(sent: $tokens);
    }
}

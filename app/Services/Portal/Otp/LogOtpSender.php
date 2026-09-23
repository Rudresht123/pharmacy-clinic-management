<?php

namespace App\Services\Portal\Otp;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes the code to the application log instead of sending it.
 *
 * For development and staging, where a developer reads the code from
 * storage/logs rather than a phone. Refuses to run in production: a sign-in
 * code in a log file there is a credential in a log file.
 */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code, string $clinicName): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('No SMS provider is configured for sign-in codes (OTP_DRIVER=log).');
        }

        Log::info("[patient sign-in] {$clinicName} code for {$phone}: {$code}");
    }
}

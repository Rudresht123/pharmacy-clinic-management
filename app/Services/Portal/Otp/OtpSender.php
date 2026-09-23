<?php

namespace App\Services\Portal\Otp;

/**
 * Delivers a one-time sign-in code to a phone.
 *
 * An interface because the carrier is a business decision that has not been
 * made — MSG91, Twilio, WhatsApp — and the sign-in rules must not change when
 * it is. Bound in AppServiceProvider from `services.otp.driver`.
 */
interface OtpSender
{
    public function send(string $phone, string $code, string $clinicName): void;
}

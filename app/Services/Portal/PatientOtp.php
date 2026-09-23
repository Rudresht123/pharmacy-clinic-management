<?php

namespace App\Services\Portal;

use App\Models\Tenant\PatientLoginCode;
use App\Services\Portal\Otp\OtpSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * One-time sign-in codes for patients: issued, checked, spent.
 *
 * The rules that make a six-digit code safe to sign in with all live here:
 *
 *   it expires after TTL_MINUTES;
 *   it is spent after MAX_ATTEMPTS wrong guesses, so it cannot be walked;
 *   a new one cannot be asked for within RESEND_SECONDS of the last, so the
 *   endpoint cannot be used to flood somebody's phone;
 *   asking for a new one retires any still outstanding, so only the latest
 *   code on a patient's screen works.
 *
 * Route throttles sit underneath these as a per-IP floor.
 */
class PatientOtp
{
    public const LENGTH = 6;

    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 30;

    public function __construct(
        private readonly OtpSender $sender,
    ) {}

    /**
     * Issue a code and send it.
     *
     * @return array{resend_in: int, expires_in: int}
     *
     * @throws ValidationException when the last code was asked for too recently
     */
    public function send(string $phone, string $clinicName, ?string $ip): array
    {
        $last = PatientLoginCode::query()->where('phone', $phone)->latest('id')->first();

        if ($last && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            $wait = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());

            throw ValidationException::withMessages([
                'phone' => 'Please wait '.max(1, $wait).' seconds before asking for another code.',
            ]);
        }

        PatientLoginCode::query()
            ->where('phone', $phone)
            ->outstanding()
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        PatientLoginCode::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'requested_ip' => $ip,
        ]);

        $this->sender->send($phone, $code, $clinicName);

        return [
            'resend_in' => self::RESEND_SECONDS,
            'expires_in' => self::TTL_MINUTES * 60,
        ];
    }

    /**
     * The outstanding code this matches, NOT yet spent.
     *
     * Checking and spending are separate so a sign-in that still needs the
     * patient's name can ask for it without making them wait for a new code.
     * A wrong guess counts against the code either way.
     *
     * @throws ValidationException when there is no live code, or this is not it
     */
    public function check(string $phone, string $code): PatientLoginCode
    {
        $login = PatientLoginCode::query()
            ->where('phone', $phone)
            ->outstanding()
            ->latest('id')
            ->first();

        if (! $login || $login->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => 'That code has expired. Ask for a new one.',
            ]);
        }

        if (! Hash::check($code, $login->code_hash)) {
            $login->increment('attempts');

            $left = self::MAX_ATTEMPTS - $login->attempts;

            throw ValidationException::withMessages([
                'code' => $left > 0
                    ? "That code isn't right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                    : 'That code has expired. Ask for a new one.',
            ]);
        }

        return $login;
    }

    public function spend(PatientLoginCode $login): void
    {
        $login->update(['consumed_at' => now()]);
    }
}

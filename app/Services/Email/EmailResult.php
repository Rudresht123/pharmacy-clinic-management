<?php

namespace App\Services\Email;

use App\Models\Tenant\MessageLog;

/**
 * What happened, in the log's own vocabulary.
 *
 * `retryable` is the distinction that matters and the only one the job reads:
 * a relay that is down deserves another attempt, a rejected password does not
 * and retrying it three times only delays somebody finding out.
 */
class EmailResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
    ) {}

    public static function sent(string $message = 'Message accepted by the mail server'): self
    {
        return new self(true, MessageLog::SENT, $message);
    }

    /** A connection test, which has no message and so cannot be "sent". */
    public static function ok(string $message): self
    {
        return new self(true, MessageLog::SENT, $message);
    }

    public static function failed(string $message, ?string $errorCode = null): self
    {
        return new self(false, MessageLog::FAILED, $message, $errorCode, retryable: false);
    }

    public static function transient(string $message): self
    {
        return new self(false, MessageLog::FAILED, $message, 'transport', retryable: true);
    }
}

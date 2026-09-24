<?php

namespace App\Services\WhatsApp;

use App\Models\Tenant\MessageLog;
use Throwable;

/**
 * What a provider answered, in the application's own terms.
 *
 * Every adapter returns one of these, whatever shape its API replied in. That
 * is what lets the manager write one log row, the job decide one retry policy,
 * and the settings screen show one result — without any of them knowing which
 * provider ran.
 *
 * `retryable` is the field that earns its place. "The template does not exist"
 * and "the connection timed out" are both failures, but retrying the first one
 * three times just fails three times; only the adapter knows which it got.
 */
final class WhatsAppResponse
{
    /**
     * @param  array<string, mixed>  $raw  redacted before it ever reaches here
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly array $raw = [],
        public readonly bool $retryable = false,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function sent(
        string $provider,
        ?string $providerMessageId = null,
        string $message = 'Message sent',
        array $raw = [],
    ): self {
        return new self(
            success: true,
            provider: $provider,
            status: MessageLog::SENT,
            message: $message,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * A refusal the provider will repeat — a bad template, an unopted-in
     * number, a malformed request. Not worth a second attempt.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failed(
        string $provider,
        string $message,
        ?string $errorCode = null,
        array $raw = [],
        bool $retryable = false,
    ): self {
        return new self(
            success: false,
            provider: $provider,
            status: MessageLog::FAILED,
            message: $message,
            errorCode: $errorCode,
            raw: $raw,
            retryable: $retryable,
        );
    }

    /**
     * A fault that may not happen again — a timeout, a 5xx, a dropped
     * connection. Worth the queue trying once more.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function transientFailure(
        string $provider,
        string $message,
        ?string $errorCode = null,
        array $raw = [],
    ): self {
        return self::failed($provider, $message, $errorCode, $raw, retryable: true);
    }

    /** A connection test that worked. Carries no message id, because none was sent. */
    public static function ok(string $provider, string $message): self
    {
        return new self(
            success: true,
            provider: $provider,
            status: MessageLog::SENT,
            message: $message,
        );
    }

    /**
     * An exception, turned into an answer.
     *
     * The exception's own message is kept for the server-side log but never
     * becomes the user-facing one — a stack trace or a raw cURL error is not
     * something to show somebody testing their settings.
     */
    public static function fromException(string $provider, Throwable $exception, string $message): self
    {
        return new self(
            success: false,
            provider: $provider,
            status: MessageLog::FAILED,
            message: $message,
            errorCode: (string) $exception->getCode(),
            raw: ['exception' => $exception::class, 'detail' => $exception->getMessage()],
            retryable: true,
        );
    }

    /**
     * Shaped for an API response.
     *
     * Deliberately omits `raw`: it is for the log and the developer, never for
     * the browser, which is how a provider's own error text leaks out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'success' => $this->success,
            'provider' => $this->provider,
            'status' => $this->status,
            'message' => $this->message,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
        ], static fn ($value) => $value !== null);
    }
}

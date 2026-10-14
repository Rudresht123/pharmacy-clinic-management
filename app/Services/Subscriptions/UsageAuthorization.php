<?php

namespace App\Services\Subscriptions;

/**
 * UsageMeter's answer to "may this go out?" — and, when it may, how much of
 * it the month's allowance covers and how much will be charged as overage.
 */
final class UsageAuthorization
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly int $fromQuota,
        public readonly int $overage,
    ) {}

    public static function allow(int $fromQuota, int $overage): self
    {
        return new self(true, null, null, $fromQuota, $overage);
    }

    /** @param  string  $reason  locked | feature | quota_exhausted */
    public static function deny(string $reason, string $message): self
    {
        return new self(false, $reason, $message, 0, 0);
    }
}

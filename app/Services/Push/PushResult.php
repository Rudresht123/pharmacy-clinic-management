<?php

namespace App\Services\Push;

/**
 * What happened to each token, sorted by what the caller should do about it.
 *
 *   sent      delivered to FCM; nothing to do
 *   invalid   the device is gone (uninstalled, signed out of Firebase, or the
 *             token belongs to another project) — stop sending to it
 *   retry     FCM was busy or down — try these again later
 *   failed    refused for some other reason; logged, not retried, and the
 *             device is NOT switched off, because a malformed message would
 *             otherwise switch off every device it was sent to
 */
final class PushResult
{
    /**
     * @param  list<string>  $sent
     * @param  list<string>  $invalid
     * @param  list<string>  $retry
     * @param  list<string>  $failed
     */
    public function __construct(
        public readonly array $sent = [],
        public readonly array $invalid = [],
        public readonly array $retry = [],
        public readonly array $failed = [],
    ) {}
}

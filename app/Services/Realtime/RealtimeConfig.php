<?php

namespace App\Services\Realtime;

/**
 * How a browser reaches Reverb: the public half of the connection.
 *
 * One place, because a signed-in session and a television are told the same
 * thing. Disabled whenever broadcasting is not Reverb or has no key — a client
 * told so falls back to re-fetching, rather than opening a socket to nowhere and
 * showing a screen that never updates. The secret never leaves the server.
 */
final class RealtimeConfig
{
    /** @return array<string, mixed> */
    public static function forClient(): array
    {
        $connection = (array) config('broadcasting.connections.reverb', []);

        if (config('broadcasting.default') !== 'reverb' || blank($connection['key'] ?? null)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'driver' => 'reverb',
            'key' => $connection['key'],
            'host' => $connection['client']['host'] ?? null,
            'port' => $connection['client']['port'] ?? null,
            'scheme' => $connection['client']['scheme'] ?? 'https',
            'auth_endpoint' => '/api/v1/broadcasting/auth',
        ];
    }

    /**
     * Reverb's signature on a private channel, the Pusher protocol's own.
     *
     * Written out for the televisions, which have no login for the framework's
     * channel routes to find. The caller has already decided this channel is
     * theirs to hear.
     */
    public static function sign(string $socketId, string $channel): string
    {
        $connection = (array) config('broadcasting.connections.reverb', []);

        $signature = hash_hmac('sha256', "{$socketId}:{$channel}", (string) ($connection['secret'] ?? ''));

        return ($connection['key'] ?? '').':'.$signature;
    }
}

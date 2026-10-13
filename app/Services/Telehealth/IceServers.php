<?php

namespace App\Services\Telehealth;

/**
 * The STUN and TURN servers a phone may use to reach the other end of a call,
 * in the shape WebRTC's `RTCConfiguration.iceServers` takes.
 *
 * Handed out per request rather than baked into the app, so TURN can be added,
 * moved or re-keyed without shipping a new build — and so derived TURN
 * credentials can expire, which a value compiled into an APK cannot.
 */
class IceServers
{
    /**
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function for(int $userId): array
    {
        $servers = [];

        $stun = (array) config('webrtc.stun', []);

        if ($stun !== []) {
            $servers[] = ['urls' => array_values($stun)];
        }

        $turn = (array) config('webrtc.turn', []);
        $urls = array_values((array) ($turn['urls'] ?? []));

        if ($urls === []) {
            return $servers;
        }

        if (filled($turn['secret'] ?? null)) {
            /*
             * coturn's REST-API scheme: the username is "expiry:who", and the
             * password is that username signed with the shared secret. coturn
             * checks the signature and the expiry itself, so nothing has to be
             * stored or revoked here.
             */
            $username = (time() + max(60, (int) ($turn['ttl'] ?? 14400))).':'.$userId;

            $servers[] = [
                'urls' => $urls,
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, (string) $turn['secret'], true)),
            ];
        } elseif (filled($turn['username'] ?? null)) {
            $servers[] = [
                'urls' => $urls,
                'username' => (string) $turn['username'],
                'credential' => (string) ($turn['credential'] ?? ''),
            ];
        }

        return $servers;
    }
}

<?php

/*
|--------------------------------------------------------------------------
| Video consultations — how two phones find each other
|--------------------------------------------------------------------------
|
| Calls are peer-to-peer WebRTC: the audio and video go straight from the
| doctor's phone to the patient's, never through this server. What the phones
| need from us is a way to discover a route between them:
|
|   STUN  tells a phone its own public address. Enough when at least one
|         side is on an ordinary home or office connection. Free public
|         servers are fine.
|
|   TURN  relays the media when no direct route exists — common on mobile
|         data, where carriers put many phones behind one address. Without
|         one, a share of calls connect on the signalling and then show no
|         video. Run coturn (or buy a hosted TURN service) for production.
|
| TURN credentials are either a shared secret (coturn `use-auth-secret` /
| `static-auth-secret`), from which short-lived per-call credentials are
| derived — the recommended way, since nothing long-lived reaches a phone —
| or a fixed username and password for a hosted service that issues those.
|
*/

$list = fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    'stun' => $list(env('WEBRTC_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302')),

    'turn' => [
        'urls' => $list(env('WEBRTC_TURN_URLS')),
        'secret' => env('WEBRTC_TURN_SECRET'),
        'username' => env('WEBRTC_TURN_USERNAME'),
        'credential' => env('WEBRTC_TURN_CREDENTIAL'),

        // How long derived credentials stay valid. Longer than any one call.
        'ttl' => (int) env('WEBRTC_TURN_TTL', 4 * 60 * 60),
    ],

];

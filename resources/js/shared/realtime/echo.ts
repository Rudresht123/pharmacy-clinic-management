import { useEffect, useState } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { http } from '@/shared/api/http';
import type { RealtimeConfig } from '@/core/tenant-auth/api';

/*
 * The one Reverb connection the workspace holds.
 *
 * Laravel decides everything about realtime: whether it runs (the session's
 * `realtime` block), which channel a screen joins (the endpoint that loads the
 * screen names it), and whether this person may join it (the authorizer below
 * asks /api/v1/broadcasting/auth, which re-checks organization, branch,
 * feature and capability). Reverb only carries the message.
 *
 * Authorized through the app's own `http` client, not Echo's built-in one, so
 * the request carries exactly what every other request does — the session
 * cookie, the CSRF token and the acting branch — instead of a second, hand-
 * configured copy that drifts the first time one of them changes.
 */

let client: Echo<'reverb'> | null = null;
let signature: string | null = null;

/**
 * The connection for this session's settings, or null when Reverb is not
 * running — the caller then falls back to re-fetching.
 */
export function realtimeClient(config: RealtimeConfig | null | undefined): Echo<'reverb'> | null {
    if (!config?.enabled || !config.key) {
        return null;
    }

    const wanted = JSON.stringify(config);

    if (client && signature === wanted) {
        return client;
    }

    client?.disconnect();

    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    const tls = config.scheme === 'https';
    const port = config.port ?? (tls ? 443 : 80);

    client = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: { name: string }) => ({
            authorize: (
                socketId: string,
                callback: (error: Error | null, data: { auth: string } | null) => void,
            ) => {
                http.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                    .then(({ data }) => callback(null, data))
                    .catch((error: Error) => callback(error, null));
            },
        }),
    });

    signature = wanted;

    return client;
}

/**
 * Whether the socket is ACTUALLY connected right now — not merely configured.
 *
 * The session saying Reverb is on is not the same as Reverb answering: the
 * server may not be started, a proxy may drop websockets, a laptop may have
 * just woken up. A live screen asks this rather than `realtime.enabled`, so
 * it falls back to re-fetching the moment the socket is not there, instead of
 * sitting on a list that quietly stopped updating.
 */
export function useRealtimeConnected(config: RealtimeConfig | null | undefined): boolean {
    const [connected, setConnected] = useState(false);

    useEffect(() => {
        const echo = realtimeClient(config);

        if (!echo) {
            setConnected(false);

            return;
        }

        const connection = echo.connector.pusher.connection;
        const update = () => setConnected(connection.state === 'connected');

        update();
        connection.bind('state_change', update);

        return () => {
            connection.unbind('state_change', update);
        };
    }, [config]);

    return connected;
}

/** Close the connection — on sign-out, so nothing keeps listening as somebody who left. */
export function disconnectRealtime(): void {
    client?.disconnect();
    client = null;
    signature = null;
}

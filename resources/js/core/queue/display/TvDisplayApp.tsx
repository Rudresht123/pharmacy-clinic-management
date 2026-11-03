import { useCallback, useEffect, useRef, useState } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ImagePreviewProvider } from '@/shared/hooks/useImagePreview';
import { announce, unlockSound } from './announce';
import { TvScreen } from './TvScreen';
import { QUEUE_EVENTS, type DisplayState, type QueueMessage } from '../types';

/** A card pulses this long after its token is called. */
const CALLING_MS = 12_000;

/*
 * Slow while the socket is there: it keeps the picture right, and this is how a
 * wall hears it was switched off, reconfigured, or that a message went missing.
 * Quick while it is not, so a Reverb outage costs seconds and the screen never
 * quietly stops being true.
 */
const REFRESH_LIVE_MS = 60_000;
const REFRESH_OFFLINE_MS = 10_000;

type Refusal = 'display_unknown' | 'display_revoked' | 'display_expired' | 'display_inactive' | 'display_unavailable';

const REFUSALS: Record<Refusal, string> = {
    display_unknown: 'This display link is not valid.',
    display_revoked: 'This display has been switched off by your administrator.',
    display_expired: 'This display link has expired.',
    display_inactive: 'This display is currently switched off.',
    display_unavailable: 'This display is not available right now.',
};

/**
 * A television, end to end. A separate little app on purpose.
 *
 * It has no session, no sign-in and no account: main.tsx hands /display/* straight
 * here, before any provider that would go looking for a user. The token in the
 * URL is what opens it, and the server re-checks that on every read, which is why
 * a revoked screen goes dark within a minute without anybody touching it.
 *
 * A message from a doctor's channel replaces that one doctor's card and touches
 * nothing else, so two doctors calling at the same instant update independently.
 * The first read and every timer read come from the same endpoint, so a socket
 * dropping and coming back is simply a re-read.
 */
export default function TvDisplayApp() {
    const token = window.location.pathname.split('/')[2] ?? '';

    const [state, setState] = useState<DisplayState | null>(null);
    const [refused, setRefused] = useState<Refusal | null>(null);
    const [connected, setConnected] = useState(false);
    const [calling, setCalling] = useState<Set<number>>(new Set());
    const [soundOn, setSoundOn] = useState(false);

    const languages = useRef<('en' | 'hi')[]>(['en']);
    const soundEnabled = useRef(false);
    const timers = useRef<Map<number, number>>(new Map());

    /** The token each doctor last called, so one call is said once however it arrives. */
    const lastCalled = useRef<Map<number, string | null>>(new Map());

    /** Pulse a doctor's card for a while. */
    const flash = useCallback((doctorId: number) => {
        setCalling((current) => new Set(current).add(doctorId));
        window.clearTimeout(timers.current.get(doctorId));
        timers.current.set(
            doctorId,
            window.setTimeout(() => {
                setCalling((current) => {
                    const next = new Set(current);

                    next.delete(doctorId);

                    return next;
                });
            }, CALLING_MS),
        );
    }, []);

    /*
     * A call noticed on a read rather than on the socket.
     *
     * The socket is the quick way, but with Reverb down the picture carried on
     * from the timer while the room heard nothing, and a patient waiting for their
     * token to be said would simply miss it. Whoever was already called when this
     * screen first looked is not news, so the first sight of a doctor says nothing.
     * A recall cannot be told apart this way: that one still needs the socket.
     */
    const noticeCalls = useCallback(
        (next: DisplayState) => {
            for (const card of next.cards) {
                const token = card.now_calling_state === 'called' ? card.now_calling : null;
                const seenBefore = lastCalled.current.has(card.doctor_id);
                const before = lastCalled.current.get(card.doctor_id);

                lastCalled.current.set(card.doctor_id, token);

                if (!seenBefore || !token || token === before) {
                    continue;
                }

                flash(card.doctor_id);

                if (soundEnabled.current) {
                    announce(
                        {
                            token,
                            room: card.room,
                            doctorName: card.doctor_name,
                            patientName: card.patient?.name ?? null,
                            recalled: false,
                        },
                        next.display.audio_languages,
                    );
                }
            }
        },
        [flash],
    );

    const removeLoader = useCallback(() => {
        const loader = document.getElementById('app-loader');

        if (loader) {
            loader.classList.add('hide');
            window.setTimeout(() => loader.remove(), 350);
        }
    }, []);

    const read = useCallback(async () => {
        try {
            const response = await fetch(`/api/v1/display/${token}`, { headers: { Accept: 'application/json' } });
            const body = await response.json();

            if (!response.ok) {
                // A 429 or a 500 is a bad moment, not a verdict. Keep the picture.
                if (response.status === 403 || response.status === 404) {
                    setRefused((body.code as Refusal) ?? 'display_unknown');
                }

                return;
            }

            const next: DisplayState = body.data;

            languages.current = next.display.audio_languages;
            noticeCalls(next);
            setRefused(null);
            setState(next);
        } catch {
            // The network went away. The next tick tries again.
        } finally {
            removeLoader();
        }
    }, [noticeCalls, removeLoader, token]);

    useEffect(() => {
        document.title = 'Live queue';

        void read();
    }, [read]);

    useEffect(() => {
        const timer = window.setInterval(() => void read(), connected ? REFRESH_LIVE_MS : REFRESH_OFFLINE_MS);

        return () => window.clearInterval(timer);
    }, [connected, read]);

    /** A screen left on all day should not be put to sleep by the browser. */
    useEffect(() => {
        let lock: { release: () => Promise<void> } | null = null;

        void (navigator as Navigator & { wakeLock?: { request: (type: string) => Promise<typeof lock> } }).wakeLock
            ?.request('screen')
            .then((held) => {
                lock = held;
            })
            .catch(() => undefined);

        return () => {
            void lock?.release();
        };
    }, []);

    const onMessage = useCallback((message: QueueMessage) => {
        setState((current) => {
            if (!current) {
                return current;
            }

            return {
                ...current,
                cards: current.cards.map((card) => (card.doctor_id === message.doctor_id ? message.card : card)),
            };
        });

        const doctorId = message.doctor_id;

        if (!message.announce) {
            lastCalled.current.set(
                doctorId,
                message.card.now_calling_state === 'called' ? message.card.now_calling : null,
            );

            return;
        }

        flash(doctorId);

        // A read may have just said this one. A recall is always said again.
        const alreadySaid =
            !message.announce.recalled && lastCalled.current.get(doctorId) === message.announce.token;

        lastCalled.current.set(doctorId, message.announce.token);

        if (soundEnabled.current && !alreadySaid) {
            announce(
                {
                    token: message.announce.token,
                    room: message.announce.room,
                    doctorName: message.announce.doctor_name,
                    patientName: message.announce.patient_name,
                    recalled: message.announce.recalled,
                },
                languages.current,
            );
        }
    }, [flash]);

    const realtime = state?.realtime;
    const channelKey = state?.channels.join('|') ?? '';
    const realtimeKey = realtime?.enabled ? `${realtime.key}|${realtime.host}|${realtime.port}|${realtime.scheme}` : '';
    const refusedNow = refused !== null;

    /*
     * The socket, for as long as the screen is allowed.
     *
     * Its own client rather than the workspace's: that one signs channels with a
     * session this page does not have. The token signs them here, and the server
     * only signs channels this screen shows. pusher-js reconnects by itself, and
     * a reconnect re-reads, in case anything was said while the wire was down.
     */
    useEffect(() => {
        if (refusedNow || !realtime?.enabled || !realtime.key || channelKey === '') {
            setConnected(false);

            return;
        }

        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        const tls = realtime.scheme === 'https';
        const port = realtime.port ?? (tls ? 443 : 80);

        const echo = new Echo({
            broadcaster: 'reverb',
            key: realtime.key,
            wsHost: realtime.host ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: tls,
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel: { name: string }) => ({
                authorize: (
                    socketId: string,
                    callback: (error: Error | null, data: { auth: string } | null) => void,
                ) => {
                    fetch(`/api/v1/display/${token}/broadcasting/auth`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                    })
                        .then(async (response) => {
                            if (!response.ok) {
                                throw new Error(`Refused ${response.status}`);
                            }

                            callback(null, await response.json());
                        })
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });

        const connection = echo.connector.pusher.connection;
        let hasBeenUp = false;
        let wentDown = false;

        const update = () => {
            const up = connection.state === 'connected';

            setConnected(up);

            // Now, and again once its channels are signed again: a token called
            // in between reached nobody, and the next timer is a minute away.
            if (up && wentDown) {
                void read();
                window.setTimeout(() => void read(), 3000);
            }

            // The first connection is not a reconnection: the page has just read.
            wentDown = hasBeenUp && !up;
            hasBeenUp = hasBeenUp || up;
        };

        connection.bind('state_change', update);
        update();

        for (const channel of channelKey.split('|')) {
            const subscription = echo.private(channel.replace(/^private-/, ''));

            for (const event of QUEUE_EVENTS) {
                subscription.listen(`.${event}`, onMessage);
            }

            subscription.listen('.queue.lab_updated', (message: { lab: DisplayState['lab'] }) => {
                setState((current) => (current ? { ...current, lab: message.lab } : current));
            });
        }

        return () => {
            connection.unbind('state_change', update);
            echo.disconnect();
        };
        // `realtime` is compared by value through realtimeKey: every read hands
        // back a new object, and that must not tear the socket down.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [channelKey, onMessage, read, realtimeKey, refusedNow, token]);

    const enableSound = useCallback(() => {
        if (soundEnabled.current) {
            return;
        }

        unlockSound();
        soundEnabled.current = true;
        setSoundOn(true);
    }, []);

    /*
     * Any touch or remote-control press counts, not just the button: a browser
     * only needs somebody to have touched the page, and a television's remote
     * cannot always reach a small button in a corner.
     */
    const wantsSound = Boolean(state?.display.audio_enabled) && !soundOn;

    useEffect(() => {
        if (!wantsSound) {
            return;
        }

        window.addEventListener('click', enableSound);
        window.addEventListener('keydown', enableSound);

        return () => {
            window.removeEventListener('click', enableSound);
            window.removeEventListener('keydown', enableSound);
        };
    }, [enableSound, wantsSound]);

    if (refused) {
        return (
            <div className="tv tv-theme-dark tv-refused">
                <i className="ti ti-device-tv-off" aria-hidden="true" />
                <h1>{REFUSALS[refused] ?? REFUSALS.display_unknown}</h1>
                <p>Ask your administrator for a new display link.</p>
            </div>
        );
    }

    if (!state) {
        return <div className="tv tv-theme-dark tv-refused" />;
    }

    // This page has no providers of its own, and the shared Avatar needs this one.
    return (
        <ImagePreviewProvider>
            <TvScreen
                state={state}
                calling={calling}
                connection={connected ? 'live' : 'reconnecting'}
                soundPrompt={state.display.audio_enabled && !soundOn ? { onEnable: enableSound } : null}
            />
        </ImagePreviewProvider>
    );
}

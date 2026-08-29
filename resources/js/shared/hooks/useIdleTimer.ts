import { useEffect, useRef } from 'react';

/** Anything here counts as the user still being present. */
const ACTIVITY_EVENTS = [
    'mousemove',
    'mousedown',
    'keydown',
    'wheel',
    'touchstart',
    'scroll',
] as const;

interface Options {
    /** Milliseconds of inactivity before `onIdle` fires. */
    timeout: number;
    onIdle(): void;
    enabled?: boolean;
}

/**
 * Calls `onIdle` once the user has done nothing for `timeout`.
 *
 * Activity handlers only reset a timestamp — the actual check runs on an
 * interval, so moving the mouse does not schedule a timer per event.
 */
export function useIdleTimer({ timeout, onIdle, enabled = true }: Options): void {
    const lastActive = useRef(Date.now());
    const onIdleRef = useRef(onIdle);

    // Keep the latest callback without re-binding listeners.
    useEffect(() => {
        onIdleRef.current = onIdle;
    }, [onIdle]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        lastActive.current = Date.now();

        const markActive = () => {
            lastActive.current = Date.now();
        };

        ACTIVITY_EVENTS.forEach((event) =>
            window.addEventListener(event, markActive, { passive: true }),
        );

        // A laptop that was asleep wakes with a large gap since the last
        // event, which this check treats as idle time — as it should.
        const interval = window.setInterval(() => {
            if (Date.now() - lastActive.current >= timeout) {
                onIdleRef.current();
            }
        }, 1000);

        return () => {
            ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, markActive));
            window.clearInterval(interval);
        };
    }, [timeout, enabled]);
}

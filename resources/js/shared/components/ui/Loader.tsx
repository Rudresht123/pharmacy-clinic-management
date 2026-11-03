import { useEffect, useLayoutEffect, useState, useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/shared/utils/cn';

/*
|------------------------------------------------------------------------
| One overlay, however many things ask for it
|------------------------------------------------------------------------
|
| The loader used to render its own portal. Two callers can be active at
| once — <NavigationLoader> sits outside <Suspense> and the Suspense
| fallback is the same component — so a route change that also downloads a
| chunk stacked two overlays on <body>: two backdrops, and two rings
| started milliseconds apart, ghosting against each other. That read as
| stuttering far more than the animation itself.
|
| Now a single host renders the overlay and callers only raise a hand. The
| host stays mounted while anything is loading, so the ring keeps turning
| through the hand-off from Suspense to NavigationLoader instead of
| restarting from zero.
*/

let requests = 0;
const listeners = new Set<() => void>();

function emit() {
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot() {
    return requests;
}

/** How many callers are asking for the overlay right now. */
export function useFullPageLoaderRequests() {
    return useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
}

/**
 * Asks for the loading overlay for as long as it is mounted.
 *
 * Renders nothing itself — see FullPageLoaderHost, which draws it.
 */
export function FullPageLoader() {
    // A layout effect, so the overlay lands in the same paint as whatever
    // raised it. A passive one lets the browser show the new page first and
    // cover it a moment later, which is the wrong way round.
    useLayoutEffect(() => {
        requests += 1;
        emit();

        return () => {
            requests -= 1;
            emit();
        };
    }, []);

    return null;
}

/** Matches the .hospital-loader transition in vendor/css/style.min.css. */
const FADE_MS = 350;

/**
 * Draws the branded overlay. Mounted once, in Providers.
 */
export function FullPageLoaderHost() {
    const active = useFullPageLoaderRequests() > 0;

    // Kept in the DOM through the fade-out, the same pattern as Modal.
    const [lingering, setLingering] = useState(false);

    useEffect(() => {
        if (active) {
            setLingering(true);

            return;
        }

        const timer = window.setTimeout(() => setLingering(false), FADE_MS);

        return () => window.clearTimeout(timer);
    }, [active]);

    if (!active && !lingering) {
        return null;
    }

    /*
     * Comes up solid, like the boot loader on a refresh, and only fades on the
     * way out. It used to fade in as well, which spent the first 350ms of a
     * 400ms minimum see-through over the new page: on a quick navigation it
     * never read as a loader at all.
     *
     * The theme's own .hide class drives the fade, so this needs no CSS of its
     * own.
     */
    return createPortal(
        <div className={cn('hospital-loader', !active && 'hide')} role="status" aria-live="polite">
            <span className="visually-hidden">Loading…</span>

            <div className="loader-card">
                <div className="loader-border" />

                <div className="loader-icon">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="34"
                        height="34"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </div>
            </div>
        </div>,
        document.body,
    );
}

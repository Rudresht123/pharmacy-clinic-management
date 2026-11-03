import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useIsFetching } from '@tanstack/react-query';
import { FullPageLoader, useFullPageLoaderRequests } from '@/shared/components/ui/Loader';

/**
 * How long things must stay quiet before the overlay comes down.
 *
 * Queries do not all start together: a page starts its own in an effect as it
 * mounts, and one result often unlocks the next. The instant between them looks
 * exactly like "finished", and closing on it shows a half-loaded screen.
 */
const SETTLE_MS = 120;

interface NavigationLoaderProps {
    /**
     * Shortest time the loader stays up. Without a floor a cached page
     * would flash it for a few milliseconds, which reads as a glitch.
     */
    minVisibleMs?: number;

    /**
     * URL changes that are not screen changes.
     *
     * A tab that keeps its position in the URL — the field settings tabs —
     * calls navigate(), but the user is still looking at the same screen.
     * Covering it with the overlay reads as a page load that did not
     * happen. When both the old and new path match one of these, the
     * loader stays down.
     */
    sameScreen?: readonly RegExp[];
}

/**
 * Shows the branded loader on every route change, not just the ones that
 * have to download a chunk.
 *
 * It stays up until the new page's chunk has arrived and its queries have
 * settled, so it reflects real readiness rather than a fixed timer.
 *
 * This relies on the router running with useTransitions={false} (see
 * providers.tsx). In its default mode the router holds the new location back
 * until the page is ready to render, so useLocation() reports the change only
 * after the wait this is here to cover.
 */
export function NavigationLoader({ minVisibleMs = 400, sameScreen }: NavigationLoaderProps) {
    const { pathname } = useLocation();
    const isFetching = useIsFetching();
    const requests = useFullPageLoaderRequests();

    const [visible, setVisible] = useState(false);
    const shownAt = useRef(0);
    const previous = useRef<string | null>(null);

    // Any hand up besides this component's own is a Suspense fallback, so the
    // page's chunk is still downloading and its queries have not even started.
    const chunkPending = requests > (visible ? 1 : 0);

    // Start on navigation. The very first render is skipped — the boot
    // loader in app.blade.php is already covering that.
    //
    // A layout effect so the overlay is part of the first paint of the new
    // page rather than arriving on top of it.
    useLayoutEffect(() => {
        const from = previous.current;
        previous.current = pathname;

        // Nothing moved. Guards against a re-render alone raising the
        // overlay if a caller passes a fresh `sameScreen` array each time.
        if (from === null || from === pathname) {
            return;
        }

        const withinOneScreen = sameScreen?.some(
            (pattern) => pattern.test(from) && pattern.test(pathname),
        );

        if (withinOneScreen) {
            return;
        }

        shownAt.current = Date.now();
        setVisible(true);
    }, [pathname, sameScreen]);

    // Hide once nothing is pending and the minimum has elapsed. pathname is here
    // so a second navigation during the wait starts the minimum over.
    useEffect(() => {
        if (!visible || isFetching > 0 || chunkPending) {
            return;
        }

        const remaining = Math.max(SETTLE_MS, minVisibleMs - (Date.now() - shownAt.current));
        const timer = window.setTimeout(() => setVisible(false), remaining);

        return () => window.clearTimeout(timer);
    }, [visible, isFetching, chunkPending, minVisibleMs, pathname]);

    // A hard ceiling, independent of isFetching. The http client now times
    // out a stuck request on its own, but this is the backstop: whatever the
    // reason nothing ever settles, the overlay is a worse failure than
    // letting the person see a half-loaded screen, so it comes down anyway.
    useEffect(() => {
        if (!visible) {
            return;
        }

        const timer = window.setTimeout(() => setVisible(false), 25_000);

        return () => window.clearTimeout(timer);
    }, [visible]);

    if (!visible) {
        return null;
    }

    return <FullPageLoader />;
}

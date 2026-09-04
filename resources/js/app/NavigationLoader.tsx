import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useIsFetching } from '@tanstack/react-query';
import { FullPageLoader } from '@/shared/components/ui/Loader';

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
 * It stays up until the new page's queries have settled, so it reflects
 * real readiness rather than a fixed timer.
 */
export function NavigationLoader({ minVisibleMs = 400, sameScreen }: NavigationLoaderProps) {
    const { pathname } = useLocation();
    const isFetching = useIsFetching();

    const [visible, setVisible] = useState(false);
    const shownAt = useRef(0);
    const previous = useRef<string | null>(null);

    // Start on navigation. The very first render is skipped — the boot
    // loader in app.blade.php is already covering that.
    useEffect(() => {
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

    // Hide once nothing is in flight and the minimum has elapsed.
    useEffect(() => {
        if (!visible || isFetching > 0) {
            return;
        }

        const remaining = Math.max(0, minVisibleMs - (Date.now() - shownAt.current));
        const timer = window.setTimeout(() => setVisible(false), remaining);

        return () => window.clearTimeout(timer);
    }, [visible, isFetching, minVisibleMs]);

    if (!visible) {
        return null;
    }

    return <FullPageLoader />;
}

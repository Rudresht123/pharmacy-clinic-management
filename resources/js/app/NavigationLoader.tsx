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
}

/**
 * Shows the branded loader on every route change, not just the ones that
 * have to download a chunk.
 *
 * It stays up until the new page's queries have settled, so it reflects
 * real readiness rather than a fixed timer.
 */
export function NavigationLoader({ minVisibleMs = 400 }: NavigationLoaderProps) {
    const { pathname } = useLocation();
    const isFetching = useIsFetching();

    const [visible, setVisible] = useState(false);
    const shownAt = useRef(0);
    const isFirstRender = useRef(true);

    // Start on navigation. The very first render is skipped — the boot
    // loader in app.blade.php is already covering that.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        shownAt.current = Date.now();
        setVisible(true);
    }, [pathname]);

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

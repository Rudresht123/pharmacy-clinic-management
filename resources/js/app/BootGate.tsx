import { useEffect, type ReactNode } from 'react';
import { useAuth } from '@/core/auth/AuthProvider';

const LOADER_ID = 'app-loader';
/** Matches the .hospital-loader opacity transition in style.min.css. */
const FADE_MS = 350;

/**
 * Hands over from the server-rendered boot loader to the React app.
 *
 * The overlay in app.blade.php is already on screen when the bundle
 * arrives; once the initial session check settles it is faded out and
 * removed. From then on, loading is shown inline per section rather than
 * blanking the whole page.
 */
export function BootGate({ children }: { children: ReactNode }) {
    const { initialising } = useAuth();

    useEffect(() => {
        if (initialising) {
            return;
        }

        const loader = document.getElementById(LOADER_ID);

        if (!loader) {
            return;
        }

        loader.classList.add('hide');

        const timer = window.setTimeout(() => loader.remove(), FADE_MS);

        return () => window.clearTimeout(timer);
    }, [initialising]);

    // Nothing is rendered until the session is known — the boot overlay is
    // covering the screen, so rendering a second loader underneath it would
    // only cause a flash when it fades.
    if (initialising) {
        return null;
    }

    return <>{children}</>;
}

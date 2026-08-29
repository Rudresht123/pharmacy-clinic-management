import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { useAuth } from './AuthProvider';
import { useIdleTimer } from '@/shared/hooks/useIdleTimer';
import { LockScreen } from './components/LockScreen';

const STORAGE_KEY = 'app.locked';

/** Inactivity before the screen locks itself. */
const IDLE_TIMEOUT_MS = 15 * 60 * 1000;

interface LockContextValue {
    locked: boolean;
    lock(): void;
    unlock(): void;
}

const LockContext = createContext<LockContextValue | null>(null);

function readLocked(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function persist(locked: boolean): void {
    try {
        if (locked) {
            localStorage.setItem(STORAGE_KEY, '1');
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Storage unavailable — the lock simply will not survive a reload.
    }
}

/**
 * Locks the screen after a period of inactivity, or on demand.
 *
 * This is a convenience for shared workstations, not a security boundary:
 * the session cookie stays valid while locked, so it keeps a passer-by out
 * of the UI but is not a substitute for signing out.
 */
export function LockProvider({ children }: { children: ReactNode }) {
    const { isAuthenticated } = useAuth();

    // Restored on boot so a refresh cannot walk past a locked screen.
    const [locked, setLocked] = useState(() => readLocked());
    const wasAuthenticated = useRef(isAuthenticated);

    const lock = useCallback(() => {
        setLocked(true);
        persist(true);
    }, []);

    const unlock = useCallback(() => {
        setLocked(false);
        persist(false);
    }, []);

    /**
     * The lock belongs to a session, so it dies with one.
     *
     * Without this, locking and then losing the session — signing out, or a
     * 401 dropping the user — would leave the flag set, and the next person
     * to sign in would land straight on the lock screen for an account that
     * is not even theirs.
     *
     * Only a true → false transition counts. Boot goes false → true (the
     * session check resolving), which must not clear a genuine lock.
     */
    useEffect(() => {
        if (wasAuthenticated.current && !isAuthenticated) {
            unlock();
        }

        wasAuthenticated.current = isAuthenticated;
    }, [isAuthenticated, unlock]);

    useIdleTimer({
        timeout: IDLE_TIMEOUT_MS,
        onIdle: lock,
        enabled: isAuthenticated && !locked,
    });

    const value = useMemo<LockContextValue>(
        () => ({ locked: locked && isAuthenticated, lock, unlock }),
        [locked, isAuthenticated, lock, unlock],
    );

    return (
        <LockContext.Provider value={value}>
            {children}

            {value.locked && <LockScreen onUnlock={unlock} />}
        </LockContext.Provider>
    );
}

export function useLock(): LockContextValue {
    const context = useContext(LockContext);

    if (!context) {
        throw new Error('useLock must be used within a LockProvider.');
    }

    return context;
}

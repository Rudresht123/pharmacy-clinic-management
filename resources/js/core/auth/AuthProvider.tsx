import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { authApi, type PlatformUser, type LoginPayload } from './api';
import { setUnauthenticatedHandler } from '@/shared/api/http';
import { queryClient } from '@/shared/api/queryClient';

interface AuthContextValue {
    user: PlatformUser | null;
    /** True until the initial session check finishes. */
    initialising: boolean;
    isAuthenticated: boolean;
    login(payload: LoginPayload): Promise<PlatformUser>;
    logout(): Promise<void>;
    setUser(user: PlatformUser | null): void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Holds the signed-in user for the whole SPA.
 *
 * On boot it asks the API who the session belongs to; a 401 simply means
 * "nobody", which is a normal outcome rather than an error.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<PlatformUser | null>(null);
    const [initialising, setInitialising] = useState(true);

    useEffect(() => {
        let cancelled = false;

        authApi
            .me()
            .then((current) => {
                if (!cancelled) {
                    setUser(current);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setUser(null);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setInitialising(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    // A 401 from anywhere in the app drops the user back to signed-out.
    useEffect(() => {
        setUnauthenticatedHandler(() => {
            setUser(null);
            queryClient.clear();
        });
    }, []);

    const login = useCallback(async (payload: LoginPayload) => {
        const authenticated = await authApi.login(payload);

        setUser(authenticated);

        return authenticated;
    }, []);

    const logout = useCallback(async () => {
        try {
            await authApi.logout();
        } finally {
            // Clear locally even if the request failed — the user asked to leave.
            setUser(null);
            queryClient.clear();
        }
    }, []);

    const value = useMemo<AuthContextValue>(
        () => ({
            user,
            initialising,
            isAuthenticated: user !== null,
            login,
            logout,
            setUser,
        }),
        [user, initialising, login, logout],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);

    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider.');
    }

    return context;
}

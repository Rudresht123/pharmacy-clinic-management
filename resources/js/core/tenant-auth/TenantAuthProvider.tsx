import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import {
    tenantAuthApi,
    type TenantUser,
    type TenantOrganization,
    type TenantLoginPayload,
} from './api';
import { setUnauthenticatedHandler } from '@/shared/api/http';
import { queryClient } from '@/shared/api/queryClient';

interface TenantAuthContextValue {
    user: TenantUser | null;
    organization: TenantOrganization | null;
    /** True until the initial session check finishes. */
    initialising: boolean;
    isAuthenticated: boolean;
    login(payload: TenantLoginPayload): Promise<TenantUser>;
    logout(): Promise<void>;
    setUser(user: TenantUser | null): void;
}

const TenantAuthContext = createContext<TenantAuthContextValue | null>(null);

/**
 * Holds the signed-in tenant user for the whole tenant SPA tree.
 *
 * Mirrors core/auth/AuthProvider.tsx exactly — kept as a separate component
 * rather than a parameterised shared one, matching how the backend itself
 * never blurs platform and tenant auth into one code path.
 */
export function TenantAuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<TenantUser | null>(null);
    const [organization, setOrganization] = useState<TenantOrganization | null>(null);
    const [initialising, setInitialising] = useState(true);

    useEffect(() => {
        let cancelled = false;

        tenantAuthApi
            .me()
            .then((session) => {
                if (!cancelled) {
                    setUser(session.user);
                    setOrganization(session.organization);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setUser(null);
                    setOrganization(null);
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

    // A 401 from anywhere in the tenant app drops the user back to signed-out.
    useEffect(() => {
        setUnauthenticatedHandler(() => {
            setUser(null);
            setOrganization(null);
            queryClient.clear();
        });
    }, []);

    const login = useCallback(async (payload: TenantLoginPayload) => {
        const session = await tenantAuthApi.login(payload);

        setUser(session.user);
        setOrganization(session.organization);

        return session.user;
    }, []);

    const logout = useCallback(async () => {
        try {
            await tenantAuthApi.logout();
        } finally {
            setUser(null);
            setOrganization(null);
            queryClient.clear();
        }
    }, []);

    const value = useMemo<TenantAuthContextValue>(
        () => ({
            user,
            organization,
            initialising,
            isAuthenticated: user !== null,
            login,
            logout,
            setUser,
        }),
        [user, organization, initialising, login, logout],
    );

    return <TenantAuthContext.Provider value={value}>{children}</TenantAuthContext.Provider>;
}

export function useTenantAuth(): TenantAuthContextValue {
    const context = useContext(TenantAuthContext);

    if (!context) {
        throw new Error('useTenantAuth must be used within a TenantAuthProvider.');
    }

    return context;
}

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
    /** The modules running where this person works; drives the sidebar. */
    modules: string[];
    /**
     * What this person may do — all three levels already resolved by the
     * server, not the organization's whole pool.
     */
    capabilities: string[];
    /**
     * Whether they hold a capability.
     *
     * For hiding what would be refused anyway, never for deciding it: the
     * route answers 403 from the same source, and this only spares somebody
     * the click. A button hidden here is still reachable by typing the URL,
     * and has to be.
     */
    can(capability: string): boolean;
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
    const [modules, setModules] = useState<string[]>([]);
    const [capabilities, setCapabilities] = useState<string[]>([]);
    const [initialising, setInitialising] = useState(true);

    useEffect(() => {
        let cancelled = false;

        tenantAuthApi
            .me()
            .then((session) => {
                if (!cancelled) {
                    setUser(session.user);
                    setOrganization(session.organization);
                    setModules(session.modules ?? []);
                    setCapabilities(session.capabilities ?? []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setUser(null);
                    setOrganization(null);
                    setModules([]);
                    setCapabilities([]);
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
            setModules([]);
            setCapabilities([]);
            queryClient.clear();
        });
    }, []);

    const login = useCallback(async (payload: TenantLoginPayload) => {
        const session = await tenantAuthApi.login(payload);

        setUser(session.user);
        setOrganization(session.organization);
        setModules(session.modules ?? []);
        setCapabilities(session.capabilities ?? []);

        return session.user;
    }, []);

    const logout = useCallback(async () => {
        try {
            await tenantAuthApi.logout();
        } finally {
            setUser(null);
            setOrganization(null);
            setModules([]);
            setCapabilities([]);
            queryClient.clear();
        }
    }, []);

    const can = useCallback(
        (capability: string) => capabilities.includes(capability),
        [capabilities],
    );

    const value = useMemo<TenantAuthContextValue>(
        () => ({
            user,
            organization,
            modules,
            capabilities,
            can,
            initialising,
            isAuthenticated: user !== null,
            login,
            logout,
            setUser,
        }),
        [user, organization, modules, capabilities, can, initialising, login, logout],
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

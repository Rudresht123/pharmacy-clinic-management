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
    type TenantBranch,
} from './api';
import { setActiveBranchHeader, setUnauthenticatedHandler } from '@/shared/api/http';
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
    /**
     * Where this person may work. Empty for the owner and head office, who
     * work across the network — the switcher hides itself for both.
     */
    branches: TenantBranch[];

    /** Which branch the workspace is currently being used from. */
    activeBranch: number | null;

    /**
     * The doctor this account belongs to, when it belongs to one.
     *
     * Null for almost everybody — a doctor may have no login at all, which is
     * why doctors are their own table rather than a role on `users`. Where it
     * is set, the queue opens on their own list instead of the department's.
     */
    doctorId: number | null;

    /**
     * Move to another branch.
     *
     * Refetches the session rather than recomputing anything locally: which
     * capabilities apply where is the server's answer, and a second
     * implementation of that rule on the client is how the two drift.
     */
    setActiveBranch(branchId: number | null): void;

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
    const [branches, setBranches] = useState<TenantBranch[]>([]);
    const [activeBranch, setActive] = useState<number | null>(null);

    /* Set only for an account that belongs to a doctor; null for everybody
       else, which is almost everybody. */
    const [doctorId, setDoctorId] = useState<number | null>(null);
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
                    setBranches(session.branches ?? []);
                    setActive(session.active_branch ?? null);
                    setDoctorId(session.doctor_id ?? null);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setUser(null);
                    setOrganization(null);
                    setModules([]);
                    setCapabilities([]);
                    setBranches([]);
                    setActive(null);
                    setDoctorId(null);
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
            setBranches([]);
            setActive(null);
            setDoctorId(null);
            queryClient.clear();
        });
    }, []);

    const login = useCallback(async (payload: TenantLoginPayload) => {
        const session = await tenantAuthApi.login(payload);

        setUser(session.user);
        setOrganization(session.organization);
        setModules(session.modules ?? []);
        setCapabilities(session.capabilities ?? []);
        setBranches(session.branches ?? []);
        setActive(session.active_branch ?? null);
        setDoctorId(session.doctor_id ?? null);

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
            setBranches([]);
            setActive(null);
            setDoctorId(null);
            queryClient.clear();
        }
    }, []);

    const can = useCallback(
        (capability: string) => capabilities.includes(capability),
        [capabilities],
    );

    /*
     * The header goes out on every request from here on, and the session is
     * refetched so modules and capabilities describe the new branch. Every
     * cached query is dropped with it — a patient list fetched at Lucknow is
     * not the answer at Delhi.
     */
    const setActiveBranch = useCallback((branchId: number | null) => {
        setActive(branchId);
        setActiveBranchHeader(branchId);
        queryClient.clear();

        tenantAuthApi.me().then((session) => {
            setModules(session.modules ?? []);
            setCapabilities(session.capabilities ?? []);
            setActive(session.active_branch ?? null);
            setDoctorId(session.doctor_id ?? null);
        });
    }, []);

    const value = useMemo<TenantAuthContextValue>(
        () => ({
            user,
            organization,
            modules,
            capabilities,
            can,
            branches,
            activeBranch,
            doctorId,
            setActiveBranch,
            initialising,
            isAuthenticated: user !== null,
            login,
            logout,
            setUser,
        }),
        [
            user,
            organization,
            modules,
            capabilities,
            can,
            branches,
            activeBranch,
            doctorId,
            setActiveBranch,
            initialising,
            login,
            logout,
        ],
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

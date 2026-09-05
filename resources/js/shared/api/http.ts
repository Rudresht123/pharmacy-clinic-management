import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import { notify } from '@/shared/utils/notify';
import type { ApiError, ApiValidationError } from '@/shared/types/api';

/**
 * The single HTTP client for the app.
 *
 * Auth is the Sanctum stateful session, so there is no token to attach —
 * the browser carries the session cookie. What this layer does own is the
 * CSRF handshake, which every mutating request depends on.
 */
export const http: AxiosInstance = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

const MUTATING = ['post', 'put', 'patch', 'delete'];

let csrfRequest: Promise<void> | null = null;

/**
 * Fetch the XSRF cookie once. Concurrent callers share the same promise so
 * a burst of requests on page load cannot trigger a stampede.
 */
export function ensureCsrfCookie(force = false): Promise<void> {
    if (force) {
        csrfRequest = null;
    }

    if (!csrfRequest) {
        csrfRequest = axios
            .get('/sanctum/csrf-cookie', { withCredentials: true })
            .then(() => undefined)
            .catch((error) => {
                csrfRequest = null;
                throw error;
            });
    }

    return csrfRequest;
}

function hasXsrfCookie(): boolean {
    return document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='));
}

/**
 * The branch the workspace is currently being used from.
 *
 * A header on every request rather than a parameter threaded through each
 * call site — it applies to all of them, and threading it is how one gets
 * forgotten. The server never trusts it: ResolveActingBranch checks it
 * against the caller's own memberships and refuses a branch they do not work
 * at, so this is a statement of intent, not a grant.
 */
let activeBranch: number | null = null;

export function setActiveBranchHeader(branchId: number | null): void {
    activeBranch = branchId;
}

http.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
    const method = (config.method ?? 'get').toLowerCase();

    if (MUTATING.includes(method) && !hasXsrfCookie()) {
        await ensureCsrfCookie();
    }

    if (activeBranch !== null) {
        config.headers.set('X-Branch-Id', String(activeBranch));
    }

    return config;
});

/** Where to send the user when the session is gone. Set by the router. */
let onUnauthenticated: (() => void) | null = null;

export function setUnauthenticatedHandler(handler: () => void): void {
    onUnauthenticated = handler;
}

http.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<ApiError | ApiValidationError>) => {
        const status = error.response?.status;
        const config = error.config as
            (InternalAxiosRequestConfig & { _retried?: boolean }) | undefined;

        // Session expired mid-visit: refresh the CSRF cookie and replay once.
        if (status === 419 && config && !config._retried) {
            config._retried = true;
            await ensureCsrfCookie(true);

            return http.request(config);
        }

        if (status === 401) {
            onUnauthenticated?.();

            return Promise.reject(error);
        }

        // 422 is expected — forms render those inline via useApiForm.
        if (status !== 422) {
            notify.error(resolveErrorMessage(error));
        }

        return Promise.reject(error);
    },
);

export function resolveErrorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        if (!error.response) {
            return 'Cannot reach the server. Check your connection.';
        }

        const message = (error.response.data as ApiError | undefined)?.message;

        if (message) {
            return message;
        }

        if (error.response.status >= 500) {
            return 'Something went wrong on our side. Please try again.';
        }
    }

    return 'Something went wrong. Please try again.';
}

/** Narrow an unknown error to a 422 so callers can read `errors`. */
export function getValidationErrors(error: unknown): Record<string, string[]> | null {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
        return (error.response.data as ApiValidationError).errors ?? null;
    }

    return null;
}

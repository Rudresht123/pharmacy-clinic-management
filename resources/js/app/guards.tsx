import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '@/core/auth/AuthProvider';

/**
 * Mirrors Laravel's `auth` middleware.
 *
 * No loading branch is needed: BootGate holds the tree back until the
 * session check has settled, so `isAuthenticated` is already truthful the
 * first time this renders.
 */
export function ProtectedRoute() {
    const { isAuthenticated } = useAuth();
    const location = useLocation();

    if (!isAuthenticated) {
        // Remember where they were headed so login can return them there.
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    return <Outlet />;
}

/** Mirrors Laravel's `guest` middleware. */
export function GuestRoute() {
    const { isAuthenticated } = useAuth();

    if (isAuthenticated) {
        return <Navigate to="/dashboard" replace />;
    }

    return <Outlet />;
}

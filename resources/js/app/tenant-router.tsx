import { lazy, Suspense } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { FullPageLoader } from '@/shared/components/ui/Loader';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';

const TenantLoginPage = lazy(() => import('@/core/tenant-auth/pages/TenantLoginPage'));
const TenantDashboardPage = lazy(() => import('@/core/tenant-auth/pages/TenantDashboardPage'));

/** Mirrors app/guards.tsx's ProtectedRoute, against the tenant auth context. */
function TenantProtectedRoute() {
    const { isAuthenticated } = useTenantAuth();
    const location = useLocation();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    return <Outlet />;
}

/** Mirrors app/guards.tsx's GuestRoute, against the tenant auth context. */
function TenantGuestRoute() {
    const { isAuthenticated } = useTenantAuth();

    if (isAuthenticated) {
        return <Navigate to="/dashboard" replace />;
    }

    return <Outlet />;
}

function NotFound() {
    return (
        <div className="text-center py-5">
            <h1 className="fw-bold mb-1">404</h1>
            <p className="text-muted">That page does not exist.</p>
        </div>
    );
}

export function TenantAppRoutes() {
    return (
        <Suspense fallback={<FullPageLoader />}>
            <Routes>
                <Route element={<TenantGuestRoute />}>
                    <Route path="/login" element={<TenantLoginPage />} />
                </Route>

                <Route element={<TenantProtectedRoute />}>
                    <Route path="/dashboard" element={<TenantDashboardPage />} />
                </Route>

                <Route path="/" element={<Navigate to="/dashboard" replace />} />
                <Route path="*" element={<NotFound />} />
            </Routes>
        </Suspense>
    );
}

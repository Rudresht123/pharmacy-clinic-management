import { lazy, Suspense } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { FullPageLoader } from '@/shared/components/ui/Loader';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { TenantShell } from './TenantShell';

const TenantLoginPage = lazy(() => import('@/core/tenant-auth/pages/TenantLoginPage'));
const TenantDashboardPage = lazy(() => import('@/core/tenant-auth/pages/TenantDashboardPage'));
const CustomerListPage = lazy(() => import('@/core/customers/pages/CustomerListPage'));
const CustomerFormPage = lazy(() => import('@/core/customers/pages/CustomerFormPage'));
const LocationListPage = lazy(() => import('@/core/locations/pages/LocationListPage'));
const LocationFormPage = lazy(() => import('@/core/locations/pages/LocationFormPage'));
const TenantUserListPage = lazy(() => import('@/core/tenant-users/pages/TenantUserListPage'));
const TenantUserFormPage = lazy(() => import('@/core/tenant-users/pages/TenantUserFormPage'));
const FieldSettingsPage = lazy(
    () => import('@/core/field-settings/pages/FieldSettingsPage'),
);

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
                    <Route element={<TenantShell />}>
                        <Route path="/dashboard" element={<TenantDashboardPage />} />

                        <Route path="/customers" element={<CustomerListPage />} />
                        <Route path="/customers/create" element={<CustomerFormPage />} />
                        <Route path="/customers/:id/edit" element={<CustomerFormPage />} />

                        <Route path="/locations" element={<LocationListPage />} />
                        {/* The literal is matched before the :id pattern, so
                            "create" is never mistaken for a location id. */}
                        <Route path="/locations/create" element={<LocationFormPage />} />
                        <Route path="/locations/:id/edit" element={<LocationFormPage />} />

                        <Route path="/people" element={<TenantUserListPage />} />
                        {/* Literal before the :id pattern, as with locations. */}
                        <Route path="/people/create" element={<TenantUserFormPage />} />
                        <Route path="/people/:id/edit" element={<TenantUserFormPage />} />

                        {/* One settings screen; the tab lives in the URL so a
                            refresh and the back button both behave. */}
                        <Route
                            path="/settings/fields"
                            element={<Navigate to="/settings/fields/location" replace />}
                        />
                        <Route path="/settings/fields/:entity" element={<FieldSettingsPage />} />

                        <Route path="*" element={<NotFound />} />
                    </Route>
                </Route>

                {/* Unmatched paths fall to the "*" inside the shell above, so
                    a signed-out visitor is sent to /login rather than shown a
                    404 with no way back — same arrangement as router.tsx. */}
                <Route path="/" element={<Navigate to="/dashboard" replace />} />
            </Routes>
        </Suspense>
    );
}

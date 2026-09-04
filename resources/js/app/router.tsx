import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AdminShell } from './AdminShell';
import { FullPageLoader } from '@/shared/components/ui/Loader';
import { NavigationLoader } from './NavigationLoader';
import { GuestRoute, ProtectedRoute } from './guards';

// Route-level code splitting keeps the initial bundle small.
const LoginPage = lazy(() => import('@/core/auth/pages/LoginPage'));
const ForgotPasswordPage = lazy(() => import('@/core/auth/pages/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('@/core/auth/pages/ResetPasswordPage'));
const OrganizationSetupPage = lazy(() => import('@/core/onboarding/pages/OrganizationSetupPage'));
const DashboardPage = lazy(() => import('@/core/dashboard/pages/DashboardPage'));
const ModuleAccessPage = lazy(() => import('@/core/modules/pages/ModuleAccessPage'));
const AuditLogPage = lazy(() => import('@/core/audit/pages/AuditLogPage'));
const OrganizationListPage = lazy(() => import('@/core/organizations/pages/OrganizationListPage'));
const OrganizationFormPage = lazy(() => import('@/core/organizations/pages/OrganizationFormPage'));
const OrganizationDetailPage = lazy(
    () => import('@/core/organizations/pages/OrganizationDetailPage'),
);
const OrganizationTypeListPage = lazy(
    () => import('@/core/organization-types/pages/OrganizationTypeListPage'),
);
const ProfilePage = lazy(() => import('@/core/profile/pages/ProfilePage'));

function NotFound() {
    return (
        <div className="text-center py-5">
            <h1 className="fw-bold mb-1">404</h1>
            <p className="text-muted">That page does not exist.</p>
        </div>
    );
}

export function AppRoutes() {
    // Navigating to a page whose chunk is not downloaded yet suspends here,
    // and the branded full-screen loader covers the transition — the same
    // loader the initial page load shows.
    return (
        <>
            <NavigationLoader />

            <Suspense fallback={<FullPageLoader />}>
                <Routes>
                    {/* Public — no session required */}
                    <Route path="/organization/setup/:token" element={<OrganizationSetupPage />} />

                    {/* Guest only */}
                    <Route element={<GuestRoute />}>
                        <Route path="/login" element={<LoginPage />} />
                        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                        <Route path="/reset-password/:token" element={<ResetPasswordPage />} />
                    </Route>

                    {/* Authenticated */}
                    <Route element={<ProtectedRoute />}>
                        <Route element={<AdminShell />}>
                            <Route path="/dashboard" element={<DashboardPage />} />

                            <Route path="/organizations" element={<OrganizationListPage />} />
                            <Route
                                path="/organizations/create"
                                element={<OrganizationFormPage />}
                            />
                            {/* §5: organizations are addressed by ULID. The
                                literal /create above is matched first, so it
                                is never mistaken for a uuid. */}
                            <Route
                                path="/organizations/:uuid"
                                element={<OrganizationDetailPage />}
                            />
                            <Route
                                path="/organizations/:uuid/edit"
                                element={<OrganizationFormPage />}
                            />

                            <Route
                                path="/organization-types"
                                element={<OrganizationTypeListPage />}
                            />

                            <Route path="/modules" element={<ModuleAccessPage />} />

                            <Route path="/audit" element={<AuditLogPage />} />

                            <Route path="/profile" element={<ProfilePage />} />

                            <Route path="*" element={<NotFound />} />
                        </Route>
                    </Route>

                    <Route path="/" element={<Navigate to="/dashboard" replace />} />
                </Routes>
            </Suspense>
        </>
    );
}

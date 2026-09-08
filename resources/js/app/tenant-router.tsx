import { lazy, Suspense } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { FullPageLoader } from '@/shared/components/ui/Loader';
import { NavigationLoader } from './NavigationLoader';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { RequireCapability, RequireModule } from './tenant-guards';
import { TenantShell } from './TenantShell';

const TenantLoginPage = lazy(() => import('@/core/tenant-auth/pages/TenantLoginPage'));
const TenantDashboardPage = lazy(() => import('@/core/tenant-auth/pages/TenantDashboardPage'));
const CustomerListPage = lazy(() => import('@/core/customers/pages/CustomerListPage'));
const CustomerFormPage = lazy(() => import('@/core/customers/pages/CustomerFormPage'));
const PatientPage = lazy(() => import('@/core/customers/pages/PatientPage'));
const LocationListPage = lazy(() => import('@/core/locations/pages/LocationListPage'));
const LocationFormPage = lazy(() => import('@/core/locations/pages/LocationFormPage'));
const TenantUserListPage = lazy(() => import('@/core/tenant-users/pages/TenantUserListPage'));
const TenantUserFormPage = lazy(() => import('@/core/tenant-users/pages/TenantUserFormPage'));
const FieldSettingsPage = lazy(() => import('@/core/field-settings/pages/FieldSettingsPage'));
const TenantHistoryPage = lazy(() => import('@/core/tenant-history/pages/TenantHistoryPage'));
const RolesPage = lazy(() => import('@/core/roles/pages/RolesPage'));
const DoctorListPage = lazy(() => import('@/core/doctors/pages/DoctorListPage'));
const DoctorFormPage = lazy(() => import('@/core/doctors/pages/DoctorFormPage'));
const MyDayPage = lazy(() => import('@/core/opd/pages/MyDayPage'));
const MyQueuePage = lazy(() => import('@/core/opd/pages/MyQueuePage'));
const MySchedulePage = lazy(() => import('@/core/opd/pages/MySchedulePage'));
const SchedulesPage = lazy(() => import('@/core/doctors/pages/SchedulesPage'));
const AvailabilityPage = lazy(() => import('@/core/availability/pages/AvailabilityPage'));
const QueuePage = lazy(() => import('@/core/appointments/pages/QueuePage'));
const OpdTodayPage = lazy(() => import('@/core/opd/pages/OpdTodayPage'));

/** Mirrors app/guards.tsx's ProtectedRoute, against the tenant auth context. */
function TenantProtectedRoute() {
    const { isAuthenticated } = useTenantAuth();
    const location = useLocation();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    return <Outlet />;
}

/**
 * Where a signed-in person belongs.
 *
 * A doctor's home is their own list. The dashboard counts branches, staff and
 * revenue — a manager's reading of the business — and landing a doctor there
 * shows them somebody else's job before their own. One helper, so login, the
 * root path and a stale /dashboard bookmark all agree.
 */
function useHome(): string {
    const { doctorId } = useTenantAuth();

    return doctorId ? '/my-day' : '/dashboard';
}

/** Mirrors app/guards.tsx's GuestRoute, against the tenant auth context. */
function TenantGuestRoute() {
    const { isAuthenticated } = useTenantAuth();
    const home = useHome();

    if (isAuthenticated) {
        return <Navigate to={home} replace />;
    }

    return <Outlet />;
}

/** The organization's dashboard, or a doctor's list in its place. */
function HomeRoute() {
    const home = useHome();

    return home === '/dashboard' ? <TenantDashboardPage /> : <Navigate to={home} replace />;
}

/**
 * The root path, which is only ever a signpost.
 *
 * It sits outside the auth guard, so it must never render a screen — a
 * signed-out visitor landing here has to be handed on to the guarded route and
 * refused there, not shown the dashboard because nothing said otherwise.
 */
function HomeRedirect() {
    return <Navigate to={useHome()} replace />;
}

/**
 * The settings tabs keep their position in the URL, so switching one is a
 * navigate() that never leaves the screen. Module-level so its identity is
 * stable across renders.
 */
const SAME_SCREEN = [/^\/settings\/fields(\/|$)/] as const;

function NotFound() {
    return (
        <div className="text-center py-5">
            <h1 className="fw-bold mb-1">404</h1>
            <p className="text-muted">That page does not exist.</p>
        </div>
    );
}

export function TenantAppRoutes() {
    /*
     * NavigationLoader covers every route change; the Suspense fallback only
     * covers the first visit to a page, when its chunk still has to be
     * downloaded. Without the former, the loader appeared once per page for
     * the life of the session and navigation felt inconsistent afterwards.
     * Both raise the same overlay, so the hand-off is seamless.
     */
    return (
        <>
            <NavigationLoader sameScreen={SAME_SCREEN} />

            <Suspense fallback={<FullPageLoader />}>
                <Routes>
                    <Route element={<TenantGuestRoute />}>
                        <Route path="/login" element={<TenantLoginPage />} />
                    </Route>

                    <Route element={<TenantProtectedRoute />}>
                        <Route element={<TenantShell />}>
                            <Route path="/dashboard" element={<HomeRoute />} />

                            {/*
                                Every screen below names the capability its own
                                API routes ask for, so the two cannot drift —
                                if a screen opens, the requests behind it are
                                allowed, and when it does not, both refuse for
                                the same stated reason.

                                The guards are courtesy, not enforcement: the
                                server refuses these regardless, and the data
                                for a screen somebody may not have never
                                arrives. What they fix is a typed URL rendering
                                a whole page and then failing, which reads like
                                a broken product rather than an answer.
                            */}
                            <Route element={<RequireCapability capability="customers.view" />}>
                                <Route path="/customers" element={<CustomerListPage />} />
                            </Route>
                            <Route element={<RequireCapability capability="customers.create" />}>
                                <Route path="/customers/create" element={<CustomerFormPage />} />
                            </Route>
                            <Route element={<RequireCapability capability="customers.edit" />}>
                                <Route
                                    path="/customers/:id/edit"
                                    element={<CustomerFormPage />}
                                />
                            </Route>

                            {/*
                                The record, to read. Declared after the literal
                                paths above so "create" is never taken for an id,
                                and behind `customers.view` rather than edit — a
                                doctor reads a patient without being able to
                                change them.
                            */}
                            <Route element={<RequireCapability capability="customers.view" />}>
                                <Route path="/customers/:id" element={<PatientPage />} />
                            </Route>

                            <Route element={<RequireCapability capability="branches.view" />}>
                                <Route path="/locations" element={<LocationListPage />} />
                            </Route>
                            {/* The literal is matched before the :id pattern, so
                                "create" is never mistaken for a location id. */}
                            <Route element={<RequireCapability capability="branches.create" />}>
                                <Route path="/locations/create" element={<LocationFormPage />} />
                            </Route>
                            <Route element={<RequireCapability capability="branches.edit" />}>
                                <Route
                                    path="/locations/:id/edit"
                                    element={<LocationFormPage />}
                                />
                            </Route>

                            {/* Two questions for OPD, asked in the same order
                                the server asks them: is the module running
                                here at all, and may this person use it. */}
                            <Route element={<RequireModule module="appointments" />}>
                                {/*
                                    A doctor's own list names `appointments.queue`,
                                    not `appointments.view` — the wider one opens
                                    the department's screens, which is not what
                                    this is.
                                */}
                                <Route
                                    element={
                                        <RequireCapability capability="appointments.queue" />
                                    }
                                >
                                    <Route path="/my-day" element={<MyDayPage />} />
                                    <Route path="/my-queue" element={<MyQueuePage />} />
                                    <Route path="/my-schedule" element={<MySchedulePage />} />
                                </Route>

                                <Route
                                    element={<RequireCapability capability="appointments.view" />}
                                >
                                    <Route path="/doctors" element={<DoctorListPage />} />
                                    <Route path="/availability" element={<AvailabilityPage />} />

                                    <Route path="/opd" element={<OpdTodayPage />} />
                                    <Route path="/opd/queue" element={<QueuePage />} />

                                    {/*
                                        The old address. Anybody who bookmarked
                                        the queue, or wrote it into a note by
                                        the desk, still lands on it.
                                    */}
                                    <Route
                                        path="/queue"
                                        element={<Navigate to="/opd/queue" replace />}
                                    />
                                </Route>

                                {/* Literal before the :id pattern, as with locations. */}
                                <Route
                                    element={
                                        <RequireCapability capability="appointments.doctors" />
                                    }
                                >
                                    <Route path="/doctors/create" element={<DoctorFormPage />} />
                                    <Route
                                        path="/doctors/:id/edit"
                                        element={<DoctorFormPage />}
                                    />
                                </Route>

                                {/*
                                    The screen names the capability its endpoint
                                    demands. Writing a week is `appointments.schedule`,
                                    not `appointments.doctors` — gating it on the
                                    latter would show the Save button to somebody
                                    the API then refuses.
                                */}
                                <Route
                                    element={
                                        <RequireCapability capability="appointments.schedule" />
                                    }
                                >
                                    <Route path="/schedules" element={<SchedulesPage />} />
                                </Route>
                            </Route>

                            <Route element={<RequireCapability capability="people.view" />}>
                                <Route path="/people" element={<TenantUserListPage />} />
                            </Route>
                            {/* Literal before the :id pattern, as with locations. */}
                            <Route element={<RequireCapability capability="people.create" />}>
                                <Route
                                    path="/people/create"
                                    element={<TenantUserFormPage />}
                                />
                            </Route>
                            <Route element={<RequireCapability capability="people.edit" />}>
                                <Route
                                    path="/people/:id/edit"
                                    element={<TenantUserFormPage />}
                                />
                            </Route>

                            {/* Level three of the permission flow. Reading is
                                open to anybody who administers staff; writing
                                is decided per role on the server. */}
                            <Route element={<RequireCapability capability="people.view" />}>
                                <Route path="/roles" element={<RolesPage />} />
                            </Route>

                            {/* One settings screen; the tab lives in the URL so a
                                refresh and the back button both behave. */}
                            <Route element={<RequireCapability capability="settings.manage" />}>
                                <Route
                                    path="/settings/fields"
                                    element={<Navigate to="/settings/fields/location" replace />}
                                />
                                <Route
                                    path="/settings/fields/:entity"
                                    element={<FieldSettingsPage />}
                                />
                            </Route>

                            {/* The whole log needs its own capability; one
                                record's history opens from that record itself. */}
                            <Route element={<RequireCapability capability="settings.audit" />}>
                                <Route path="/activity" element={<TenantHistoryPage />} />
                            </Route>

                            <Route path="*" element={<NotFound />} />
                        </Route>
                    </Route>

                    {/* Unmatched paths fall to the "*" inside the shell above, so
                        a signed-out visitor is sent to /login rather than shown a
                        404 with no way back — same arrangement as router.tsx. */}
                    <Route path="/" element={<HomeRedirect />} />
                </Routes>
            </Suspense>
        </>
    );
}

import { lazy, Suspense } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { FullPageLoader } from '@/shared/components/ui/Loader';
import { NavigationLoader } from './NavigationLoader';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { EXPORT_CAPABILITIES } from '@/core/exports/capabilities';
import { RequireAnyCapability, RequireCapability, RequireModule, RequireOwner } from './tenant-guards';
import { firstScreen, tenantNavigation } from './tenant-navigation';
import { TenantShell } from './TenantShell';

const OrganizationSetupPage = lazy(() => import('@/core/org-setup/pages/OrganizationSetupPage'));
const DepartmentsPage = lazy(() => import('@/core/departments/pages/DepartmentsPage'));

const TenantLoginPage = lazy(() => import('@/core/tenant-auth/pages/TenantLoginPage'));
const TenantForgotPasswordPage = lazy(() => import('@/core/tenant-auth/pages/ForgotPasswordPage'));
const TenantResetPasswordPage = lazy(() => import('@/core/tenant-auth/pages/ResetPasswordPage'));
const TenantProfilePage = lazy(() => import('@/core/tenant-auth/pages/ProfilePage'));
const TenantDashboardPage = lazy(() => import('@/core/tenant-auth/pages/TenantDashboardPage'));
const CustomerListPage = lazy(() => import('@/core/customers/pages/CustomerListPage'));
const CustomerFormPage = lazy(() => import('@/core/customers/pages/CustomerFormPage'));
const PatientPage = lazy(() => import('@/core/customers/pages/PatientPage'));
const PatientRecordsPage = lazy(() => import('@/core/patient-records/pages/PatientRecordsPage'));
const ExportCenterPage = lazy(() => import('@/core/exports/pages/ExportCenterPage'));
const ExportColumnsPage = lazy(() => import('@/core/exports/pages/ExportColumnsPage'));
const LocationListPage = lazy(() => import('@/core/locations/pages/LocationListPage'));
const LocationFormPage = lazy(() => import('@/core/locations/pages/LocationFormPage'));
const TenantUserListPage = lazy(() => import('@/core/tenant-users/pages/TenantUserListPage'));
const TenantUserFormPage = lazy(() => import('@/core/tenant-users/pages/TenantUserFormPage'));
const FieldSettingsPage = lazy(() => import('@/core/field-settings/pages/FieldSettingsPage'));
const TenantHistoryPage = lazy(() => import('@/core/tenant-history/pages/TenantHistoryPage'));
const RolesPage = lazy(() => import('@/core/roles/pages/RolesPage'));
const ModuleSettingsPage = lazy(() => import('@/core/roles/pages/ModuleSettingsPage'));
const DoctorListPage = lazy(() => import('@/core/doctors/pages/DoctorListPage'));
const DoctorFormPage = lazy(() => import('@/core/doctors/pages/DoctorFormPage'));
const MyDayPage = lazy(() => import('@/core/opd/pages/MyDayPage'));
const MyQueuePage = lazy(() => import('@/core/opd/pages/MyQueuePage'));
const QueueBoardPage = lazy(() => import('@/core/queue/pages/QueueBoardPage'));
const DoctorQueuePage = lazy(() => import('@/core/queue/pages/DoctorQueuePage'));
const QueueSettingsPage = lazy(() => import('@/core/queue/pages/QueueSettingsPage'));
const QueueDisplaysPage = lazy(() => import('@/core/queue/pages/QueueDisplaysPage'));
const QueueDisplayFormPage = lazy(() => import('@/core/queue/pages/QueueDisplayFormPage'));
const DisplayPreviewPage = lazy(() => import('@/core/queue/pages/DisplayPreviewPage'));
const MySchedulePage = lazy(() => import('@/core/opd/pages/MySchedulePage'));
const MyRecordsPage = lazy(() => import('@/core/opd/pages/MyRecordsPage'));
const MyConsultationsPage = lazy(() => import('@/core/opd/pages/MyConsultationsPage'));
const FollowUpsPage = lazy(() => import('@/core/opd/pages/FollowUpsPage'));
const SchedulesPage = lazy(() => import('@/core/doctors/pages/SchedulesPage'));
const AvailabilityPage = lazy(() => import('@/core/availability/pages/AvailabilityPage'));
const QueuePage = lazy(() => import('@/core/appointments/pages/QueuePage'));
const RescheduleRequestsPage = lazy(
    () => import('@/core/appointments/pages/RescheduleRequestsPage'),
);
const AppointmentSettingsPage = lazy(
    () => import('@/core/appointments/pages/AppointmentSettingsPage'),
);
const OpdTodayPage = lazy(() => import('@/core/opd/pages/OpdTodayPage'));
const MedicineListPage = lazy(() => import('@/core/medicines/pages/MedicineListPage'));
const MedicineFormPage = lazy(() => import('@/core/medicines/pages/MedicineFormPage'));
const StoreListPage = lazy(() => import('@/core/pharmacy/pages/StoreListPage'));
const StoreFormPage = lazy(() => import('@/core/pharmacy/pages/StoreFormPage'));
const StoreMedicinesPage = lazy(() => import('@/core/pharmacy/pages/StoreMedicinesPage'));
const StockPage = lazy(() => import('@/core/pharmacy/pages/StockPage'));
const InwardListPage = lazy(() => import('@/core/pharmacy/pages/InwardListPage'));
const InwardFormPage = lazy(() => import('@/core/pharmacy/pages/InwardFormPage'));
const MovementsPage = lazy(() => import('@/core/pharmacy/pages/MovementsPage'));
const SuppliersPage = lazy(() => import('@/core/pharmacy/pages/SuppliersPage'));
const PharmacySettingsPage = lazy(() => import('@/core/pharmacy/pages/PharmacySettingsPage'));
const LabSettingsPage = lazy(() => import('@/core/laboratory/settings/pages/LabSettingsPage'));
const PharmacyDashboardPage = lazy(() => import('@/core/pharmacy/pages/PharmacyDashboardPage'));
const PharmacyReportsPage = lazy(() => import('@/core/pharmacy/pages/PharmacyReportsPage'));
const PosPage = lazy(() => import('@/core/pharmacy/pages/PosPage'));
const SalesListPage = lazy(() => import('@/core/pharmacy/pages/SalesListPage'));
const BillingOverviewPage = lazy(() => import('@/core/billing/pages/BillingOverviewPage'));
const InvoiceListPage = lazy(() => import('@/core/billing/pages/InvoiceListPage'));
const InvoicePage = lazy(() => import('@/core/billing/pages/InvoicePage'));
const NewInvoicePage = lazy(() => import('@/core/billing/pages/NewInvoicePage'));
const PaymentsPage = lazy(() => import('@/core/billing/pages/PaymentsPage'));
const OutstandingPage = lazy(() => import('@/core/billing/pages/OutstandingPage'));
const FinancePage = lazy(() => import('@/core/billing/pages/FinancePage'));
const ServicesPage = lazy(() => import('@/core/billing/pages/ServicesPage'));
const BillingSettingsPage = lazy(() => import('@/core/billing/pages/BillingSettingsPage'));
const DocumentTemplatesPage = lazy(() => import('@/core/documents/pages/DocumentTemplatesPage'));
const DocumentAutomationPage = lazy(() => import('@/core/documents/pages/DocumentAutomationPage'));
const GuidesPage = lazy(() => import('@/core/guides/pages/GuidesPage'));
const SubscriptionPage = lazy(() => import('@/core/subscription/pages/SubscriptionPage'));
const SubscriptionLockedPage = lazy(() => import('@/core/subscription/pages/SubscriptionLockedPage'));
const GuidePage = lazy(() => import('@/core/guides/pages/GuidePage'));
const WhatsAppPage = lazy(() => import('@/core/communication/pages/WhatsAppPage'));
const EmailPage = lazy(() => import('@/core/communication/pages/EmailPage'));
const PushPage = lazy(() => import('@/core/communication/pages/PushPage'));
const RecipientsPage = lazy(() => import('@/core/communication/pages/RecipientsPage'));
const CampaignWizardPage = lazy(() => import('@/core/communication/pages/CampaignWizardPage'));

/**
 * Screens the owner may open before setup is finished: the setup itself, and
 * the roles editor its Roles section links to.
 */
const OPEN_DURING_SETUP = [/^\/setup(\/|$)/, /^\/roles(\/|$)/];

/**
 * What everybody but the owner sees while the organisation is being set up.
 * The owner is the only one who can finish it, so there is nothing else here
 * for anybody to do yet.
 */
function SetupPending() {
    const { organization, logout } = useTenantAuth();

    return (
        <div className="d-flex align-items-center justify-content-center min-vh-100 px-3">
            <div className="org-pending">
                <i className="ti ti-settings-cog" />
                <h6>{organization?.name ?? 'Your organisation'} is still being set up</h6>
                <p>
                    The owner has not finished setting up the organisation yet. You can sign in
                    and start working as soon as they have.
                </p>

                <button type="button" className="btn btn-light mt-3" onClick={() => void logout()}>
                    <i className="ti ti-logout me-1" />
                    Sign out
                </button>
            </div>
        </div>
    );
}

/**
 * What somebody sees when the platform has not granted what they need yet.
 *
 * Nothing is included by default, so a new organization starts here. The setup
 * also lands here until Branches is granted: it cannot finish without a branch,
 * and the owner would otherwise hit a refusal partway through it.
 */
function ModulesPending() {
    const { organization, logout } = useTenantAuth();

    return (
        <div className="d-flex align-items-center justify-content-center min-vh-100 px-3">
            <div className="org-pending">
                <i className="ti ti-puzzle" />
                <h6>{organization?.name ?? 'Your organisation'} is waiting for its modules</h6>
                <p>
                    Your ZEVRO administrator has not switched on what this organisation needs yet.
                    You can sign in and start working as soon as they have.
                </p>

                <button type="button" className="btn btn-light mt-3" onClick={() => void logout()}>
                    <i className="ti ti-logout me-1" />
                    Sign out
                </button>
            </div>
        </div>
    );
}

/**
 * Mirrors app/guards.tsx's ProtectedRoute, against the tenant auth context.
 *
 * Also keeps the workspace closed until organisation setup is finished: the
 * owner is taken to the setup screen from anywhere else, everybody else is
 * told to wait. The server's session says whether it is finished.
 */
/**
 * What stays open while the subscription is locked: renewing it, one's own
 * account, and the guides. The API answers 423 to everything else anyway.
 */
const OPEN_WHEN_LOCKED = [/^\/subscription(\/|$)/, /^\/profile(\/|$)/, /^\/guide(\/|$)/] as const;

/**
 * What stays open while waiting for modules. Not the guides: with nothing in the
 * menu /guide is where somebody lands, and it would hide the reason they are
 * looking at a nearly empty screen.
 */
const OPEN_WITHOUT_MODULES = [/^\/subscription(\/|$)/, /^\/profile(\/|$)/] as const;

function TenantProtectedRoute() {
    const { isAuthenticated, setupCompleted, user, subscription, modules } = useTenantAuth();
    const location = useLocation();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    /*
     * A locked subscription: the expired page instead of the workspace. Not
     * the lock itself — the server refuses every request with a 423 — but the
     * explanation, so nobody is left staring at error states.
     */
    if (subscription?.locked && !OPEN_WHEN_LOCKED.some((path) => path.test(location.pathname))) {
        return <SubscriptionLockedPage />;
    }

    const cannotStart = modules.length === 0 || (!setupCompleted && !modules.includes('branches'));

    if (cannotStart && !OPEN_WITHOUT_MODULES.some((path) => path.test(location.pathname))) {
        return <ModulesPending />;
    }

    if (!setupCompleted) {
        if (user?.role !== 'owner') {
            return <SetupPending />;
        }

        if (!OPEN_DURING_SETUP.some((path) => path.test(location.pathname))) {
            return <Navigate to="/setup" replace />;
        }
    }

    return <Outlet />;
}

/**
 * Where a signed-in person belongs: the first screen their role opens, in
 * menu order — My day for a doctor given it, the dashboard for a role given
 * that, Messages for a role given only Messages. One helper, so login, the
 * root path and a stale /dashboard bookmark all agree. Before setup is
 * finished the owner's home is the setup.
 */
function useHome(): string {
    const { doctorId, setupCompleted, user, modules, capabilities } = useTenantAuth();

    if (!setupCompleted && user?.role === 'owner') {
        return '/setup';
    }

    return (
        firstScreen(tenantNavigation(user?.role, undefined, modules, capabilities, doctorId, setupCompleted)) ??
        '/guide'
    );
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

/** The organization's dashboard, for a role given it; otherwise where they land. */
function HomeRoute() {
    const { can } = useTenantAuth();
    const home = useHome();

    return can('dashboard.view') ? <TenantDashboardPage /> : <Navigate to={home} replace />;
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
                        <Route path="/forgot-password" element={<TenantForgotPasswordPage />} />
                        <Route
                            path="/reset-password/:token"
                            element={<TenantResetPasswordPage />}
                        />
                    </Route>

                    <Route element={<TenantProtectedRoute />}>
                        {/*
                            Organisation setup is a page of its own, outside the
                            workspace's chrome: nothing else opens until it is
                            finished, so a sidebar would only offer shut doors.
                            The owner's alone, as its API is. The open step is
                            ?section=, so switching never leaves the page.
                        */}
                        <Route element={<RequireOwner />}>
                            <Route path="/setup" element={<OrganizationSetupPage />} />
                        </Route>

                        <Route element={<RequireModule module="queue" />}>
                            <Route element={<RequireCapability capability="queue.displays" />}>
                                <Route path="/settings/tv-displays/:id/preview" element={<DisplayPreviewPage />} />
                            </Route>
                        </Route>

                        <Route element={<TenantShell />}>
                            <Route path="/dashboard" element={<HomeRoute />} />

                            {/* Their own account — open to everybody signed
                                in, same as the platform panel's /profile. */}
                            <Route path="/profile" element={<TenantProfilePage />} />

                            {/* How the software works — open to everybody
                                signed in; a guide holds nothing to gate. */}
                            <Route path="/guide" element={<GuidesPage />} />

                            {/* The organization's own subscription — the owner
                                and billing contacts; the page and its API
                                both say so to anybody else. Open while locked. */}
                            <Route path="/subscription" element={<SubscriptionPage />} />
                            <Route path="/guide/:slug" element={<GuidePage />} />

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
                            <Route element={<RequireCapability capability="customers.record" />}>
                                <Route path="/customers/:id" element={<PatientPage />} />
                            </Route>

                            {/*
                                One patient's history from every module, beside
                                the register, behind its own tick. Each tab
                                inside asks for its own module's capability.
                            */}
                            <Route element={<RequireCapability capability="customers.records_view" />}>
                                <Route path="/patient-records" element={<PatientRecordsPage />} />
                            </Route>

                            {/*
                                The Export Center opens for any one of its four
                                tabs; which tabs show is the server's answer.
                                Column access is the organization's.
                            */}
                            <Route element={<RequireAnyCapability capabilities={EXPORT_CAPABILITIES} />}>
                                <Route path="/exports" element={<ExportCenterPage />} />
                            </Route>
                            <Route element={<RequireCapability capability="exports.columns" />}>
                                <Route path="/exports/columns" element={<ExportColumnsPage />} />
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

                            {/*
                                The Smart Queue, a module of its own: sold to the
                                organization, run per branch, then one tick per
                                screen. The board and a doctor's own queue are the
                                day's work; rooms, tokens and televisions are set
                                up under Settings. A preview sits outside this
                                shell, so it looks the way the wall does.
                            */}
                            <Route element={<RequireModule module="queue" />}>
                                <Route element={<RequireCapability capability="queue.board" />}>
                                    <Route path="/opd/live-queue" element={<QueueBoardPage />} />
                                    <Route path="/opd/live-queue/:doctorId" element={<DoctorQueuePage />} />
                                </Route>
                                <Route element={<RequireCapability capability="queue.my_console" />}>
                                    <Route path="/my-live-queue" element={<DoctorQueuePage />} />
                                </Route>
                                <Route element={<RequireCapability capability="queue.settings" />}>
                                    <Route path="/settings/queue" element={<QueueSettingsPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="queue.displays" />}>
                                    <Route path="/settings/tv-displays" element={<QueueDisplaysPage />} />
                                    <Route path="/settings/tv-displays/new" element={<QueueDisplayFormPage />} />
                                    <Route path="/settings/tv-displays/:id/edit" element={<QueueDisplayFormPage />} />
                                </Route>
                            </Route>

                            {/*
                                The laboratory, a module of its own that needs only
                                the patient register — a diagnostic lab can buy it
                                alone. Its set-up lives under Settings; the page
                                itself only lets lab setup save.
                            */}
                            <Route element={<RequireModule module="laboratory" />}>
                                <Route
                                    element={
                                        <RequireAnyCapability
                                            capabilities={['laboratory.settings_view', 'laboratory.settings_manage']}
                                        />
                                    }
                                >
                                    <Route path="/settings/laboratory" element={<LabSettingsPage />} />
                                </Route>
                            </Route>

                            {/* Two questions for OPD, asked in the same order
                                the server asks them: is the module running
                                here at all, and may this person use it. */}
                            <Route element={<RequireModule module="appointments" />}>
                                {/* A doctor's own screens, one tick each. */}
                                <Route element={<RequireCapability capability="appointments.my_day" />}>
                                    <Route path="/my-day" element={<MyDayPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_queue" />}>
                                    <Route path="/my-queue" element={<MyQueuePage />} />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_consultations" />}>
                                    {/*
                                        Consultations are worked, not read: the
                                        list and the write-up on one screen,
                                        because that is the actual loop.
                                    */}
                                    <Route
                                        path="/my-consultations"
                                        element={<MyConsultationsPage />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_schedule" />}>
                                    <Route path="/my-schedule" element={<MySchedulePage />} />
                                </Route>
                                {/*
                                    Readings of the same rows, so one component
                                    with a prop rather than near-identical files;
                                    one tick each all the same.
                                */}
                                <Route element={<RequireCapability capability="appointments.my_patients" />}>
                                    <Route
                                        path="/my-appointments"
                                        element={<MyRecordsPage view="appointments" />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_prescriptions" />}>
                                    <Route
                                        path="/my-prescriptions"
                                        element={<MyRecordsPage view="prescriptions" />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_investigations" />}>
                                    <Route
                                        path="/my-investigations"
                                        element={<MyRecordsPage view="investigations" />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="appointments.my_follow_ups" />}>
                                    {/* Worked from, not only read: tiles, filters, contact. */}
                                    <Route path="/my-follow-ups" element={<FollowUpsPage />} />
                                </Route>

                                <Route
                                    element={
                                        <RequireCapability capability="appointments.reschedule" />
                                    }
                                >
                                    <Route
                                        path="/opd/reschedule-requests"
                                        element={<RescheduleRequestsPage />}
                                    />
                                </Route>

                                {/* One tick per screen, as the menu shows them. */}
                                <Route
                                    element={<RequireCapability capability="appointments.doctors_view" />}
                                >
                                    <Route path="/doctors" element={<DoctorListPage />} />
                                </Route>

                                <Route
                                    element={<RequireCapability capability="appointments.availability_view" />}
                                >
                                    <Route path="/availability" element={<AvailabilityPage />} />
                                </Route>

                                <Route
                                    element={<RequireCapability capability="appointments.view" />}
                                >
                                    <Route path="/opd" element={<OpdTodayPage />} />
                                </Route>

                                <Route
                                    element={<RequireCapability capability="appointments.queue_view" />}
                                >
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

                                <Route
                                    element={<RequireCapability capability="appointments.settings" />}
                                >
                                    <Route
                                        path="/settings/appointments"
                                        element={<AppointmentSettingsPage />}
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

                            {/* The medicine master: its module, then the
                                capability — reading at a branch, writing for
                                the whole organization. */}
                            <Route element={<RequireModule module="medicines" />}>
                                <Route
                                    element={<RequireCapability capability="medicines.view" />}
                                >
                                    <Route path="/medicines" element={<MedicineListPage />} />
                                </Route>

                                {/* Literal before the :id pattern, as with doctors. */}
                                <Route
                                    element={<RequireCapability capability="medicines.manage" />}
                                >
                                    <Route
                                        path="/medicines/create"
                                        element={<MedicineFormPage />}
                                    />
                                    <Route
                                        path="/medicines/:id/edit"
                                        element={<MedicineFormPage />}
                                    />
                                </Route>
                            </Route>

                            {/* Pharmacy stores: module, then capability. Setting
                                stores up is the organization's; looking at
                                them is the branch's. */}
                            <Route element={<RequireModule module="pharmacy" />}>
                                {/* Literal before the :id pattern. */}
                                <Route
                                    element={<RequireCapability capability="pharmacy.stores" />}
                                >
                                    <Route
                                        path="/pharmacy/stores/create"
                                        element={<StoreFormPage />}
                                    />
                                    <Route
                                        path="/pharmacy/stores/:id/edit"
                                        element={<StoreFormPage />}
                                    />
                                </Route>

                                <Route
                                    element={<RequireCapability capability="pharmacy.inward" />}
                                >
                                    <Route
                                        path="/pharmacy/inwards/create"
                                        element={<InwardFormPage />}
                                    />
                                </Route>

                                {/* The counter itself: only whoever may sell. */}
                                <Route element={<RequireCapability capability="pharmacy.sell" />}>
                                    <Route path="/pharmacy/pos" element={<PosPage />} />
                                </Route>

                                {/*
                                    Its own module, sold separately from the
                                    rest of the pharmacy — no pharmacy row's
                                    tick required here. WHICH of the six reports
                                    somebody may open is decided inside the
                                    page and by the API per request, the same
                                    split `documents.view_clinical` makes
                                    within one screen.
                                */}
                                <Route element={<RequireModule module="reports" />}>
                                    <Route path="/pharmacy/reports" element={<PharmacyReportsPage />} />
                                </Route>

                                {/* One tick per screen, as the menu shows them. */}
                                <Route element={<RequireCapability capability="pharmacy.view" />}>
                                    {/* One store's day, gathered from the
                                        screens below it. */}
                                    <Route
                                        path="/pharmacy/dashboard"
                                        element={<PharmacyDashboardPage />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.bills_view" />}>
                                    <Route path="/pharmacy/sales" element={<SalesListPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.stores_view" />}>
                                    <Route path="/pharmacy/stores" element={<StoreListPage />} />
                                    <Route
                                        path="/pharmacy/stores/:id/medicines"
                                        element={<StoreMedicinesPage />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.inventory_view" />}>
                                    <Route path="/pharmacy/stock" element={<StockPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.purchases_view" />}>
                                    <Route path="/pharmacy/inwards" element={<InwardListPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.movements_view" />}>
                                    <Route path="/pharmacy/movements" element={<MovementsPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="pharmacy.suppliers_view" />}>
                                    <Route path="/pharmacy/suppliers" element={<SuppliersPage />} />
                                </Route>
                                {/* The page itself only lets pharmacy setup save. */}
                                <Route element={<RequireCapability capability="pharmacy.settings_view" />}>
                                    <Route
                                        path="/pharmacy/settings"
                                        element={<PharmacySettingsPage />}
                                    />
                                </Route>
                            </Route>

                            {/*
                                The letterhead. Behind the documents module
                                and the read capability; what somebody may
                                then DO on the screen — edit, publish, lock —
                                is decided inside it from their own
                                capabilities, because the three are held by
                                three different people.
                             */}
                            <Route element={<RequireModule module="documents" />}>
                                <Route
                                    element={<RequireCapability capability="documents.template_view" />}
                                >
                                    <Route
                                        path="/settings/document-templates"
                                        element={<DocumentTemplatesPage />}
                                    />
                                </Route>
                                {/* What every branch's counter makes on its own
                                    is the organisation's call. */}
                                <Route
                                    element={<RequireCapability capability="documents.template_org" />}
                                >
                                    <Route
                                        path="/settings/document-automation"
                                        element={<DocumentAutomationPage />}
                                    />
                                </Route>
                            </Route>

                            {/*
                                Billing. Every route inside asks for the
                                capability too, so the sidebar and the URL
                                agree.
                             */}
                            <Route element={<RequireModule module="billing" />}>
                                {/*
                                    Raising a bill by hand is its own
                                    capability, and the literal path sits
                                    before `invoices/:id` so it is not read
                                    as an invoice called "new".
                                */}
                                <Route element={<RequireCapability capability="billing.create" />}>
                                    <Route
                                        path="/billing/invoices/new"
                                        element={<NewInvoicePage />}
                                    />
                                </Route>

                                {/* Every department's money together — its own capability. */}
                                <Route element={<RequireCapability capability="billing.finance" />}>
                                    <Route path="/billing/finance" element={<FinancePage />} />
                                </Route>

                                {/* The counter's own screens, in the order the
                                    menu lists them, one tick each. */}
                                <Route element={<RequireCapability capability="billing.view" />}>
                                    <Route path="/billing" element={<BillingOverviewPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="billing.invoices_view" />}>
                                    <Route
                                        path="/billing/invoices"
                                        element={<InvoiceListPage />}
                                    />
                                </Route>
                                <Route element={<RequireCapability capability="billing.payments_view" />}>
                                    <Route path="/billing/payments" element={<PaymentsPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="billing.outstanding_view" />}>
                                    <Route
                                        path="/billing/outstanding"
                                        element={<OutstandingPage />}
                                    />
                                </Route>
                                <Route
                                    element={
                                        <RequireAnyCapability
                                            capabilities={['billing.view', 'billing.manage_services']}
                                        />
                                    }
                                >
                                    <Route path="/billing/services" element={<ServicesPage />} />
                                </Route>
                                {/* Under /settings with the rest of the set-up
                                    screens, so only the Settings menu lights up
                                    on it. The old address still lands there for
                                    bookmarks. */}
                                <Route
                                    element={
                                        <RequireAnyCapability
                                            capabilities={['billing.view', 'billing.manage_settings']}
                                        />
                                    }
                                >
                                    <Route
                                        path="/settings/billing"
                                        element={<BillingSettingsPage />}
                                    />
                                    <Route
                                        path="/billing/settings"
                                        element={<Navigate to="/settings/billing" replace />}
                                    />
                                </Route>
                                {/* One invoice opens from any list that names it. */}
                                <Route
                                    element={
                                        <RequireAnyCapability
                                            capabilities={[
                                                'billing.view',
                                                'billing.invoices_view',
                                                'billing.outstanding_view',
                                                'billing.payments_view',
                                            ]}
                                        />
                                    }
                                >
                                    <Route
                                        path="/billing/invoices/:id"
                                        element={<InvoicePage />}
                                    />
                                </Route>
                            </Route>

                            <Route element={<RequireModule module="communication" />}>
                                <Route element={<RequireCapability capability="communication.view" />}>
                                    <Route path="/communication/whatsapp" element={<WhatsAppPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="communication.email" />}>
                                    <Route path="/communication/email" element={<EmailPage />} />
                                </Route>
                                <Route element={<RequireCapability capability="communication.push" />}>
                                    <Route path="/communication/push" element={<PushPage />} />
                                </Route>
                                <Route
                                    element={
                                        <RequireAnyCapability
                                            capabilities={['communication.recipients', 'communication.branch_templates']}
                                        />
                                    }
                                >
                                    <Route path="/communication/recipients" element={<RecipientsPage />} />
                                </Route>

                                {/* Building a campaign is a write, and the
                                    literal `new` sits before the `:id` pattern
                                    so it is not read as a campaign called
                                    "new". */}
                                <Route element={<RequireCapability capability="communication.manage" />}>
                                    <Route
                                        path="/communication/email/campaigns/new"
                                        element={<CampaignWizardPage channel="email" />}
                                    />
                                    <Route
                                        path="/communication/email/campaigns/:id"
                                        element={<CampaignWizardPage channel="email" />}
                                    />
                                    <Route
                                        path="/communication/push/campaigns/new"
                                        element={<CampaignWizardPage key="push-new" channel="push" />}
                                    />
                                    <Route
                                        path="/communication/push/campaigns/:id"
                                        element={<CampaignWizardPage key="push-edit" channel="push" />}
                                    />
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

                            {/* Level three of the permission flow, behind its own
                                tick. Writing is decided per role on the server. */}
                            <Route element={<RequireCapability capability="people.roles_view" />}>
                                <Route path="/roles" element={<RolesPage />} />
                            </Route>

                            {/* Which modules run where — the owner's alone,
                                as every API route behind it is. */}
                            <Route element={<RequireOwner />}>
                                <Route path="/settings/modules" element={<ModuleSettingsPage />} />
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

                            {/* Departments and their sub-departments. */}
                            <Route element={<RequireCapability capability="settings.departments" />}>
                                <Route path="/departments" element={<DepartmentsPage />} />
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

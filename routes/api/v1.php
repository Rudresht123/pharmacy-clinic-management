<?php

use App\Http\Controllers\Api\V1\Onboarding\OrganizationSetupController;
use App\Http\Controllers\Api\V1\Platform\AuditController;
use App\Http\Controllers\Api\V1\Platform\Auth\AuthController;
use App\Http\Controllers\Api\V1\Platform\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Platform\ModuleController;
use App\Http\Controllers\Api\V1\Platform\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\OrganizationModuleController;
use App\Http\Controllers\Api\V1\Platform\OrganizationTypeController;
use App\Http\Controllers\Api\V1\Platform\MessagingSettingsController;
use App\Http\Controllers\Api\V1\Tenant\AppointmentController;
use App\Http\Controllers\Api\V1\Tenant\AutomationRuleController;
use App\Http\Controllers\Api\V1\Tenant\BillableServiceController;
use App\Http\Controllers\Api\V1\Tenant\BillingOverviewController;
use App\Http\Controllers\Api\V1\Tenant\BillingSettingController;
use App\Http\Controllers\Api\V1\Tenant\InvoiceController;
use App\Http\Controllers\Api\V1\Tenant\InvoicePaymentController;
use App\Http\Controllers\Api\V1\Tenant\CampaignController;
use App\Http\Controllers\Api\V1\Tenant\CommunicationController;
use App\Http\Controllers\Api\V1\Tenant\MessageTemplateController;
use App\Http\Controllers\Api\V1\Tenant\Auth\AuthController as TenantAuthController;
use App\Http\Controllers\Api\V1\Tenant\AvailabilityController;
use App\Http\Controllers\Api\V1\Tenant\BrandingController;
use App\Http\Controllers\Api\V1\Tenant\ConsultationController;
use App\Http\Controllers\Api\V1\Tenant\CustomerController;
use App\Http\Controllers\Api\V1\Tenant\DashboardController;
use App\Http\Controllers\Api\V1\Tenant\DepartmentController;
use App\Http\Controllers\Api\V1\Tenant\DoctorController;
use App\Http\Controllers\Api\V1\Tenant\DoctorScheduleController;
use App\Http\Controllers\Api\V1\Tenant\FieldSettingController;
use App\Http\Controllers\Api\V1\Tenant\HistoryController;
use App\Http\Controllers\Api\V1\Tenant\LocationController;
use App\Http\Controllers\Api\V1\Tenant\BranchRolePermissionController;
use App\Http\Controllers\Api\V1\Tenant\EffectivePermissionController;
use App\Http\Controllers\Api\V1\Tenant\LocationModuleController;
use App\Http\Controllers\Api\V1\Tenant\ModuleLockController;
use App\Http\Controllers\Api\V1\Tenant\LabOrderController;
use App\Http\Controllers\Api\V1\Tenant\LabTestCatalogController;
use App\Http\Controllers\Api\V1\Tenant\MedicineAvailabilityController;
use App\Http\Controllers\Api\V1\Tenant\MedicineBatchController;
use App\Http\Controllers\Api\V1\Tenant\MedicineController;
use App\Http\Controllers\Api\V1\Tenant\OpdController;
use App\Http\Controllers\Api\V1\Tenant\DocumentGenerationController;
use App\Http\Controllers\Api\V1\Tenant\DocumentTemplateController;
use App\Http\Controllers\Api\V1\Tenant\LocationManagerController;
use App\Http\Controllers\Api\V1\Tenant\PatientDocumentController;
use App\Http\Controllers\Api\V1\Tenant\PharmacyDashboardController;
use App\Http\Controllers\Api\V1\Tenant\PharmacyReportController;
use App\Http\Controllers\Api\V1\Tenant\PharmacySaleController;
use App\Http\Controllers\Api\V1\Tenant\PharmacySettingController;
use App\Http\Controllers\Api\V1\Tenant\PharmacyStoreController;
use App\Http\Controllers\Api\V1\Tenant\PincodeController;
use App\Http\Controllers\Api\V1\Tenant\PrescriptionController;
use App\Http\Controllers\Api\V1\Tenant\RoleController;
use App\Http\Controllers\Api\V1\Tenant\SetupController;
use App\Http\Controllers\Api\V1\Tenant\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Tenant\StockInwardController;
use App\Http\Controllers\Api\V1\Tenant\StockMovementController;
use App\Http\Controllers\Api\V1\Tenant\StockTransferController;
use App\Http\Controllers\Api\V1\Tenant\StoreMedicineController;
use App\Http\Controllers\Api\V1\Tenant\SupplierController;
use App\Http\Controllers\Api\V1\Tenant\UserController as TenantUserController;
use App\Http\Controllers\Api\V1\Tenant\UserPermissionController;
use App\Http\Controllers\Api\V1\Tenant\WorkspaceLookupController;
use App\Services\Pharmacy\PharmacyReports;
use App\Services\Tenant\OrganizationSetup;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted under /api/v1 by routes/api.php, which is where other versions are
| mounted too. The browser authenticates on the stateful Sanctum session it
| shares with the SPA; the mobile app on a bearer token plus X-Organization.
|
| Four areas live here:
|
|   /api/v1/organization-setup/*   public, token from the invitation email
|   /api/v1/admin/*                the landlord panel, `platform` guard
|   /api/v1/tenant/*               an organization's own staff, `web` guard
|   /api/v1/tenant/portal/*        a patient's own data (routes/api/v1/portal.php)
|
*/

/*
| Public — organization owner completing their invitation ------------------
*/
Route::prefix('organization-setup')->group(function () {
    Route::get('{token}', [OrganizationSetupController::class, 'show']);
    Route::post('{token}', [OrganizationSetupController::class, 'store']);
});

/*
| Super Admin panel — Build Spec §19 --------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {

    /*
    | Signed out
    */
    Route::middleware('guest:platform')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

        Route::post('auth/forgot-password', [PasswordController::class, 'forgot'])
            ->middleware('throttle:6,1');

        Route::post('auth/reset-password', [PasswordController::class, 'reset']);

        /*
         * There is deliberately no registration route. §18: administrators
         * are created by administrators, never by self-signup.
         */
    });

    /*
    | Signed in
    */
    Route::middleware('auth:platform')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);

        Route::post('auth/confirm-password', [PasswordController::class, 'confirm']);
        Route::put('auth/password', [PasswordController::class, 'update']);

        /*
        | The module catalogue, with adoption. Read-only: it mirrors
        | ModuleRegistry and modules:sync would revert any edit.
        */
        /*
        | The audit trail. Read-only by design: rows arrive from the
        | RecordsHistory trait on the models, never from an endpoint, so a
        | change made by a console command is recorded like any other.
        */
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/filters', [AuditController::class, 'filters'])->name('audit.filters');

        Route::get(
            'organizations/{organization}/history',
            [AuditController::class, 'forOrganization']
        )->name('organizations.history');

        // Who has what — the list an administrator assigning one starts from.
        Route::get('modules/organizations', [ModuleController::class, 'organizations'])
            ->name('modules.organizations');

        Route::get('modules', [ModuleController::class, 'index'])->name('modules.index');

        Route::apiResource('organizations', OrganizationController::class);

        Route::post(
            'organizations/{organization}/retry-provisioning',
            [OrganizationController::class, 'retryProvisioning']
        );

        /*
        | Module entitlements — what an organization has been sold.
        |
        | The outer half of the permission model: this decides what an
        | organization is able to do. Who inside it may do each thing is the
        | organization's own business, answered later by roles built on the
        | capability pool these produce.
        */
        Route::get(
            'organizations/{organization}/modules',
            [OrganizationModuleController::class, 'index']
        )->name('organizations.modules.index');

        Route::put(
            'organizations/{organization}/modules',
            [OrganizationModuleController::class, 'update']
        )->name('organizations.modules.update');

        // Explicit parameter name: the default would be {organization_type},
        // which does not match the $organizationType controller argument.
        Route::apiResource('organization-types', OrganizationTypeController::class)
            ->parameters(['organization-types' => 'organizationType']);

        Route::patch(
            'organization-types/{organizationType}/toggle-status',
            [OrganizationTypeController::class, 'toggleStatus']
        );

        /*
        | The sending accounts, for the whole platform.
        |
        | Here rather than on a tenant because there is ONE account with each
        | provider — one WhatsApp number patients see, one mail relay — so a
        | clinic setting these would be changing what every other clinic sends
        | through.
        */
        Route::get('messaging/{channel}', [MessagingSettingsController::class, 'show'])
            ->name('messaging.show');
        Route::put('messaging/{channel}', [MessagingSettingsController::class, 'update'])
            ->name('messaging.update');
        Route::post('messaging/{channel}/test', [MessagingSettingsController::class, 'test'])
            ->name('messaging.test');
    });
});

/*
| An organization's own staff — `web` guard --------------------------------
*/
Route::prefix('tenant')->name('tenant.')->group(function () {
    // Public: the login screen needs this before anyone has signed in.
    Route::get('branding', [BrandingController::class, 'show'])->name('branding');

    /*
     * The same question the branding endpoint answers, asked the other way
     * round. A browser is already on the tenant's subdomain, so branding can
     * read it off the Host header; a phone app has no host until it knows
     * which organization it is talking to, so it asks by code instead.
     *
     * Throttled because it is the one route that will confirm whether a code
     * exists. The limit is per IP and generous enough that nobody typing their
     * own code will ever meet it.
     */
    Route::get('workspace/{code}', [WorkspaceLookupController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('workspace.lookup');

    /*
     * resolve.tenant runs ahead of guest:web on purpose. `guest` asks the web
     * guard whether anyone is signed in, and that lookup hits the tenant
     * database — with no tenant connected it queries an empty database and
     * blows up on "relation users does not exist". With no session at all
     * resolve.tenant is a no-op, so the pre-login path is unaffected.
     */
    Route::middleware(['resolve.tenant', 'guest:web'])->group(function () {
        Route::post('auth/login', [TenantAuthController::class, 'login'])->name('auth.login');
    });

    /*
     * The same sign-in for clients that cannot hold a cookie.
     *
     * Deliberately outside the `guest:web` group: that guard asks the session
     * whether anyone is signed in, and a phone has no session for it to ask.
     * Throttled at the route because it is a credential endpoint reachable
     * without one -- LoginRequest rate limits per address too, and this is the
     * per-IP floor underneath it.
     */
    Route::post('auth/token', [TenantAuthController::class, 'token'])
        ->middleware('throttle:10,1')
        ->name('auth.token');

    // A patient's own sign-in and data. Its own file, and its own URL
    // segment, so no staff route can ever be reached by adding a portal
    // capability to a role — and the reverse.
    Route::prefix('portal')->name('portal.')->group(__DIR__.'/v1/portal.php');

    /*
     * `branch` runs after the guard and before every gate: somebody who works
     * at two branches has different permissions at each, so which one this
     * request is in has to be settled — and checked against their memberships
     * — before anything asks what they may do.
     */
    /*
     * Two ways in, one set of routes.
     *
     * `resolve.tenant` connects the database from the session; its header
     * sibling does the same from X-Organization. Each is a no-op when its own
     * input is absent, so a browser takes the first and a phone the second
     * without either knowing about the other. `auth:web,tenant-api` then
     * accepts whichever guard actually holds a signed-in user, and
     * `tenant.actor` makes a token user visible to the gates that ask the
     * session guard — see App\Http\Middleware\AdoptTokenUser.
     *
     * Duplicating every route for the mobile client was the alternative, and
     * it would have meant every future gate being added in two places -- which
     * is how one of them ends up missing.
     */
    Route::middleware([
        'resolve.tenant',
        'resolve.tenant.header',
        'auth:web,tenant-api',
        'tenant.actor',
        'branch',
    ])->group(function () {
        Route::get('auth/me', [TenantAuthController::class, 'me'])->name('auth.me');
        Route::delete('auth/token', [TenantAuthController::class, 'revokeToken'])
            ->name('auth.token.revoke');

        /*
        | The workspace's first screen. Ungated here because anybody who may
        | sign in may open their own dashboard; every panel inside it is gated
        | on its own capability and narrowed to their branches.
        */
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('auth/logout', [TenantAuthController::class, 'logout']);

        /*
        | Reference lookups: facts about the world rather than about this
        | organization, so ungated beyond being signed in. Any address form
        | can use them.
        |
        | The pattern keeps anything that is not a PIN code from ever reaching
        | India Post, and the throttle keeps one browser from turning this
        | into a scraper — a found code is cached, a miss is not free.
        */
        Route::get('lookups/pincode/{pincode}', [PincodeController::class, 'show'])
            ->where('pincode', '[1-9][0-9]{5}')
            ->middleware('throttle:60,1')
            ->name('lookups.pincode');

        /*
        | Locations.
        |
        | `permission:` where `tenant.owner` used to be. The owner bypasses the
        | role check, so nothing changes for them; what changes is that the
        | owner can now hand branch management to somebody without making them
        | an owner. Every route below reads the same three levels — see
        | App\Services\Permissions\Permission.
        */
        Route::get('locations/fields', [LocationController::class, 'fields'])
            ->middleware('permission:branches.view')
            ->name('locations.fields');
        Route::get('locations', [LocationController::class, 'index'])
            ->middleware('permission:branches.view')
            ->name('locations.index');
        Route::get('locations/{location}', [LocationController::class, 'show'])
            ->middleware('permission:branches.view')
            ->name('locations.show');

        /*
        | Field settings — the forms need to read them, the owner writes them.
        | {entity} is validated against FieldRegistry, so adding a
        | configurable screen needs no route change.
        */
        Route::get('settings/fields', [FieldSettingController::class, 'index'])
            ->name('settings.fields.index');
        Route::get('settings/fields/{entity}', [FieldSettingController::class, 'show'])
            ->name('settings.fields.show');

        /*
        | Customers. Serving one and removing one are different permissions:
        | the first is what the desk does all day, the second is not.
        */
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('customers/fields', [CustomerController::class, 'fields'])
                ->name('customers.fields');
            Route::get('customers/stats', [CustomerController::class, 'stats'])
                ->name('customers.stats');
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])
                ->name('customers.show');
        });

        /*
        | Adding, changing and removing are three capabilities rather than one
        | `manage`. "Can take a new patient but not edit an existing record"
        | and "can edit but not delete" are both ordinary things to want, and
        | a single key could not express either.
        */
        Route::post('customers', [CustomerController::class, 'store'])
            ->middleware('permission:customers.create')
            ->name('customers.store');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('permission:customers.edit')
            ->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('permission:customers.delete')
            ->name('customers.destroy');

        /*
        | The medicine master — behind its own module, which prescriptions and
        | pharmacy both require.
        |
        | Reading is branch-scoped (a doctor searching); writing is the
        | organization's, because the catalogue is shared by every branch.
        | Restoring a removed medicine is `pharmacy.restore`, the capability
        | that restores every removed pharmacy record. Literal paths before
        | {medicine}.
        */
        Route::middleware('module:medicines')->group(function () {
            Route::middleware('permission:medicines.view')->group(function () {
                Route::get('medicines/fields', [MedicineController::class, 'fields'])
                    ->name('medicines.fields');
                Route::get('medicines/overview', [MedicineController::class, 'overview'])
                    ->name('medicines.overview');
                Route::get('medicines', [MedicineController::class, 'index'])
                    ->name('medicines.index');
            });

            Route::middleware('permission:pharmacy.restore')->group(function () {
                Route::get('medicines/removed', [MedicineController::class, 'removed'])
                    ->name('medicines.removed');
                Route::post('medicines/{medicine}/restore', [MedicineController::class, 'restore'])
                    ->withTrashed()
                    ->name('medicines.restore');
            });

            Route::get('medicines/{medicine}', [MedicineController::class, 'show'])
                ->middleware('permission:medicines.view')
                ->name('medicines.show');
            // Stock of one medicine at every store the caller works at.
            Route::get('medicines/{medicine}/availability', [MedicineAvailabilityController::class, 'forMedicine'])
                ->middleware('permission:medicines.view')
                ->name('medicines.availability');

            Route::middleware('permission:medicines.manage')->group(function () {
                Route::post('medicines', [MedicineController::class, 'store'])
                    ->name('medicines.store');
                Route::put('medicines/{medicine}', [MedicineController::class, 'update'])
                    ->name('medicines.update');
                Route::delete('medicines/{medicine}', [MedicineController::class, 'destroy'])
                    ->name('medicines.destroy');
            });
        });

        /*
        | Pharmacy stores and what each stocks — behind the pharmacy module.
        |
        | The middleware asks about the acting branch; PharmacyStorePolicy asks
        | again about the store's own branch, which is the one a store belongs
        | to. Creating and changing stores is organization-scoped
        | (`pharmacy.stores`); reading is the branch's (`pharmacy.view`).
        | Literal paths before {store}.
        */
        Route::middleware('module:pharmacy')->group(function () {
            /*
            | How the organisation bills, prices and warns. Everyone at a
            | counter reads them — the POS prices and warns from them — and
            | only pharmacy setup changes them.
            */
            Route::get('pharmacy/settings', [PharmacySettingController::class, 'show'])
                ->middleware('permission:pharmacy.view')
                ->name('pharmacy.settings.show');
            Route::put('pharmacy/settings', [PharmacySettingController::class, 'update'])
                ->middleware('permission:pharmacy.stores')
                ->name('pharmacy.settings.update');

            Route::middleware('permission:pharmacy.stores')->group(function () {
                Route::get('pharmacy-stores/form-options', [PharmacyStoreController::class, 'formOptions'])
                    ->name('pharmacy-stores.form-options');
                Route::post('pharmacy-stores', [PharmacyStoreController::class, 'store'])
                    ->name('pharmacy-stores.store');
                Route::put('pharmacy-stores/{store}', [PharmacyStoreController::class, 'update'])
                    ->name('pharmacy-stores.update');
                Route::delete('pharmacy-stores/{store}', [PharmacyStoreController::class, 'destroy'])
                    ->name('pharmacy-stores.destroy');

                Route::put('pharmacy-stores/{store}/medicines', [StoreMedicineController::class, 'update'])
                    ->name('pharmacy-stores.medicines.update');
                Route::delete(
                    'pharmacy-stores/{store}/medicines/{storeMedicine}',
                    [StoreMedicineController::class, 'destroy']
                )->name('pharmacy-stores.medicines.destroy');
            });

            Route::middleware('permission:pharmacy.restore')->group(function () {
                Route::get('pharmacy-stores/removed', [PharmacyStoreController::class, 'removed'])
                    ->name('pharmacy-stores.removed');
                Route::post('pharmacy-stores/{store}/restore', [PharmacyStoreController::class, 'restore'])
                    ->withTrashed()
                    ->name('pharmacy-stores.restore');
            });

            /*
            | The list itself is behind `pharmacy.view` OR any one report —
            | the Reports screen has to know which store it is reporting on
            | before it can ask for anything, and a role holding only reports
            | must not be locked out of the one call that picks the store. A
            | single store's OWN detail and its medicine list stay behind
            | `pharmacy.view` alone: those answer questions a report never
            | asks.
            */
            Route::get('pharmacy-stores', [PharmacyStoreController::class, 'index'])
                ->middleware('permission:pharmacy.view,'.implode(',', PharmacyReports::CAPABILITIES))
                ->name('pharmacy-stores.index');

            Route::middleware('permission:pharmacy.view')->group(function () {
                Route::get('pharmacy-stores/{store}', [PharmacyStoreController::class, 'show'])
                    ->name('pharmacy-stores.show');
                Route::get('pharmacy-stores/{store}/medicines', [StoreMedicineController::class, 'index'])
                    ->name('pharmacy-stores.medicines.index');
            });

            // What a store holds, for a doctor choosing what to prescribe.
            Route::get('pharmacy-stores/{store}/availability', [MedicineAvailabilityController::class, 'atStore'])
                ->middleware('permission:medicines.view')
                ->name('pharmacy-stores.availability');

            /*
            | Suppliers: organization-wide, so no store Policy — the capability
            | is the whole question. Literal paths before {supplier}.
            */
            Route::get('suppliers/removed', [SupplierController::class, 'removed'])
                ->middleware('permission:pharmacy.restore')
                ->name('suppliers.removed');
            Route::post('suppliers/{supplier}/restore', [SupplierController::class, 'restore'])
                ->withTrashed()
                ->middleware('permission:pharmacy.restore')
                ->name('suppliers.restore');

            Route::middleware('permission:pharmacy.view')->group(function () {
                Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
                Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
            });

            Route::middleware('permission:pharmacy.stores')->group(function () {
                Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
                Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
                Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
            });

            /*
            | Stock. Every quantity change goes through StockMovementService;
            | these routes only choose which document causes it. Stock-changing
            | POSTs require an Idempotency-Key header.
            */
            Route::middleware('permission:pharmacy.view')->group(function () {
                /*
                | One store's day: takings, purchases, what the shelf is worth
                | and what is about to stop being sellable. Read-only, and
                | nothing on it that the stock and bill screens do not already
                | answer one at a time.
                */
                Route::get('pharmacy-stores/{store}/dashboard', [PharmacyDashboardController::class, 'show'])
                    ->name('pharmacy-stores.dashboard');

                Route::get('pharmacy-stores/{store}/stock', [MedicineBatchController::class, 'stock'])
                    ->name('pharmacy-stores.stock');

                // The counter's category chips, scoped to what this store stocks.
                Route::get('pharmacy-stores/{store}/categories', [MedicineBatchController::class, 'categories'])
                    ->name('pharmacy-stores.categories');
                Route::get('pharmacy-stores/{store}/batches', [MedicineBatchController::class, 'index'])
                    ->name('pharmacy-stores.batches');
                Route::get('pharmacy-stores/{store}/movements', [StockMovementController::class, 'index'])
                    ->name('pharmacy-stores.movements');
                Route::get('pharmacy-stores/{store}/inwards', [StockInwardController::class, 'index'])
                    ->name('pharmacy-stores.inwards.index');
                Route::get('pharmacy-stores/{store}/adjustments', [StockAdjustmentController::class, 'index'])
                    ->name('pharmacy-stores.adjustments.index');
                Route::get('pharmacy-stores/{store}/transfers', [StockTransferController::class, 'index'])
                    ->name('pharmacy-stores.transfers.index');

                Route::get('pharmacy-stores/{store}/sales', [PharmacySaleController::class, 'index'])
                    ->name('pharmacy-stores.sales.index');
            });

            /*
            | Reports — its OWN module, sold separately from the rest of
            | pharmacy and requiring it (ModuleRegistry: `reports.requires =
            | ['pharmacy']`), so a superadmin binds it to an organization the
            | same way as any other module rather than it riding along free
            | with `pharmacy.view`.
            |
            | `permission:` is deliberately NOT on this group: which of the
            | six reports somebody may open is a SEPARATE capability per
            | report (`reports.sales`, `reports.profit`, …), decided inside
            | the controller against the STORE'S branch — the same split
            | `documents.view` / `documents.view_clinical` makes within one
            | screen, because a role holding one report has no business
            | reading another just for sharing a route.
            |
            | Reports, each readable two ways: the summary is the shape of it,
            | the rows are the evidence. Same filters, same query underneath,
            | so one is the sum of the other.
            */
            Route::middleware('module:reports')->group(function () {
                Route::get('pharmacy-stores/{store}/reports/{report}/summary', [PharmacyReportController::class, 'summary'])
                    ->whereIn('report', PharmacyReports::REPORTS)
                    ->name('pharmacy-stores.reports.summary');
                Route::get('pharmacy-stores/{store}/reports/{report}', [PharmacyReportController::class, 'rows'])
                    ->whereIn('report', PharmacyReports::REPORTS)
                    ->name('pharmacy-stores.reports.rows');
            });

            Route::middleware('permission:pharmacy.view')->group(function () {
                Route::get('batches/{batch}', [MedicineBatchController::class, 'show'])->name('batches.show');
                Route::get('inwards/{inward}', [StockInwardController::class, 'show'])->name('inwards.show');
                Route::get('sales/{sale}', [PharmacySaleController::class, 'show'])->name('sales.show');
                Route::get('stock-transfers/{transfer}', [StockTransferController::class, 'show'])
                    ->name('stock-transfers.show');
            });

            /*
            | The counter. One path for both ways the pharmacy sells: a sale
            | with no prescription behind it, and a dispensing, which is the
            | same bill carrying one. Cancelling is its own capability —
            | it puts stock back and takes money off the day's takings.
            */
            /*
             * Either key opens the door; the controller decides which one
             * this bill actually needed. A bill with a prescription behind
             * it credits prescribed lines and can close a visit, so it asks
             * `pharmacy.dispense`; a plain counter sale asks `pharmacy.sell`.
             * Listing both here rather than splitting the route keeps one
             * path for both ways the pharmacy sells, which is what stops a
             * till and a shelf disagreeing.
             */
            Route::post('pharmacy-stores/{store}/sales', [PharmacySaleController::class, 'store'])
                ->middleware('permission:pharmacy.sell,pharmacy.dispense')
                ->name('pharmacy-stores.sales.store');
            Route::post('sales/{sale}/cancel', [PharmacySaleController::class, 'cancel'])
                ->middleware('permission:pharmacy.sale_cancel')
                ->name('sales.cancel');

            Route::post('pharmacy-stores/{store}/inwards', [StockInwardController::class, 'store'])
                ->middleware('permission:pharmacy.inward')
                ->name('pharmacy-stores.inwards.store');
            Route::post('inwards/{inward}/cancel', [StockInwardController::class, 'cancel'])
                ->middleware('permission:pharmacy.adjust')
                ->name('inwards.cancel');
            Route::post('pharmacy-stores/{store}/adjustments', [StockAdjustmentController::class, 'store'])
                ->middleware('permission:pharmacy.adjust')
                ->name('pharmacy-stores.adjustments.store');
            Route::post('stock-transfers', [StockTransferController::class, 'store'])
                ->middleware('permission:pharmacy.transfer')
                ->name('stock-transfers.store');

            Route::middleware('permission:pharmacy.batches')->group(function () {
                Route::patch('batches/{batch}/status', [MedicineBatchController::class, 'updateStatus'])
                    ->name('batches.status');
                Route::delete('batches/{batch}', [MedicineBatchController::class, 'destroy'])
                    ->name('batches.destroy');
            });

            Route::post('batches/{batch}/restore', [MedicineBatchController::class, 'restore'])
                ->withTrashed()
                ->middleware('permission:pharmacy.restore')
                ->name('batches.restore');
        });

        /*
        | Billing.
        |
        | Core module — an organisation always has it — and independent of
        | pharmacy and lab. A clinic that dispenses nothing still bills for
        | its consultations; a shop's own bills stay under `pharmacy.sell`.
        |
        | Reading is `billing.view`; drawing an invoice is `billing.create`;
        | rewriting an unpaid one is `billing.edit`; cancelling is
        | `billing.cancel`; taking money is `billing.collect_payment`;
        | refunding is `billing.refund`. Settings and the named-services
        | catalogue are organisation-scoped, so they live at head office
        | (`billing.manage_settings` / `billing.manage_services`).
        */
        Route::middleware('module:billing')->group(function () {
            Route::middleware('permission:billing.view')->group(function () {
                /*
                | The counter's own two screens. `overview` is the dashboard —
                | cards, trend, status breakdown and recent bills in one
                | request, because they are read together. `payments` is the
                | register a till reconciles a drawer against.
                |
                | Both before `invoices/{invoice}`, though neither collides
                | with it — kept adjacent so the group reads as one screen's
                | worth of endpoints.
                */
                Route::get('billing/overview', [BillingOverviewController::class, 'show'])
                    ->name('billing.overview');
                Route::get('billing/payments', [BillingOverviewController::class, 'payments'])
                    ->name('billing.payments');

                Route::get('invoices', [InvoiceController::class, 'index'])
                    ->name('invoices.index');
                Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
                    ->name('invoices.show');
                Route::get('billable-services', [BillableServiceController::class, 'index'])
                    ->name('billable-services.index');
                Route::get('billing/settings', [BillingSettingController::class, 'show'])
                    ->name('billing.settings.show');
            });

            Route::post('invoices', [InvoiceController::class, 'store'])
                ->middleware('permission:billing.create')
                ->name('invoices.store');

            /*
            | Closing a visit's bill. `bill-visit` gathers a visit's charges
            | and finalizes in one step (for the manual-trigger clinic);
            | `finalize` closes a draft that a trigger already opened. Both
            | are `billing.create` — deciding a bill is complete is the same
            | authority as raising one.
            |
            | Literal paths before {invoice}, else they read as invoice ids.
            */
            Route::post('invoices/bill-visit', [InvoiceController::class, 'billVisit'])
                ->middleware('permission:billing.create')
                ->name('invoices.bill-visit');
            Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])
                ->middleware('permission:billing.create')
                ->name('invoices.finalize');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
                ->middleware('permission:billing.edit')
                ->name('invoices.update');
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
                ->middleware('permission:billing.cancel')
                ->name('invoices.cancel');

            Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])
                ->middleware('permission:billing.collect_payment')
                ->name('invoices.payments.store');
            Route::post('invoices/{invoice}/payments/{payment}/refund', [InvoicePaymentController::class, 'refund'])
                ->middleware('permission:billing.refund')
                ->name('invoices.payments.refund');

            Route::put('billing/settings', [BillingSettingController::class, 'update'])
                ->middleware('permission:billing.manage_settings')
                ->name('billing.settings.update');

            Route::middleware('permission:billing.manage_services')->group(function () {
                Route::post('billable-services', [BillableServiceController::class, 'store'])
                    ->name('billable-services.store');
                Route::put('billable-services/{service}', [BillableServiceController::class, 'update'])
                    ->name('billable-services.update');
                Route::delete('billable-services/{service}', [BillableServiceController::class, 'destroy'])
                    ->name('billable-services.destroy');

                /*
                | The lab's price list lives under BILLING, not the lab.
                |
                | What the clinic charges for a test is a billing decision —
                | the same organisation-wide key that owns every other price.
                | A technician who may sign a result off has no business
                | repricing it. Reading the list is the doctor's, below.
                */
                Route::post('lab-test-catalog', [LabTestCatalogController::class, 'store'])
                    ->name('lab-test-catalog.store');
                Route::put('lab-test-catalog/{test}', [LabTestCatalogController::class, 'update'])
                    ->name('lab-test-catalog.update');
                Route::delete('lab-test-catalog/{test}', [LabTestCatalogController::class, 'destroy'])
                    ->name('lab-test-catalog.destroy');
            });
        });

        /*
        | Reading the lab's price list — the order screen picks from it.
        |
        | Outside the billing group on purpose: a doctor orders tests without
        | holding any billing capability, and the list is what they choose
        | from. Behind the laboratory module, because a clinic that refers its
        | bloods out has no list to read.
        */
        Route::middleware(['module:laboratory', 'permission:laboratory.order'])->group(function () {
            Route::get('lab-test-catalog', [LabTestCatalogController::class, 'index'])
                ->name('lab-test-catalog.index');
        });

        /*
        | Prescriptions — one structured document per visit.
        |
        | The route holds the capability at the acting branch;
        | PrescriptionPolicy asks about the prescription's own branch and,
        | for writing, whether this is the doctor who saw the patient.
        | Editing only while a draft; an issued one is cancelled with a
        | reason, never deleted.
        */
        Route::middleware('module:prescriptions')->group(function () {
            Route::middleware('permission:prescriptions.view')->group(function () {
                Route::get('prescriptions', [PrescriptionController::class, 'index'])
                    ->name('prescriptions.index');
                Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show'])
                    ->name('prescriptions.show');
                Route::get('appointments/{appointment}/prescription', [PrescriptionController::class, 'forAppointment'])
                    ->name('appointments.prescription');
            });

            Route::middleware('permission:prescriptions.write')->group(function () {
                Route::post('prescriptions', [PrescriptionController::class, 'store'])
                    ->name('prescriptions.store');
                Route::put('prescriptions/{prescription}', [PrescriptionController::class, 'update'])
                    ->name('prescriptions.update');
                Route::post('prescriptions/{prescription}/issue', [PrescriptionController::class, 'issue'])
                    ->name('prescriptions.issue');
                Route::delete('prescriptions/{prescription}', [PrescriptionController::class, 'destroy'])
                    ->name('prescriptions.destroy');
            });

            Route::post('prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel'])
                ->middleware('permission:prescriptions.cancel')
                ->name('prescriptions.cancel');
        });

        /*
        |----------------------------------------------------------------------
        | Laboratory
        |----------------------------------------------------------------------
        |
        | The other thing a consultation produces, and it gates the visit the
        | same way a prescription does.
        |
        | THREE AUDIENCES, told apart by capability rather than by prefix.
        | The doctor orders (`laboratory.order`, plus the Policy's check that
        | the visit is theirs). The bench takes the work on and enters
        | readings (`laboratory.process`). Signing off and withdrawing are
        | held apart (`laboratory.complete`), because a completed order is
        | what a doctor will act on and what lets the visit close — plenty of
        | labs want that with somebody senior to whoever ran the sample.
        | Reading is wider than all three: the desk is asked "are my results
        | back" all day.
        |
        | Results are recorded PER LINE. A CBC back in twenty minutes and an
        | LFT back tomorrow are one order and two results, and an endpoint
        | that took them together would make the bench hold the first.
        */
        Route::middleware('module:laboratory')->group(function () {
            Route::middleware('permission:laboratory.view')->group(function () {
                Route::get('lab-orders', [LabOrderController::class, 'index'])
                    ->name('lab-orders.index');
                Route::get('lab-orders/{order}', [LabOrderController::class, 'show'])
                    ->name('lab-orders.show');
                Route::get('appointments/{appointment}/lab-orders', [LabOrderController::class, 'forAppointment'])
                    ->name('appointments.lab-orders');
            });

            Route::middleware('permission:laboratory.order')->group(function () {
                Route::post('lab-orders', [LabOrderController::class, 'store'])
                    ->name('lab-orders.store');
                Route::put('lab-orders/{order}', [LabOrderController::class, 'update'])
                    ->name('lab-orders.update');
            });

            Route::middleware('permission:laboratory.process')->group(function () {
                Route::post('lab-orders/{order}/process', [LabOrderController::class, 'process'])
                    ->name('lab-orders.process');
                Route::put('lab-orders/{order}/items/{item}/result', [LabOrderController::class, 'record'])
                    ->name('lab-orders.items.result');
                Route::post('lab-orders/{order}/items/{item}/cancel', [LabOrderController::class, 'cancelLine'])
                    ->name('lab-orders.items.cancel');
            });

            Route::middleware('permission:laboratory.complete')->group(function () {
                Route::post('lab-orders/{order}/complete', [LabOrderController::class, 'complete'])
                    ->name('lab-orders.complete');
                Route::post('lab-orders/{order}/cancel', [LabOrderController::class, 'cancel'])
                    ->name('lab-orders.cancel');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Patient documents
        |----------------------------------------------------------------------
        |
        | Scans, reports and letters kept against a patient or a visit.
        |
        | `documents.view` is the ticket into the folder and reaches the
        | administrative files — ID, insurance, consent, bills. The medical
        | ones need `documents.view_clinical` ON TOP, which the controller
        | asks per record because it depends on the document's category
        | rather than on which URL was called. A desk and a pharmacist both
        | hold the first; only clinical staff hold the second.
        |
        | The bytes are on the private disk and leave through `download`
        | alone, which re-asks the same question — there is no public URL to
        | leak, and no signed link that would skip the check silently.
        */
        Route::middleware('module:documents')->group(function () {
            Route::middleware('permission:documents.view')->group(function () {
                Route::get('document-categories', [PatientDocumentController::class, 'categories'])
                    ->name('documents.categories');
                Route::get('customers/{customer}/documents', [PatientDocumentController::class, 'index'])
                    ->name('customers.documents.index');
                Route::get('appointments/{appointment}/documents', [PatientDocumentController::class, 'forAppointment'])
                    ->name('appointments.documents.index');
                Route::get('documents/{document}/download', [PatientDocumentController::class, 'download'])
                    ->name('documents.download');
            });

            Route::post('customers/{customer}/documents', [PatientDocumentController::class, 'store'])
                ->middleware('permission:documents.upload')
                ->name('customers.documents.store');

            Route::delete('documents/{document}', [PatientDocumentController::class, 'destroy'])
                ->middleware('permission:documents.delete')
                ->name('documents.destroy');

            /*
            | The letterhead — what a printed document looks like, per branch.
            |
            | `documents.template_view` to read, `_edit` to save a draft,
            | `_publish` to put one into use. Which BRANCH each of those
            | reaches is decided per template in TemplateAuthority, because
            | "the organisation's default" and "this branch's copy" are
            | different answers no middleware can give.
            |
            | Literal paths before {template}.
            */
            Route::middleware('permission:documents.template_view')->group(function () {
                Route::get('document-types', [DocumentTemplateController::class, 'types'])
                    ->name('documents.types');
                Route::get('document-templates/lockable', [DocumentTemplateController::class, 'lockable'])
                    ->name('documents.templates.lockable');
                Route::get('document-templates', [DocumentTemplateController::class, 'index'])
                    ->name('documents.templates.index');
                Route::get('document-templates/{template}', [DocumentTemplateController::class, 'show'])
                    ->name('documents.templates.show');
            });

            Route::middleware('permission:documents.template_edit')->group(function () {
                Route::post('document-templates', [DocumentTemplateController::class, 'store'])
                    ->name('documents.templates.store');
                Route::put('document-templates/{template}', [DocumentTemplateController::class, 'update'])
                    ->name('documents.templates.update');
                Route::delete('document-templates/{template}', [DocumentTemplateController::class, 'destroy'])
                    ->name('documents.templates.destroy');

                /* Rendered and thrown away — see the controller. Behind edit
                   rather than view, because it renders a config the caller
                   sent rather than one that was saved. */
                Route::post('document-templates/preview', [DocumentTemplateController::class, 'preview'])
                    ->name('documents.templates.preview');
            });

            Route::post('document-templates/{template}/publish', [DocumentTemplateController::class, 'publish'])
                ->middleware('permission:documents.template_publish')
                ->name('documents.templates.publish');

            /* Locks are organisation-wide authority; the controller refuses
               anybody without `documents.template_org` and refuses locks on
               anything but the organisation's own default. */
            Route::put('document-templates/{template}/locks', [DocumentTemplateController::class, 'lock'])
                ->middleware('permission:documents.template_view')
                ->name('documents.templates.lock');

            /* Printing one. The record is named by id; WHICH record it may be
               is decided by the document type, never by the request. */
            Route::post('documents/generate', [DocumentGenerationController::class, 'store'])
                ->middleware('permission:documents.generate')
                ->name('documents.generate');
        });

        /*
        | OPD — behind the module the organization has to have been sold.
        |
        | Reads are open to anyone signed in: the booking screen needs the
        | doctor list. Writes are the owner's. A doctor's week is nested
        | under the doctor and replaced whole, because whether a sitting
        | overlaps depends on every other sitting that day — a per-row
        | endpoint could not validate the one rule that matters.
        */
        Route::middleware('module:appointments')->group(function () {
            /*
            | The group check and the capability check are not redundant. The
            | group makes the whole section disappear at once when the module
            | is not running here, which reads very differently from every
            | request inside it failing separately.
            */
            Route::middleware('permission:appointments.view')->group(function () {
                Route::get('doctors/fields', [DoctorController::class, 'fields'])
                    ->name('doctors.fields');
                Route::get('doctors', [DoctorController::class, 'index'])->name('doctors.index');
                Route::get('doctors/{doctor}', [DoctorController::class, 'show'])
                    ->name('doctors.show');
                Route::get('doctors/{doctor}/schedules', [DoctorScheduleController::class, 'index'])
                    ->name('doctors.schedules.index');
            });

            /*
            | Availability — derived, never stored. The weekly sittings for
            | that weekday, minus what is cancelled, with changed hours
            | applied, plus anything extra.
            */
            Route::middleware('permission:appointments.view')->group(function () {
                Route::get('availability/day', [AvailabilityController::class, 'day'])
                    ->name('availability.day');
                Route::get('availability/week', [AvailabilityController::class, 'week'])
                    ->name('availability.week');
                Route::get('availability/exceptions', [AvailabilityController::class, 'exceptions'])
                    ->name('availability.exceptions');
                Route::get('availability/doctors/{doctor}', [AvailabilityController::class, 'forDoctor'])
                    ->name('availability.doctor');
            });

            /*
            | The OPD queue.
            |
            | Open to anyone signed in — whoever is at the desk books, checks
            | in and calls patients through. The branch check narrows it to
            | where they work, which is a different question from seniority.
            */
            /*
            | The department as a whole, rather than one doctor's list.
            |
            | `opd/branches` is deliberately not /tenant/locations: that route
            | needs `branches.view`, which somebody who runs the desk has no
            | reason to hold, and it returns branches this person would be
            | refused at the moment they picked one.
            */
            Route::middleware('permission:appointments.view')->group(function () {
                Route::get('opd/branches', [OpdController::class, 'branches'])
                    ->name('opd.branches');
                Route::get('opd/today', [OpdController::class, 'today'])
                    ->name('opd.today');
            });

            /*
            | A doctor's own list.
            |
            | Behind `appointments.queue` rather than `appointments.view`: this
            | is the screen somebody works the queue from, and it is the
            | capability every doctor login is given. The doctor is taken from
            | the session, never the query string.
            */
            /*
            | A patient's own record — who they are and what has happened.
            |
            | Behind `customers.view`, the same capability as the list it is
            | opened from: being able to see a patient at all is what makes
            | their visits readable, and a doctor holds it.
            */
            Route::get('customers/{customer}/visits', [CustomerController::class, 'visits'])
                ->middleware('permission:customers.view')
                ->name('customers.visits');

            Route::get('opd/my-day', [OpdController::class, 'myDay'])
                ->middleware('permission:appointments.queue')
                ->name('opd.my-day');

            Route::get('opd/my-month', [OpdController::class, 'myMonth'])
                ->middleware('permission:appointments.queue')
                ->name('opd.my-month');

            Route::get('opd/my-records', [OpdController::class, 'myRecords'])
                ->middleware('permission:appointments.queue')
                ->name('opd.my-records');

            /*
            | THE CONSULTATION — the doctor's, and nobody else's.
            |
            | Three capabilities across four routes, and the split is the
            | whole point of the workflow:
            |
            |   consult_start     take the patient in and open the record
            |   consult_complete  close it, and set the visit moving
            |                     downstream — and reopen it, because undoing
            |                     a mis-click on a clinical record belongs
            |                     with whoever could make it
            |
            | Reading and writing the notes sit behind `consult_start`: if
            | somebody may begin a consultation they may write one up, and a
            | fourth capability for the textarea between the two buttons
            | would be a distinction no clinic draws.
            |
            | NONE of these is `appointments.queue`. That key used to cover
            | all of it, which is exactly how the receptionist's screen came
            | to carry a button that closed a doctor's consultation.
            |
            | The controller then checks the visit is THIS doctor's, which no
            | route middleware can know.
            */
            Route::middleware('permission:appointments.consult_start')->group(function () {
                Route::get('appointments/{appointment}/consultation', [ConsultationController::class, 'show'])
                    ->name('appointments.consultation.show');
                Route::put('appointments/{appointment}/consultation', [ConsultationController::class, 'save'])
                    ->name('appointments.consultation.save');
                Route::post('appointments/{appointment}/consultation/start', [ConsultationController::class, 'start'])
                    ->name('appointments.consultation.start');
            });

            Route::middleware('permission:appointments.consult_complete')->group(function () {
                Route::post('appointments/{appointment}/consultation/complete', [ConsultationController::class, 'complete'])
                    ->name('appointments.consultation.complete');
                Route::post('appointments/{appointment}/consultation/reopen', [ConsultationController::class, 'reopen'])
                    ->name('appointments.consultation.reopen');
            });

            Route::get('appointments', [AppointmentController::class, 'index'])
                ->middleware('permission:appointments.view')
                ->name('appointments.index');
            Route::get('appointments/slots/{doctor}', [AppointmentController::class, 'slots'])
                ->middleware('permission:appointments.view')
                ->name('appointments.slots');

            Route::post('appointments', [AppointmentController::class, 'store'])
                ->middleware('permission:appointments.book')
                ->name('appointments.store');

            /*
            | THE QUEUE — the reception desk's, and the whole of it.
            |
            | Two verbs. Arriving, which issues the token and puts somebody in
            | the queue, and calling them through, which is where the desk's
            | authority over a consultation ends.
            |
            | There is deliberately no `complete` here and no `start`. Those
            | were both on this list, behind this capability, and between them
            | they let whoever was at reception open and close a clinical
            | record. They are now the two consultation routes above.
            |
            | Each move is its own verb rather than a status field somebody
            | can set to anything: the state machine is the point, and the
            | server owns it.
            */
            Route::middleware('permission:appointments.queue')->group(function () {
                Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
                    ->name('appointments.check-in');
                Route::post('appointments/{appointment}/call', [AppointmentController::class, 'call'])
                    ->name('appointments.call');
            });

            Route::middleware('permission:appointments.cancel')->group(function () {
                Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
                    ->name('appointments.cancel');
                Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])
                    ->name('appointments.no-show');
            });

            /*
            | Who the doctors are changes rarely; when they sit changes every
            | time somebody takes leave. Two questions, two capabilities — a
            | practice manager can keep the diary straight without being able
            | to add a doctor to the practice.
            */
            Route::middleware('permission:appointments.doctors')->group(function () {
                Route::post('doctors', [DoctorController::class, 'store'])
                    ->name('doctors.store');
                Route::put('doctors/{doctor}', [DoctorController::class, 'update'])
                    ->name('doctors.update');
                Route::delete('doctors/{doctor}', [DoctorController::class, 'destroy'])
                    ->name('doctors.destroy');

                /*
                 * A photograph is part of maintaining who the doctors are, so
                 * it sits behind the same capability rather than inventing one
                 * nobody would think to grant.
                 */
                Route::post('doctors/{doctor}/photo', [DoctorController::class, 'uploadPhoto'])
                    ->name('doctors.photo.store');
                Route::delete('doctors/{doctor}/photo', [DoctorController::class, 'deletePhoto'])
                    ->name('doctors.photo.destroy');
            });

            Route::middleware('permission:appointments.schedule')->group(function () {
                Route::put('doctors/{doctor}/schedules', [DoctorScheduleController::class, 'update'])
                    ->name('doctors.schedules.update');

                Route::post('availability/exceptions', [AvailabilityController::class, 'storeException'])
                    ->name('availability.exceptions.store');
                Route::delete(
                    'availability/exceptions/{exception}',
                    [AvailabilityController::class, 'destroyException']
                )->name('availability.exceptions.destroy');
            });
        });

        /*
        | This organization's own history, from its own database.
        |
        | One record's story is open to anybody signed in — somebody who can
        | already see a customer may reasonably ask who last changed their
        | phone number. The whole log is the owner's, below.
        */
        Route::get('history/{entity}/{id}', [HistoryController::class, 'forRecord'])
            ->whereNumber('id')
            ->name('history.record');

        Route::get('history', [HistoryController::class, 'index'])
            ->middleware('permission:settings.audit')
            ->name('history.index');
        Route::get('history-filters', [HistoryController::class, 'filters'])
            ->middleware('permission:settings.audit')
            ->name('history.filters');

        Route::put('settings/fields/{entity}', [FieldSettingController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('settings.fields.update');

        /*
        | Departments and their sub-departments — what doctors are grouped by.
        | Read by anyone signed in (the doctor form and booking pick from the
        | tree); changed under `settings.manage`, which kept the department
        | list before it became a tree.
        */
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');

        Route::middleware('permission:settings.manage')->group(function () {
            Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
            Route::put('departments/{department}', [DepartmentController::class, 'update'])
                ->name('departments.update');
            Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
                ->name('departments.destroy');
        });

        /*
        | The organization's own people.
        |
        | `people.manage` rather than `tenant.owner`, so an owner can hand
        | staff administration to an office manager. Promoting anybody to
        | owner stays the owner's alone — the request refuses it for
        | everybody else, or this capability would be a way to grant oneself
        | every other one.
        */
        // Before the apiResource, or {user} would swallow the literal.
        Route::get('users/fields', [TenantUserController::class, 'fields'])
            ->middleware('permission:people.view')
            ->name('users.fields');

        Route::apiResource('users', TenantUserController::class)
            ->parameters(['users' => 'user'])
            ->names('users')
            ->only(['index', 'show'])
            ->middleware('permission:people.view');

        // Written out rather than an apiResource group: the three writes are
        // three capabilities now, and a group can only carry one.
        Route::post('users', [TenantUserController::class, 'store'])
            ->middleware('permission:people.create')
            ->name('users.store');
        Route::put('users/{user}', [TenantUserController::class, 'update'])
            ->middleware('permission:people.edit')
            ->name('users.update');
        Route::delete('users/{user}', [TenantUserController::class, 'destroy'])
            ->middleware('permission:people.delete')
            ->name('users.destroy');

        /*
        | Where somebody works, and what they hold at each place.
        |
        | `people.edit`, not an organization-scoped capability. A branch
        | manager who may add somebody has to be able to give them a role, or
        | the person they just created can do nothing at all — which is what
        | happened while this was gated on `people.assign_branch`.
        |
        | What stops it becoming cross-branch reach is not the capability but
        | TenantBranchAccess: every branch in the payload must be one the
        | caller works at, and memberships anywhere else are left untouched.
        | Moving somebody between two branches therefore needs access to both,
        | which only the owner and head office have.
        */
        Route::put('users/{user}/branches', [TenantUserController::class, 'branches'])
            ->middleware('permission:people.edit')
            ->name('users.branches');

        /*
        | What one person holds, and the small differences from their role.
        |
        | `people.view` to read and `people.edit` to write, the same pair as
        | the person's own record — and StaffScope decides WHOM, so a branch
        | manager reaches their own branch's people and nobody else's.
        |
        | Only denies are writable, so this cannot hand anybody anything: the
        | escalation guard that matters is on role assignment, which is a
        | different endpoint.
        */
        Route::get('users/{user}/permissions', [UserPermissionController::class, 'show'])
            ->middleware('permission:people.view')
            ->name('users.permissions.show');
        Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
            ->middleware('permission:people.edit')
            ->name('users.permissions.update');

        Route::post('locations', [LocationController::class, 'store'])
            ->middleware('permission:branches.create')
            ->name('locations.store');
        Route::put('locations/{location}', [LocationController::class, 'update'])
            ->middleware('permission:branches.edit')
            ->name('locations.update');
        Route::delete('locations/{location}', [LocationController::class, 'destroy'])
            ->middleware('permission:branches.delete')
            ->name('locations.destroy');

        /*
        | Who runs a branch.
        |
        | Its own capability, ORGANISATION-SCOPED, and deliberately not
        | `branches.edit`. Editing a branch changes its address; naming its
        | manager decides who administers the people there — so a manager
        | holding it could appoint themselves at another branch, or appoint
        | somebody who would appoint them back.
        |
        | The endpoint moves the membership and its role as well as the
        | column, in one transaction, so a branch can never have a manager who
        | holds nothing.
        */
        Route::put('locations/{location}/manager', [LocationManagerController::class, 'assign'])
            ->middleware('permission:branches.manage_manager')
            ->name('locations.manager.assign');
        Route::delete('locations/{location}/manager', [LocationManagerController::class, 'revoke'])
            ->middleware('permission:branches.manage_manager')
            ->name('locations.manager.revoke');

        /*
        |--------------------------------------------------------------------
        | Levels two and three — the owner's alone
        |--------------------------------------------------------------------
        |
        | Deliberately behind `tenant.owner` and NOT behind a capability.
        | A role able to edit roles could grant itself every other one, and a
        | role able to switch a module on at a branch could re-open a door the
        | owner closed — so neither is delegatable, and there is nothing for
        | the other levels to be talked out of.
        */
        /*
        | Roles.
        |
        | No longer owner-only. In a large organization the owner is not going
        | to sit writing every role, and the branch manager is the person who
        | knows what their receptionist actually does — so a branch writes its
        | own, from the modules that branch was given.
        |
        | Which roles somebody may write is decided per role in the controller,
        | because "the organization's" and "this branch's" are different
        | answers that no single middleware can give. Reading is open to
        | anybody who administers staff: they have to pick a role from
        | somewhere.
        */
        // Before the apiResource, or {role} would swallow the literal.
        Route::get('roles/grantable', [RoleController::class, 'grantable'])
            ->middleware('permission:people.view')
            ->name('roles.grantable');

        // The same library the seeder uses, so the two cannot drift apart.
        Route::get('roles/templates', [RoleController::class, 'templates'])
            ->middleware('permission:people.view')
            ->name('roles.templates');

        Route::get('roles/{role}/members', [RoleController::class, 'members'])
            ->middleware('permission:people.view')
            ->name('roles.members');

        Route::apiResource('roles', RoleController::class)
            ->parameters(['roles' => 'role'])
            ->names('roles')
            ->only(['index', 'show'])
            ->middleware('permission:people.view');

        Route::apiResource('roles', RoleController::class)
            ->parameters(['roles' => 'role'])
            ->names('roles')
            ->only(['store', 'update', 'destroy'])
            ->middleware('permission:people.view');

        /*
        | How one branch customises one of the organization's roles.
        |
        | `people.roles` and the caller's OWN branch, both checked in the
        | controller — a branch manager customising another branch's copy of a
        | role is the one thing this level must never allow, and it is a
        | comparison against the acting branch rather than a capability, so no
        | middleware can make it.
        |
        | A branch may only SUBTRACT. That is a property of the table rather
        | than of this route: there is no column in which a grant could be
        | written down.
        */
        Route::get('locations/{location}/roles/{role}/permissions', [BranchRolePermissionController::class, 'show'])
            ->middleware('permission:people.view')
            ->name('locations.roles.permissions.show');

        Route::put('locations/{location}/roles/{role}/permissions', [BranchRolePermissionController::class, 'update'])
            ->middleware('permission:people.roles')
            ->name('locations.roles.permissions.update');

        /*
        | What one person can actually do, and which of the six levels decided
        | it. Read-only, and behind the capability somebody administering staff
        | already holds — it says nothing their role screen does not.
        */
        Route::get('users/{user}/effective-permissions', [EffectivePermissionController::class, 'show'])
            ->middleware('permission:people.view')
            ->name('users.effective-permissions');

        /*
        |----------------------------------------------------------------------
        | Communication — WhatsApp, email and SMS
        |----------------------------------------------------------------------
        |
        | Reading is `communication.view`, the branch capability a receptionist
        | holds: the dashboards, the template library and the delivery log are
        | what somebody at a desk opens to answer "did that go out".
        |
        | Writing is `communication.manage`, which is organisation-scoped —
        | a template, a from-address and an automation rule apply to every
        | patient on the register, so they are not a branch's to change.
        |
        | Literal paths before {template} and {campaign}.
        */
        Route::middleware('module:communication')->group(function () {
            Route::middleware('permission:communication.view')->group(function () {
                Route::get('communication/segments', [CampaignController::class, 'segments'])
                    ->name('communication.segments');
                Route::get('communication/templates', [MessageTemplateController::class, 'index'])
                    ->name('communication.templates.index');
                Route::get('communication/automation-rules', [AutomationRuleController::class, 'index'])
                    ->name('communication.rules.index');

                Route::get('communication/campaigns', [CampaignController::class, 'index'])
                    ->name('communication.campaigns.index');
                Route::get('communication/campaigns/{campaign}', [CampaignController::class, 'show'])
                    ->name('communication.campaigns.show');

                // Everything one channel's screen needs, in one request.
                Route::get('communication/{channel}/overview', [CommunicationController::class, 'show'])
                    ->name('communication.overview');
            });

            Route::middleware('permission:communication.manage')->group(function () {
                Route::put('communication/{channel}/connection', [CommunicationController::class, 'update'])
                    ->name('communication.connection.update');
                Route::post('communication/{channel}/test', [CommunicationController::class, 'test'])
                    ->name('communication.test');

                // Proves the credentials without messaging a patient.
                Route::post('communication/{channel}/test-connection', [CommunicationController::class, 'testConnection'])
                    ->name('communication.test-connection');


                Route::post('communication/templates', [MessageTemplateController::class, 'store'])
                    ->name('communication.templates.store');
                Route::put('communication/templates/{template}', [MessageTemplateController::class, 'update'])
                    ->name('communication.templates.update');
                Route::delete('communication/templates/{template}', [MessageTemplateController::class, 'destroy'])
                    ->name('communication.templates.destroy');

                Route::put('communication/automation-rules/{rule}', [AutomationRuleController::class, 'update'])
                    ->name('communication.rules.update');

                /*
                | Campaigns. Scheduling is its own route rather than a field on
                | update: setting a time is the act that makes a campaign go
                | out by itself, and it deserves to be refused on its own terms
                | — a past time, or an audience of nobody.
                */
                Route::post('communication/audience-preview', [CampaignController::class, 'audiencePreview'])
                    ->name('communication.audience-preview');
                Route::post('communication/campaigns', [CampaignController::class, 'store'])
                    ->name('communication.campaigns.store');
                Route::put('communication/campaigns/{campaign}', [CampaignController::class, 'update'])
                    ->name('communication.campaigns.update');
                Route::post('communication/campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])
                    ->name('communication.campaigns.schedule');
                Route::post('communication/campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule'])
                    ->name('communication.campaigns.unschedule');
                Route::post('communication/campaigns/{campaign}/send', [CampaignController::class, 'send'])
                    ->name('communication.campaigns.send');
                Route::delete('communication/campaigns/{campaign}', [CampaignController::class, 'destroy'])
                    ->name('communication.campaigns.destroy');
            });
        });

        Route::middleware('tenant.owner')->group(function () {
            Route::get('locations/{location}/modules', [LocationModuleController::class, 'show'])
                ->name('locations.modules.show');
            Route::put('locations/{location}/modules', [LocationModuleController::class, 'update'])
                ->name('locations.modules.update');

            /*
            | Which of those a branch may NOT switch off. The organization's
            | answer, one per module, so it sits beside the screen it bounds
            | rather than being repeated at every branch.
            */
            Route::get('module-locks', [ModuleLockController::class, 'show'])
                ->name('module-locks.show');
            Route::put('module-locks', [ModuleLockController::class, 'update'])
                ->name('module-locks.update');

            /*
            | Organisation setup — the owner configuring their organization.
            | Only the setup's own questions live here; each section's data
            | is saved through the routes above that already own it.
            */
            Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
            Route::put('setup/organization', [SetupController::class, 'updateOrganization'])
                ->name('setup.organization');
            Route::post('setup/steps/{step}/confirm', [SetupController::class, 'confirm'])
                ->whereIn('step', OrganizationSetup::CONFIRMABLE)
                ->name('setup.confirm');
            Route::post('setup/complete', [SetupController::class, 'complete'])->name('setup.complete');
        });
    });
});

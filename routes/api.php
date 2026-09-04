<?php

use App\Http\Controllers\Api\V1\Onboarding\OrganizationSetupController;
use App\Http\Controllers\Api\V1\Platform\AuditController;
use App\Http\Controllers\Api\V1\Platform\Auth\AuthController;
use App\Http\Controllers\Api\V1\Platform\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Platform\ModuleController;
use App\Http\Controllers\Api\V1\Platform\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\OrganizationModuleController;
use App\Http\Controllers\Api\V1\Platform\OrganizationTypeController;
use App\Http\Controllers\Api\V1\Tenant\AppointmentController;
use App\Http\Controllers\Api\V1\Tenant\Auth\AuthController as TenantAuthController;
use App\Http\Controllers\Api\V1\Tenant\AvailabilityController;
use App\Http\Controllers\Api\V1\Tenant\BrandingController;
use App\Http\Controllers\Api\V1\Tenant\CustomerController;
use App\Http\Controllers\Api\V1\Tenant\DashboardController;
use App\Http\Controllers\Api\V1\Tenant\DoctorController;
use App\Http\Controllers\Api\V1\Tenant\DoctorScheduleController;
use App\Http\Controllers\Api\V1\Tenant\FieldSettingController;
use App\Http\Controllers\Api\V1\Tenant\HistoryController;
use App\Http\Controllers\Api\V1\Tenant\LocationController;
use App\Http\Controllers\Api\V1\Tenant\LocationModuleController;
use App\Http\Controllers\Api\V1\Tenant\RoleController;
use App\Http\Controllers\Api\V1\Tenant\UserController as TenantUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Prefixed with /api/v1 (see bootstrap/app.php). Authentication is the
| stateful Sanctum session shared with the SPA, not bearer tokens.
|
| Three areas live here:
|
|   /api/v1/organization-setup/*   public, token from the invitation email
|   /api/v1/admin/*                the landlord panel, `platform` guard
|   /api/v1/tenant/*               an organization's own staff, `web` guard
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
    });
});

/*
| An organization's own staff — `web` guard --------------------------------
*/
Route::prefix('tenant')->name('tenant.')->group(function () {
    // Public: the login screen needs this before anyone has signed in.
    Route::get('branding', [BrandingController::class, 'show'])->name('branding');

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
     * `branch` runs after the guard and before every gate: somebody who works
     * at two branches has different permissions at each, so which one this
     * request is in has to be settled — and checked against their memberships
     * — before anything asks what they may do.
     */
    Route::middleware(['resolve.tenant', 'auth:web', 'branch'])->group(function () {
        Route::get('auth/me', [TenantAuthController::class, 'me'])->name('auth.me');

        /*
        | The workspace's first screen. Ungated here because anybody who may
        | sign in may open their own dashboard; every panel inside it is gated
        | on its own capability and narrowed to their branches.
        */
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('auth/logout', [TenantAuthController::class, 'logout']);

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
            | Each move is its own verb rather than a status field somebody can
            | set to anything: the state machine is the point.
            |
            | Moving somebody through the queue is what the desk does all day.
            | Cancelling and marking a no-show change what the day is recorded
            | as having been, so they are a separate capability that plenty of
            | clinics will want to keep with somebody senior.
            */
            Route::middleware('permission:appointments.queue')->group(function () {
                Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
                    ->name('appointments.check-in');
                Route::post('appointments/{appointment}/start', [AppointmentController::class, 'start'])
                    ->name('appointments.start');
                Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])
                    ->name('appointments.complete');
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
        Route::middleware('tenant.owner')->group(function () {
            // Before the apiResource, or {role} would swallow the literal.
            Route::get('roles/grantable', [RoleController::class, 'grantable'])
                ->name('roles.grantable');

            Route::get('roles/{role}/members', [RoleController::class, 'members'])
                ->name('roles.members');

            Route::apiResource('roles', RoleController::class)
                ->parameters(['roles' => 'role'])
                ->names('roles');

            Route::get('locations/{location}/modules', [LocationModuleController::class, 'show'])
                ->name('locations.modules.show');
            Route::put('locations/{location}/modules', [LocationModuleController::class, 'update'])
                ->name('locations.modules.update');
        });
    });
});

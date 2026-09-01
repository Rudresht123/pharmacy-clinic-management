<?php

use App\Http\Controllers\Api\V1\Onboarding\OrganizationSetupController;
use App\Http\Controllers\Api\V1\Platform\Auth\AuthController;
use App\Http\Controllers\Api\V1\Platform\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Platform\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\OrganizationTypeController;
use App\Http\Controllers\Api\V1\Tenant\Auth\AuthController as TenantAuthController;
use App\Http\Controllers\Api\V1\Tenant\BrandingController;
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

        Route::apiResource('organizations', OrganizationController::class);

        Route::post(
            'organizations/{organization}/retry-provisioning',
            [OrganizationController::class, 'retryProvisioning']
        );

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

    Route::middleware(['resolve.tenant', 'auth:web'])->group(function () {
        Route::get('auth/me', [TenantAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [TenantAuthController::class, 'logout']);
    });
});

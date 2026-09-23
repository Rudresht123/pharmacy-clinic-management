<?php

use App\Http\Controllers\Api\V1\Tenant\Portal\AppointmentController;
use App\Http\Controllers\Api\V1\Tenant\Portal\DoctorController;
use App\Http\Controllers\Api\V1\Tenant\Portal\HomeController;
use App\Http\Controllers\Api\V1\Tenant\Portal\SignInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Patient portal — /api/v1/tenant/portal/*
|--------------------------------------------------------------------------
|
| Mounted from routes/api/v1.php inside the tenant group. Mobile only: the
| clinic is named in X-Organization, the patient by a bearer token.
|
| Every endpoint answers about the signed-in patient and nobody else —
| EnsurePortalPatient resolves whose record that is, and no route here
| accepts a patient id. Capabilities (portal.*) then say what they may do,
| through the same `module:` and `permission:` gates staff routes use, so an
| owner who takes booking off the Patient role turns it off here too.
|
*/

/*
| Signing in. Throttled per IP underneath PatientOtp's own per-number
| limits: the first is a floor against a script, the second against
| somebody's phone being flooded.
*/
Route::middleware('resolve.tenant.header')->group(function () {
    Route::post('auth/code', [SignInController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('auth.code');

    Route::post('auth/verify', [SignInController::class, 'verify'])
        ->middleware('throttle:12,1')
        ->name('auth.verify');
});

Route::middleware([
    'resolve.tenant.header',
    'auth:tenant-api',
    'tenant.actor',
    'branch',
    'portal.patient',
])->group(function () {
    Route::get('home', [HomeController::class, 'show'])->name('home');

    Route::middleware('module:appointments')->group(function () {
        Route::middleware('permission:portal.appointments.book')->group(function () {
            Route::get('doctors', [DoctorController::class, 'index'])->name('doctors.index');
            Route::get('doctors/{doctor}', [DoctorController::class, 'show'])->name('doctors.show');
            Route::get('doctors/{doctor}/slots', [DoctorController::class, 'slots'])->name('doctors.slots');
            Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        });

        Route::get('appointments', [AppointmentController::class, 'index'])
            ->middleware('permission:portal.appointments.view')
            ->name('appointments.index');
    });
});

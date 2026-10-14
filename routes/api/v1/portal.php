<?php

use App\Http\Controllers\Api\V1\Tenant\ConversationController;
use App\Http\Controllers\Api\V1\Tenant\DeviceController;
use App\Http\Controllers\Api\V1\Tenant\Portal\AppointmentController;
use App\Http\Controllers\Api\V1\Tenant\Portal\DoctorController;
use App\Http\Controllers\Api\V1\Tenant\Portal\HomeController;
use App\Http\Controllers\Api\V1\Tenant\Portal\LabReportController;
use App\Http\Controllers\Api\V1\Tenant\Portal\PrescriptionController;
use App\Http\Controllers\Api\V1\Tenant\Portal\SignInController;
use App\Http\Controllers\Api\V1\Tenant\VideoCallController;
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
    // A clinic whose subscription has lapsed: the patient keeps reading
    // their own records, and can no longer book, message or call.
    'subscription:portal',
])->group(function () {
    Route::get('home', [HomeController::class, 'show'])->name('home');

    // The patient's own phone, for pushes about their own care — the same
    // controller staff use, registered to whoever is signed in.
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::delete('devices/{deviceId}', [DeviceController::class, 'destroy'])
        ->where('deviceId', '[A-Za-z0-9._:-]{1,191}')
        ->name('devices.destroy');

    // One canned push to whoever hits this, on their own phone — how a
    // patient checks push actually reaches them, end to end. Carries
    // `query-auth` so the link works pasted straight into a browser.
    Route::get('devices/test-push', [DeviceController::class, 'testPush'])
        ->middleware('query-auth')
        ->name('devices.test-push');

    Route::middleware('module:appointments')->group(function () {
        Route::middleware('permission:portal.appointments.book')->group(function () {
            Route::get('doctors', [DoctorController::class, 'index'])->name('doctors.index');
            Route::get('doctors/{doctor}', [DoctorController::class, 'show'])->name('doctors.show');
            Route::get('doctors/{doctor}/slots', [DoctorController::class, 'slots'])->name('doctors.slots');
            Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');

            // Whoever may book may call off their own booking.
            Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
                ->whereNumber('appointment')
                ->name('appointments.cancel');

            // ...and ask to move it. The desk answers; until then nothing moves.
            Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
                ->whereNumber('appointment')
                ->middleware('throttle:10,1')
                ->name('appointments.reschedule');
            Route::delete('appointments/{appointment}/reschedule', [AppointmentController::class, 'withdrawReschedule'])
                ->whereNumber('appointment')
                ->name('appointments.reschedule.withdraw');
        });

        Route::get('appointments', [AppointmentController::class, 'index'])
            ->middleware('permission:portal.appointments.view')
            ->name('appointments.index');

        /*
        | Messages with the doctors they have seen. The doctor's half is in
        | routes/api/v1.php on the same controller; EnsurePortalPatient above
        | is what makes the asker the patient side here.
        */
        Route::middleware('permission:portal.messages')
            ->prefix('messages')
            ->name('messages.')
            ->group(function () {
                Route::get('conversations', [ConversationController::class, 'index'])->name('index');
                Route::get('unread', [ConversationController::class, 'unread'])->name('unread');
                Route::get('contacts', [ConversationController::class, 'contacts'])->name('contacts');
                Route::post('conversations', [ConversationController::class, 'store'])->name('store');
                Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
                    ->whereNumber('conversation')->name('show');
                Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])
                    ->whereNumber('conversation')->name('messages');
                Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send'])
                    ->whereNumber('conversation')->middleware('throttle:60,1')->name('send');
                Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])
                    ->whereNumber('conversation')->name('read');
                Route::post('conversations/{conversation}/delivered', [ConversationController::class, 'delivered'])
                    ->whereNumber('conversation')->name('delivered');
                Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])
                    ->whereNumber('conversation')->middleware('throttle:40,1')->name('typing');
                Route::get('attachments/{message}', [ConversationController::class, 'attachment'])
                    ->whereNumber('message')->name('attachment');
            });

        /*
        | Video consultations — the patient's end: hearing the doctor ring,
        | answering, and the handshake that connects the two phones. The
        | doctor's end is in routes/api/v1.php on the same controller.
        */
        Route::middleware('permission:portal.video_calls')
            ->prefix('video-calls')
            ->name('video-calls.')
            ->group(function () {
                Route::get('current', [VideoCallController::class, 'current'])->name('current');
                Route::get('{call}', [VideoCallController::class, 'show'])->whereNumber('call')->name('show');
                Route::post('{call}/accept', [VideoCallController::class, 'accept'])->whereNumber('call')->name('accept');
                Route::post('{call}/decline', [VideoCallController::class, 'decline'])->whereNumber('call')->name('decline');
                Route::post('{call}/end', [VideoCallController::class, 'end'])->whereNumber('call')->name('end');
                Route::post('{call}/signals', [VideoCallController::class, 'signal'])
                    ->whereNumber('call')->middleware('throttle:300,1')->name('signal');
                Route::get('{call}/signals', [VideoCallController::class, 'signals'])
                    ->whereNumber('call')->middleware('throttle:300,1')->name('signals');
            });
    });

    Route::middleware('module:prescriptions')->group(function () {
        Route::get('prescriptions', [PrescriptionController::class, 'index'])->name('prescriptions.index');
        Route::get('prescriptions/{id}', [PrescriptionController::class, 'show'])->name('prescriptions.show');
    });

    Route::middleware('module:laboratory')->group(function () {
        Route::get('lab-reports', [LabReportController::class, 'index'])->name('lab-reports.index');
        Route::get('lab-reports/{id}', [LabReportController::class, 'show'])->name('lab-reports.show');
    });
});

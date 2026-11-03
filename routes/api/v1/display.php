<?php

use App\Http\Controllers\Api\V1\Display\DisplayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Televisions — /api/v1/display/{token}/*
|--------------------------------------------------------------------------
|
| No login: a screen on a wall has nobody to sign in. The secret in the URL is
| what opens it (`queue.display`), and it opens only tokens, counts, doctors and
| rooms. Nothing reachable here names a patient.
|
| Rate limited per screen and per address, so a guessing script is stopped while
| a hospital with ten televisions behind one router is not.
|
*/

Route::middleware(['throttle:queue-display', 'queue.display'])
    ->prefix('{token}')
    ->where(['token' => '[A-Za-z0-9-]{20,120}'])
    ->group(function () {
        Route::get('/', [DisplayController::class, 'show'])->name('show');

        Route::post('broadcasting/auth', [DisplayController::class, 'authorizeChannel'])
            ->name('broadcasting.auth');
    });

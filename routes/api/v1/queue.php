<?php

use App\Http\Controllers\Api\V1\Tenant\QueueActionController;
use App\Http\Controllers\Api\V1\Tenant\QueueBoardController;
use App\Http\Controllers\Api\V1\Tenant\QueueDisplayController;
use App\Http\Controllers\Api\V1\Tenant\QueueSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The live queue — /api/v1/tenant/queue/* and /queue-displays/*
|--------------------------------------------------------------------------
|
| Required from routes/api/v1.php inside the `module:appointments` group, and
| every route here also needs `module:queue`: the Smart Queue is sold on its own.
| A clinic without it answers 403 here, whatever its roles say.
|
| Every queue route names a doctor or a visit, and there is deliberately no
| "call next" without one: each doctor runs their own line. The capability on
| the route says what somebody may do at all; the controller then checks the
| branch, and that a doctor's login is driving their own queue.
|
| Booking, check-in, Start and Complete keep their existing routes, which is why
| they are not repeated here.
|
*/

Route::middleware('module:queue')->group(function () {
    Route::get('queue/board', [QueueBoardController::class, 'index'])
        ->middleware('permission:queue.board,queue.reassign')
        ->name('queue.board');

    Route::middleware('permission:queue.board,queue.my_console')->group(function () {
        Route::get('queue/doctors/{doctor}', [QueueBoardController::class, 'doctor'])
            ->whereNumber('doctor')
            ->name('queue.doctor');
        Route::get('queue/doctors/{doctor}/history', [QueueBoardController::class, 'history'])
            ->whereNumber('doctor')
            ->name('queue.doctor.history');
    });

    Route::middleware('permission:queue.control')->group(function () {
        Route::post('queue/doctors/{doctor}/call-next', [QueueActionController::class, 'callNext'])
            ->whereNumber('doctor')
            ->name('queue.call-next');
        Route::post('queue/doctors/{doctor}/pause', [QueueActionController::class, 'pause'])
            ->whereNumber('doctor')
            ->name('queue.pause');
        Route::post('queue/doctors/{doctor}/resume', [QueueActionController::class, 'resume'])
            ->whereNumber('doctor')
            ->name('queue.resume');

        Route::post('queue/visits/{appointment}/skip', [QueueActionController::class, 'skip'])
            ->whereNumber('appointment')
            ->name('queue.skip');
        Route::post('queue/visits/{appointment}/recall', [QueueActionController::class, 'recall'])
            ->whereNumber('appointment')
            ->name('queue.recall');
        Route::post('queue/visits/{appointment}/requeue', [QueueActionController::class, 'requeue'])
            ->whereNumber('appointment')
            ->name('queue.requeue');
        Route::post('queue/visits/{appointment}/absent', [QueueActionController::class, 'absent'])
            ->whereNumber('appointment')
            ->name('queue.absent');
    });

    Route::post('queue/visits/{appointment}/priority', [QueueActionController::class, 'priority'])
        ->whereNumber('appointment')
        ->middleware('permission:queue.priority')
        ->name('queue.priority');

    Route::post('queue/visits/{appointment}/reassign', [QueueActionController::class, 'reassign'])
        ->whereNumber('appointment')
        ->middleware('permission:queue.reassign')
        ->name('queue.reassign');

    Route::middleware('permission:queue.settings')->group(function () {
        Route::get('queue/settings', [QueueSettingController::class, 'index'])->name('queue.settings');
        Route::put('queue/settings/doctors/{doctor}', [QueueSettingController::class, 'updateDoctor'])
            ->whereNumber('doctor')
            ->name('queue.settings.doctor');
        Route::put('queue/settings/departments/{department}', [QueueSettingController::class, 'saveDepartment'])
            ->whereNumber('department')
            ->name('queue.settings.department');
        Route::delete('queue/settings/departments/{department}', [QueueSettingController::class, 'removeDepartment'])
            ->whereNumber('department')
            ->name('queue.settings.department.remove');
    });

    Route::middleware('permission:queue.displays')->group(function () {
        Route::get('queue-displays', [QueueDisplayController::class, 'index'])->name('queue-displays.index');
        Route::get('queue-displays/options', [QueueDisplayController::class, 'options'])->name('queue-displays.options');
        Route::post('queue-displays', [QueueDisplayController::class, 'store'])->name('queue-displays.store');
        Route::get('queue-displays/{display}', [QueueDisplayController::class, 'show'])->whereNumber('display')->name('queue-displays.show');
        Route::put('queue-displays/{display}', [QueueDisplayController::class, 'update'])->whereNumber('display')->name('queue-displays.update');
        Route::delete('queue-displays/{display}', [QueueDisplayController::class, 'destroy'])->whereNumber('display')->name('queue-displays.destroy');
        Route::get('queue-displays/{display}/preview', [QueueDisplayController::class, 'preview'])->whereNumber('display')->name('queue-displays.preview');
        Route::post('queue-displays/{display}/rotate', [QueueDisplayController::class, 'rotate'])->whereNumber('display')->name('queue-displays.rotate');
        Route::post('queue-displays/{display}/revoke', [QueueDisplayController::class, 'revoke'])->whereNumber('display')->name('queue-displays.revoke');
    });
});

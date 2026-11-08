<?php

use App\Http\Controllers\Api\V1\Tenant\LabDashboardController;
use App\Http\Controllers\Api\V1\Tenant\LabMasterController;
use App\Http\Controllers\Api\V1\Tenant\LabOrderableController;
use App\Http\Controllers\Api\V1\Tenant\LabReferenceRangeController;
use App\Http\Controllers\Api\V1\Tenant\LabSettingController;
use App\Http\Controllers\Api\V1\Tenant\LabTestController;
use App\Support\Laboratory\LabMasters;
use App\Support\Modules\ScreenTicks;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The laboratory — /api/v1/tenant/lab/*
|--------------------------------------------------------------------------
|
| Required from routes/api/v1.php inside the tenant stack. Everything here needs
| `module:laboratory`, then one of the ticks that opens a screen reading the
| data — never a broad "laboratory" key, so taking a tick off a role really
| refuses at the server.
|
| The older lab-orders routes stay where they are in v1.php; they move here as
| the phases of docs/architecture/laboratory.md rebuild them.
|
*/

Route::middleware('module:laboratory')->prefix('lab')->name('lab.')->group(function () {
    /*
    | Settings: one record, organisation-wide. Read by whoever opens the
    | settings screen; saved by the organisation's lab setup tick.
    */
    Route::get('settings', [LabSettingController::class, 'show'])
        ->middleware(ScreenTicks::gate(ScreenTicks::LAB_SETTINGS))
        ->name('settings.show');
    Route::put('settings', [LabSettingController::class, 'update'])
        ->middleware('permission:laboratory.settings_manage')
        ->name('settings.update');

    /*
    | The short lists. Read by the screens that pick from them; written only
    | by lab setup. `{master}` is constrained to the lists that exist, so any
    | other name is a plain 404.
    */
    Route::middleware(ScreenTicks::gate(ScreenTicks::LAB_LISTS))->group(function () {
        Route::get('masters', [LabMasterController::class, 'catalogue'])->name('masters.catalogue');
        Route::get('masters/{master}', [LabMasterController::class, 'index'])
            ->whereIn('master', LabMasters::keys())
            ->name('masters.index');
    });

    /*
    | The test master. Read by the Lab tests screen (and by whoever may change
    | it); a test and its parameters are written together under catalog_manage.
    | Reference ranges are a clinical decision with a tick of their own.
    */
    Route::middleware(ScreenTicks::gate(ScreenTicks::LAB_CATALOG))->group(function () {
        Route::get('tests', [LabTestController::class, 'index'])->name('tests.index');
        Route::get('tests/{test}', [LabTestController::class, 'show'])->whereNumber('test')->name('tests.show');
        Route::get('parameters/{parameter}/ranges', [LabReferenceRangeController::class, 'index'])
            ->whereNumber('parameter')
            ->name('parameters.ranges.index');
    });

    Route::middleware('permission:laboratory.catalog_manage')->group(function () {
        Route::post('tests', [LabTestController::class, 'store'])->name('tests.store');
        Route::put('tests/{test}', [LabTestController::class, 'update'])->whereNumber('test')->name('tests.update');
        Route::delete('tests/{test}', [LabTestController::class, 'destroy'])->whereNumber('test')->name('tests.destroy');
        Route::put('tests/{test}/prices', [LabTestController::class, 'savePrices'])
            ->whereNumber('test')
            ->name('tests.prices');
    });

    Route::put('parameters/{parameter}/ranges', [LabReferenceRangeController::class, 'update'])
        ->whereNumber('parameter')
        ->middleware('permission:laboratory.ranges_manage')
        ->name('parameters.ranges.update');

    // The lab's day and trend, from its own orders.
    Route::get('dashboard', [LabDashboardController::class, 'show'])
        ->middleware('permission:laboratory.dashboard')
        ->name('dashboard');

    // What can be ordered, priced for the branch: the doctor's and the desk's picker.
    Route::get('orderables', [LabOrderableController::class, 'index'])
        ->middleware(ScreenTicks::gate(ScreenTicks::LAB_ORDERABLES))
        ->name('orderables');

    Route::middleware('permission:laboratory.settings_manage')->group(function () {
        Route::post('masters/{master}', [LabMasterController::class, 'store'])
            ->whereIn('master', LabMasters::keys())
            ->name('masters.store');
        Route::post('masters/{master}/starter', [LabMasterController::class, 'starter'])
            ->whereIn('master', LabMasters::keys())
            ->name('masters.starter');
        Route::put('masters/{master}/{id}', [LabMasterController::class, 'update'])
            ->whereIn('master', LabMasters::keys())
            ->whereNumber('id')
            ->name('masters.update');
        Route::delete('masters/{master}/{id}', [LabMasterController::class, 'destroy'])
            ->whereIn('master', LabMasters::keys())
            ->whereNumber('id')
            ->name('masters.destroy');
    });
});

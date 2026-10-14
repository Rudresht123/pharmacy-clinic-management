<?php

use App\Http\Controllers\Api\V1\Website\PricingController;
use App\Http\Controllers\Api\V1\Website\SignupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The marketing website — /api/v1/website/*
|--------------------------------------------------------------------------
|
| Public. No session, no tenant: these read and write the MASTER database
| only (prices, plans, registrations), so the website never needs — and can
| never reach — a clinic's database. CSRF-exempt with the rest of
| `api/v1/website/*` (bootstrap/app.php); browser origins are allowed by
| PUBLIC_SITE_ORIGINS.
|
| The enquiry form (`website/enquiries`) is declared in routes/api/v1.php.
|
*/

Route::middleware('throttle:120,1')->group(function () {
    Route::get('pricing', [PricingController::class, 'pricing'])->name('pricing');
    Route::get('plans', [PricingController::class, 'plans'])->name('plans.index');
    Route::get('plans/{slug}', [PricingController::class, 'plan'])
        ->where('slug', '[a-z0-9-]+')
        ->name('plans.show');
    Route::get('modules', [PricingController::class, 'modules'])->name('modules');
    Route::get('addons', [PricingController::class, 'addons'])->name('addons');
    Route::get('registration-status/{reference}', [SignupController::class, 'status'])
        ->where('reference', '[0-9A-Za-z]{26}')
        ->name('registrations.status');
});

Route::middleware('throttle:30,1')->post('quote', [PricingController::class, 'quote'])->name('quote');

// Creating sign-ups and payment orders: tight, per IP.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('registrations', [SignupController::class, 'store'])->name('registrations.store');
    Route::post('checkout', [SignupController::class, 'checkout'])->name('checkout');
    Route::post('checkout/verify', [SignupController::class, 'verify'])->name('checkout.verify');
});

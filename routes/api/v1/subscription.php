<?php

use App\Http\Controllers\Api\V1\Tenant\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| An organization's own subscription — /api/v1/tenant/subscription/*
|--------------------------------------------------------------------------
|
| Mounted inside the signed-in tenant group, after `subscription` — which
| deliberately lets these paths through while the organization is locked:
| renewing is how a locked organization gets back in. The controller checks
| the person is the owner or holds `settings.subscription`.
|
| Throttled: these create invoices and payment orders.
|
*/

Route::get('/', [SubscriptionController::class, 'show'])->name('show');
Route::get('catalogue', [SubscriptionController::class, 'catalogue'])->name('catalogue');
Route::get('usage', [SubscriptionController::class, 'usage'])->name('usage');
Route::get('invoices', [SubscriptionController::class, 'invoices'])->name('invoices');

Route::middleware('throttle:20,1')->group(function () {
    Route::post('renew', [SubscriptionController::class, 'renew'])->name('renew');
    Route::post('upgrade', [SubscriptionController::class, 'upgrade'])->name('upgrade');
    Route::post('addons/quote', [SubscriptionController::class, 'quoteAddons'])->name('addons.quote');
    Route::post('addons', [SubscriptionController::class, 'buyAddons'])->name('addons.buy');
    Route::post('invoices/{invoice}/pay', [SubscriptionController::class, 'pay'])
        ->where('invoice', '[0-9A-Za-z]{26}')
        ->name('invoices.pay');
    Route::post('payments/verify', [SubscriptionController::class, 'verifyPayment'])->name('payments.verify');
});

<?php

use App\Http\Controllers\Api\V1\Platform\Billing\AddonController;
use App\Http\Controllers\Api\V1\Platform\Billing\BillingSettingsController;
use App\Http\Controllers\Api\V1\Platform\Billing\ModulePriceController;
use App\Http\Controllers\Api\V1\Platform\Billing\OrganizationSubscriptionController;
use App\Http\Controllers\Api\V1\Platform\Billing\PlanController;
use App\Http\Controllers\Api\V1\Platform\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\Platform\Billing\SubscriptionInvoiceController;
use App\Http\Controllers\Api\V1\Platform\Billing\UsageRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin — billing: pricing, plans, subscriptions, invoices
|--------------------------------------------------------------------------
|
| Mounted at /api/v1/admin/billing by routes/api/v1.php, inside the signed-in
| `auth:platform` group.
|
| READING is open to the billing, operations and support roles; CHANGING
| anything — a price, a plan, an organization's subscription — is the billing
| role's (a Super Admin always passes either). Every change goes through
| SubscriptionLifecycle or a model that records its own history, so each one
| is on the audit log with who made it and, for subscriptions, why.
|
*/

Route::middleware('platform.role:billing,ops,support')->group(function () {
    Route::get('overview', [SubscriptionController::class, 'overview'])->name('overview');
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/insights', [SubscriptionController::class, 'insights'])->name('subscriptions.insights');
    Route::get('plans/summary', [PlanController::class, 'summary'])->name('plans.summary');
    Route::get('pricing/insights', [PlanController::class, 'pricing'])->name('pricing.insights');
    Route::get('invoices/insights', [SubscriptionInvoiceController::class, 'insights'])->name('invoices.insights');

    Route::get('settings', [BillingSettingsController::class, 'show'])->name('settings.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
    Route::get('module-prices', [ModulePriceController::class, 'index'])->name('module-prices.index');
    Route::get('addons', [AddonController::class, 'index'])->name('addons.index');
    Route::get('usage-rates', [UsageRateController::class, 'index'])->name('usage-rates.index');

    Route::get('invoices', [SubscriptionInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [SubscriptionInvoiceController::class, 'show'])->name('invoices.show');

    Route::get('organizations/{organization}/subscription', [OrganizationSubscriptionController::class, 'show'])
        ->name('organizations.subscription.show');
    Route::get('organizations/{organization}/subscription/events', [OrganizationSubscriptionController::class, 'events'])
        ->name('organizations.subscription.events');
});

Route::middleware('platform.role:billing')->group(function () {
    Route::put('settings', [BillingSettingsController::class, 'update'])->name('settings.update');

    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::post('plans/{plan}/status', [PlanController::class, 'status'])->name('plans.status');
    Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

    Route::post('module-prices/{module}', [ModulePriceController::class, 'store'])->name('module-prices.store');

    Route::post('addons', [AddonController::class, 'store'])->name('addons.store');
    Route::put('addons/{addon}', [AddonController::class, 'update'])->name('addons.update');
    Route::post('addons/{addon}/status', [AddonController::class, 'status'])->name('addons.status');

    Route::post('usage-rates', [UsageRateController::class, 'store'])->name('usage-rates.store');
    Route::put('usage-rates/{rate}', [UsageRateController::class, 'update'])->name('usage-rates.update');
    Route::post('usage-rates/{rate}/publish', [UsageRateController::class, 'publish'])->name('usage-rates.publish');
    Route::post('usage-rates/{rate}/archive', [UsageRateController::class, 'archive'])->name('usage-rates.archive');

    Route::post('invoices/{invoice}/void', [SubscriptionInvoiceController::class, 'void'])->name('invoices.void');
    Route::post('invoices/{invoice}/payments', [SubscriptionInvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::post('organizations/{organization}/subscription/invoices', [SubscriptionInvoiceController::class, 'issueRenewal'])
        ->name('organizations.subscription.invoices.store');

    /*
    | One organization's subscription — every lever needs a reason.
    */
    Route::prefix('organizations/{organization}/subscription')
        ->name('organizations.subscription.')
        ->group(function () {
            Route::post('assign', [OrganizationSubscriptionController::class, 'assign'])->name('assign');
            Route::post('change-plan', [OrganizationSubscriptionController::class, 'changePlan'])->name('change-plan');
            Route::post('trial', [OrganizationSubscriptionController::class, 'trial'])->name('trial');
            Route::post('extend', [OrganizationSubscriptionController::class, 'extend'])->name('extend');
            Route::post('suspend', [OrganizationSubscriptionController::class, 'suspend'])->name('suspend');
            Route::post('unlock', [OrganizationSubscriptionController::class, 'unlock'])->name('unlock');
            Route::post('cancel', [OrganizationSubscriptionController::class, 'cancel'])->name('cancel');
            Route::post('addons', [OrganizationSubscriptionController::class, 'addAddon'])->name('addons.store');
            Route::delete('items/{item}', [OrganizationSubscriptionController::class, 'removeItem'])
                ->whereNumber('item')->name('items.destroy');
            Route::post('credits', [OrganizationSubscriptionController::class, 'grantCredits'])->name('credits');
            Route::post('limits', [OrganizationSubscriptionController::class, 'overrideLimit'])->name('limits');
            Route::post('features', [OrganizationSubscriptionController::class, 'grantFeature'])->name('features');
        });
});

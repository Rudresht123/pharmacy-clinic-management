<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Pharmacy, once a day in every tenant, on the clinic's clock (APP_TIMEZONE).
|
| Expiry just after midnight, so a batch whose date has come stops being
| dispensable before the first counter opens. Reconciliation in the small
| hours: it only reports, and a mismatch is for a person to read.
|
| Needs the scheduler running (`php artisan schedule:run` every minute).
*/
Schedule::command('pharmacy:expire-batches')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('pharmacy:reconcile-stock')->dailyAt('02:30')->withoutOverlapping();

// A prescription past its valid-until date stops being dispensable overnight.
Schedule::command('prescriptions:expire')->dailyAt('00:15')->withoutOverlapping();

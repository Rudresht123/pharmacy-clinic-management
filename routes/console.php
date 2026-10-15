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

/*
| Scheduled campaigns, every minute.
|
| A clinic that schedules a campaign for 9:00 expects it at 9:00, so the
| granularity of this is the granularity of the whole feature. Cheap when
| nothing is due — one indexed query per tenant — and the dispatcher is
| idempotent, so `withoutOverlapping` is belt as well as braces.
*/
Schedule::command('campaigns:dispatch')->everyMinute()->withoutOverlapping();

/*
| Appointment reminders — the clinic's "24 hours before" and "1 hour before"
| rules, on every channel they are switched on for. Every five minutes is
| close enough for either, and the delivery table makes each reminder go
| once however often this asks.
*/
Schedule::command('communication:send-reminders')->everyFiveMinutes()->withoutOverlapping();

/*
| Subscriptions — persist expiry, grace and lock transitions, send the owner's
| reminders. Hourly is plenty: ACCESS never waits for this (the subscription
| middleware decides from the dates on every request); this only writes the
| status down and sends the emails, each exactly once.
*/
Schedule::command('subscriptions:process')->hourly()->withoutOverlapping()->onOneServer();

// Last month's usage ledger against each clinic's own records — a report for a
// person to read; it changes nothing.
Schedule::command('subscriptions:reconcile-usage')->monthlyOn(2, '03:00')->withoutOverlapping()->onOneServer();

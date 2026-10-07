<?php

use App\Broadcasting\BranchPharmacyChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Realtime channels
|--------------------------------------------------------------------------
|
| Every channel is PRIVATE and named for the organization first:
|
|   org.{organization uuid}.branch.{location id}.{what}
|
| Users are numbered per tenant database — user 7 exists in every
| organization — so a channel without the organization in its name could not
| tell two clinics apart. Each callback checks that the name's organization is
| the one the request authenticated against before anything else.
|
| `guards: web` because the application's default guard is the platform
| administrator's. Tenant users — browser sessions, and phone tokens adopted
| by `tenant.actor` — are on `web`.
|
| The framework's default `App.Models.User.{id}` channel is deliberately
| absent: matched by id alone, it would let user 7 of one organization hear
| user 7 of another.
|
*/

Broadcast::channel(
    'org.{organization}.branch.{branch}.pharmacy',
    BranchPharmacyChannel::class,
    ['guards' => ['web']],
);

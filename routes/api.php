<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API versions
|--------------------------------------------------------------------------
|
| Everything here is under /api (bootstrap/app.php). Each version is its own
| route file, mounted under its own segment, so a new version is one line
| here and a file beside the others — never an edit to a version clients
| already depend on.
|
|   routes/api/v1.php      /api/v1/*   the current contract
|   routes/api/v1/*.php    pieces of v1, required from v1.php
|
| Adding v2:
|
|   1. routes/api/v2.php, holding only the endpoints whose contract changes.
|   2. Controllers under App\Http\Controllers\Api\V2, extending their V1
|      counterparts and overriding just what differs — services, models and
|      repositories are version-free and shared.
|   3. Mount it below with ->name('v2.') so its route names cannot collide
|      with v1's (v1 keeps its unprefixed names; existing callers use them).
|
| v1 then stays frozen for the mobile builds already in people's hands, and is
| retired only once no supported app version calls it.
|
*/

Route::prefix('v1')->group(base_path('routes/api/v1.php'));

// Route::prefix('v2')->name('v2.')->group(base_path('routes/api/v2.php'));

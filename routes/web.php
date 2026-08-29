<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
|
| The application is a single-page app. Every path renders the same shell
| and React resolves the route on the client; the JSON API lives in
| routes/api.php under /api/v1.
|
| Paths owned by something else are excluded from the catch-all rather than
| left to registration order:
|
|   api      the JSON API
|   sanctum  the CSRF cookie endpoint the SPA calls before logging in
|   storage  Laravel's file serving route
|   up       the health check
|
| The lookahead is anchored to a whole segment — (/|$) — so only those exact
| prefixes are excluded. A bare ^(?!api) would also have swallowed a future
| page at /api-docs.
|
| Moving the SPA here also fixes the invitation link: provisioning mails a
| URL of /organization/setup/{token}, and while the SPA lived under /app
| that path matched no route at all, so the owner landed on a 404.
|
*/
Route::view('/{any?}', 'app')
    ->where('any', '^(?!(api|sanctum|storage|up)(/|$)).*$')
    ->name('spa');

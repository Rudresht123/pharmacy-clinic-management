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
|   docs     the API documentation (Swagger UI, below) and Scribe's own routes
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

/*
| API documentation — plain Swagger UI reading the OpenAPI spec that Scribe
| extracts from routes/api/v1.php (see config/scribe.php). Scribe's own
| generated page — richer, with per-language code samples — stays reachable
| at /docs/reference for anyone who wants it; this is the familiar UI people
| ask for by name.
*/
Route::get('/docs', function () {
    return view('swagger-ui', ['specUrl' => '/docs/reference.openapi']);
})->name('docs.swagger-ui');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!(api|sanctum|storage|up|docs)(/|$)).*$')
    ->name('spa');

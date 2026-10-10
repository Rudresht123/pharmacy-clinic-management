<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;
use function Knuckles\Scribe\Config\removeStrategies;

// Only the most common configs are shown. See the https://scribe.knuckles.wtf/laravel/reference/config for all.

return [
    // The HTML <title> for the generated documentation.
    'title' => config('app.name').' API Documentation',

    // A short description of your API. Will be included in the docs webpage, Postman collection and OpenAPI spec.
    'description' => 'The HTTP API behind the HMS platform — the landlord panel, each organization\'s own staff workspace, and the patient portal.',

    // Text to place in the "Introduction" section, right after the `description`. Markdown and HTML are supported.
    'intro_text' => <<<'INTRO'
            This is the current contract for `/api/v1`. See `routes/api/v1.php` for how it is wired together.

            There are three separate audiences behind one base path, told apart by guard rather than by prefix alone:

            - **Platform** (`/api/v1/admin/*`) — the landlord panel, `platform` guard. A browser session only; no mobile client.
            - **Tenant** (`/api/v1/tenant/*`) — an organization's own staff, `web` guard (browser session) or `tenant-api` guard (bearer token). A phone identifies its organization with an `X-Organization` header on every request; a browser is already on the organization's subdomain.
            - **Patient portal** (`/api/v1/tenant/portal/*`) — a patient's own data, mobile only, `tenant-api` guard plus `X-Organization`.

            <aside>As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
            You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).</aside>
        INTRO,

    // The base URL displayed in the docs.
    // If you're using `laravel` type, you can set this to a dynamic string, like '{{ config("app.tenant_url") }}' to get a dynamic base URL.
    'base_url' => config('app.url'),

    // Routes to include in the docs
    'routes' => [
        [
            'match' => [
                // Match only routes whose paths match this pattern (use * as a wildcard to match any characters). Example: 'users/*'.
                'prefixes' => ['api/v1/*'],

                // Match only routes whose domains match this pattern (use * as a wildcard to match any characters). Example: 'api.*'.
                'domains' => ['*'],
            ],

            // Include these routes even if they did not match the rules above.
            'include' => [
                // 'users.index', 'POST /new', '/auth/*'
            ],

            // Exclude these routes even if they matched the rules above.
            'exclude' => [
                // 'GET /health', 'admin.*'
            ],
        ],
    ],

    // The type of documentation output to generate.
    // - "static" will generate a static HTMl page in the /public/docs folder,
    // - "laravel" will generate the documentation as a Blade view, so you can add routing and authentication.
    // - "external_static" and "external_laravel" do the same as above, but pass the OpenAPI spec as a URL to an external UI template
    'type' => 'laravel',

    // See https://scribe.knuckles.wtf/laravel/reference/config#theme for supported options
    'theme' => 'default',

    'static' => [
        // HTML documentation, assets and Postman collection will be generated to this folder.
        // Source Markdown will still be in resources/docs.
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        // Whether to automatically create a docs route for you to view your generated docs. You can still set up routing manually.
        'add_routes' => true,

        // URL path to use for the docs endpoint (if `add_routes` is true).
        // By default, `/docs` opens the HTML page, `/docs.postman` opens the Postman collection, and `/docs.openapi` the OpenAPI spec.
        //
        // Moved off '/docs': that URL now serves a plain Swagger UI page (routes/web.php) built
        // on the same openapi.yaml, so '/docs.openapi' below becomes '/docs/reference.openapi'.
        'docs_url' => '/docs/reference',

        // Directory within `public` in which to store CSS and JS assets.
        // By default, assets are stored in `public/vendor/scribe`.
        // If set, assets will be stored in `public/{{assets_directory}}`
        'assets_directory' => null,

        // Middleware to attach to the docs endpoint (if `add_routes` is true).
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        // Add a Try It Out button to your endpoints so consumers can test endpoints right from their browser.
        // Don't forget to enable CORS headers for your endpoints.
        'enabled' => true,

        // The base URL to use in the API tester. Leave as null to be the same as the displayed URL (`scribe.base_url`).
        'base_url' => null,

        // [Laravel Sanctum] Fetch a CSRF token before each request, and add it as an X-XSRF-TOKEN header.
        'use_csrf' => false,

        // The URL to fetch the CSRF token from (if `use_csrf` is true).
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // How is your API authenticated? This information will be used in the displayed docs, generated examples and response calls.
    'auth' => [
        // Set this to true if ANY endpoints in your API use authentication.
        'enabled' => true,

        // Set this to true if your API should be authenticated by default. If so, you must also set `enabled` (above) to true.
        // You can then use @unauthenticated or @authenticated on individual endpoints to change their status from the default.
        'default' => true,

        // Where is the auth value meant to be sent in a request?
        'in' => AuthIn::BEARER->value,

        // The name of the auth parameter (e.g. token, key, apiKey) or header (e.g. Authorization, Api-Key).
        'name' => 'Authorization',

        // The value of the parameter to be used by Scribe to authenticate response calls.
        // This will NOT be included in the generated documentation. If empty, Scribe will use a random value.
        'use_value' => env('SCRIBE_AUTH_KEY'),

        // Placeholder your users will see for the auth parameter in the example requests.
        // Set this to null if you want Scribe to use a random value as placeholder instead.
        'placeholder' => '{YOUR_SANCTUM_TOKEN}',

        // Any extra authentication-related info for your users. Markdown and HTML are supported.
        'extra_info' => <<<'INFO'
            Endpoints shown here as authenticated expect a Sanctum bearer token, **except**:

            - **Platform** (`/api/v1/admin/*`) and the browser side of **Tenant** (`/api/v1/tenant/*`, `web` guard) actually
              authenticate with a signed-in session cookie, not a bearer token — login there with
              `POST .../auth/login`, which starts the session. The bearer flows shown in this
              page are for the mobile/API clients, which use `.../auth/token` (tenant) instead.
            - Every **Tenant** and **Patient portal** request from a client that is not already on the
              organization's own subdomain must also send an `X-Organization` header naming the
              organization (by uuid or code) — see `App\Http\Middleware\ResolveTenantFromHeader`.

            Endpoints tagged `@unauthenticated` need neither.
            INFO,
    ],

    // Example requests for each endpoint will be shown in each of these languages.
    // Supported options are: bash, javascript, php, python
    // To add a language of your own, see https://scribe.knuckles.wtf/laravel/advanced/example-requests
    // Note: does not work for `external` docs types
    'example_languages' => [
        'bash',
        'javascript',
    ],

    // Generate a Postman collection (v2.1.0) in addition to HTML docs.
    // For 'static' docs, the collection will be generated to public/docs/collection.json.
    // For 'laravel' docs, it will be generated to storage/app/scribe/collection.json.
    // Setting `laravel.add_routes` to true (above) will also add a route for the collection.
    'postman' => [
        'enabled' => true,

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Generate an OpenAPI spec in addition to docs webpage.
    // For 'static' docs, the collection will be generated to public/docs/openapi.yaml.
    // For 'laravel' docs, it will be generated to storage/app/scribe/openapi.yaml.
    // Setting `laravel.add_routes` to true (above) will also add a route for the spec.
    'openapi' => [
        'enabled' => true,

        // The OpenAPI spec version to generate. Supported versions: '3.0.3', '3.1.0'.
        // OpenAPI 3.1 is more compatible with JSON Schema and is becoming the dominant version.
        // See https://spec.openapis.org/oas/v3.1.0 for details on 3.1 changes.
        'version' => '3.0.3',

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],

        // Additional generators to use when generating the OpenAPI spec.
        // Should extend `Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`.
        'generators' => [],
    ],

    'groups' => [
        // Endpoints which don't have a @group will be placed in this default group.
        'default' => 'Endpoints',

        // By default, Scribe will sort groups alphabetically, and endpoints in the order their routes are defined.
        // You can override this by listing the groups, subgroups and endpoints here in the order you want them.
        // See https://scribe.knuckles.wtf/blog/laravel-v4#easier-sorting and https://scribe.knuckles.wtf/laravel/reference/config#order for details
        // Note: does not work for `external` docs types
        //
        // Ordered to match how the three audiences in routes/api/v1.php are laid out: Onboarding,
        // then Platform (landlord panel), then Tenant (an organization's own staff), then the
        // patient portal — and within Tenant, roughly the order a new organization sets up in.
        'order' => [
            'Onboarding',
            'Platform > Authentication',
            'Platform > Organizations',
            'Platform > Modules',
            'Platform > Audit',
            'Platform > Messaging Settings',
            'Tenant > Authentication',
            'Tenant > Branding & Workspace',
            'Tenant > Setup',
            'Tenant > Dashboard',
            'Tenant > Locations & Branches',
            'Tenant > Users, Roles & Permissions',
            'Tenant > Module Settings',
            'Tenant > Field Settings',
            'Tenant > Customers',
            'Tenant > Appointments & OPD',
            'Tenant > Prescriptions',
            'Tenant > Laboratory',
            'Tenant > Pharmacy > Medicines',
            'Tenant > Pharmacy > Stores & Sales',
            'Tenant > Pharmacy > Stock',
            'Tenant > Pharmacy > Suppliers',
            'Tenant > Billing',
            'Tenant > Patient Documents',
            'Tenant > Communications & Automation',
            'Tenant > Devices & Push',
            'Tenant > Guides',
            'Tenant > Lookups',
            'Tenant > Audit History',
            'Patient Portal > Authentication',
            'Patient Portal > Home',
            'Patient Portal > Doctors',
            'Patient Portal > Appointments',
            'Patient Portal > Prescriptions',
            'Patient Portal > Account',
        ],
    ],

    // Custom logo path. This will be used as the value of the src attribute for the <img> tag,
    // so make sure it points to an accessible URL or path. Set to false to not use a logo.
    // For example, if your logo is in public/img:
    // - 'logo' => '../img/logo.png' // for `static` type (output folder is public/docs)
    // - 'logo' => 'img/logo.png' // for `laravel` type
    'logo' => false,

    // Customize the "Last updated" value displayed in the docs by specifying tokens and formats.
    // Examples:
    // - {date:F j Y} => March 28, 2022
    // - {git:short} => Short hash of the last Git commit
    // Available tokens are `{date:<format>}` and `{git:<format>}`.
    // The format you pass to `date` will be passed to PHP's `date()` function.
    // The format you pass to `git` can be either "short" or "long".
    // Note: does not work for `external` docs types
    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        // Set this to any number to generate the same example values for parameters on each run,
        'faker_seed' => 1234,

        // With API resources and transformers, Scribe tries to generate example models to use in your API responses.
        // By default, Scribe will try the model's factory, and if that fails, try fetching the first from the database.
        // You can reorder or remove strategies here.
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    // The strategies Scribe will use to extract information about your routes at each stage.
    // Use configureStrategy() to specify settings for a strategy in the list.
    // Use removeStrategies() to remove an included strategy.
    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        // ResponseCalls is deliberately removed: it would fire real HTTP requests through
        // tenant-resolution, guard and permission middleware for every GET route, which
        // for an unauthenticated run mostly just produces noisy 401/403 examples, and for
        // an authenticated one risks touching whichever tenant database the session or
        // X-Organization header happens to resolve to. Documented examples come from
        // @response / @responseFile / Resource-based strategies instead.
        'responses' => removeStrategies(
            Defaults::RESPONSES_STRATEGIES,
            [Strategies\Responses\ResponseCalls::class]
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    // For response calls, API resource responses and transformer responses,
    // Scribe will try to start database transactions, so no changes are persisted to your database.
    // Tell Scribe which connections should be transacted here. If you only use one db connection, you can leave this as is.
    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        // If you are using a custom serializer with league/fractal, you can specify it here.
        'serializer' => null,
    ],
];

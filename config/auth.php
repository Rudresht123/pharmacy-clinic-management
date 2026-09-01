<?php

use App\Models\Platform\PlatformUser;
use App\Models\Tenant\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    /*
     * The default guard is 'platform', not Laravel's stock 'web'.
     *
     * Nothing in this application authenticates through the default — every
     * call names its guard explicitly — but the framework itself reaches for
     * it, and the database session driver is the case that matters:
     * DatabaseSessionHandler stamps sessions.user_id on every session write
     * using the default guard. Pointed at 'web' that resolves a tenant User
     * over the `organization` connection, so any request that had not already
     * selected a tenant database (the whole admin panel, the public branding
     * endpoint) died on "relation users does not exist".
     *
     * 'platform' is also the honest answer for that column: the sessions
     * table lives in the master database, and a tenant user id is ambiguous
     * there — id 1 exists in every tenant database at once.
     */
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'platform'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        // Tenant / application users.
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * The landlord panel. Build Spec §18 makes this separation
         * non-negotiable: a tenant user can never authenticate here, and an
         * admin can never authenticate on `web`. Keeping two guards is what
         * enforces that — not a role column on a shared table.
         */
        'platform' => [
            'driver' => 'session',
            'provider' => 'platform_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'platform_users' => [
            'driver' => 'eloquent',
            'model' => PlatformUser::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        // Its own token table, so a reset issued for an admin cannot be
        // redeemed against a tenant account that happens to share an address.
        'platform_users' => [
            'provider' => 'platform_users',
            'table' => 'platform_password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];

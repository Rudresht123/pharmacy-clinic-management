<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main Domain
    |--------------------------------------------------------------------------
    |
    | Tenants are reached at {subdomain}.{main_domain}. Reading this through
    | config (rather than env() at call time) keeps it working once the
    | configuration is cached in production.
    |
    */

    'main_domain' => env('MAIN_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Setup Token Lifetime
    |--------------------------------------------------------------------------
    |
    | How many days an emailed organization setup link stays valid.
    |
    */

    'setup_token_ttl_days' => (int) env('ORGANIZATION_SETUP_TOKEN_TTL_DAYS', 7),

];

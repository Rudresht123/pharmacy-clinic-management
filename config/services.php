<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | India Post's public PIN code API. See App\Services\Geo\PincodeLookup.
    |
    | No key: it is free and open, but slow (twenty seconds and more is
    | normal), and its firewall refuses requests that call themselves
    | "GuzzleHttp", which is why the user agent is set. Each code is fetched
    | once and then cached, so only the first lookup waits.
    */
    'india_post' => [
        'url' => env('INDIA_POST_URL', 'https://api.postalpincode.in'),
        'timeout' => (int) env('INDIA_POST_TIMEOUT', 25),
        'user_agent' => env('INDIA_POST_USER_AGENT', 'HMS-Care/1.0 (clinic management; PIN code lookup)'),
    ],

];

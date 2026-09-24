<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | The provider used when an organization has not chosen one of its own.
    | Per-clinic settings live in the tenant's `communication_channels` row and
    | always win; this is only the fallback, and the fallback for a clinic that
    | has configured nothing must not be a live account somebody else pays for.
    |
    | `log` writes the message to storage/logs and refuses to run in production,
    | exactly as the OTP sender does. That is deliberate: a misconfigured
    | production deployment should fail loudly rather than silently send
    | nothing, or worse, send from the wrong account.
    |
    */

    'default' => env('WHATSAPP_PROVIDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each entry maps a provider key to the class that implements
    | WhatsAppProviderInterface, plus the credentials to use when the tenant
    | has none of its own. Adding Meta or Twilio later is a new class and a new
    | entry here — no business code changes.
    |
    | Credentials in this file are DEFAULTS FOR DEVELOPMENT. Real per-clinic
    | credentials are encrypted on the tenant's channel row.
    |
    */

    'providers' => [

        'log' => [
            'driver' => App\Services\WhatsApp\Providers\LogWhatsAppProvider::class,
            'label' => 'Log only (development)',
        ],

        'digiware' => [
            'driver' => App\Services\WhatsApp\Providers\DigiwareWhatsAppProvider::class,
            'label' => 'Digiware',

            'credentials' => [
                'base_url' => env('DIGIWARE_WHATSAPP_BASE_URL', 'https://lms.digiware.in/api'),
                'vendor_uid' => env('DIGIWARE_VENDOR_UID'),
                'token' => env('DIGIWARE_TOKEN'),
                'from_phone_number_id' => env('DIGIWARE_FROM_PHONE_NUMBER_ID'),
            ],

            /*
            | What a clinic must fill in, and which of those are secret.
            |
            | The settings screen builds itself from this, and `secret` is what
            | stops a token ever being sent back to the browser — it is masked
            | on read and only written when a new value is supplied.
            */
            'fields' => [
                'base_url' => ['label' => 'API base URL', 'required' => true],
                'vendor_uid' => ['label' => 'Vendor UID', 'required' => true],
                'token' => ['label' => 'API token', 'required' => true, 'secret' => true],
                'from_phone_number_id' => ['label' => 'Default phone number ID', 'required' => false],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Shared by every provider, so one slow endpoint cannot hold a queue worker
    | open indefinitely. Retries are the HTTP client's, for connection-level
    | faults only — an API that answers "invalid template" is not retried,
    | because it will say the same thing the second time.
    |
    */

    'http' => [
        'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('WHATSAPP_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('WHATSAPP_HTTP_RETRIES', 2),
        'retry_delay_ms' => (int) env('WHATSAPP_HTTP_RETRY_DELAY', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Sending is asynchronous: an appointment must not wait on somebody else's
    | API. Null uses the application's default connection and queue.
    |
    */

    'queue' => [
        'connection' => env('WHATSAPP_QUEUE_CONNECTION'),
        'queue' => env('WHATSAPP_QUEUE', 'whatsapp'),
        'tries' => (int) env('WHATSAPP_JOB_TRIES', 3),
        'timeout' => (int) env('WHATSAPP_JOB_TIMEOUT', 60),

        // Backoff in seconds between attempts. A provider that is down tends
        // to be down for minutes, not milliseconds.
        'backoff' => [10, 60, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Request and response bodies are stored on the message log so a failed
    | send can be explained. `redact` names the keys stripped before anything
    | is written — a token in a log row is a credential in the database, and
    | the log is read by support staff who have no business holding one.
    |
    */

    'redact' => [
        'token', 'access_token', 'auth_token', 'api_key', 'apikey',
        'password', 'secret', 'authorization', 'x-api-key',
    ],

    /*
    | Whether to keep payloads at all. Off for a clinic that would rather not
    | have patient-facing message bodies sitting in a log table.
    */
    'store_payloads' => (bool) env('WHATSAPP_STORE_PAYLOADS', true),

];

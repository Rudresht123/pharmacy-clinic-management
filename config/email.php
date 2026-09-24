<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | There is deliberately NO provider interface here, unlike WhatsApp.
    | Laravel already abstracts SMTP, SES, Postmark, Mailgun and Resend behind
    | one mailer, and wrapping that in a second abstraction would be a layer
    | whose only job is to forward calls to a layer that already exists.
    |
    | A clinic supplies SMTP settings and they are used. A clinic that supplies
    | none falls back to the application's own mailer, which is how a
    | development box sends without every tenant being configured first.
    |
    */

    'fallback_mailer' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Settings a clinic supplies
    |--------------------------------------------------------------------------
    |
    | The settings screen builds itself from this, and `secret` is what stops a
    | password ever being sent back to the browser — it is masked on read and
    | only written when a new value is supplied.
    |
    | Stored encrypted on the clinic's own `communication_channels` row, which
    | already carries an `encrypted:array` column for exactly this. A separate
    | table would be a second place for the same fact to live.
    |
    */

    'default' => 'smtp',

    'providers' => [
        'smtp' => [
            'label' => 'SMTP',
            'fields' => [
                'host' => ['label' => 'SMTP host', 'required' => true, 'placeholder' => 'smtp.gmail.com'],
                'port' => ['label' => 'Port', 'required' => true, 'placeholder' => '587'],

                'encryption' => [
                    'label' => 'Encryption',
                    'required' => false,
                    'options' => ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'],
                ],

                'username' => ['label' => 'Username', 'required' => true, 'placeholder' => 'clinic@careplus.com'],
                'password' => ['label' => 'Password', 'required' => true, 'secret' => true],

                'from_address' => [
                    'label' => 'From address',
                    'required' => true,
                    'placeholder' => 'clinic@careplus.com',
                    'hint' => 'Must be an address this SMTP account is allowed to send as.',
                ],

                'from_name' => ['label' => 'From name', 'required' => true, 'placeholder' => 'CarePlus Clinic'],

                'reply_to' => [
                    'label' => 'Reply-to address',
                    'required' => false,
                    'placeholder' => 'frontdesk@careplus.com',
                    'hint' => 'Where a patient hitting Reply is answered. Optional.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Sending is asynchronous for the same reason WhatsApp is: an appointment
    | must not wait on somebody else's mail server, and a slow relay must not
    | turn into a booking that cannot be made.
    |
    */

    'queue' => [
        'connection' => env('EMAIL_QUEUE_CONNECTION'),
        'queue' => env('EMAIL_QUEUE', 'email'),
        'tries' => (int) env('EMAIL_JOB_TRIES', 3),
        'timeout' => (int) env('EMAIL_JOB_TIMEOUT', 60),
        'backoff' => [10, 60, 300],
    ],

    /*
    | Keys stripped before anything is written to a log row. A password in a
    | delivery log is a credential in the database, and the log is read by
    | support staff who have no business holding one.
    */

    'redact' => [
        'password', 'secret', 'token', 'authorization', 'api_key',
    ],

];

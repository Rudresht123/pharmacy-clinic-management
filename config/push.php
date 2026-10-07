<?php

/*
|--------------------------------------------------------------------------
| Push notifications
|--------------------------------------------------------------------------
|
| Firebase Cloud Messaging carries pushes to the mobile app. It decides
| nothing: who receives a push and what it says are settled in Laravel
| before a sender is called (see App\Services\Push).
|
|   log   write the push to the log instead — development, staging, and any
|         environment with no Firebase project yet. The in-app notification
|         is written either way, so nobody is left uninformed.
|   fcm   FCM HTTP v1, signed in with a service account key.
|
*/

return [

    'driver' => env('PUSH_DRIVER', 'log'),

    'fcm' => [
        // Optional when the service account file names its own project.
        'project_id' => env('FIREBASE_PROJECT_ID'),

        /*
         * Absolute path to the service account JSON downloaded from the
         * Firebase console. Kept OUTSIDE the repository and never stored in
         * the database — it can send to every device the project knows.
         */
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // Which queue the sending job uses, so pushes can be given their own worker.
    'queue' => env('PUSH_QUEUE', 'default'),

];

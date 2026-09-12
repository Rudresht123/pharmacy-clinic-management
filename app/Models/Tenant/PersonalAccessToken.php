<?php

namespace App\Models\Tenant;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token, pinned to the tenant connection.
 *
 * Without the pin it would be queried against whatever the default connection
 * happens to be — which is the master database, where an organization's tokens
 * have no business living. Every other tenant model does the same thing for the
 * same reason.
 *
 * Registered globally with `Sanctum::usePersonalAccessTokenModel()`. That is
 * safe here because nothing else in this application issues Sanctum tokens: the
 * platform panel authenticates over a session, and its
 * `personal_access_tokens` table in the master database is Laravel's default
 * migration, unused. If the platform ever does issue tokens, this global
 * registration is the thing that has to change first.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $connection = 'organization';
}

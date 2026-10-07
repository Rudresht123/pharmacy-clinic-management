<?php

namespace App\Services\Tenancy;

use App\Models\Platform\Organization;
use Illuminate\Support\Facades\Config;

/**
 * The organization whose database the tenant connection points at right now.
 *
 * For code that runs below a request — a model observer, a service called from
 * several places — and needs the master record of the clinic it is working
 * in. Read from the CONNECTION, which is the one fact that cannot disagree
 * with the data being written; a request attribute may not exist (a console
 * command, a queued job) and an id passed down a call chain is a second copy
 * of the answer.
 *
 * Remembered per database name for the life of the process, which is safe
 * precisely because the key is the database: a worker that moves to another
 * tenant asks about a different name.
 */
class CurrentTenant
{
    /** @var array<string, Organization|null> */
    private static array $resolved = [];

    public static function database(): ?string
    {
        $name = Config::get('database.connections.'.TenantConnectionService::CONNECTION.'.database');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function organization(): ?Organization
    {
        $database = self::database();

        if ($database === null) {
            return null;
        }

        if (! array_key_exists($database, self::$resolved)) {
            self::$resolved[$database] = Organization::query()->where('database_name', $database)->first();
        }

        return self::$resolved[$database];
    }
}

<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Points the shared "organization" connection at a specific tenant database.
 *
 * The connection is defined in config/database.php with a null database; this
 * service fills it in and resets the pooled PDO instance. Previously this
 * three-step dance was copy-pasted into every place that touched a tenant.
 */
class TenantConnectionService
{
    public const CONNECTION = 'organization';

    /**
     * Switch the tenant connection to the given database.
     */
    public function connect(string $databaseName): void
    {
        Config::set(
            'database.connections.' . self::CONNECTION . '.database',
            $databaseName
        );

        DB::purge(self::CONNECTION);
        DB::reconnect(self::CONNECTION);
    }

    /**
     * Run a callback against a tenant database, then drop the connection so
     * the next caller cannot accidentally inherit it.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $databaseName, \Closure $callback): mixed
    {
        $this->connect($databaseName);

        try {
            return $callback();
        } finally {
            $this->disconnect();
        }
    }

    public function disconnect(): void
    {
        DB::purge(self::CONNECTION);

        Config::set('database.connections.' . self::CONNECTION . '.database', null);
    }
}

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
            'database.connections.'.self::CONNECTION.'.database',
            $databaseName
        );

        DB::purge(self::CONNECTION);
        DB::reconnect(self::CONNECTION);
    }

    /**
     * Run a callback against a tenant database, then leave the connection as
     * it was found.
     *
     * On a queue worker that is "not connected", so the next job cannot
     * inherit this one's clinic. But a job run INLINE — the `sync` queue, a
     * dispatchSync, every test — shares the request's connection, and simply
     * disconnecting at the end left the request that dispatched it talking to
     * no database at all ("relation does not exist" on its very next query).
     * So the previous database is put back, and when it is already the one
     * asked for, the connection is not touched at all.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $databaseName, \Closure $callback): mixed
    {
        $previous = $this->current();

        if ($previous === $databaseName) {
            return $callback();
        }

        $this->connect($databaseName);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->disconnect() : $this->connect($previous);
        }
    }

    /** The tenant database the connection points at now, if any. */
    public function current(): ?string
    {
        $database = Config::get('database.connections.'.self::CONNECTION.'.database');

        return is_string($database) && $database !== '' ? $database : null;
    }

    public function disconnect(): void
    {
        DB::purge(self::CONNECTION);

        Config::set('database.connections.'.self::CONNECTION.'.database', null);
    }
}

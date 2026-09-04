<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

class DatabaseService
{
    /**
     * CREATE DATABASE / DROP DATABASE cannot run inside a transaction on
     * PostgreSQL — a dedicated connection (config/database.php) keeps these
     * calls off the `pgsql` connection, which a test suite using
     * RefreshDatabase wraps in a transaction per test.
     */
    private const DDL_CONNECTION = 'pgsql_ddl';

    public static function create(string $databaseName): void
    {
        self::assertSafeName($databaseName);

        DB::connection(self::DDL_CONNECTION)
            ->statement(sprintf('CREATE DATABASE "%s" WITH ENCODING \'UTF8\'', $databaseName));
    }

    public static function drop(string $databaseName): void
    {
        self::assertSafeName($databaseName);

        DB::connection(self::DDL_CONNECTION)
            ->statement(sprintf('DROP DATABASE IF EXISTS "%s"', $databaseName));
    }

    public static function exists(string $databaseName): bool
    {
        self::assertSafeName($databaseName);

        return DB::connection(self::DDL_CONNECTION)
            ->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]) !== null;
    }

    /**
     * Postgres does not support bound parameters for identifiers in
     * CREATE/DROP DATABASE, so the name is guarded before interpolation.
     */
    private static function assertSafeName(string $databaseName): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $databaseName)) {
            throw new \InvalidArgumentException("Invalid database name: {$databaseName}");
        }
    }
}

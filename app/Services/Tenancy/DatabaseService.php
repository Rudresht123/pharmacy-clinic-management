<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

class DatabaseService
{
    public static function create(string $databaseName): void
    {
        self::assertSafeName($databaseName);

        DB::statement(sprintf('CREATE DATABASE "%s" WITH ENCODING \'UTF8\'', $databaseName));
    }

    public static function drop(string $databaseName): void
    {
        self::assertSafeName($databaseName);

        DB::statement(sprintf('DROP DATABASE IF EXISTS "%s"', $databaseName));
    }

    /**
     * Postgres does not support bound parameters for identifiers in
     * CREATE/DROP DATABASE, so the name is guarded before interpolation.
     */
    private static function assertSafeName(string $databaseName): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $databaseName)) {
            throw new \InvalidArgumentException("Invalid database name: {$databaseName}");
        }
    }
}

<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Refuses to let the suite touch a database that is not a test database.
 *
 * RefreshDatabase begins by dropping every table. Pointed at the wrong
 * database — a stale config cache, a missing .env.testing, an APP_ENV
 * exported in the shell — that is somebody's clinic gone. Called from
 * TestCase::createApplication(), before any trait gets a chance to migrate.
 */
final class TestDatabaseGuard
{
    public static function assertSafe(string $environment, ?string $database): void
    {
        if ($environment !== 'testing') {
            throw new RuntimeException(
                "Refusing to run tests: APP_ENV is \"{$environment}\", not \"testing\". "
                .'Clear any cached config (php artisan config:clear) and check phpunit.xml.'
            );
        }

        if ($database === null || ! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                'Refusing to run tests: the database "'.($database ?? '(none)').'" does not end in "_testing".'
            );
        }
    }
}

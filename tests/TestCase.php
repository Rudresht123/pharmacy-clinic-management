<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Check the database before anything can wipe it.
     *
     * Here rather than in setUp(): setUp() runs RefreshDatabase before any
     * code of ours, so a check placed there would fire after the tables were
     * already gone.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app->make('config');
        $connection = $config->get('database.connections.'.$config->get('database.default'), []);

        // A DB_URL, when set, wins over DB_DATABASE — so it is what gets checked.
        $database = ! empty($connection['url'])
            ? ltrim((string) parse_url($connection['url'], PHP_URL_PATH), '/')
            : ($connection['database'] ?? null);

        TestDatabaseGuard::assertSafe((string) $config->get('app.env'), $database);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Make every request look like it came from the SPA.
         *
         * Sanctum only attaches session middleware to /api routes when it
         * recognises the caller as the first-party frontend, and it decides
         * that from the Origin or Referer header — not the host. A test
         * request carries neither, so anything touching the session (login,
         * logout, password confirmation) failed with "Session store not set
         * on request" while behaving perfectly in a browser.
         *
         * localhost is already in sanctum's stateful list.
         */
        $this->withHeader('Origin', config('app.url'));
    }
}

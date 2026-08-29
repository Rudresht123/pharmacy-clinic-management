<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

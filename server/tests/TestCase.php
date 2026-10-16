<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DatabaseSafetyGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the test application and enforce the database safety guard
     * strictly BEFORE parent::setUp() runs traits or RefreshDatabase hooks.
     */
    protected function setUp(): void
    {
        if (! $this->app) {
            $this->refreshApplication();
        }

        // Fail-closed guard: must execute BEFORE setUpTraits() / RefreshDatabase!
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        parent::setUp();
    }
}

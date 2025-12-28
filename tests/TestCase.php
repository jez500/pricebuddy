<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent issues with test variables no being used.
        Artisan::call('config:clear');

        // Ensure we're always using the test database.
        config()->set('database.connections.mariadb.host', 'tests_db');
    }

    /**
     * Define the migrations to run before regular migrations.
     * Settings migrations must run first because some regular migrations depend on them.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Run settings migrations first
        $this->artisan('migrate', ['--path' => 'database/settings', '--force' => true]);
    }
}

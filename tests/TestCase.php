<?php

namespace Tests;

use App\Settings\AppSettings;
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

        // Drop any cached settings instance so each test loads settings fresh
        // from the migrated database. A singleton loaded before the settings
        // rows exist marks them as "default-loaded", which makes a later
        // AppSettings::save() throw MissingSettings.
        app()->forgetInstance(AppSettings::class);
    }

    /**
     * Define the migrations to run before regular migrations.
     * Settings migrations must run first because some regular migrations depend on them.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Settings migrations must run first because some regular migrations depend on them.
        // We need to run the core settings table migration first.
        $this->artisan('migrate', ['--path' => 'database/migrations/2025_01_17_000000_create_settings_table.php', '--force' => true]);

        // Run settings migrations
        $this->artisan('migrate', ['--path' => 'database/settings', '--force' => true]);
    }
}

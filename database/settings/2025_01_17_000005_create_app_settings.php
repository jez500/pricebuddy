<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('app.scrape_schedule', '0 6 * * *');
        $this->migrator->add('app.scrape_cache_ttl', 720);
        $this->migrator->add('app.sleep_seconds_between_scrape', 10);
        $this->migrator->add('app.log_retention_days', 30);
        $this->migrator->add('app.max_attempts_to_scrape', 3);
        $this->migrator->add('app.notification_services', []);
        $this->migrator->add('app.integrated_services', []);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->delete('app.scrape_schedule');
        $this->migrator->delete('app.scrape_cache_ttl');
        $this->migrator->delete('app.sleep_seconds_between_scrape');
        $this->migrator->delete('app.log_retention_days');
        $this->migrator->delete('app.max_attempts_to_scrape');
        $this->migrator->delete('app.notification_services');
        $this->migrator->delete('app.integrated_services');
    }
};

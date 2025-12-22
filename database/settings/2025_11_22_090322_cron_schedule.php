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
        $this->migrator->delete('app.scrape_schedule_time');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->delete('app.scrape_schedule');
        $this->migrator->add('app.scrape_schedule_time', '06:00');
    }
};

<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existingTime = $this->migrator->read('app.scrape_schedule_time');
        if ($existingTime) {
            // Convert HH:MM format to cron expression: "M H * * *"
            [$hours, $minutes] = explode(':', $existingTime);
            $cronExpression = sprintf('%d %d * * *', (int) $minutes, (int) $hours);
            $this->migrator->add('app.scrape_schedule', $cronExpression);
        } else {
            $this->migrator->add('app.scrape_schedule', '0 6 * * *');
        }
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

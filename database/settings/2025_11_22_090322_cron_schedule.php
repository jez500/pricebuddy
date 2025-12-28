<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if scrape_schedule already exists
        $existingSchedule = DB::table('settings')
            ->where('group', 'app')
            ->where('name', 'scrape_schedule')
            ->first();

        // Only proceed if scrape_schedule doesn't exist yet
        if (! $existingSchedule) {
            $existingTime = DB::table('settings')
                ->where('group', 'app')
                ->where('name', 'scrape_schedule_time')
                ->first();

            if ($existingTime) {
                $existingTime = $existingTime->payload;
                // Convert "HH:MM" format to cron expression: "M H * * *"
                [$hours, $minutes] = explode(':', str_replace('"', '', $existingTime));
                $cronExpression = sprintf('%d %d * * *', (int) ltrim($minutes, '0'), (int) ltrim($hours, '0'));
                $this->migrator->add('app.scrape_schedule', $cronExpression);
            } else {
                $this->migrator->add('app.scrape_schedule', '0 6 * * *');
            }
        }

        // Always try to delete the old setting if it exists
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

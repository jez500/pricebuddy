<?php

namespace Tests\Feature\Schedule;

use App\Console\Commands\FetchAll;
use App\Models\UrlResearch;
use App\Services\Helpers\ScheduleHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Carbon time after each test
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Get the application's schedule instance.
     */
    protected function getSchedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    /**
     * Rebuild the application's schedule after changing settings.
     * This is necessary because the schedule is built during bootstrap
     * and needs to be recreated after settings changes.
     */
    protected function rebuildSchedule(): Schedule
    {
        // Clear the existing schedule instance
        $this->app->forgetInstance(Schedule::class);

        // Create a new schedule instance
        $schedule = new Schedule;

        // Register the schedule commands.
        ScheduleHelper::registerSchedule($schedule);

        // Bind the new schedule instance
        $this->app->instance(Schedule::class, $schedule);

        return $schedule;
    }

    /**
     * Test that FetchAll command is scheduled with custom cron schedule.
     */
    public function test_fetch_all_command_is_scheduled_with_default_cron(): void
    {
        // Set the scrape_schedule setting to default '0 6 * * *' (6 AM daily)
        SettingsHelper::setSetting('scrape_schedule', '0 6 * * *');

        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent, 'FetchAll command should be scheduled');
        $this->assertStringContainsString('--log', $fetchAllEvent->command ?? '');
    }

    /**
     * Test FetchAll command runs at 6 AM with default schedule.
     */
    public function test_fetch_all_command_runs_at_6am_with_default_schedule(): void
    {
        SettingsHelper::setSetting('scrape_schedule', '0 6 * * *');

        // Set time to 6:00 AM
        Carbon::setTestNow('2025-12-28 06:00:00');

        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent);
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'FetchAll should run at 6:00 AM');
    }

    /**
     * Test FetchAll command does not run at other times with default schedule.
     */
    public function test_fetch_all_command_does_not_run_at_7am_with_default_schedule(): void
    {
        SettingsHelper::setSetting('scrape_schedule', '0 6 * * *');

        // Set time to 7:00 AM
        Carbon::setTestNow('2025-12-28 07:00:00');

        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent);
        $this->assertFalse($fetchAllEvent->isDue($this->app), 'FetchAll should not run at 7:00 AM');
    }

    /**
     * Test FetchAll command with custom hourly schedule.
     */
    public function test_fetch_all_command_with_hourly_schedule(): void
    {
        // Set custom hourly schedule (every hour)
        SettingsHelper::setSetting('scrape_schedule', '0 * * * *');

        $schedule = $this->rebuildSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent);

        // Test at different hours
        Carbon::setTestNow('2025-12-28 10:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 10:00');

        Carbon::setTestNow('2025-12-28 14:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 14:00');

        Carbon::setTestNow('2025-12-28 10:30:00');
        $this->assertFalse($fetchAllEvent->isDue($this->app), 'Should not run at 10:30');
    }

    /**
     * Test FetchAll command with custom twice daily schedule.
     */
    public function test_fetch_all_command_with_twice_daily_schedule(): void
    {
        // Set custom twice daily schedule (at 6 AM and 6 PM)
        SettingsHelper::setSetting('scrape_schedule', '0 6,18 * * *');

        $schedule = $this->rebuildSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent);

        // Test at 6 AM
        Carbon::setTestNow('2025-12-28 06:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 6:00 AM');

        // Test at 6 PM
        Carbon::setTestNow('2025-12-28 18:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 6:00 PM');

        // Test at noon (should not run)
        Carbon::setTestNow('2025-12-28 12:00:00');
        $this->assertFalse($fetchAllEvent->isDue($this->app), 'Should not run at 12:00 PM');
    }

    /**
     * Test FetchAll command with every 4 hours schedule.
     */
    public function test_fetch_all_command_with_every_four_hours_schedule(): void
    {
        // Set custom every 4 hours schedule
        SettingsHelper::setSetting('scrape_schedule', '0 */4 * * *');

        $schedule = $this->rebuildSchedule();
        $events = collect($schedule->events());

        $fetchAllEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        });

        $this->assertNotNull($fetchAllEvent);

        // Test at midnight
        Carbon::setTestNow('2025-12-28 00:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 00:00');

        // Test at 4 AM
        Carbon::setTestNow('2025-12-28 04:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 04:00');

        // Test at 8 AM
        Carbon::setTestNow('2025-12-28 08:00:00');
        $this->assertTrue($fetchAllEvent->isDue($this->app), 'Should run at 08:00');

        // Test at 3 AM (should not run)
        Carbon::setTestNow('2025-12-28 03:00:00');
        $this->assertFalse($fetchAllEvent->isDue($this->app), 'Should not run at 03:00');
    }

    /**
     * Test model:prune command for LogMessage is scheduled daily.
     */
    public function test_log_message_prune_command_is_scheduled_daily(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneLogEvent = $events->first(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, LogMessage::class);
        });

        $this->assertNotNull($pruneLogEvent, 'LogMessage prune command should be scheduled');
        $this->assertStringContainsString('model:prune', $pruneLogEvent->command ?? '');
    }

    /**
     * Test model:prune for LogMessage runs daily at midnight.
     */
    public function test_log_message_prune_runs_daily_at_midnight(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneLogEvent = $events->first(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, LogMessage::class);
        });

        $this->assertNotNull($pruneLogEvent);

        // Test at midnight
        Carbon::setTestNow('2025-12-28 00:00:00');
        $this->assertTrue($pruneLogEvent->isDue($this->app), 'Should run at midnight');

        // Test at other times
        Carbon::setTestNow('2025-12-28 12:00:00');
        $this->assertFalse($pruneLogEvent->isDue($this->app), 'Should not run at noon');
    }

    /**
     * Test model:prune command for UrlResearch is scheduled daily.
     */
    public function test_url_research_prune_command_is_scheduled_daily(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneUrlResearchEvent = $events->first(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, UrlResearch::class);
        });

        $this->assertNotNull($pruneUrlResearchEvent, 'UrlResearch prune command should be scheduled');
        $this->assertStringContainsString('model:prune', $pruneUrlResearchEvent->command ?? '');
    }

    /**
     * Test model:prune for UrlResearch runs daily at midnight.
     */
    public function test_url_research_prune_runs_daily_at_midnight(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneUrlResearchEvent = $events->first(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, UrlResearch::class);
        });

        $this->assertNotNull($pruneUrlResearchEvent);

        // Test at midnight
        Carbon::setTestNow('2025-12-28 00:00:00');
        $this->assertTrue($pruneUrlResearchEvent->isDue($this->app), 'Should run at midnight');

        // Test at other times
        Carbon::setTestNow('2025-12-28 15:00:00');
        $this->assertFalse($pruneUrlResearchEvent->isDue($this->app), 'Should not run at 3 PM');
    }

    /**
     * Test sanctum:prune-expired command is scheduled daily.
     */
    public function test_sanctum_prune_expired_command_is_scheduled_daily(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneSanctumEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'sanctum:prune-expired');
        });

        $this->assertNotNull($pruneSanctumEvent, 'Sanctum prune-expired command should be scheduled');
        $this->assertStringContainsString('--hours', $pruneSanctumEvent->command ?? '');
        $this->assertStringContainsString('24', $pruneSanctumEvent->command ?? '');
    }

    /**
     * Test sanctum:prune-expired runs daily at midnight.
     */
    public function test_sanctum_prune_expired_runs_daily_at_midnight(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        $pruneSanctumEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'sanctum:prune-expired');
        });

        $this->assertNotNull($pruneSanctumEvent);

        // Test at midnight
        Carbon::setTestNow('2025-12-28 00:00:00');
        $this->assertTrue($pruneSanctumEvent->isDue($this->app), 'Should run at midnight');

        // Test at other times
        Carbon::setTestNow('2025-12-28 09:00:00');
        $this->assertFalse($pruneSanctumEvent->isDue($this->app), 'Should not run at 9 AM');
    }

    /**
     * Test that all four scheduled tasks are present.
     */
    public function test_all_scheduled_tasks_are_present(): void
    {
        $schedule = $this->getSchedule();
        $events = collect($schedule->events());

        // Count tasks
        $fetchAllCount = $events->filter(function ($event) {
            return str_contains($event->command ?? '', FetchAll::COMMAND);
        })->count();

        $logMessagePruneCount = $events->filter(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, LogMessage::class);
        })->count();

        $urlResearchPruneCount = $events->filter(function ($event) {
            $command = $event->command ?? '';

            return str_contains($command, 'model:prune') &&
                   str_contains($command, UrlResearch::class);
        })->count();

        $sanctumPruneCount = $events->filter(function ($event) {
            return str_contains($event->command ?? '', 'sanctum:prune-expired');
        })->count();

        $this->assertEquals(1, $fetchAllCount, 'Should have exactly 1 FetchAll scheduled task');
        $this->assertEquals(1, $logMessagePruneCount, 'Should have exactly 1 LogMessage prune task');
        $this->assertEquals(1, $urlResearchPruneCount, 'Should have exactly 1 UrlResearch prune task');
        $this->assertEquals(1, $sanctumPruneCount, 'Should have exactly 1 Sanctum prune task');
    }
}

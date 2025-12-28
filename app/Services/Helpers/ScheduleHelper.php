<?php

namespace App\Services\Helpers;

use App\Console\Commands\FetchAll;
use App\Models\UrlResearch;
use Illuminate\Console\Scheduling\Schedule;
use Lorisleiva\CronTranslator\CronTranslator;
use Throwable;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

class ScheduleHelper
{
    /**
     * Invalid cron expression text.
     */
    public static function getInvalidCronText(): string
    {
        return __('Invalid cron expression');
    }

    /**
     * Parse cron expression and return a human-readable string or huma-readable invalid text.
     */
    public static function parseCronExpression(?string $expression): string
    {
        try {
            return CronTranslator::translate($expression, CurrencyHelper::getLocale());
        } catch (Throwable $e) {
            return self::getInvalidCronText();
        }
    }

    /**
     * Register the scheduled commands for the application. Abstracted for testing.
     */
    public static function registerSchedule(Schedule $schedule): void
    {
        // Check for new prices
        $schedule->command(FetchAll::COMMAND, ['--log'])
            ->cron(SettingsHelper::getSetting('scrape_schedule', '0 6 * * *'));
        // Prune old log messages
        $schedule->command('model:prune', ['--model' => [LogMessage::class]])->daily();
        // Prune search research results.
        $schedule->command('model:prune', ['--model' => [UrlResearch::class]])->daily();
        // Prune expired Sanctum tokens
        $schedule->command('sanctum:prune-expired', ['--hours' => 24])->daily();
    }
}

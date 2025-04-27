<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppSettings extends Settings
{
    public string $scrape_schedule_time;

    public int $scrape_cache_ttl;

    public int $sleep_seconds_between_scrape;

    public int $log_retention_days;

    public int $max_attempts_to_scrape;

    public array $notification_services;

    public array $integrated_services;

    public array $default_locale_settings;

    public static function new(): self
    {
        return resolve(static::class);
    }

    public static function group(): string
    {
        return 'app';
    }
}

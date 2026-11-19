<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppSettings extends Settings
{
    public string $scrape_schedule = '0 6 * * *';

    public int $scrape_cache_ttl = 720;

    public int $sleep_seconds_between_scrape = 10;

    public int $log_retention_days = 30;

    public int $max_attempts_to_scrape = 3;

    /**
     * Delayed, schedule-level retries for a failed URL scrape. Distinct from
     * max_attempts_to_scrape, which retries immediately in-process within a
     * single scrape. scrape_retry_max_attempts is the TOTAL attempt count
     * including the original scrape; a value <= 1 disables delayed retries.
     */
    public int $scrape_retry_max_attempts = 3;

    public int $scrape_retry_delay_minutes = 15;

    public array $notification_services = [];

    public array $integrated_services = [];

    public array $default_locale_settings = [];

    /**
     * Convert store prices to the default currency. Keys: enabled (bool),
     * provider (ExchangeRateProvider value) and manual_rates (list of
     * ['currency' => ISO code, 'rate' => units of the default currency per 1 unit,
     * 'entered_for' => the default currency the rate was entered for]).
     */
    public array $currency_conversion = [];

    public static function new(): self
    {
        return resolve(static::class);
    }

    public static function group(): string
    {
        return 'app';
    }
}

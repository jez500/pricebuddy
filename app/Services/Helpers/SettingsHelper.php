<?php

namespace App\Services\Helpers;

use App\Settings\AppSettings;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

class SettingsHelper
{
    public static ?array $settings = null;

    public static function getSettings(): array
    {
        try {
            return static::$settings ??
                (config('app.key') && Schema::hasTable('settings')
                    ? AppSettings::new()->toArray()
                    : []
                );
        } catch (Exception $e) {
            return [];
        }
    }

    public static function getSetting(string $name, $default = null): mixed
    {
        try {
            return data_get(static::getSettings(), $name, $default);
        } catch (MissingSettings $e) {
            Log::error("Attempted to get missing setting: $name, you need to run the migrations");

            return $default;
        }
    }

    public static function setSetting(string $name, mixed $value): void
    {
        $settings = static::getSettings();
        data_set($settings, $name, $value);
        AppSettings::new()->fill($settings)->save();
        self::$settings = $settings;
    }
}

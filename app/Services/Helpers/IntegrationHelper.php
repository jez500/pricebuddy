<?php

namespace App\Services\Helpers;

use App\Enums\AiProvider;
use App\Enums\IntegratedServices;

class IntegrationHelper
{
    public static function getSettings(): array
    {
        return once(fn () => SettingsHelper::getSetting('integrated_services', []));
    }

    public static function setSettings(array $settings): void
    {
        SettingsHelper::setSetting('integrated_services', $settings);
    }

    public static function getSearchSettings(): array
    {
        return data_get(self::getSettings(), IntegratedServices::SearXng->value, []);
    }

    public static function getAiSettings(): array
    {
        return data_get(self::getSettings(), IntegratedServices::Ai->value, []);
    }

    public static function isSearchEnabled(): bool
    {
        $searchSettings = self::getSearchSettings();

        return data_get($searchSettings, 'enabled', false)
            && data_get($searchSettings, 'url', null);
    }

    public static function isAiEnabled(): bool
    {
        $aiSettings = self::getAiSettings();

        return (bool) data_get($aiSettings, 'enabled', false)
            && self::getAiProvider() !== null;
    }

    public static function getAiProvider(): ?AiProvider
    {
        $provider = data_get(self::getAiSettings(), 'provider');
        if (! is_string($provider) || $provider === '') {
            return null;
        }

        return AiProvider::tryFrom($provider);
    }
}

<?php

namespace App\Services\Helpers;

use App\Dto\AiProviderConfigDto;
use App\Enums\AiFeature;
use App\Enums\IntegratedServices;
use App\Models\Store;

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
        return (bool) data_get(self::getAiSettings(), 'enabled', false)
            && self::getActiveAiProvider() !== null;
    }

    /**
     * @return array<int, AiProviderConfigDto>
     */
    public static function getAiProviders(): array
    {
        return collect(data_get(self::getAiSettings(), 'providers', []))
            ->map(fn ($provider) => is_array($provider) ? AiProviderConfigDto::fromArray($provider) : null)
            ->filter()
            ->values()
            ->all();
    }

    public static function getActiveAiProvider(): ?AiProviderConfigDto
    {
        $defaultId = data_get(self::getAiSettings(), 'default_provider_id');

        if (blank($defaultId)) {
            return null;
        }

        foreach (self::getAiProviders() as $provider) {
            if ($provider->id === $defaultId) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Resolve a configured AI provider by id, falling back to the global default
     * provider when the id is blank or no longer matches a configured provider.
     */
    public static function getAiProvider(?string $id): ?AiProviderConfigDto
    {
        if (blank($id)) {
            return self::getActiveAiProvider();
        }

        foreach (self::getAiProviders() as $provider) {
            if ($provider->id === $id) {
                return $provider;
            }
        }

        return self::getActiveAiProvider();
    }

    /**
     * Settings sentinel meaning "this AI feature is turned off".
     */
    public const string FEATURE_DISABLED = '__disabled__';

    /**
     * Resolve the provider an AI feature should use for the given store.
     *
     * Returns null when AI is globally disabled, the feature is explicitly
     * disabled, or no provider can be resolved. A store-level provider override
     * (ai_provider_id) takes precedence over the global per-feature selection.
     *
     * An unknown provider id (store override or feature selection) falls back to
     * the global default provider rather than returning null (see getAiProvider).
     */
    public static function resolveFeatureProvider(AiFeature $feature, ?Store $store = null): ?AiProviderConfigDto
    {
        $aiSettings = self::getAiSettings();

        if (! data_get($aiSettings, 'enabled', false)) {
            return null;
        }

        $selected = data_get($aiSettings, 'feature_providers.'.$feature->value);

        if ($selected === self::FEATURE_DISABLED) {
            return null;
        }

        if ($store !== null && filled($store->ai_provider_id)) {
            return self::getAiProvider($store->ai_provider_id);
        }

        return blank($selected)
            ? self::getActiveAiProvider()
            : self::getAiProvider($selected);
    }

    public static function isFeatureEnabled(AiFeature $feature, ?Store $store = null): bool
    {
        return self::resolveFeatureProvider($feature, $store) !== null;
    }
}

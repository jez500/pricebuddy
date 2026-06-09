<?php

namespace App\Settings;

use Illuminate\Support\Str;

class AiProvidersRestructure
{
    /**
     * Convert the legacy single-provider `ai` settings into the multi-provider shape.
     * Idempotent: if `ai.providers` already exists, the input is returned unchanged.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function transform(array $services): array
    {
        $ai = $services['ai'] ?? [];

        if (array_key_exists('providers', $ai)) {
            return $services;
        }

        $providers = [];
        $defaultId = null;

        $type = $ai['provider'] ?? null;

        if (filled($type)) {
            $id = (string) Str::ulid();
            // If the type-specific subkey is missing (corrupt/partial legacy data), fall back
            // to an empty array; the provider entry is still created with null credentials so
            // the migration stays non-destructive.
            $typeSettings = is_array($ai[$type] ?? null) ? $ai[$type] : [];

            $providers[] = [
                'id' => $id,
                'name' => ucfirst((string) $type),
                'type' => $type,
                'base_url' => $typeSettings['base_url'] ?? null,
                'api_key' => $typeSettings['api_key'] ?? null,
                'model' => $type === 'ollama'
                    ? ($typeSettings['model'] ?? null)
                    : ($ai['default_model'] ?? null),
                'timeout_seconds' => (int) ($ai['timeout_seconds'] ?? 60),
                'max_tokens' => (int) ($ai['max_tokens'] ?? 2000),
                'temperature' => (float) ($ai['temperature'] ?? 0.2),
            ];
            $defaultId = $id;
        }

        $services['ai'] = [
            'enabled' => (bool) ($ai['enabled'] ?? false),
            'default_provider_id' => $defaultId,
            'providers' => $providers,
        ];

        return $services;
    }
}

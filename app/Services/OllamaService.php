<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    public const int DEFAULT_TIMEOUT = 10;

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Fetch the list of model names available on an Ollama server via /api/tags.
     *
     * @return array<int, string>
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function listModels(string $baseUrl, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $response = Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout($timeout)
            ->throw()
            ->get('/api/tags');

        return collect($response->json('models', []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }
}

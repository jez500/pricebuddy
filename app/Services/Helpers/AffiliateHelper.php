<?php

namespace App\Services\Helpers;

use Illuminate\Support\Uri;

class AffiliateHelper
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('affiliates', []);
    }

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function getSettingsFromUrl(Uri $uri): ?array
    {
        return once(function () use ($uri) {
            if ($this->getConfig('enabled', false) === false) {
                return null;
            }

            $host = str_starts_with($uri->host(), 'www.')
                ? substr($uri->host(), 4)
                : $uri->host();

            return collect($this->getConfig('sites'))
                ->filter(fn ($site) => in_array($host, $site['domains']))
                ->first();
        });
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function parseUrl(string $url): string
    {
        $uri = Uri::of($url);

        $settings = $this->getSettingsFromUrl($uri);

        if ($settings === null || ! $settings['query_params']) {
            return $url;
        }

        $uri = $uri->withQueryIfMissing($settings['query_params']);

        return $uri->value();
    }
}

<?php

namespace App\Services;

use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Uri;

class MetaExtractionService
{
    public function __construct(
        protected int $timeout = 10,
    ) {}

    public static function new(int $timeout = 10): self
    {
        return resolve(static::class, [
            'timeout' => $timeout,
        ]);
    }

    public function extract(string $url, array $storeOverride = []): array
    {
        $store = $this->resolveStore($url, $storeOverride);

        if ($store) {
            return $this->extractWithStore($url, $store);
        }

        return $this->extractWithAutoCreate($url);
    }

    protected function extractWithStore(string $url, Store $store): array
    {
        $result = ScrapeUrl::new($url)
            ->setMaxAttempts(1)
            ->setConnectTimeout($this->timeout)
            ->setRequestTimeout($this->timeout)
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->scrape([
                'store' => $store,
                'use_cache' => false,
            ]);

        return $this->normalizeResult($result);
    }

    protected function extractWithAutoCreate(string $url): array
    {
        $autoCreateStore = AutoCreateStore::new($url, timeout: $this->timeout)
            ->setLogErrors(false);

        $result = $autoCreateStore->strategyParse();
        $price = data_get($result, 'price.data');

        return [
            'title' => data_get($result, 'title.data'),
            'price' => $price === null || $price === ''
                ? null
                : CurrencyHelper::toFloat($price),
            'image' => data_get($result, 'image.data'),
        ];
    }

    protected function resolveStore(string $url, array $storeOverride = []): ?Store
    {
        $host = Uri::of($url)->host();
        $store = Store::findByDomain($host);

        if (empty($storeOverride)) {
            return $store;
        }

        $overrideCookies = data_get($storeOverride, 'settings.cookies', data_get($storeOverride, 'cookies', $store?->cookies));

        $merged = [
            'name' => data_get($storeOverride, 'name', $store->name ?? ucfirst($host)),
            'domains' => data_get($storeOverride, 'domains', $store->domains ?? [
                ['domain' => $host],
            ]),
            'scrape_strategy' => array_replace_recursive(
                $store->scrape_strategy ?? [],
                data_get($storeOverride, 'scrape_strategy', [])
            ),
            'settings' => array_replace_recursive(
                $store->settings ?? [],
                data_get($storeOverride, 'settings', [])
            ),
        ];

        if (! is_null($overrideCookies)) {
            $merged['cookies'] = $overrideCookies;
        }

        return new Store($merged);
    }

    protected function normalizeResult(array $result): array
    {
        $price = data_get($result, 'price');

        return [
            'title' => data_get($result, 'title'),
            'price' => $price === null || $price === ''
                ? null
                : CurrencyHelper::toFloat($price),
            'image' => data_get($result, 'image'),
        ];
    }
}

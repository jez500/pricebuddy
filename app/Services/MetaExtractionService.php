<?php

namespace App\Services;

use App\Enums\ScraperService;
use App\Enums\StockStatus;
use App\Models\Store;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Uri;
use Throwable;

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

    /**
     * @param  array<string, mixed>  $storeOverride
     * @return array<string, mixed>
     */
    public function extract(string $url, array $storeOverride = []): array
    {
        $store = $this->resolveStore($url, $storeOverride);

        if ($store) {
            return $this->extractWithStore($url, $store);
        }

        return $this->extractWithAutoCreate($url);
    }

    /**
     * @return array<string, mixed>
     */
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

        if ($this->shouldHeal($store, $result)) {
            $config = $this->healPreview($url, $store, data_get($result, 'body'));

            if ($config !== null) {
                AiConfigHealer::new()->applyPreviewToStore($store, $config);

                foreach ($config['extracted'] as $field => $value) {
                    data_set($result, $field, $value);
                }
            }
        }

        return $this->normalizeResult($result);
    }

    /**
     * Whether a failed-to-find-price scrape should attempt AI healing. Mirrors the UI
     * add-URL gate: only when the store hasn't opted out, no price was found, and the
     * item is not detected as unavailable. The global Healing feature flag is enforced
     * separately by previewForUrl().
     *
     * @param  array<string, mixed>  $rawScrapeResult
     */
    private function shouldHeal(Store $store, array $rawScrapeResult): bool
    {
        if ($store->ai_self_healing_disabled) {
            return false;
        }

        if (filled(data_get($rawScrapeResult, 'price'))) {
            return false;
        }

        return ! StockStatus::resolveAvailability(
            data_get($rawScrapeResult, 'availability'),
            $store->scrape_strategy->availability,
        )->isUnavailable();
    }

    /**
     * Run preview-only AI healing for the URL, swallowing any error so a healing failure
     * never turns a normal extraction into a 500.
     *
     * @return array{fields: array<string, array<string, mixed>>, extracted: array<string, mixed>, usedBrowser: bool}|null
     */
    private function healPreview(string $url, ?Store $store, ?string $html): ?array
    {
        try {
            return AiConfigHealer::new()->previewForUrl($url, $store, $html);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractWithAutoCreate(string $url): array
    {
        $autoCreateStore = AutoCreateStore::new($url, timeout: $this->timeout)
            ->setLogErrors(false);

        $detected = $autoCreateStore->detect();

        // No viable (title+price) strategy detected deterministically: try preview-only
        // AI healing, and fall back to whatever partial data was found (no store).
        if ($detected === null) {
            $config = $this->healPreview($url, null, $autoCreateStore->getHtml());

            if ($config !== null) {
                $attributes = AutoCreateStore::buildAttributes($url, $config['fields']);

                if ($config['usedBrowser']) {
                    data_set($attributes, 'settings.scraper_service', ScraperService::Api->value);
                }

                $price = data_get($config, 'extracted.price');

                return [
                    'title' => data_get($config, 'extracted.title'),
                    'price' => $price === null || $price === ''
                        ? null
                        : CurrencyHelper::toFloat($price),
                    'image' => data_get($config, 'extracted.image'),
                    'availability' => data_get($config, 'extracted.availability'),
                    'store' => new Store($attributes),
                ];
            }

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

        $price = data_get($detected, 'extracted.price');

        return [
            'title' => data_get($detected, 'extracted.title'),
            'price' => $price === null || $price === ''
                ? null
                : CurrencyHelper::toFloat($price),
            'image' => data_get($detected, 'extracted.image'),
            'availability' => data_get($detected, 'extracted.availability'),
            // Return the detected (unsaved) store so a successful auto-create
            // extraction always carries the strategy it built.
            'store' => new Store(AutoCreateStore::buildAttributes($url, $detected['fields'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $storeOverride
     */
    protected function resolveStore(string $url, array $storeOverride = []): ?Store
    {
        $host = Uri::of($url)->host();
        $store = Store::query()->domainFilter($host)->oldest()->first();

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
                $store?->scrape_strategy?->toArray() ?? [],
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeResult(array $result): array
    {
        $price = data_get($result, 'price');

        return [
            'title' => data_get($result, 'title'),
            'price' => $price === null || $price === ''
                ? null
                : CurrencyHelper::toFloat($price),
            'image' => data_get($result, 'image'),
            'description' => data_get($result, 'description'),
            'availability' => data_get($result, 'availability'),
            'store' => data_get($result, 'store'),
        ];
    }
}

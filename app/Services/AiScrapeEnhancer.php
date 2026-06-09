<?php

namespace App\Services;

use App\Enums\AiFeature;
use App\Enums\StockStatus;
use App\Exceptions\AiProviderException;
use App\Models\Url;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Support\Facades\Log;

class AiScrapeEnhancer
{
    public const float MIN_CONFIDENCE = 0.6;

    public function __construct(protected AiExtractionService $extraction) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Backfill a missing price via AI when enabled and allowed. AI is purely additive:
     * any guard failure returns the scrape result exactly as it was scraped.
     *
     * @param  array<string, mixed>  $scrapeResult
     * @return array<string, mixed>
     */
    public function enhance(Url $url, array $scrapeResult): array
    {
        // Only fill a genuine gap. The scraped price is a raw string here, so filled()
        // treats null/'' as a gap and any non-empty value as already-present.
        if (filled(data_get($scrapeResult, 'price'))) {
            return $scrapeResult;
        }

        $store = $url->store;

        // Enablement is per-store; a product inherits it from the store of each URL.
        if (! $store?->ai_extraction_enabled) {
            return $scrapeResult;
        }

        // Resolve the extraction provider (honours the global per-feature select
        // and the store's provider override).
        $provider = IntegrationHelper::resolveFeatureProvider(AiFeature::Extraction, $store);

        if ($provider === null) {
            return $scrapeResult;
        }

        $html = data_get($scrapeResult, 'body');

        if (blank($html)) {
            return $scrapeResult;
        }

        // An out-of-stock item has no purchasable price; don't spend tokens.
        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrapeResult, 'availability'), $matchConfig)
            ->isUnavailable();

        if ($isUnavailable) {
            return $scrapeResult;
        }

        try {
            $result = $this->extraction->extract($html, provider: $provider);
        } catch (AiProviderException $e) {
            // @phpstan-ignore-next-line - withContext is valid.
            Log::channel('db')->withContext(['url' => $url->url])
                ->warning('AI provider error during scrape enhancement; leaving price unchanged.', ['error' => $e->getMessage()]);

            return $scrapeResult;
        }

        if ($result === null || $result->price === null || $result->confidence < self::MIN_CONFIDENCE) {
            // @phpstan-ignore-next-line - withContext is valid.
            Log::channel('db')->withContext(['url' => $url->url])
                ->debug('AI could not recover a confident price.');

            return $scrapeResult;
        }

        data_set($scrapeResult, 'price', $result->price);

        // @phpstan-ignore-next-line - withContext is valid.
        Log::channel('db')->withContext(['url' => $url->url])
            ->info('Price recovered via AI (confidence '.number_format($result->confidence, 2).').');

        return $scrapeResult;
    }
}

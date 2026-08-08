<?php

namespace App\Services;

use App\Dto\HealingOutcomeDto;
use App\Enums\AiFeature;
use App\Enums\HealingReason;
use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\Ai\AiProviderHealth;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Support\Uri;
use Throwable;

class MetaExtractionService
{
    protected int $budgetSeconds;

    protected int $healFloorSeconds;

    public function __construct(
        protected int $timeout = 10,
        ?int $budgetSeconds = null,
        ?int $healFloorSeconds = null,
    ) {
        $this->budgetSeconds = $budgetSeconds ?? (int) config('price_buddy.meta_extraction.budget_seconds', 25);
        $this->healFloorSeconds = $healFloorSeconds ?? (int) config('price_buddy.meta_extraction.heal_floor_seconds', 5);
    }

    public static function new(int $timeout = 10): self
    {
        return resolve(static::class, [
            'timeout' => $timeout,
        ]);
    }

    /**
     * $heal opts in to AI healing. It defaults to false because this endpoint is
     * synchronous: a caller testing the selectors it just typed wants a fast,
     * deterministic answer, and healing may return a different strategy than the one
     * under test. Healing is a slow, explicitly-chosen action.
     *
     * @param  array<string, mixed>  $storeOverride
     * @return array<string, mixed>
     */
    public function extract(string $url, array $storeOverride = [], bool $heal = false): array
    {
        $budget = new ExtractionBudget($this->budgetSeconds);
        $outcome = new HealingOutcomeDto;

        $store = $this->resolveStore($url, $storeOverride);

        $result = $store
            ? $this->extractWithStore($url, $store, $heal, $budget, $outcome)
            : $this->extractWithAutoCreate($url, $heal, $budget, $outcome);

        $result['healing'] = $outcome;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractWithStore(string $url, Store $store, bool $heal, ExtractionBudget $budget, HealingOutcomeDto $outcome): array
    {
        $result = ScrapeUrl::new($url)
            ->setMaxAttempts(1)
            ->setConnectTimeout($this->scrapeTimeout($budget))
            ->setRequestTimeout($this->scrapeTimeout($budget))
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->scrape([
                'store' => $store,
                'use_cache' => false,
            ]);

        if ($skipReason = $this->healSkipReason($store, $result)) {
            $outcome->skipped($skipReason);

            return $this->normalizeResult($result);
        }

        $config = $this->healPreview($url, $store, data_get($result, 'body'), $heal, $budget, $outcome);

        if ($config !== null) {
            AiConfigHealer::new()->applyPreviewToStore($store, $config);

            foreach ($config['extracted'] as $field => $value) {
                data_set($result, $field, $value);
            }
        }

        return $this->normalizeResult($result);
    }

    /**
     * Why a failed-to-find-price scrape should NOT attempt AI healing, or null when it
     * should. Mirrors the UI add-URL gate: only when the store hasn't opted out, no price
     * was found, and the item is not detected as unavailable. The global Healing feature
     * flag is enforced separately, in healPreview().
     *
     * @param  array<string, mixed>  $rawScrapeResult
     */
    private function healSkipReason(Store $store, array $rawScrapeResult): ?HealingReason
    {
        if ($store->ai_self_healing_disabled) {
            return HealingReason::Disabled;
        }

        if (filled(data_get($rawScrapeResult, 'price'))) {
            return HealingReason::NotNeeded;
        }

        $isUnavailable = ScrapeUrl::resolveStockStatus(
            $rawScrapeResult,
            $store->scrape_strategy->availability,
        )->isUnavailable();

        return $isUnavailable ? HealingReason::NotNeeded : null;
    }

    /**
     * Run preview-only AI healing for the URL within the remaining budget, recording what
     * happened on $outcome. Every failure mode — opted out, no provider, no budget left,
     * provider error, no usable plan — returns null and leaves the deterministic result
     * intact, so healing can never turn a working extraction into an error response.
     *
     * @return array{fields: array<string, array<string, mixed>>, extracted: array<string, mixed>, usedBrowser: bool}|null
     */
    private function healPreview(string $url, ?Store $store, ?string $html, bool $heal, ExtractionBudget $budget, HealingOutcomeDto $outcome): ?array
    {
        $provider = $heal ? IntegrationHelper::resolveFeatureProvider(AiFeature::Healing, $store) : null;

        if ($provider === null) {
            $outcome->skipped(HealingReason::Disabled);

            return null;
        }

        // A provider that has failed repeatedly will almost certainly fail again, and
        // finding out costs this request its whole budget. Callers that can afford to
        // wait (the admin UI, queued jobs) skip this check, so they keep probing and any
        // success closes the breaker.
        if (AiProviderHealth::new()->isUnavailable($provider)) {
            $outcome->skipped(HealingReason::ProviderUnavailable);

            return null;
        }

        // Starting an agent run that cannot finish costs the caller the whole remaining
        // wait and can only return a cut-off answer, so decline it up front instead.
        if (! $budget->hasAtLeast($this->healFloorSeconds)) {
            $outcome->skipped(HealingReason::Timeout);

            return null;
        }

        $outcome->started();

        try {
            $config = AiConfigHealer::new()->previewForUrl($url, $store, $html, $budget);
        } catch (Throwable $e) {
            $outcome->failed($this->healFailureReason($budget));

            return null;
        }

        if ($config === null) {
            $outcome->failed($this->healFailureReason($budget));

            return null;
        }

        $outcome->succeeded();

        return $config;
    }

    /**
     * Distinguish "healing ran out of time" from "healing failed for its own reasons".
     * The healer reports both as a null config, so the budget is the evidence: an attempt
     * that leaves too little to have started at all is one that spent its whole
     * allowance, which is what a client should report as a timeout. Anything faster
     * failed for another reason — no provider response, an unusable plan, a bad page.
     */
    private function healFailureReason(ExtractionBudget $budget): HealingReason
    {
        return $budget->hasAtLeast($this->healFloorSeconds)
            ? HealingReason::Error
            : HealingReason::Timeout;
    }

    /**
     * The scrape never gets more than the per-scrape timeout, and never more than the
     * request has left.
     *
     * The scrape is the first thing a request does, so the budget is always full here;
     * the one-second floor only bites on a misconfigured near-zero budget, where the
     * scrape still has to be attempted with something other than Guzzle's "0 means no
     * timeout".
     */
    private function scrapeTimeout(ExtractionBudget $budget): int
    {
        return max(1, min($this->timeout, $budget->remainingSecondsForTimeout() ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractWithAutoCreate(string $url, bool $heal, ExtractionBudget $budget, HealingOutcomeDto $outcome): array
    {
        $autoCreateStore = AutoCreateStore::new($url, timeout: $this->scrapeTimeout($budget))
            ->setLogErrors(false);

        $detected = $autoCreateStore->detect();

        // No viable (title+price) strategy detected deterministically: try preview-only
        // AI healing, and fall back to whatever partial data was found (no store).
        if ($detected === null) {
            $config = $this->healPreview($url, null, $autoCreateStore->getHtml(), $heal, $budget, $outcome);

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

        $outcome->skipped(HealingReason::NotNeeded);

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
            'name' => data_get($storeOverride, 'name', $store?->name ?? ucfirst($host)), // @phpstan-ignore nullsafe.neverNull
            'domains' => data_get($storeOverride, 'domains', $store?->domains ?? [ // @phpstan-ignore nullsafe.neverNull
                ['domain' => $host],
            ]),
            'scrape_strategy' => array_replace_recursive(
                $store?->scrape_strategy?->toArray() ?? [],
                data_get($storeOverride, 'scrape_strategy', [])
            ),
            'settings' => array_replace_recursive(
                $store?->settings ?? [], // @phpstan-ignore nullsafe.neverNull
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

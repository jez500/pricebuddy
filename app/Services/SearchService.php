<?php

namespace App\Services;

use App\Dto\ProductResearchUrlDto;
use App\Enums\Icons;
use App\Models\ProductSource;
use App\Models\Store;
use App\Models\UrlResearch;
use App\Services\Helpers\IntegrationHelper;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class SearchService
{
    public const string CACHE_KEY = 'search:';

    public const int CACHE_TTL_MINS = 30;

    public const string LOG_KEY = 'log';

    public const int LOG_TTL_MINS = 60; // 1 hour

    public const int DEFAULT_MAX_PAGES = 1;

    public Collection $results;

    public ?string $searchQuery = null;

    protected ?ProductSource $productSource = null;

    protected bool $useLaravelLog = false;

    protected array $ignoredExtensions = ['pdf', 'doc', 'xls', 'ppt', 'jpg', 'png', 'jpeg'];

    public function __construct(?string $query = null)
    {
        $this->results = collect();
        $this->searchQuery = $query;
    }

    public static function new(?string $query = null): self
    {
        return resolve(static::class, ['query' => $query]);
    }

    public function setProductSource(?ProductSource $productSource): self
    {
        $this->productSource = $productSource;

        return $this;
    }

    public function build(string $searchQuery): self
    {
        $this->searchQuery = $searchQuery;

        $this->logReset();
        $this->log('Starting research for: '.$searchQuery);

        try {
            $builder = $this
                ->setIsComplete(false)
                ->setInProgress(true)
                ->getProductSourceResults();

            // Only call getRawResults() if no specific product source is set
            if (! $this->productSource) {
                $builder->getRawResults();
            }

            $builder
                ->filterResults()
                ->normalizeStructure()
                ->addStores()
                ->hydrateWithScrapedData()
                ->saveUrlResearch()
                ->log('Completed research for: '.$searchQuery)
                ->setIsComplete(true);
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        $this
            ->setInProgress(false);

        return $this;
    }

    public function getResults(): Collection
    {
        return $this->results;
    }

    public function saveUrlResearch(): self
    {
        $this->log('Saving URL research');

        $this->results->each(function ($result) {
            UrlResearch::updateOrCreate(
                ['url' => $result['url']],
                collect($result)->only([
                    'html', 'title', 'image', 'price', 'store_id', 'strategies', 'execution_time',
                ])->all()
            );
        });

        return $this;
    }

    public function getSearchUrl(): ?string
    {
        return data_get(self::getSettings(), 'url');
    }

    public function getMaxPages(): int
    {
        return data_get(self::getSettings(), 'max_pages', self::DEFAULT_MAX_PAGES);
    }

    public function getProductSourceResults(): self
    {
        // If a specific product source is set, use only that one
        if ($this->productSource) {
            $sources = collect([$this->productSource]);
        } else {
            $sources = ProductSource::userScopedQuery()->get();
        }

        $this->log(__('Using :count product sources'), ['count' => $sources->count()]);

        $sources->each(function ($source) {
            /** @var ProductSource $source */
            try {
                $results = $source->search($this->searchQuery);
                $this->log(__('Found :count results via :source', ['count' => $results->count(), 'source' => $source->name]));
                $this->results = $this->results->merge($results);
            } catch (Throwable $e) {
                $this->log(__('Error searching via :source: :error', ['source' => $source->name, 'error' => $e->getMessage()]));
            }
        });

        return $this;
    }

    public function getRawResults(): self
    {
        $this->log('Fetching raw search results');

        $results = [];

        // For each page, get the results and merge them into the results array.
        for ($page = 1; $page <= $this->getMaxPages(); $page++) {
            // Merge page results, cache if not already cached.
            $results = array_merge(
                $results,
                $this->getRawResultsForPage($page)
            );
        }

        $this->results = $this->results->merge($results);

        $this->log(__('Found :count results via SearchXNG', ['count' => count($results)]));

        return $this;
    }

    protected function getRawResultsForPage(int $page): array
    {
        try {
            return Cache::remember(
                $this->getCacheKey('results', $this->searchQuery).':page-'.$page,
                now()->addMinutes(self::CACHE_TTL_MINS),
                fn () => Http::timeout(10)
                    ->get($this->getSearchUrl(), [
                        'format' => 'json',
                        'q' => $this->searchQuery,
                        'pageno' => $page,
                    ])
                    ->throw()
                    ->json('results', []));
        } catch (Throwable $e) {
            $this->log(__('Error fetching results via SearchXNG: :error', ['error' => $e->getMessage()]));
        }

        return [];
    }

    public function flushRawResultsCache(int $page = 1): self
    {
        $key = $this->getCacheKey('results', $this->searchQuery).':page-'.$page;
        Cache::forget($key);

        return $this;
    }

    public function filterResults(): self
    {
        $this->log('Filtering incompatible results');
        $usedUrls = [];

        $this->results = $this->results->filter(function ($result) use (&$usedUrls) {
            $url = data_get($result, 'url');

            if (in_array($url, $usedUrls)) {
                return false;
            }

            $usedUrls[] = $url;
            $extension = pathinfo($url, PATHINFO_EXTENSION);

            return empty($extension) || ! in_array($extension, $this->ignoredExtensions);
        })->values();

        return $this;
    }

    public function normalizeStructure(): self
    {
        $this->results = $this->results->map(function ($result, $idx) {
            return [
                'title' => data_get($result, 'title'),
                'url' => data_get($result, 'url'),
                'snippet' => data_get($result, 'content'),
                'thumbnail' => data_get($result, 'thumbnail'),
                'domain' => parse_url(data_get($result, 'url'), PHP_URL_HOST),
                'relevance' => $idx,
            ];
        });

        return $this;
    }

    protected function addStores(): self
    {
        $this->log('Adding stores to search results');

        $domains = $this->results->pluck('domain')->toArray();
        $stores = Store::query()->select('id', 'domains')->domainFilter($domains)->get();

        $this->results = $this->results
            ->map(function ($result) use ($stores) {
                $store = $stores->filter(fn ($store) => $store->hasDomain($result['domain']))->first();
                $result['store_id'] = $store->id ?? null;

                return $result;
            });

        return $this;
    }

    public function hydrateWithScrapedData(): self
    {
        $this->log('Hydrating results');

        $existing = $this->getUrlResearch();

        $this->results = $this->results
            ->map(function ($result) use ($existing) {
                $logArgs = collect($result)->only(['title', 'url', 'domain'])->all();

                if ($existing->get($result['url'])) {
                    $this->log(__('Using cache ":title" (:domain)', $logArgs), ['subtitle' => $result['url'], 'icon' => Icons::Database->value]);

                    return $result;
                }

                $timeStart = microtime(true);
                $this->log(__('Analyzing ":title" (:domain)', $logArgs), ['subtitle' => $result['url']]);

                try {
                    $dto = new ProductResearchUrlDto(url: $result['url'], cached: true);

                    $result = array_merge($result, [
                        'price' => $dto->getPrice(),
                        'image' => $dto->getImage(),
                        'strategies' => $dto->getStrategies(),
                        'is_product_page' => $dto->getIsProductPage()->value,
                        'html' => $dto->getHtml(),
                    ]);

                    if (! empty($result['price'])) {
                        $this->replaceLastLogEntry(__('Price found ":title" (:domain)', $logArgs));
                    } else {
                        $this->replaceLastLogEntry(__('No Price found ":title" (:domain)', $logArgs), ['icon' => Icons::Warning->value]);
                    }
                } catch (Exception $e) {
                    $this->log(__('Failed for ":title": :error', array_merge($logArgs, ['error' => $e->getMessage()])), ['subtitle' => $result['url']]);
                }

                $result['execution_time'] = (microtime(true) - $timeStart);

                return $result;
            });

        return $this;
    }

    public static function getSettings(): array
    {
        return IntegrationHelper::getSearchSettings();
    }

    public function getUrlResearch(): Collection
    {
        return UrlResearch::query()
            ->whereIn('url', $this->results->pluck('url'))
            ->get()
            ->keyBy('url');
    }

    protected function getCacheKey(string $type, string $key): string
    {
        return self::CACHE_KEY.$type.':'.md5($key);
    }

    protected function getInProgressKey(): string
    {
        return $this->getCacheKey('in_progress', $this->searchQuery);
    }

    protected function getIsCompleteKey(): string
    {
        return $this->getCacheKey('complete', $this->searchQuery);
    }

    protected function getLogKey(): string
    {
        return $this->getCacheKey(self::LOG_KEY, $this->searchQuery);
    }

    public function getInProgress(?string $searchQuery = null): false|string
    {
        if ($searchQuery) {
            $this->searchQuery = $searchQuery;
        }

        return Cache::get($this->getInProgressKey(), false);
    }

    protected function setInProgress(bool $inProgress): self
    {
        if ($inProgress) {
            Cache::put($this->getInProgressKey(), now()->toDateTimeString(), now()->addMinutes(self::CACHE_TTL_MINS));
        } else {
            Cache::forget($this->getInProgressKey());
        }

        return $this;
    }

    public function getIsComplete(): false|string
    {
        return Cache::get($this->getIsCompleteKey(), false);
    }

    protected function setIsComplete(bool $complete): self
    {
        if ($complete) {
            Cache::put($this->getIsCompleteKey(), now()->toDateTimeString(), now()->addMinutes(self::CACHE_TTL_MINS));
        } else {
            Cache::forget($this->getIsCompleteKey());
        }

        return $this;
    }

    public function log(string $message, array $data = []): self
    {
        $cache = Cache::get($this->getLogKey(), []);

        $cache[] = [
            'message' => $message,
            'data' => array_merge(['icon' => Icons::Success->value], $data),
            'timestamp' => now()->toDateTimeString(),
        ];

        Cache::put($this->getLogKey(), $cache, now()->addMinutes(self::LOG_TTL_MINS));

        if ($this->useLaravelLog) {
            logger()->info($message, $data);
        }

        return $this;
    }

    public function replaceLastLogEntry(string $message, array $data = []): self
    {
        $cache = Cache::get($this->getLogKey(), []);

        if (! empty($cache)) {
            $last = array_pop($cache);
            Cache::put($this->getLogKey(), $cache, now()->addMinutes(self::LOG_TTL_MINS));
        }

        $this->log($message, array_merge($last['data'] ?? [], $data));

        return $this;
    }

    public function setUseLaravelLog(bool $useLaravelLog): self
    {
        $this->useLaravelLog = $useLaravelLog;

        return $this;
    }

    public function getLog(?string $searchQuery = null): array
    {
        if ($searchQuery) {
            $this->searchQuery = $searchQuery;
        }

        return Cache::get($this->getLogKey(), []);
    }

    public function logReset(): self
    {
        Cache::delete($this->getLogKey());

        return $this;
    }

    public static function canSearch(): bool
    {
        return IntegrationHelper::isSearchEnabled() || ProductSource::userScopedQuery()->count() > 0;
    }
}

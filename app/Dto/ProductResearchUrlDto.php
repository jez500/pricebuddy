<?php

namespace App\Dto;

use App\Enums\IsProductPage as IsProductPageEnum;
use App\Models\Store;
use App\Services\AutoCreateStore;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ScrapeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Uri;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;

class ProductResearchUrlDto
{
    protected IsProductPageEnum $isProductPage;

    protected ?array $scrapeResult = null;

    protected ?Store $store = null;

    protected WebScraperInterface $scraper;

    protected ?AutoCreateStore $autoCreateStore = null;

    protected ?array $autoCreateStoreStrategies = null;

    protected ?string $html = null;

    protected Uri $uri;

    protected int $httpTimeout = 10;

    public function __construct(
        protected string $url,
        protected $cached = false,
    ) {
        $this->uri = Uri::of($url);

        $cacheKey = 'search_result_dto_'.md5($url);
        $this->isProductPage = Cache::remember($cacheKey, now()->addMinutes(30), function () {
            $this->isProductPage = IsProductPageEnum::NotProcessed;
            $this->guessIsProductPage();

            return $this->isProductPage;
        });
    }

    public function guessIsProductPage(): self
    {
        if ($this->getIsProductPage() !== IsProductPageEnum::NotProcessed) {
            return $this;
        }

        $newStatus = IsProductPageEnum::Maybe;
        if ($this->canScrapeViaStore()) {
            $newStatus = IsProductPageEnum::YesViaStore;
        } elseif ($this->canScrapeViaAuto()) {
            $newStatus = IsProductPageEnum::YesViaAutoCreate;
        }

        $this->isProductPage = $newStatus;

        return $this;
    }

    public function setIsProductPage(IsProductPageEnum $isProductPage): self
    {
        $this->isProductPage = $isProductPage;

        return $this;
    }

    public function getIsProductPage(): IsProductPageEnum
    {
        return $this->isProductPage;
    }

    public function getScrapeUrlService(): ScrapeUrl
    {
        return ScrapeUrl::new($this->url)
            ->setMaxAttempts(1)
            ->setLogErrors(false)
            ->setSendUiNotifications(false)
            ->setConnectTimeout($this->httpTimeout)
            ->setRequestTimeout($this->httpTimeout);
    }

    public function getAutoCreateStoreService(): AutoCreateStore
    {
        if (! $this->autoCreateStore) {
            $this->autoCreateStore = AutoCreateStore::new($this->getUrl(), timeout: $this->httpTimeout)
                ->setLogErrors(false);
        }

        return $this->autoCreateStore;
    }

    public function getAutoCreateStoreStrategies(): ?array
    {
        if (! $this->autoCreateStoreStrategies) {
            $this->autoCreateStoreStrategies = $this->getAutoCreateStoreService()->strategyParse();
        }

        return $this->autoCreateStoreStrategies;
    }

    public function getStoreResult(): array
    {
        if ($this->scrapeResult) {
            return $this->scrapeResult;
        }

        $this->scrapeResult = $this->getScrapeUrlService()->scrape();

        return $this->scrapeResult;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getScraper(): WebScraperInterface
    {
        return WebScraper::http()->from($this->url)
            ->setConnectTimeout($this->httpTimeout)
            ->setRequestTimeout($this->httpTimeout)
            ->get();
    }

    public function hasStore(): bool
    {
        return ! empty($this->getStore());
    }

    public function getStore(): ?Store
    {
        if ($this->store) {
            return $this->store;
        }

        $this->store = Store::findByDomain($this->uri->host());

        return $this->store;
    }

    public function getPriceRaw(): ?string
    {
        return $this->getScrapedValue('price');
    }

    public function getImage(): ?string
    {
        return $this->getScrapedValue('image');
    }

    protected function getScrapedValue(string $strategy): ?string
    {
        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            return $this->getStoreResult()[$strategy] ?? null;
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            return data_get($this->getAutoCreateStoreStrategies(), $strategy.'.data');
        }

        return null;
    }

    public function getPrice(): ?float
    {
        $price = $this->getPriceRaw();

        return $price ? CurrencyHelper::toFloat($price) : null;
    }

    public function getStrategies(): array
    {
        $strategy = [];

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            $strategy = $this->getStore()->scrape_strategy;
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            $strategy = $this->getAutoCreateStoreStrategies();
        }

        return $strategy;
    }

    public function getHtml(): ?string
    {
        $html = null;

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            $html = $this->getStoreResult()['body'] ?? '';
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            $html = $this->getAutoCreateStoreService()->getHtml();
        }

        return $html;
    }

    public function canScrapeViaStore(): bool
    {
        $scraped = $this->getStoreResult();

        return $this->hasStore()
            && ! empty(data_get($scraped, 'title'))
            && ! empty(data_get($scraped, 'price'));
    }

    public function canScrapeViaAuto(): bool
    {
        return ! is_null($this->getAutoCreateStoreService()->getStoreAttributes());
    }
}

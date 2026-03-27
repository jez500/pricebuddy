<?php

namespace App\Dto;

use App\Enums\StockStatus;
use App\Enums\Trend;
use App\Models\Price;
use App\Models\Product;
use App\Services\Helpers\CurrencyHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PriceCacheDto
{
    private ?int $storeId;

    private ?string $storeName;

    private ?int $urlId;

    private ?string $url;

    private string $trend;

    private float $price;

    private float $unitPrice;

    private float $priceFactor;

    private array $history;

    private ?Carbon $lastScrapeDate;

    private string $locale;

    private string $currency;

    private ?string $unitOfMeasure;

    private StockStatus $stockStatus;

    public function __construct(
        float $price,
        ?int $storeId = null,
        ?string $storeName = null,
        ?int $urlId = null,
        ?string $url = null,
        string $trend = Trend::None->value,
        array $history = [],
        ?string $lastScrape = null,
        ?string $locale = null,
        ?string $currency = null,
        ?float $unitPrice = null,
        float $priceFactor = 1,
        ?string $unitOfMeasure = null,
        ?string $availability = null,
    ) {
        $this->storeId = $storeId;
        $this->storeName = $storeName;
        $this->urlId = $urlId;
        $this->url = $url;
        $this->trend = $trend;
        $this->price = $price;
        $this->unitPrice = $unitPrice ?? $price;
        $this->priceFactor = $priceFactor;
        $this->history = $history;
        $this->lastScrapeDate = $lastScrape ? Carbon::parse($lastScrape) : null;
        $this->locale = $locale ?? CurrencyHelper::getLocale();
        $this->currency = $currency ?? CurrencyHelper::getCurrency();
        $this->unitOfMeasure = $unitOfMeasure;
        $this->stockStatus = StockStatus::fromScrapedValue($availability);
    }

    // Getters
    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function getStoreName(): ?string
    {
        return $this->storeName;
    }

    public function getUrlId(): ?int
    {
        return $this->urlId;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getTrend(): string
    {
        return $this->trend;
    }

    public function getTrendColor(): string
    {
        return Trend::getColor($this->getTrend());
    }

    public function getTrendIcon(): string
    {
        return Trend::getIcon($this->getTrend());
    }

    public function getTrendText(): string
    {
        return Trend::getText($this->getTrend());
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getPriceFormatted(): string
    {
        return CurrencyHelper::toString($this->getPrice(), locale: $this->locale, iso: $this->currency);
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getUnitPriceFormatted(): string
    {
        return CurrencyHelper::toString($this->getUnitPrice(), locale: $this->locale, iso: $this->currency);
    }

    public function getUnitOfMeasure(): ?string
    {
        return $this->unitOfMeasure;
    }

    public function getPriceFactor(): float
    {
        return $this->priceFactor;
    }

    public function hasPriceHistory(): bool
    {
        return ! empty($this->history);
    }

    public function hasVisiblePrice(): bool
    {
        return ! ($this->isUnavailable() && ! $this->hasPriceHistory() && $this->getPrice() <= 0);
    }

    public function getHistory(int $count = 365): Collection
    {
        return collect($this->history)->reverse()->take($count)->reverse();
    }

    public function getAggregateFormatted(): array
    {
        $history = $this->getHistory();

        return [
            'avg' => CurrencyHelper::toString($history->avg(), locale: $this->locale, iso: $this->currency),
            'min' => CurrencyHelper::toString($history->min(), locale: $this->locale, iso: $this->currency),
            'max' => CurrencyHelper::toString($history->max(), locale: $this->locale, iso: $this->currency),
        ];
    }

    public function getFirstDate(): string
    {
        return $this->getHistory()->keys()->first() ?? now()->toDateString();
    }

    public function getLastScrapeDate(): ?Carbon
    {
        return $this->lastScrapeDate;
    }

    public function getHoursSinceLastScrape(): ?float
    {
        return $this->lastScrapeDate?->diffInHours(now());
    }

    public function isLastScrapeSuccessful(): bool
    {
        $hours = $this->getHoursSinceLastScrape();

        return $hours && $hours < 24;
    }

    public function isUnavailable(): bool
    {
        return $this->stockStatus->isUnavailable();
    }

    public function getStockStatus(): StockStatus
    {
        return $this->stockStatus;
    }

    public function getStockStatusLabel(): string
    {
        return $this->stockStatus->getLabel();
    }

    public function getStockStatusColor(): string
    {
        return $this->stockStatus->getColor();
    }

    public function getStockStatusIcon(): string
    {
        return $this->stockStatus->getIcon();
    }

    public function matchesNotification(Product $product): bool
    {
        if ($this->isUnavailable()) {
            return false;
        }

        return $product->shouldNotifyOnPrice(new Price([
            'price' => $this->getPrice(),
        ]));
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['price'],
            $data['store_id'] ?? null,
            $data['store_name'] ?? 'Unknown',
            $data['url_id'] ?? null,
            $data['url'] ?? null,
            $data['trend'] ?? Trend::None->value,
            $data['history'],
            $data['last_scrape'] ?? null,
            $data['locale'] ?? null,
            $data['currency'] ?? null,
            $data['unit_price'] ?? null,
            $data['price_factor'] ?? 1,
            $data['unit_of_measure'] ?? null,
            $data['availability'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'store_id' => $this->getStoreId(),
            'store_name' => $this->getStoreName(),
            'url_id' => $this->getUrlId(),
            'url' => $this->getUrl(),
            'trend' => $this->getTrend(),
            'trend_color' => $this->getTrendColor(),
            'trend_icon' => $this->getTrendIcon(),
            'trend_text' => $this->getTrendText(),
            'price' => $this->getPrice(),
            'price_formatted' => $this->getPriceFormatted(),
            'unit_price' => $this->getUnitPrice(),
            'unit_price_formatted' => $this->getUnitPriceFormatted(),
            'price_factor' => $this->getPriceFactor(),
            'history' => $this->getHistory(),
            'last_scrape' => $this->getLastScrapeDate(),
            'hours_since_last_scrape' => $this->getHoursSinceLastScrape(),
            'successful_last_scrape' => $this->isLastScrapeSuccessful(),
            'locale' => $this->locale,
            'currency' => $this->currency,
            'unit_of_measure' => $this->getUnitOfMeasure(),
            'availability' => $this->stockStatus === StockStatus::InStock ? null : $this->stockStatus->value,
        ];
    }
}

<?php

namespace App\Dto;

use App\Enums\StockStatus;
use App\Enums\Trend;
use App\Models\Price;
use App\Models\Product;
use App\Services\Helpers\CurrencyHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PriceCacheDto
{
    public const string DEFAULT_UNIT_OF_MEASURE = 'units';

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

    /**
     * Set when the price was converted from the store's currency into the default one.
     *
     * @var array{price: float, unit_price: float, currency: string, locale: string, rate: ?float}|null
     */
    private ?array $original;

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
        ?string $unitOfMeasure = self::DEFAULT_UNIT_OF_MEASURE,
        StockStatus|string|null $availability = null,
        ?array $original = null,
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
        $this->stockStatus = $availability instanceof StockStatus
            ? $availability
            : StockStatus::fromScrapedValue($availability);
        $this->original = $original;
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

    public function getUnitOfMeasure(): string
    {
        return Str::singular($this->getUnitOfMeasurePlural());
    }

    public function getUnitOfMeasurePlural(): string
    {
        return $this->unitOfMeasure ?? self::DEFAULT_UNIT_OF_MEASURE;
    }

    public function getPriceFactor(): float
    {
        return $this->priceFactor;
    }

    public function hasPriceFactor(): bool
    {
        return $this->getPriceFactor() > 1;
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
        // Out-of-stock URLs don't get fresh Price rows by design (`Url::updatePrice`
        // doesn't insert when the scrape returns no price), so the price-history
        // timestamp will lag arbitrarily far behind reality. Treat them as fresh
        // so the product doesn't show a "scrape error" badge while it's just
        // genuinely unavailable.
        if ($this->isUnavailable()) {
            return true;
        }

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

    /**
     * Whether the price was converted from another currency.
     */
    public function isConverted(): bool
    {
        return $this->original !== null;
    }

    public function getOriginalCurrency(): ?string
    {
        return $this->original['currency'] ?? null;
    }

    public function getExchangeRate(): ?float
    {
        return $this->original['rate'] ?? null;
    }

    public function getOriginalPriceFormatted(): ?string
    {
        return $this->original === null ? null : CurrencyHelper::toString(
            $this->original['price'],
            locale: $this->original['locale'],
            iso: $this->original['currency']
        );
    }

    public function getOriginalUnitPriceFormatted(): ?string
    {
        return $this->original === null ? null : CurrencyHelper::toString(
            $this->original['unit_price'],
            locale: $this->original['locale'],
            iso: $this->original['currency']
        );
    }

    /**
     * How the price was converted, e.g. "Converted from USD at 1 USD = 1.4258 CAD".
     */
    public function getConversionDescription(): ?string
    {
        if ($this->original === null) {
            return null;
        }

        $rate = $this->getExchangeRate();

        return $rate === null
            ? __('Converted from :currency', ['currency' => $this->original['currency']])
            : __('Converted from :from at 1 :from = :rate :to', [
                'from' => $this->original['currency'],
                'rate' => rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.'),
                'to' => $this->currency,
            ]);
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
            $data['history'] ?? [],
            $data['last_scrape'] ?? null,
            $data['locale'] ?? null,
            $data['currency'] ?? null,
            $data['unit_price'] ?? null,
            $data['price_factor'] ?? 1,
            $data['unit_of_measure'] ?? null,
            $data['availability'] ?? null,
            isset($data['original_currency']) ? [
                'price' => (float) ($data['original_price'] ?? 0),
                'unit_price' => (float) ($data['original_unit_price'] ?? $data['original_price'] ?? 0),
                'currency' => (string) $data['original_currency'],
                'locale' => (string) ($data['original_locale'] ?? CurrencyHelper::getLocale()),
                'rate' => isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            ] : null,
        );
    }

    public function toArray(): array
    {
        $data = [
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

        // Only converted prices carry the original, so unconverted output is unchanged.
        if ($this->original !== null) {
            $data += [
                'original_price' => $this->original['price'],
                'original_price_formatted' => $this->getOriginalPriceFormatted(),
                'original_unit_price' => $this->original['unit_price'],
                'original_unit_price_formatted' => $this->getOriginalUnitPriceFormatted(),
                'original_currency' => $this->original['currency'],
                'original_locale' => $this->original['locale'],
                'exchange_rate' => $this->original['rate'],
            ];
        }

        return $data;
    }
}

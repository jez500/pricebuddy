<?php

namespace App\Models;

use App\Dto\PriceCacheDto;
use App\Enums\Statuses;
use App\Enums\Trend;
use App\Filament\Actions\BaseAction;
use App\Services\Helpers\CurrencyHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property ?string $title
 * @property ?string $title_short
 * @property string $primary_image
 * @property ?string $image
 * @property array $action_urls
 * @property ?string $view_url
 * @property array $price_cache
 * @property string $average_price
 * @property string $trend
 * @property Collection $urls
 * @property ?float $notify_price
 * @property ?float $notify_percent
 * @property ?User $user
 * @property int $user_id
 * @property array $price_aggregates
 * @property array $urls_array
 * @property array $ignored_urls
 * @property array $ignored_search_urls
 * @property float $current_price
 * @property bool $is_last_scrape_successful
 * @property bool $is_notified_price
 * @property Carbon $created_at
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $guarded = [
        'price',
    ];

    protected $casts = [
        'status' => Statuses::class,
        'ignored_urls' => 'array',
        'price_cache' => 'array',
        'created_at' => 'datetime',
        'favourite' => 'boolean',
    ];

    public static function booted()
    {
        static::deleted(function (Product $product) {
            // Delete all related urls, should cascade to prices.
            $product->urls()->delete();
        });
    }

    /***************************************************
     * Relationships.
     **************************************************/

    /**
     * Product has many urls.
     */
    public function urls(): HasMany
    {
        return $this->hasMany(Url::class);
    }

    /**
     * Product has many users.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Product has many prices through urls.
     */
    public function prices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Price::class,
            Url::class,
            'product_id', // Foreign key on the urls table...
            'url_id', // Foreign key on the prices table...
            'id', // Local key on the product table...
            'id' // Local key on the prices table...
        );
    }

    /**
     * Product has many stores through urls.
     */
    public function stores(): HasManyThrough
    {
        return $this->hasManyThrough(
            Store::class,
            Url::class,
            'product_id', // Foreign key on the urls table...
            'id', // Foreign key on the store table...
            'id', // Local key on the product table...
            'store_id' // Local key on the stores table...
        );
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /***************************************************
     * Scopes.
     **************************************************/

    /**
     * Scope to only published products.
     */
    public function scopePublished(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('status', Statuses::Published);
    }

    /**
     * Scope to only favoured products.
     */
    public function scopeFavourite(EloquentBuilder $query, bool $favourite = true): EloquentBuilder
    {
        return $query->where('favourite', $favourite);
    }

    /**
     * Scope to only current user.
     */
    public function scopeCurrentUser(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('user_id', auth()->id());
    }

    /**
     * Scope the lowest price in days.
     */
    public function scopeLowestPriceInDays(EloquentBuilder $query, int $days = 7): EloquentBuilder
    {
        return $query->whereHas('prices', function ($priceQuery) use ($days) {
            $priceQuery
                ->where('prices.created_at', '>=', Carbon::now()->subDays($days)->startOfDay())
                ->where('prices.created_at', '<', Carbon::now()->startOfDay())
                ->where('prices.price', '>', 0)
                ->whereColumn('current_price', '<', 'prices.price');
        });
    }

    /***************************************************
     * Attributes.
     **************************************************/

    /**
     * Price trend for lowest priced store.
     */
    public function trend(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                return Trend::calculateTrend(
                    $this->current_price,
                    $this->getPriceCacheAggregate('avg'),
                    $this->getPriceCacheAggregate('min'),
                );
            },
        );
    }

    /**
     * Does the product have a price history.
     */
    public function hasHistory(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                return $this->trend !== Trend::None->value;
            },
        );
    }

    /**
     * Price trend for lowest priced store.
     */
    public function priceAggregates(): Attribute
    {
        return Attribute::make(
            get: fn (): Collection => collect(['max', 'avg', 'min'])
                ->mapWithKeys(fn ($method) => [$method => $this->getPriceCacheAggregate($method)])
                ->filter(fn ($value) => $value > 0)
                ->mapWithKeys(fn ($value, $method) => [$method => CurrencyHelper::toString($value)])
        );
    }

    /**
     * Get main image with fallback.
     */
    public function getPrimaryImageAttribute(): string
    {
        return empty($this->image)
            ? asset('/images/placeholder.png')
            : $this->image;
    }

    /**
     * Short version of the product title.
     */
    public function titleShort(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->title(20)
        );
    }

    /**
     * Get urls array.
     */
    public function urlsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->urls->pluck('url')->toArray()
        );
    }

    /**
     * Get urls array.
     */
    public function ignoredSearchUrls(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => collect($this->ignored_urls)->merge($this->urls_array)->unique()->values()->toArray()
        );
    }

    /**
     * Were all the last scrapes successful.
     */
    public function isLastScrapeSuccessful(): Attribute
    {
        return Attribute::make(
            get: fn ($value): bool => $this->getPriceCache()
                ->filter(fn (PriceCacheDto $price) => $price->isLastScrapeSuccessful())
                ->count() === $this->getPriceCache()->count()
        );
    }

    /**
     * Does lowest price match notification.
     */
    public function isNotifiedPrice(): Attribute
    {
        return Attribute::make(
            get: fn ($value): bool => $this->shouldNotifyOnPrice(new Price(['price' => $this->current_price]))
        );
    }

    /**
     * Get the view url for the product.
     */
    public function getViewUrlAttribute(): ?string
    {
        return $this->action_urls['view'] ?? null;
    }

    /**
     * Get key urls for the product.
     */
    public function getActionUrlsAttribute(): array
    {
        return $this->getKey()
            ? [
                'edit' => route(BaseAction::ROUTE_NAMESPACE.'products.edit', $this, false),
                'view' => route(BaseAction::ROUTE_NAMESPACE.'products.view', $this, false),
                'fetch' => route(BaseAction::ROUTE_NAMESPACE.'products.fetch', $this, false),
            ]
            : [];
    }

    /***************************************************
     * Helpers.
     **************************************************/

    /**
     * Get the price cache for this product.
     *
     * @return Collection<PriceCacheDto>
     */
    public function getPriceCache(): Collection
    {
        return collect($this->price_cache)
            ->sortBy('price')
            ->map(fn ($price) => PriceCacheDto::fromArray($price))
            ->values();
    }

    /**
     * Get the price aggregate for this product via getPriceCache().
     */
    public function getPriceCacheAggregate(string $method = 'avg', ?int $urlId = null): float|int
    {
        $cache = $this->getPriceCache();

        if ($urlId) {
            $cache->filter(fn (PriceCacheDto $price) => $price->getUrlId() === $urlId);
        }

        return round($cache->map(fn (PriceCacheDto $price) => $price->getHistory()->values()->toArray())
            ->flatten()
            ->{$method}(), 2);
    }

    /**
     * Build a price cache array.
     */
    public function buildPriceCache(): Collection
    {
        $history = $this->getPriceHistory();
        $urls = Url::findMany($history->keys());

        return $urls
            ->map(function ($url) use ($history): array {
                /** @var Url $url */
                /** @var Collection $urlHistory */
                $urlHistory = $history->get($url->getKey());
                /** @var ?Store $store */
                $store = $url->store;

                // Get last scraped price.
                /** @var ?Price $lastScrapedPrice */
                $lastScrapedPrice = $url->prices()->latest('id')->first();
                $lastScrapedTimestamp = $lastScrapedPrice?->created_at;

                // Build trend, current price vs average price.
                $trend = Trend::calculateTrend(
                    $urlHistory->last(),
                    $urlHistory->values()->avg(),
                    $urlHistory->values()->min(),
                );

                // Build output suitable for dto.
                return [
                    'store_id' => $store->getKey(),
                    'store_name' => $store->name,
                    'url_id' => $url->getKey(),
                    'url' => $url->buy_url,
                    'trend' => $trend,
                    'price' => $urlHistory->last(),
                    'history' => $urlHistory->toArray(),
                    'last_scrape' => $lastScrapedTimestamp?->toDateTimeString(),
                    'locale' => $store->locale,
                    'currency' => $store->currency,
                ];
            })
            ->sortBy('price')
            ->values();
    }

    public function getAggregateRange(): array
    {
        return once(function () {
            $dailyPrices = [];

            $this->getPriceCache()
                ->each(function (PriceCacheDto $price) use (&$dailyPrices) {
                    $price->getHistory()
                        ->each(function ($price, $date) use (&$dailyPrices) {
                            $dailyPrices[$date][] = $price;
                        });
                });

            $data = [];
            foreach ($dailyPrices as $date => $prices) {
                $min = min($prices);
                $max = max($prices);
                $avg = round((array_sum($prices) / count($prices)), 2);

                $data['max'][$date] = $max - $avg;
                $data['avg'][$date] = $avg - $min;
                $data['min'][$date] = $min;
            }

            return $data;
        });
    }

    public function getAllPricesQuery(): Builder
    {
        return DB::table('prices')
            ->select(
                'prices.id',
                'prices.price',
                'prices.created_at',
                'urls.id as url_id',
                'urls.store_id'
            )
            ->join('urls', 'prices.url_id', '=', 'urls.id')
            ->where('urls.product_id', $this->id)
            ->orderByDesc('prices.created_at');
    }

    /**
     * Update all prices for this product.
     */
    public function updatePrices(): bool
    {
        $successful = $this->urls
            ->map(fn (Url $url) => $url->updatePrice())
            ->filter();

        $this->updatePriceCache();

        return $successful->count() === $this->urls->count();
    }

    /**
     * Update the price cache for this product.
     */
    public function updatePriceCache(): void
    {
        $priceCache = $this->buildPriceCache()->toArray();
        $this->update(['price_cache' => $priceCache, 'current_price' => data_get($priceCache, '0.price', 0)]);
    }

    /**
     * Get the price history for this product.
     */
    public function getPriceHistory(): Collection
    {
        return $this->getAllPricesQuery()
            ->whereDate('prices.created_at', '>=', now()->subYear())
            ->where('prices.price', '>', 0)
            ->orderBy('prices.created_at')
            ->get()
            ->groupBy('url_id')
            ->mapWithKeys(function ($prices, int $storeId) {
                // All price entries for the store.
                $storeHistory = $prices->sortBy('created_at')
                    ->groupBy('created_at')
                    ->mapWithKeys(fn ($prices, string $date) => [
                        Carbon::parse($date)->toDateString() => $prices->pluck('price')->min(),
                    ]);

                // If only one price, extend back one day.
                if ($storeHistory->count() === 1) {
                    $storeHistory = $this->extendSinglePriceHistory($storeHistory);
                }

                return [$storeId => $storeHistory->sortKeys()];
            });
    }

    /**
     * If we only have one price history, extend it back one day.
     */
    protected function extendSinglePriceHistory(Collection $history): Collection
    {
        return $history->put(
            Carbon::parse($history->keys()->first())->subDay()->toDateString(),
            $history->values()->first()
        );
    }

    /**
     * Get the cached price history for this product.
     */
    public function getPriceHistoryCached(): Collection
    {
        return $this->getPriceCache()
            ->mapWithKeys(fn (PriceCacheDto $price) => [
                $price->getUrlId() => $price->getHistory(),
            ]);
    }

    public function shouldNotifyOnPrice(Price $price): bool
    {
        $priceValue = (float) $price->price;

        // Check if price is less than notify price.
        if (! empty($this->notify_price) && $priceValue <= (float) $this->notify_price) {
            return true;
        }

        if (! empty($this->notify_percent)) {
            // Get first price.
            /** @var ?Price $firstPriceModel */
            $firstPriceModel = $this->prices()->oldest('prices.created_at')->first();
            $firstPrice = $firstPriceModel?->price;

            // No first price, can't calculate percent.
            if (is_null($firstPrice)) {
                return false;
            }

            // Calculate percent.
            $notifyPrice = $firstPrice - ($firstPrice * ($this->notify_percent / 100));

            // Check if percent is greater than notify percent.
            return $priceValue <= $notifyPrice;
        }

        return false;
    }

    public function title(int $length = 1000): string
    {
        return Str::limit($this->title, $length);
    }
}

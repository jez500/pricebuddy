<?php

namespace App\Models;

use App\Enums\StockStatus;
use App\Services\AutoCreateStore;
use App\Services\Helpers\AffiliateHelper;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ScrapeUrl;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Product URL.
 *
 * @property ?string $url
 * @property string $product_name_short
 * @property string $store_name
 * @property string $buy_url
 * @property string $product_url
 * @property string $average_price
 * @property string $latest_price_formatted
 * @property ?Store $store
 * @property ?Product $product
 * @property ?int $store_id
 * @property ?int $product_id
 * @property Collection $prices
 * @property Carbon $updated_at
 * @property Carbon $created_at
 */
class Url extends Model
{
    /** @use HasFactory<\Database\Factories\UrlFactory> */
    use HasFactory;

    public static function booted()
    {
        static::deleted(function (Url $url) {
            $url->prices()->delete();
            $url->product->updatePriceCache();
        });
    }

    protected $guarded = [];

    public function casts(): array
    {
        return [
            'updated_at' => 'datetime',
            'created_at' => 'datetime',
            'availability' => 'string',
        ];
    }

    /***************************************************
     * Relationships.
     **************************************************/

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class)->latest('created_at');
    }

    public function latestPrice(): HasMany
    {
        return $this->prices()->limit(1);
    }

    /***************************************************
     * Attributes.
     **************************************************/

    public function productNameShort(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product->title_short ?? 'Unknown'
        );
    }

    public function storeName(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::limit(($this->store->name ?? 'Missing store'), 100)
        );
    }

    protected function buyUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => AffiliateHelper::new()->parseUrl($this->url)
        );
    }

    protected function productUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product?->action_urls['view'] ?? '/'
        );
    }

    protected function latestPriceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => CurrencyHelper::toString(
                $this->latestPrice()->first()->price ?? 0,
                locale: $this->store?->locale,
                iso: $this->store?->currency
            )
        );
    }

    /**
     * Price trend for lowest priced store.
     */
    public function averagePrice(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $avg = $this->prices()->avg('price') ?? 0;

                return CurrencyHelper::toString(round($avg, 2));
            },
        );
    }

    /***************************************************
     * Helpers.
     **************************************************/

    public function scrape(): array
    {
        return $this->url ? ScrapeUrl::new($this->url)->scrape() : [];
    }

    public static function createFromUrl(string $url, ?int $productId = null, ?int $userId = null, bool $createStore = true): Url|false
    {
        $userId = $userId ?? auth()->id();

        if ($createStore) {
            AutoCreateStore::createStoreFromUrl($url);
        }

        $scrape = ScrapeUrl::new($url)->scrape();

        /** @var ?Store $store */
        $store = data_get($scrape, 'store');

        $matchConfig = data_get($store, 'scrape_strategy.availability.match');
        $isUnavailable = StockStatus::matchFromScrapedValue(data_get($scrape, 'availability'), $matchConfig)->isUnavailable();

        if (! $store || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }

        if (is_null($productId)) {
            if (! $userId) {
                throw new AuthorizationException('User is required to create a product.');
            }

            $image = data_get($scrape, 'image');

            $productId = Product::create([
                'title' => data_get($scrape, 'title'),
                'image' => strlen($image) < ScrapeUrl::MAX_STR_LENGTH ? $image : null,
                'user_id' => $userId,
                'favourite' => true,
            ])->id;
        }

        /** @var Url $urlModel */
        $urlModel = self::create([
            'url' => $url,
            'store_id' => $store->getKey(),
            'product_id' => $productId,
        ]);

        $urlModel->updatePrice(data_get($scrape, 'price'), $scrape);

        return $urlModel;
    }

    public function updatePrice(int|float|string|null $price = null, ?array $scrapeResult = null): Price|Model|null
    {
        if (! $this->store_id) {
            return null;
        }

        if (is_null($price) || $price === '') {
            $scrapeResult = $scrapeResult ?? $this->scrape();
            $price = data_get($scrapeResult, 'price');
        }

        // Update out-of-stock status based on scrape result.
        if ($scrapeResult) {
            $scrapedValue = data_get($scrapeResult, 'availability');
            $matchConfig = data_get($this->store, 'scrape_strategy.availability.match');
            $stockStatus = StockStatus::matchFromScrapedValue($scrapedValue, $matchConfig);
            $this->availability = $stockStatus->isUnavailable() ? $stockStatus->value : null;
            $this->save();

            // If unavailable and no price scraped, use the most recent price or 0.
            if ($stockStatus->isUnavailable() && (is_null($price) || $price === '')) {
                /** @var ?Price $latestPrice */
                $latestPrice = $this->prices()->first();
                $price = $latestPrice !== null ? $latestPrice->price : 0;
            }
        }

        if (is_null($price) || $price === '') {
            return null;
        }

        return $this->prices()->create([
            'price' => CurrencyHelper::toFloat($price, locale: $this->store?->locale, iso: $this->store?->currency),
            'store_id' => $this->store_id,
        ]);
    }

    /**
     * Get the last price the user was notified for.
     */
    public function lastNotifiedPrice(): Price|Model|null
    {
        return $this->prices()
            ->orderBy('created_at')
            ->where('notified', true)
            ->first();
    }

    /**
     * Only notify if the price has changed.
     */
    public function shouldNotifyOnPrice(Price $price): bool
    {
        /** @var ?Price $lastNotified */
        $lastNotified = $this->lastNotifiedPrice();

        if (! $lastNotified) {
            return true;
        }

        $pricesQuery = $this->prices()
            ->orderBy('created_at')
            ->where('created_at', '>=', (string) $lastNotified->created_at);

        $all = $pricesQuery->count();
        $samePrice = $pricesQuery->where('price', $price->price)->count();

        return $all > $samePrice;
    }
}

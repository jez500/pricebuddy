<?php

namespace App\Models;

use App\Casts\StoreScraperStrategySetCast;
use App\Dto\AdjustedPriceDto;
use App\Dto\PriceAdjustmentsDto;
use App\Dto\StoreScraperStrategySetDto;
use App\Enums\Role;
use App\Enums\ScraperService;
use App\Jobs\RecalculateStorePrices;
use App\Services\Helpers\CurrencyHelper;
use Carbon\CarbonInterface;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property string $name
 * @property string $initials
 * @property array $domains
 * @property HtmlString $domains_html
 * @property StoreScraperStrategySetDto $scrape_strategy
 * @property array $settings
 * @property string $scraper_service
 * @property array $scraper_options
 * @property bool $ai_extraction_enabled
 * @property string|null $ai_provider_id
 * @property bool $ai_self_healing_disabled
 * @property bool $missing_price_out_of_stock
 * @property PriceAdjustmentsDto $price_adjustments
 * @property string $locale
 * @property string $currency
 * @property Collection $urls
 * @property Collection $products
 * @property ?User $user
 * @property ?string $cookies
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    use HasSlug;

    protected $fillable = [
        'name',
        'initials',
        'domains',
        'scrape_strategy',
        'settings',
        'notes',
        'user_id',
        'cookies',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'scrape_strategy' => StoreScraperStrategySetCast::class,
            'settings' => 'array',
        ];
    }

    public static function booted()
    {
        // Stores are shared. user_id records who created the store. Jobs and the CLI
        // have no logged-in user, so they fall back to the oldest admin.
        static::creating(function (Store $store) {
            $store->user_id ??= auth()->id() ?? self::defaultCreatorId();
        });

        static::deleted(function (Store $store) {
            // Get all products before deleting URLs (since products() relationship uses URLs).
            $products = Product::whereIn('id', function ($query) use ($store) {
                $query->select('product_id')
                    ->from('urls')
                    ->where('store_id', $store->id);
            })->get();

            // Delete URLs individually to trigger model events (which cascade delete prices).
            $store->urls->each->delete();

            // Update price cache for all affected products.
            $products->each(function (Product $product): void {
                $product->updatePriceCache();
                $product->updateInsightsCache();
            });
        });
    }

    /**
     * The creator for a store made without a user: the oldest admin, else the oldest user.
     */
    public static function defaultCreatorId(): ?int
    {
        $id = User::query()->where('role', Role::Admin)->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /***************************************************
     * Relationships.
     **************************************************/

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function urls(): HasMany
    {
        return $this->hasMany(Url::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            Url::class,
            'store_id',
            'id',
            'id',
            'product_id'
        );
    }

    /***************************************************
     * Scopes.
     **************************************************/

    public function scopeDomainFilter(Builder $query, string|array|null $domains): Builder
    {
        $domains = array_values(array_filter(
            Arr::wrap($domains),
            fn ($domain) => filled($domain)
        ));

        if ($domains === []) {
            return $query->whereRaw('1 = 0');
        }

        // `domains` is normally an array of objects, but the store API and StoreFactory::forUrl()
        // can also store a single bare object. MySQL's JSON_CONTAINS matches a bare-object needle against
        // both shapes, but Postgres' @> only matches like with like, so check both needles.
        return $query->where(function (Builder $subQuery) use ($domains) {
            foreach ($domains as $domain) {
                $subQuery->orWhereJsonContains('domains', ['domain' => $domain])
                    ->orWhereJsonContains('domains', [['domain' => $domain]]);
            }
        });
    }

    /***************************************************
     * Attributes.
     **************************************************/

    public function initials(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! empty($value)) {
                    return strtoupper($value);
                }

                $parts = explode(' ', Str::slug($this->name, ' '));
                $initials = count($parts) > 1
                    ? collect(explode(' ', Str::slug($this->name, ' ')))
                        ->map(fn ($part) => Str::substr($part, 0, 1))
                        ->take(2)->join('')
                    : Str::substr($this->name, 0, 2);

                return strtoupper($initials);
            }
        );
    }

    public function domainsHtml(): Attribute
    {
        return Attribute::make(
            get: fn () => new HtmlString(Str::limit(collect($this->domains)
                ->pluck('domain')
                ->join(', ')))
        );
    }

    public function scraperService(): Attribute
    {
        return Attribute::make(
            get: function () {
                return data_get($this->settings, 'scraper_service', ScraperService::Http->value);
            }
        );
    }

    public function aiExtractionEnabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) data_get($this->settings, 'ai_extraction_enabled', false),
        );
    }

    public function aiProviderId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => data_get($this->settings, 'ai_provider_id'),
        );
    }

    public function aiSelfHealingDisabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) data_get($this->settings, 'ai_self_healing_disabled', false),
        );
    }

    public function missingPriceOutOfStock(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (bool) data_get($this->settings, 'missing_price_out_of_stock', false),
        );
    }

    public function priceAdjustments(): Attribute
    {
        return Attribute::make(
            get: fn (): PriceAdjustmentsDto => PriceAdjustmentsDto::fromArray(data_get($this->settings, 'price_adjustments')),
        )->withoutObjectCaching(); // `settings` changes under it
    }

    public function scraperOptions(): Attribute
    {
        return Attribute::make(
            get: function () {
                return collect(explode(PHP_EOL, data_get($this->settings, 'scraper_service_settings', '')))
                    ->filter(fn ($option) => ! empty($option) && Str::contains($option, '='))
                    ->mapWithKeys(function ($option) {
                        $parts = explode('=', $option);

                        return [data_get($parts, 0) => data_get($parts, 1)];
                    })
                    ->toArray();
            }
        );
    }

    public function testUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'test_url', ''),
        );
    }

    public function locale(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'locale_settings.locale', CurrencyHelper::getLocale()),
        );
    }

    public function currency(): Attribute
    {
        return Attribute::make(
            get: fn () => data_get($this->settings, 'locale_settings.currency', CurrencyHelper::getCurrency()),
        );
    }

    /***************************************************
     * Helpers.
     **************************************************/

    public function hasDomain($domain): bool
    {
        return collect($this->domains)
            ->pluck('domain')
            ->contains($domain);
    }

    public function hasShippingStrategy(): bool
    {
        return filled($this->scrape_strategy->shipping?->value);
    }

    /**
     * Whether the store adds shipping to its prices: a flat fee or shipping read from the
     * page. A listing can only ignore shipping when it does.
     */
    public function hasShipping(): bool
    {
        return $this->price_adjustments->shippingCost > 0 || $this->hasShippingStrategy();
    }

    /**
     * Whether prices from this store are adjusted before they are saved: tax, a flat
     * shipping fee, or shipping read from the page.
     */
    public function hasPriceAdjustments(): bool
    {
        return ! $this->price_adjustments->isEmpty() || $this->hasShippingStrategy();
    }

    /**
     * The price the user pays for a page price, or null when the store adjusts nothing.
     * Shipping read from the page only counts while the store has a shipping strategy.
     */
    public function adjustPrice(float $pagePrice, ?float $pageShipping = null, bool $ignoreShipping = false): ?AdjustedPriceDto
    {
        return $this->price_adjustments->adjust($pagePrice, $pageShipping, $this->hasShippingStrategy(), $ignoreShipping);
    }

    /**
     * Recalculate saved prices in the background, in batches, with the store's price
     * adjustments as they are now: from a date (midnight) or from the start. Only prices
     * saved before this call are touched, since later ones already use these settings.
     */
    public function queuePriceRecalculation(?CarbonInterface $since = null): void
    {
        Cache::put(self::recalculatingCacheKey($this->getKey()), true, RecalculateStorePrices::LOCK_MINUTES * 60);

        RecalculateStorePrices::dispatch(
            storeId: $this->getKey(),
            settings: $this->price_adjustments->toArray(),
            readsPageShipping: $this->hasShippingStrategy(),
            upToId: (int) Price::query()->where('store_id', $this->getKey())->max('id'),
            since: $since?->toDateTimeString(),
        )->afterCommit();
    }

    /**
     * Whether a background recalculation of this store's prices is still running. A
     * second one could finish first and be overwritten, so it waits for this one.
     */
    public function isRecalculatingPrices(): bool
    {
        return Cache::has(self::recalculatingCacheKey($this->getKey()));
    }

    public static function recalculatingCacheKey(int $storeId): string
    {
        return 'store-prices-recalculating:'.$storeId;
    }

    /**
     * Recalculate the next batch of saved prices, in id order after $afterId and up to
     * $upToId. Returns the last id done, or null when there are no more.
     */
    public function recalculatePriceBatch(PriceAdjustmentsDto $settings, bool $readsPageShipping, int $afterId, int $upToId, ?CarbonInterface $since, int $limit): ?int
    {
        $prices = Price::query()
            ->where('store_id', $this->getKey())
            ->where('id', '>', $afterId)
            ->where('id', '<=', $upToId)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->orderBy('id')
            ->limit($limit)
            ->with('url:id,ignore_shipping')
            ->get();

        /** @var Price $price */
        foreach ($prices as $price) {
            $price->recalculate($settings, $readsPageShipping, (bool) $price->url?->ignore_shipping);
        }

        return $prices->count() < $limit ? null : (int) $prices->last()?->getKey();
    }

    /**
     * Rebuild the price and insights caches of every product with a URL at this store.
     */
    public function refreshProductCaches(): void
    {
        Product::query()
            ->whereIn('id', $this->urls()->select('product_id'))
            ->each(function (Product $product): void {
                $product->updatePriceCache();
                $product->updateInsightsCache();
            });
    }

    /**
     * Recalculate saved prices right away with the store's current price adjustments,
     * from a date or from the start. Returns how many prices were recalculated.
     */
    public function recalculatePrices(?CarbonInterface $since = null): int
    {
        $upToId = (int) Price::query()->where('store_id', $this->getKey())->max('id');
        $count = Price::query()
            ->where('store_id', $this->getKey())
            ->where('id', '<=', $upToId)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->count();

        $afterId = 0;

        do {
            $afterId = $this->recalculatePriceBatch($this->price_adjustments, $this->hasShippingStrategy(), $afterId, $upToId, $since, 500);
        } while ($afterId !== null);

        $this->refreshProductCaches();

        return $count;
    }

    public function getAiHealFailedAt(): ?Carbon
    {
        $value = data_get($this->settings, 'ai_heal_failed_at');

        return filled($value) ? Carbon::parse($value) : null;
    }

    public function markAiHealFailed(): void
    {
        $this->settings = array_merge($this->settings ?? [], [
            'ai_heal_failed_at' => now()->toIso8601String(),
        ]);
        $this->save();
    }

    public function clearAiHealFailed(): void
    {
        $settings = $this->settings ?? [];
        unset($settings['ai_heal_failed_at']);
        $this->settings = $settings;
        $this->save();
    }
}

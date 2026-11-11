<?php

namespace App\Models;

use App\Casts\StoreScraperStrategySetCast;
use App\Dto\AdjustedPriceDto;
use App\Dto\PriceAdjustmentsDto;
use App\Dto\StoreScraperStrategySetDto;
use App\Enums\Role;
use App\Enums\ScraperService;
use App\Exceptions\PriceRecalculationRunningException;
use App\Jobs\RecalculateStorePrices;
use App\Services\Helpers\CurrencyHelper;
use Carbon\CarbonInterface;
use Closure;
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
use Illuminate\Support\Facades\DB;
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
 * @property ?string $price_recalculation_run
 * @property ?Carbon $price_recalculation_started_at
 * @property ?Carbon $price_recalculation_progress_at
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

    protected $hidden = [
        'price_recalculation_run',
        'price_recalculation_started_at',
        'price_recalculation_progress_at',
    ];

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
            'price_recalculation_started_at' => 'datetime',
            'price_recalculation_progress_at' => 'datetime',
        ];
    }

    public static function booted()
    {
        // Stores are shared. user_id records who created the store. Jobs and the CLI
        // have no logged-in user, so they fall back to the oldest admin.
        static::creating(function (Store $store) {
            $store->user_id ??= auth()->id() ?? self::defaultCreatorId();
        });

        // The price adjustments cannot change while a recalculation is under way. Read from
        // the database, not this instance, which may be older than the run, and locked until
        // the save is written (see save()), so a run cannot start in between. A save ends an
        // abandoned run.
        static::updating(function (Store $store) {
            if (! $store->priceAdjustmentsChanged()) {
                return;
            }

            /** @var ?Store $saved */
            $saved = static::query()->whereKey($store->getKey())->lockForUpdate()
                ->first(['id', 'price_recalculation_run', 'price_recalculation_progress_at']);

            if ($saved?->price_recalculation_run === null) {
                return;
            }

            if ($saved->isRecalculatingPrices()) {
                throw new PriceRecalculationRunningException($saved->priceRecalculationStatus());
            }

            self::endPriceRecalculation($saved->getKey(), $saved->price_recalculation_run);
            $store->forceFill(self::NO_RECALCULATION)->syncOriginalAttributes(array_keys(self::NO_RECALCULATION));
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
     * A recalculation with no batch for this long is taken as abandoned (no queue worker,
     * or its job lost): settings can be saved again, and another one started. Longer than
     * the longest normal wait in the queue, behind a scheduled price check.
     */
    public const int ABANDONED_RECALCULATION_MINUTES = 30;

    /**
     * After this long with no batch, a recalculation is reported as stuck.
     */
    public const int STUCK_RECALCULATION_MINUTES = 10;

    /**
     * Seconds per price and per product's caches, measured on 2 CPUs, for estimates.
     */
    protected const float SECONDS_PER_PRICE = 0.00013;

    protected const float SECONDS_PER_PRODUCT = 0.3;

    /**
     * Whether the saved price adjustments differ from the ones being saved, in a way that
     * changes how prices are worked out.
     */
    public function priceAdjustmentsChanged(): bool
    {
        /** @var ?StoreScraperStrategySetDto $originalStrategy */
        $originalStrategy = $this->getOriginal('scrape_strategy');

        return PriceAdjustmentsDto::fromArray(data_get($this->getOriginal('settings'), 'price_adjustments'))->toArray() !== $this->price_adjustments->toArray()
            || filled($originalStrategy?->shipping?->value) !== $this->hasShippingStrategy();
    }

    /**
     * The run columns of a store with no recalculation under way.
     */
    protected const array NO_RECALCULATION = [
        'price_recalculation_run' => null,
        'price_recalculation_started_at' => null,
        'price_recalculation_progress_at' => null,
    ];

    /**
     * Recalculate saved prices in the background, in batches, with the store's price
     * adjustments: from a date (midnight) or from the start. Returns false, and starts
     * nothing, while another recalculation of this store is under way.
     *
     * The price adjustments cannot change while a recalculation runs (see booted()), so
     * every batch uses the store's settings as they are.
     */
    public function queuePriceRecalculation(?CarbonInterface $since = null): bool
    {
        $run = [
            'price_recalculation_run' => (string) Str::uuid(),
            'price_recalculation_started_at' => now(),
            'price_recalculation_progress_at' => now(),
        ];

        // Atomic: of two starts at once, only one finds the store free.
        $started = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query->whereNull('price_recalculation_run')
                ->orWhereNull('price_recalculation_progress_at')
                ->orWhere('price_recalculation_progress_at', '<', now()->subMinutes(self::ABANDONED_RECALCULATION_MINUTES)))
            ->toBase()
            ->update($run);

        if ($started === 0) {
            return false;
        }

        $this->forceFill($run)->syncOriginalAttributes(array_keys($run));

        RecalculateStorePrices::dispatch(
            storeId: $this->getKey(),
            runId: $run['price_recalculation_run'],
            since: $since?->toDateTimeString(),
        )->afterCommit();

        return true;
    }

    /**
     * Whether a recalculation of this store's prices, as loaded, is under way and not
     * abandoned.
     */
    public function isRecalculatingPrices(): bool
    {
        return $this->price_recalculation_run !== null
            && $this->price_recalculation_progress_at?->gte(now()->subMinutes(self::ABANDONED_RECALCULATION_MINUTES)) === true;
    }

    /**
     * Why the price adjustments cannot be changed or recalculated right now.
     */
    public function priceRecalculationStatus(): string
    {
        $run = static::query()->whereKey($this->getKey())->firstOrFail(['price_recalculation_started_at', 'price_recalculation_progress_at']);
        $progressAt = $run->price_recalculation_progress_at ?? now();

        if ($progressAt->lte(now()->subMinutes(self::STUCK_RECALCULATION_MINUTES))) {
            return __('Still recalculating, no progress for :time. Is the queue worker running?', [
                'time' => self::shortDuration($progressAt),
            ]);
        }

        return __('Still recalculating (started :time ago). Try again in a moment.', [
            'time' => self::shortDuration($run->price_recalculation_started_at ?? now()),
        ]);
    }

    protected static function shortDuration(CarbonInterface $since): string
    {
        $seconds = (int) max(0, $since->diffInSeconds(now()));

        return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min';
    }

    /**
     * "Recalculating about 6,200 prices, usually a few seconds." For a recalculation from a
     * date (midnight) or from the start, assuming nothing is queued ahead of it.
     */
    public function priceRecalculationEstimate(?CarbonInterface $since = null): string
    {
        $prices = Price::query()
            ->where('store_id', $this->getKey())
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->count();
        $seconds = $prices * self::SECONDS_PER_PRICE + $this->affectedProducts()->count() * self::SECONDS_PER_PRODUCT;

        return __('Recalculating :count :prices, usually :time.', [
            'count' => $prices >= 1000 ? __('about :count', ['count' => number_format(round($prices, -2))]) : $prices,
            'prices' => Str::plural('price', $prices),
            'time' => match (true) {
                $seconds <= 10 => __('a few seconds'),
                $seconds <= 45 => __('under a minute'),
                default => __('a few minutes'),
            },
        ]);
    }

    /**
     * End a run, if it is still the store's current one.
     */
    public static function endPriceRecalculation(int $storeId, string $runId): void
    {
        static::query()->whereKey($storeId)->where('price_recalculation_run', $runId)
            ->toBase()->update(self::NO_RECALCULATION);
    }

    /**
     * Record that a run made progress, if it is still the store's current one.
     */
    public static function touchPriceRecalculation(int $storeId, string $runId): void
    {
        static::query()->whereKey($storeId)->where('price_recalculation_run', $runId)
            ->toBase()->update(['price_recalculation_progress_at' => now()]);
    }

    /**
     * Saved in a transaction when the price adjustments change, so the store's row stays
     * locked from the check in booted() until the save is written.
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->priceAdjustmentsChanged()) {
            return parent::save($options);
        }

        return DB::transaction(fn (): bool => parent::save($options));
    }

    /**
     * How long a price recalculation waits for a store's price lock, in seconds.
     */
    public static int $priceLockWait = 30;

    public static function priceLockKey(int $storeId): string
    {
        return 'store-prices:'.$storeId;
    }

    /**
     * Run $callback while holding these stores' price locks, which every recalculation of
     * their saved prices takes, so a store batch and a listing recalculation never
     * interleave. Waits up to $priceLockWait seconds for each lock; a batch holds one for
     * well under a second.
     *
     * @template T
     *
     * @param  array<int, int>  $storeIds
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withPriceLocks(array $storeIds, Closure $callback): mixed
    {
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        sort($storeIds); // the same order everywhere, so two callers cannot deadlock

        if ($storeIds === []) {
            return $callback();
        }

        $first = array_shift($storeIds);

        return Cache::lock(self::priceLockKey($first), RecalculateStorePrices::LOCK_SECONDS)
            ->block(self::$priceLockWait, fn () => self::withPriceLocks($storeIds, $callback));
    }

    /**
     * Recalculate the next batch of saved prices with the store's price adjustments, in id
     * order after $afterId and up to $upToId, written in one statement. Returns the last id
     * done, or null when there are no more.
     */
    public function recalculatePriceBatch(int $afterId, int $upToId, ?CarbonInterface $since, int $limit): ?int
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

        Price::saveRecalculated($prices, fn (Price $price): Store => $this, fn (Price $price): bool => (bool) $price->url?->ignore_shipping);

        return $prices->count() < $limit ? null : (int) $prices->last()?->getKey();
    }

    /**
     * The products whose prices this store affects: those with a URL at the store now,
     * and those with saved prices from it, whose URL may have moved to another store.
     *
     * @return Builder<Product>
     */
    public function affectedProducts(): Builder
    {
        return Product::query()->whereIn('id', Url::query()
            ->select('product_id')
            ->where('store_id', $this->getKey())
            ->orWhereIn('id', Price::query()->select('url_id')->where('store_id', $this->getKey())));
    }

    /**
     * Rebuild the price and insights caches of the next batch of affected products, in id
     * order after $afterId. Returns the last id done, or null when there are no more.
     */
    public function refreshProductCacheBatch(int $afterId, int $limit): ?int
    {
        $products = $this->affectedProducts()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        /** @var Product $product */
        foreach ($products as $product) {
            $product->updatePriceCache();
            $product->updateInsightsCache();
        }

        return $products->count() < $limit ? null : (int) $products->last()?->getKey();
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

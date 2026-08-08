<?php

namespace App\Models;

use App\Enums\StockStatus;
use App\Events\AvailabilityChangedEvent;
use App\Services\AiConfigHealer;
use App\Services\AiScrapeEnhancer;
use App\Services\AutoCreateStore;
use App\Services\Helpers\AffiliateHelper;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ScrapeUrl;
use Carbon\Carbon;
use Database\Factories\UrlFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

/**
 * Product URL.
 *
 * @property ?string $url
 * @property ?string $url_normalized
 * @property float $price_factor
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
 * @property ?StockStatus $availability
 * @property Collection $prices
 * @property Carbon $updated_at
 * @property Carbon $created_at
 */
class Url extends Model
{
    /** @use HasFactory<UrlFactory> */
    use HasFactory;

    public static function booted()
    {
        static::saving(function (Url $url) {
            $url->url_normalized = static::normalizeForMatch((string) $url->url) ?: null;
        });

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
            'availability' => StockStatus::class,
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

    /**
     * Build the normalised key used to decide whether two URLs point at the same
     * product page.
     *
     * Rule: lowercase host without a leading "www.", plus the lowercased path with
     * trailing slashes removed, plus the surviving query parameters sorted and
     * joined. Scheme, port, userinfo and fragment are discarded. Returns an empty
     * string for anything unparseable — callers must coerce that to NULL before
     * persisting, or every malformed row would share one key.
     *
     * The tracking-parameter denylist lives in config/url_matching.php. It is
     * internal only: it is never exposed in an API response and has no client-side
     * mirror, so it can be extended freely. Changing it does invalidate every
     * stored `url_normalized` value — run `urls:renormalize` afterwards.
     */
    public static function normalizeForMatch(string $url): string
    {
        $input = trim($url);

        if ($input === '') {
            return '';
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:\/\//', $input)) {
            $input = 'https://'.$input;
        }

        try {
            $uri = Uri::of($input);
        } catch (\Throwable) {
            return '';
        }

        $host = static::normalizeHost((string) $uri->host());

        if ($host === '') {
            return '';
        }

        $path = $uri->path();
        $path = $path === '/' ? '' : '/'.Str::lower($path);

        $query = static::filterTrackingParams((string) $uri->query()->value());

        return Str::substr($host.$path.($query === '' ? '' : '?'.$query), 0, 255);
    }

    /**
     * Normalise a bare host: drop any port, lowercase, strip one leading "www.".
     * Shared by normalizeForMatch() and the stores `filter[domain]` so there is a
     * single host rule rather than two.
     */
    public static function normalizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            return '';
        }

        $host = Str::lower(Str::before($host, ':'));

        return Str::startsWith($host, 'www.') ? Str::substr($host, 4) : $host;
    }

    /**
     * Drop tracking parameters from a raw query string and sort what remains.
     * Tokens are kept verbatim, so "?foo" stays "foo" and "?foo=" stays "foo=".
     */
    protected static function filterTrackingParams(string $query): string
    {
        if (trim($query) === '') {
            return '';
        }

        $exact = static::configuredList('url_matching.tracking_params');
        $prefixes = static::configuredList('url_matching.tracking_param_prefixes');

        return collect(explode('&', Str::lower($query)))
            ->reject(function (string $token) use ($exact, $prefixes): bool {
                $key = Str::before($token, '=');

                if ($key === '') {
                    return true;
                }

                return in_array($key, $exact, true)
                    || ($prefixes !== [] && Str::startsWith($key, $prefixes));
            })
            ->sort(SORT_STRING)
            ->values()
            ->implode('&');
    }

    /**
     * Read a denylist from config, trimmed, lowercased, de-duplicated and with
     * empties removed, so a messy env value behaves.
     *
     * @return array<int, string>
     */
    protected static function configuredList(string $key): array
    {
        return collect(Arr::wrap(config($key, [])))
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Recompute `url_normalized` for every row and return how many rows changed.
     *
     * Writes through the query builder so no model events fire and `updated_at` is
     * left alone. Shared by the backfill migration and the `urls:renormalize`
     * command, which must be run after any change to the tracking-param denylist —
     * stored keys are computed at save time and go stale otherwise.
     */
    public static function renormalizeAll(): int
    {
        $changed = 0;

        static::query()
            ->select(['id', 'url', 'url_normalized'])
            ->chunkById(500, function ($urls) use (&$changed): void {
                /** @var Url $url */
                foreach ($urls as $url) {
                    $normalized = static::normalizeForMatch((string) $url->url) ?: null;

                    if ($normalized === $url->url_normalized) {
                        continue;
                    }

                    static::query()
                        ->whereKey($url->getKey())
                        ->toBase()
                        ->update(['url_normalized' => $normalized]);

                    $changed++;
                }
            });

        return $changed;
    }

    public function scrape(): array
    {
        return $this->url ? ScrapeUrl::new($this->url)->scrape() : [];
    }

    public function getAvailabilityStatus(): ?StockStatus
    {
        if ($this->availability instanceof StockStatus) {
            return $this->availability;
        }

        $availability = $this->getRawOriginal('availability');

        if (is_string($availability) && $availability !== '') {
            return StockStatus::fromScrapedValue($availability);
        }

        return null;
    }

    public static function createFromUrl(string $url, ?int $productId = null, ?int $userId = null, bool $createStore = true, float $priceFactor = 1): Url|false
    {
        $userId = $userId ?? auth()->id();

        ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable] = self::scrapeAndResolveStore($url, $createStore);

        if (! $store || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }

        if (is_null($productId)) {
            if (! $userId) {
                throw new AuthorizationException('User is required to create a product.');
            }

            $image = data_get($scrape, 'image');

            $productId = Product::create([
                'title' => ScrapeUrl::preSaveCleanTitle(data_get($scrape, 'title'), $store->name),
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
            'price_factor' => $priceFactor,
        ]);

        $urlModel->updatePrice(data_get($scrape, 'price'), $scrape);

        return $urlModel;
    }

    /**
     * Re-point this URL to a new address, keeping the record (and therefore its price
     * history) intact. The store is re-resolved from the new domain and a fresh price is
     * scraped and appended. Fails closed: on an invalid/unresolvable URL the record is
     * left untouched and `false` is returned.
     */
    public function changeUrl(string $newUrl, bool $createStore = true): bool
    {
        $newUrl = trim($newUrl);

        if ($newUrl === '' || ! filter_var($newUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        if ($newUrl === $this->url) {
            return true;
        }

        ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable] = self::scrapeAndResolveStore($newUrl, $createStore);

        if (! $store || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }

        $this->forceFill([
            'url' => $newUrl,
            'store_id' => $store->getKey(),
        ])->save();

        $this->updatePrice(data_get($scrape, 'price'), $scrape);

        return true;
    }

    /**
     * Scrape a URL and resolve its Store, using AI self-healing as a fallback when a
     * normal scrape would not yield a usable store/price.
     *
     * @return array{store: ?Store, scrape: array<string, mixed>, isUnavailable: bool}
     */
    protected static function scrapeAndResolveStore(string $url, bool $createStore = true): array
    {
        if ($createStore) {
            AutoCreateStore::createStoreFromUrl($url);
        }

        // Suppress UI toasts on these intermediate scrapes: a first attempt may legitimately
        // fail (e.g. bot-blocked) before AI self-healing switches to browser scraping. The
        // only user-facing feedback is the final outcome (product created, or the caller's error).
        $scrape = ScrapeUrl::new($url)->setSendUiNotifications(false)->scrape();

        /** @var ?Store $store */
        $store = data_get($scrape, 'store');

        $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
        $isUnavailable = StockStatus::resolveAvailability(data_get($scrape, 'availability'), $availabilityStrategy)->isUnavailable();

        // AI fallback: when a normal create would fail, let the healing agent build or
        // repair the store config, then re-scrape once with the new config.
        if ((! $store || (! data_get($scrape, 'price') && ! $isUnavailable))
            && AiConfigHealer::new()->healStoreForUrl($url, $store, data_get($scrape, 'body')) !== null) {
            $scrape = ScrapeUrl::new($url)->setSendUiNotifications(false)->scrape();
            $store = data_get($scrape, 'store');
            $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
            $isUnavailable = StockStatus::resolveAvailability(data_get($scrape, 'availability'), $availabilityStrategy)->isUnavailable();
        }

        return ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable];
    }

    public function updatePrice(int|float|string|null $price = null, ?array $scrapeResult = null): Price|Model|null
    {
        if (! $this->store_id) {
            return null;
        }

        $availabilityChanged = false;
        $stockStatus = null;

        if (is_null($price) || $price === '') {
            $scrapeResult = $scrapeResult ?? $this->scrape();
            $scrapeResult = AiConfigHealer::new()->heal($this, $scrapeResult);
            $scrapeResult = AiScrapeEnhancer::new()->enhance($this, $scrapeResult);
            $price = data_get($scrapeResult, 'price');
        }

        // Update out-of-stock status based on scrape result.
        if ($scrapeResult) {
            $scrapedValue = data_get($scrapeResult, 'availability');
            $availabilityStrategy = data_get($this->store, 'scrape_strategy.availability');
            $stockStatus = StockStatus::resolveAvailability($scrapedValue, $availabilityStrategy);
            $availability = $stockStatus->isUnavailable() ? $stockStatus : null;
            $previousAvailability = $this->getAvailabilityStatus();
            $availabilityChanged = $previousAvailability !== $availability;

            if ($availabilityChanged) {
                $this->availability = $availability;
                $this->save();
                AvailabilityChangedEvent::dispatch($this, $previousAvailability, $availability);
            }
        }

        if (is_null($price) || $price === '') {
            if ($availabilityChanged) {
                $this->product?->updatePriceCache();
            }

            // An out-of-stock product with no scraped price is the expected
            // outcome, not a failure. Keep the existing price history (we
            // don't insert a new row) and return the latest known Price so
            // the parent `Product::updatePrices()` doesn't count this URL
            // as failed and trigger a scrape-error notification.
            if ($stockStatus?->isUnavailable()) {
                return $this->prices()->latest('id')->first();
            }

            return null;
        }

        $priceFloat = CurrencyHelper::toFloat($price, locale: $this->store?->locale, iso: $this->store?->currency);
        $priceFactor = $this->price_factor ?: 1;

        return $this->prices()->create([
            'price' => $priceFloat,
            'unit_price' => $priceFloat / $priceFactor,
            'price_factor' => $priceFactor,
            'store_id' => $this->store_id,
        ]);
    }

    public function syncStoredPricesForCurrentFactor(): void
    {
        $priceFactor = $this->price_factor ?: 1;

        /** @var Collection<int, Price> $prices */
        $prices = $this->prices()->get();

        $prices->each(function (Price $price) use ($priceFactor): void {
            $price->forceFill([
                'unit_price' => $price->price / $priceFactor,
                'price_factor' => $priceFactor,
            ])->saveQuietly();
        });
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

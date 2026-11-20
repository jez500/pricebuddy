<?php

namespace App\Models;

use App\Dto\VariantFilterDto;
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
use Filament\Notifications\Notification;
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
 * @property ?array<string, mixed> $variant
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

    /**
     * See self::variantScrapeState().
     */
    protected const string VARIANT_SCRAPE_OK = 'ok';

    protected const string VARIANT_SCRAPE_FAILED = 'failed';

    protected const string VARIANT_SCRAPE_STALE = 'stale';

    public function casts(): array
    {
        return [
            'updated_at' => 'datetime',
            'created_at' => 'datetime',
            'availability' => StockStatus::class,
            'variant' => 'array',
        ];
    }

    /**
     * The variant this URL tracks (eg size "Small"), or null to track the page as a whole.
     */
    public function getVariantFilter(): ?VariantFilterDto
    {
        return VariantFilterDto::fromArray(is_array($this->variant) ? $this->variant : null);
    }

    public function variantLabel(): ?string
    {
        return $this->getVariantFilter()?->label();
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
        return $this->url ? ScrapeUrl::new($this->url)->scrape(['variant' => $this->getVariantFilter()]) : [];
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

    /**
     * @param  array<string, mixed>|null  $variant  the variant to track, see VariantFilterDto
     */
    public static function createFromUrl(string $url, ?int $productId = null, ?int $userId = null, bool $createStore = true, float $priceFactor = 1, ?array $variant = null): Url|false
    {
        $variant = VariantFilterDto::fromArray($variant);

        $userId = $userId ?? auth()->id();

        // A user can only add a URL to their own product. Without a user (console,
        // seeders), the caller is trusted.
        if ($productId && $userId && ! Product::query()->whereKey($productId)->where('user_id', $userId)->exists()) {
            throw new AuthorizationException('The product does not belong to this user.');
        }

        ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable] = self::scrapeAndResolveStore($url, $createStore, $userId, $variant);

        if (! $store || data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY) || (! data_get($scrape, 'price') && ! $isUnavailable)) {
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
            'variant' => $variant?->toArray(),
        ]);

        $urlModel->updatePrice(data_get($scrape, 'price'), $scrape);

        return $urlModel;
    }

    /**
     * Re-point this URL to a new address, keeping the record (and therefore its price
     * history) intact. The store is re-resolved from the new domain and a fresh price is
     * scraped and appended. Fails closed: on an invalid/unresolvable URL the record is
     * left untouched and `false` is returned.
     *
     * With $silentStockChange the new page's stock is saved without an
     * AvailabilityChangedEvent, eg when the same edit also changed the tracked variant:
     * the user changed what is tracked, nothing came back in stock.
     */
    public function changeUrl(string $newUrl, bool $createStore = true, bool $silentStockChange = false): bool
    {
        $newUrl = trim($newUrl);

        if ($newUrl === '' || ! filter_var($newUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        if ($newUrl === $this->url) {
            return true;
        }

        ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable] = self::scrapeAndResolveStore($newUrl, $createStore, $this->product?->user_id, $this->getVariantFilter());

        if (! $store || data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY) || (! data_get($scrape, 'price') && ! $isUnavailable)) {
            return false;
        }

        $this->forceFill([
            'url' => $newUrl,
            'store_id' => $store->getKey(),
        ]);

        if ($silentStockChange) {
            $this->setStockFromScrape($scrape, $store);
        }

        $this->save();

        $this->updatePrice(data_get($scrape, 'price'), $scrape);

        return true;
    }

    /**
     * Whether a submitted variant (from a form or the API) tracks a different variant
     * from the current one. Order, case and separators don't count, so ["black", "small"]
     * is the same variant as ["Small", "Black"].
     *
     * @param  array<string, mixed>|null  $variant
     */
    public function variantDiffers(?array $variant): bool
    {
        return VariantFilterDto::fromArray($variant)?->key() !== $this->getVariantFilter()?->key();
    }

    /**
     * Set the stock status from a scrape without dispatching an AvailabilityChangedEvent.
     * Used when what the URL tracks changed, so the new status is a fresh baseline rather
     * than a stock change. The caller saves.
     *
     * @param  array<string, mixed>  $scrape
     */
    protected function setStockFromScrape(array $scrape, ?Store $store = null): void
    {
        $status = ScrapeUrl::resolveStockStatus($scrape, data_get($store ?? $this->store, 'scrape_strategy.availability'));
        $this->availability = $status->isUnavailable() ? $status : null;
    }

    /**
     * Change the variant this URL tracks (null to track the page as a whole) and refresh
     * its stock and price straight away. The stock change is saved without an
     * AvailabilityChangedEvent: the user changed what is tracked, nothing came back in
     * stock.
     *
     * The new variant is only saved once a scrape says what its stock is. When the
     * scrape fails, or the store lists no variants, nothing changes and false is
     * returned. So the stored stock status always belongs to the stored variant, and a
     * later fetch can't mistake the new variant's stock for a change of the old one's.
     *
     * @param  array<string, mixed>|null  $variant
     */
    public function changeVariant(?array $variant): bool
    {
        $filter = VariantFilterDto::fromArray($variant);

        // The same variant, perhaps spelt differently. Keep the new spelling, but leave
        // its stock alone so a real back-in-stock alert isn't lost.
        if (! $this->variantDiffers($variant)) {
            if ($filter?->toArray() != $this->getVariantFilter()?->toArray()) {
                $this->variant = $filter?->toArray();
                $this->save();
                $this->product?->updatePriceCache();
            }

            return true;
        }

        $previous = $this->variant;
        $this->variant = $filter?->toArray();

        $scrape = $this->scrape();
        $confirmed = $scrape
            && ! data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY)
            && ($filter ? ScrapeUrl::hasVariantResult($scrape) : filled(data_get($scrape, 'title')));

        if (! $confirmed) {
            $this->variant = $previous;

            return false;
        }

        $this->setStockFromScrape($scrape);
        $this->save();

        $this->updatePrice(data_get($scrape, 'price'), $scrape);
        $this->product?->updatePriceCache();

        return true;
    }

    /**
     * Scrape a URL and resolve its Store, using AI self-healing as a fallback when a
     * normal scrape would not yield a usable store/price.
     *
     * @return array{store: ?Store, scrape: array<string, mixed>, isUnavailable: bool}
     */
    protected static function scrapeAndResolveStore(string $url, bool $createStore = true, ?int $userId = null, ?VariantFilterDto $variant = null): array
    {
        if ($createStore) {
            AutoCreateStore::createStoreFromUrl($url, $userId);
        }

        // Suppress UI toasts on these intermediate scrapes: a first attempt may legitimately
        // fail (e.g. bot-blocked) before AI self-healing switches to browser scraping. The
        // only user-facing feedback is the final outcome (product created, or the caller's error).
        $scrape = ScrapeUrl::new($url)->setSendUiNotifications(false)->scrape(['variant' => $variant]);

        // The page scraped fine but the store can't find its variants. Healing the price
        // selector would not fix that, so fail closed and let the caller report it.
        if (data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY)) {
            /** @var ?Store $store */
            $store = data_get($scrape, 'store');

            Notification::make()
                ->title(__('Variant not found'))
                ->body(data_get($scrape, ScrapeUrl::VARIANT_ERROR_KEY))
                ->danger()
                ->send();

            return ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => false];
        }

        /** @var ?Store $store */
        $store = data_get($scrape, 'store');

        $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
        $isUnavailable = ScrapeUrl::resolveStockStatus($scrape, $availabilityStrategy)->isUnavailable();

        // AI fallback: when a normal create would fail, let the healing agent build or
        // repair the store config, then re-scrape once with the new config.
        if ((! $store || (! data_get($scrape, 'price') && ! $isUnavailable))
            && AiConfigHealer::new()->healStoreForUrl($url, $store, data_get($scrape, 'body'), $userId) !== null) {
            $scrape = ScrapeUrl::new($url)->setSendUiNotifications(false)->scrape(['variant' => $variant]);
            $store = data_get($scrape, 'store');
            $availabilityStrategy = data_get($store, 'scrape_strategy.availability');
            $isUnavailable = ScrapeUrl::resolveStockStatus($scrape, $availabilityStrategy)->isUnavailable();
        }

        return ['store' => $store, 'scrape' => $scrape, 'isUnavailable' => $isUnavailable];
    }

    /**
     * Whether a scrape result can update this URL's stock and price:
     *  - stale: it was scraped for a different variant than the URL tracks now, eg the
     *    variant was changed while a scheduled update was scraping.
     *  - failed: the URL tracks a variant but the result doesn't say what its stock is,
     *    eg the store lists no variants or the page couldn't be fetched.
     *  - ok: anything else, including results that don't record their variant.
     *
     * @param  array<string, mixed>|null  $scrapeResult
     */
    protected function variantScrapeState(?array $scrapeResult): string
    {
        if (! is_array($scrapeResult)) {
            return self::VARIANT_SCRAPE_OK;
        }

        if (data_get($scrapeResult, ScrapeUrl::VARIANT_ERROR_KEY)) {
            return self::VARIANT_SCRAPE_FAILED;
        }

        if (! array_key_exists(ScrapeUrl::VARIANT_FILTER_KEY, $scrapeResult)) {
            return self::VARIANT_SCRAPE_OK;
        }

        $scrapedFor = VariantFilterDto::fromArray($scrapeResult[ScrapeUrl::VARIANT_FILTER_KEY]);

        // Read the stored variant, not this instance's: a job may have loaded the URL
        // before the variant was changed.
        $stored = $this->exists ? static::query()->whereKey($this->getKey())->value('variant') : $this->variant;
        $tracked = VariantFilterDto::fromArray(is_array($stored) ? $stored : null);

        if ($scrapedFor?->key() !== $tracked?->key()) {
            return self::VARIANT_SCRAPE_STALE;
        }

        return $scrapedFor && ! ScrapeUrl::hasVariantResult($scrapeResult)
            ? self::VARIANT_SCRAPE_FAILED
            : self::VARIANT_SCRAPE_OK;
    }

    public function updatePrice(int|float|string|null $price = null, ?array $scrapeResult = null): Price|Model|null
    {
        if (! $this->store_id) {
            return null;
        }

        $availabilityChanged = false;
        $stockStatus = null;
        $needsPrice = is_null($price) || $price === '';

        if ($needsPrice) {
            $scrapeResult = $scrapeResult ?? $this->scrape();
        }

        $variantState = $this->variantScrapeState($scrapeResult);

        // A tracked variant that can't be resolved fails closed: no price, no stock change
        // and no alerts. Healing is skipped because the price selector isn't the problem.
        if ($variantState === self::VARIANT_SCRAPE_FAILED) {
            return null;
        }

        // The variant changed while this was being scraped. The change already saved the
        // new variant's stock and price, so keep them rather than write the old one's.
        if ($variantState === self::VARIANT_SCRAPE_STALE) {
            return $this->prices()->latest('id')->first();
        }

        if ($needsPrice) {
            $scrapeResult = AiConfigHealer::new()->heal($this, $scrapeResult);
            $scrapeResult = AiScrapeEnhancer::new()->enhance($this, $scrapeResult);
            $price = data_get($scrapeResult, 'price');
        }

        // Update out-of-stock status based on scrape result.
        if ($scrapeResult) {
            $availabilityStrategy = data_get($this->store, 'scrape_strategy.availability');
            $stockStatus = ScrapeUrl::resolveStockStatus($scrapeResult, $availabilityStrategy);
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

        // An unreadable price is a failed scrape, not a price of zero.
        if ($priceFloat === null) {
            return null;
        }

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
     * The price recorded for this URL before the given price.
     */
    public function previousPrice(Price $price): ?Price
    {
        /** @var ?Price */
        return $this->prices()
            ->reorder('id', 'desc')
            ->where('id', '<', $price->getKey())
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

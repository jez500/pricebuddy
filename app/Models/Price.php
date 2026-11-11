<?php

namespace App\Models;

use App\Dto\AdjustedPriceDto;
use App\Events\PriceCreatedEvent;
use Carbon\Carbon;
use Closure;
use Database\Factories\UrlFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property ?Product $product
 * @property ?Url $url
 * @property ?Store $store
 * @property ?float $price
 * @property ?float $unit_price
 * @property float $price_factor
 * @property ?float $scraped_price
 * @property ?array $price_adjustments
 * @property ?AdjustedPriceDto $adjusted_price
 * @property Carbon $created_at
 */
class Price extends Model
{
    /** @use HasFactory<UrlFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'notified' => 'boolean',
        'price_adjustments' => 'array',
    ];

    public static function booted(): void
    {
        static::created(function (Price $price) {
            // Update price cache.
            $price->product?->updatePriceCache();
            // Dispatch event.
            PriceCreatedEvent::dispatch($price);
        });
    }

    /***************************************************
     * Attributes.
     **************************************************/

    /**
     * The parts that add up to this price, when the store adjusted the page price.
     */
    public function adjustedPrice(): Attribute
    {
        return Attribute::make(
            get: fn (): ?AdjustedPriceDto => AdjustedPriceDto::fromStorage(
                $this->price_adjustments,
                (float) ($this->scraped_price ?? $this->price),
                (float) $this->price,
            ),
        )->withoutObjectCaching(); // a recalculation changes the columns under it
    }

    /**
     * The shipping read from the store's page for this price, if any.
     */
    public function getPageShipping(): ?float
    {
        return $this->adjusted_price?->pageShipping;
    }

    /**
     * Work these prices out again from their page price, with their store's price
     * adjustments, and save them in one statement. Shipping read from the page is counted
     * while the store reads it, and kept either way, since a past page cannot be read
     * again. No events: a recalculated price is not a new price and alerts nobody. Returns
     * how many were saved: a price with no store is left as it is.
     *
     * @param  iterable<Price>  $prices
     * @param  Closure(Price): ?Store  $storeFor  the store whose settings apply
     * @param  Closure(Price): bool  $ignoresShipping  whether its listing ignores shipping
     */
    public static function saveRecalculated(iterable $prices, Closure $storeFor, Closure $ignoresShipping): int
    {
        $rows = [];

        foreach ($prices as $price) {
            $store = $storeFor($price);

            if (! $store) {
                continue;
            }

            $pagePrice = (float) ($price->scraped_price ?? $price->price);
            $adjusted = $store->price_adjustments->adjust($pagePrice, $price->getPageShipping(), $store->hasShippingStrategy(), $ignoresShipping($price));
            $total = $adjusted->total ?? $pagePrice;

            $rows[] = [
                'id' => $price->getKey(),
                'url_id' => $price->url_id,
                'store_id' => $price->store_id,
                'price' => $total,
                'unit_price' => $total / ($price->price_factor ?: 1),
                'scraped_price' => $pagePrice,
                'price_adjustments' => $adjusted ? json_encode($adjusted->toStorageArray()) : null,
            ];
        }

        if ($rows !== []) {
            static::query()->upsert($rows, ['id'], ['price', 'unit_price', 'scraped_price', 'price_adjustments']);
        }

        return count($rows);
    }

    /***************************************************
     * Relationships.
     **************************************************/

    /**
     * Price belongs to a URL.
     */
    public function url(): BelongsTo
    {
        return $this->belongsTo(Url::class);
    }

    /**
     * Price belongs to a Store.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Price belongs to a Product.
     */
    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            Url::class,
            'id',
            'id',
            'url_id',
            'product_id'
        );
    }
}

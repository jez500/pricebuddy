<?php

namespace App\Models;

use App\Dto\AdjustedPriceDto;
use App\Dto\PriceAdjustmentsDto;
use App\Events\PriceCreatedEvent;
use Carbon\Carbon;
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
     * Work this price out again from its page price, with the given price adjustments.
     * Shipping read from the page is kept while the store reads it, since a past page
     * cannot be read again. Saved quietly: a recalculated price is not a new price and
     * alerts nobody.
     */
    public function recalculate(PriceAdjustmentsDto $settings, bool $readsPageShipping, bool $ignoreShipping = false): void
    {
        $pagePrice = (float) ($this->scraped_price ?? $this->price);
        $adjusted = $settings->adjust($pagePrice, $this->getPageShipping(), $readsPageShipping, $ignoreShipping);
        $total = $adjusted->total ?? $pagePrice;

        $this->forceFill([
            'price' => $total,
            'unit_price' => $total / ($this->price_factor ?: 1),
            'scraped_price' => $pagePrice,
            'price_adjustments' => $adjusted?->toStorageArray(),
        ])->saveQuietly();
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

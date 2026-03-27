<?php

namespace App\Models;

use App\Events\PriceCreatedEvent;
use Carbon\Carbon;
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
 * @property Carbon $created_at
 */
class Price extends Model
{
    /** @use HasFactory<\Database\Factories\UrlFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'notified' => 'boolean',
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

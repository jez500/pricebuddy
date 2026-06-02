<?php

namespace App\Services;

use App\Jobs\UpdateAllPricesJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class PriceFetcherService
{
    public const JOB_TIMEOUT = 1200; // 20 minutes

    protected array $config;

    protected bool $logging = false;

    public function __construct()
    {
        $this->config = config('price_buddy');
    }

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function setLogging(bool $logging): self
    {
        $this->logging = $logging;

        return $this;
    }

    /**
     * Global lane: fetch every published product that follows the global
     * schedule (no custom interval) and isn't paused.
     */
    public function updateAllPrices(): void
    {
        Product::select('id')
            ->published()
            ->where('paused', false)
            ->whereNull('refresh_interval')
            ->chunk(data_get($this->config, 'chunk_size'), function (EloquentCollection $productIds) {
                UpdateAllPricesJob::dispatch($productIds->pluck('id')->toArray());
            });
    }

    /**
     * Per-product lane: fetch published, non-paused products with a custom
     * interval that are now due, then push their next check into the future
     * up-front so a slow queue can't re-dispatch them on the next tick.
     */
    public function updateDuePrices(): void
    {
        $due = Product::query()
            ->published()
            ->where('paused', false)
            ->whereNotNull('refresh_interval')
            ->where(fn ($query) => $query
                ->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now())
            )
            ->get(['id', 'refresh_interval', 'next_check_at']);

        $due->each(fn (Product $product) => $product->scheduleNextCheck());

        $due->pluck('id')
            ->chunk((int) data_get($this->config, 'chunk_size'))
            ->each(fn ($productIds) => UpdateAllPricesJob::dispatch($productIds->values()->toArray()));
    }

    public function getProducts(array $productIds): EloquentCollection
    {
        return Product::whereIn('id', $productIds)->get();
    }

    public function updatePrices(array $productIds): EloquentCollection
    {
        return $this
            ->getProducts($productIds)
            ->each(function ($product) {
                /** @var Product $product */
                UpdateProductPricesJob::dispatch($product, $this->logging);
            });
    }
}

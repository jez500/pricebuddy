<?php

namespace App\Jobs;

use App\Dto\PriceAdjustmentsDto;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recalculate one batch of a store's saved prices, then queue the next batch. The last
 * batch rebuilds the product caches. Start it with Store::queuePriceRecalculation().
 *
 * Each job carries the settings and scope it was started with, so a later change to the
 * store cannot alter a recalculation already under way, and it never touches prices
 * saved after it started. Batches are short, and a failed one is retried from where it
 * stopped.
 */
class RecalculateStorePrices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * While a recalculation runs, the store refuses to start another. Each batch renews
     * this, so it only runs out if the chain stops without failing.
     */
    public const int LOCK_MINUTES = 30;

    public static int $batchSize = 1000;

    public $tries = 3;

    public $backoff = 10;

    public $timeout = 300;

    /**
     * @param  array<string, mixed>  $settings  the store's price adjustments when it started
     * @param  bool  $readsPageShipping  whether the store had a shipping strategy then
     * @param  int  $upToId  the newest price when it started
     * @param  ?string  $since  only prices saved from this time
     * @param  int  $afterId  the last price done by the previous batch
     */
    public function __construct(
        public int $storeId,
        public array $settings,
        public bool $readsPageShipping,
        public int $upToId,
        public ?string $since = null,
        public int $afterId = 0,
    ) {}

    public function handle(): void
    {
        $store = Store::find($this->storeId);

        if (! $store) {
            Cache::forget(Store::recalculatingCacheKey($this->storeId));

            return;
        }

        Cache::put(Store::recalculatingCacheKey($this->storeId), true, self::LOCK_MINUTES * 60);

        $lastId = $store->recalculatePriceBatch(
            PriceAdjustmentsDto::fromArray($this->settings),
            $this->readsPageShipping,
            $this->afterId,
            $this->upToId,
            $this->since === null ? null : Carbon::parse($this->since),
            self::$batchSize,
        );

        if ($lastId !== null) {
            static::dispatch($this->storeId, $this->settings, $this->readsPageShipping, $this->upToId, $this->since, $lastId);

            return;
        }

        $store->refreshProductCaches();
        Cache::forget(Store::recalculatingCacheKey($this->storeId));
    }

    /**
     * Unlock the store so the recalculation can be started again, from its page or by
     * saving changed settings.
     */
    public function failed(?Throwable $exception): void
    {
        Cache::forget(Store::recalculatingCacheKey($this->storeId));

        logger()->error('Recalculating store prices stopped', [
            'store_id' => $this->storeId,
            'after_price_id' => $this->afterId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

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
 * Recalculate one batch of a store's saved prices, then queue the next batch. After the
 * prices, the same chain rebuilds the affected products' caches, also in batches. Start
 * it with Store::queuePriceRecalculation().
 *
 * Each job carries the settings and scope it was started with, so a later change to the
 * store cannot alter a recalculation already under way, and it never touches prices
 * saved after it started. Batches are short, and a failed one is retried from where it
 * stopped.
 *
 * The store's marker holds the run's id. A job whose run no longer holds it stops; a
 * job that finds it expired, for example after waiting in a long queue, takes it back.
 */
class RecalculateStorePrices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const string STEP_PRICES = 'prices';

    public const string STEP_CACHES = 'caches';

    /**
     * How long the store's marker lasts without a batch renewing it.
     */
    public const int LOCK_MINUTES = 30;

    public static int $batchSize = 1000;

    public static int $productBatchSize = 50;

    public $tries = 3;

    public $backoff = 10;

    /**
     * A batch takes seconds. Keeping this under the queue's default retry_after (90 s)
     * means a slow batch is never handed to a second worker while it still runs.
     */
    public $timeout = 60;

    /**
     * @param  string  $runId  the run's id, held in the store's marker while it runs
     * @param  array<string, mixed>  $settings  the store's price adjustments when it started
     * @param  bool  $readsPageShipping  whether the store had a shipping strategy then
     * @param  int  $upToId  the newest price when it started
     * @param  ?string  $since  only prices saved from this time
     * @param  string  $step  recalculating prices, or then rebuilding product caches
     * @param  int  $afterId  the last price, or product, done by the previous batch
     */
    public function __construct(
        public int $storeId,
        public string $runId,
        public array $settings,
        public bool $readsPageShipping,
        public int $upToId,
        public ?string $since = null,
        public string $step = self::STEP_PRICES,
        public int $afterId = 0,
    ) {}

    public function handle(): void
    {
        $store = Store::find($this->storeId);

        if (! $store) {
            $this->releaseMarker();

            return;
        }

        if (! $this->holdMarker()) {
            return;
        }

        if ($this->step === self::STEP_PRICES) {
            $lastId = $store->recalculatePriceBatch(
                PriceAdjustmentsDto::fromArray($this->settings),
                $this->readsPageShipping,
                $this->afterId,
                $this->upToId,
                $this->since === null ? null : Carbon::parse($this->since),
                self::$batchSize,
            );

            $this->next($lastId === null ? self::STEP_CACHES : self::STEP_PRICES, $lastId ?? 0);

            return;
        }

        $lastId = $store->refreshProductCacheBatch($this->afterId, self::$productBatchSize);

        if ($lastId !== null) {
            $this->next(self::STEP_CACHES, $lastId);

            return;
        }

        $this->releaseMarker();
    }

    /**
     * Unlock the store so the recalculation can be started again, from its page or by
     * saving changed settings.
     */
    public function failed(?Throwable $exception): void
    {
        $this->releaseMarker();

        logger()->error('Recalculating store prices stopped', [
            'store_id' => $this->storeId,
            'step' => $this->step,
            'after_id' => $this->afterId,
            'error' => $exception?->getMessage(),
        ]);
    }

    protected function next(string $step, int $afterId): void
    {
        static::dispatch($this->storeId, $this->runId, $this->settings, $this->readsPageShipping, $this->upToId, $this->since, $step, $afterId);
    }

    /**
     * Renew the store's marker for this run. False when another run holds it: this one
     * was replaced and stops.
     */
    protected function holdMarker(): bool
    {
        $key = Store::recalculatingCacheKey($this->storeId);
        $ttl = self::LOCK_MINUTES * 60;
        $holder = Cache::get($key);

        if ($holder === null) {
            return Cache::add($key, $this->runId, $ttl);
        }

        if ($holder !== $this->runId) {
            return false;
        }

        Cache::put($key, $this->runId, $ttl);

        return true;
    }

    /**
     * Remove the store's marker, if this run still holds it.
     */
    protected function releaseMarker(): void
    {
        $key = Store::recalculatingCacheKey($this->storeId);

        if (Cache::get($key) === $this->runId) {
            Cache::forget($key);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Store;
use DateTimeInterface;
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
 * A batch only runs while its run is the store's current one, a record kept in the
 * database rather than in an expiring cache entry. Starting another run, or changing the
 * price adjustments, replaces it, so a run held up in the queue can never overwrite a
 * newer one. Since any change of settings replaces the run, a batch uses the store's
 * settings as they are, which are the ones the run started with.
 *
 * Each batch holds the store's price lock, the same one a listing recalculation takes,
 * so the two never interleave. It never touches prices saved after the run started.
 */
class RecalculateStorePrices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const string STEP_PRICES = 'prices';

    public const string STEP_CACHES = 'caches';

    /**
     * How long the store's price lock lasts if its holder dies. Longer than a batch can run.
     */
    public const int LOCK_SECONDS = 120;

    public static int $batchSize = 1000;

    public static int $productBatchSize = 50;

    /**
     * A batch takes seconds. Keeping this under the queue's default retry_after (90 s)
     * means a slow batch is never handed to a second worker while it still runs.
     */
    public $timeout = 60;

    /**
     * Retried while the store's lock is busy, up to retryUntil(); failing three times
     * gives up.
     */
    public $maxExceptions = 3;

    public $backoff = 10;

    /**
     * @param  string  $runId  the run's id, the store's current run while it is not replaced
     * @param  int  $upToId  the newest price when it started
     * @param  ?string  $since  only prices saved from this time
     * @param  string  $step  recalculating prices, or then rebuilding product caches
     * @param  int  $afterId  the last price, or product, done by the previous batch
     */
    public function __construct(
        public int $storeId,
        public string $runId,
        public int $upToId,
        public ?string $since = null,
        public string $step = self::STEP_PRICES,
        public int $afterId = 0,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $lock = Cache::lock(Store::priceLockKey($this->storeId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $store = Store::find($this->storeId);

            // Deleted, or replaced by a newer run or by changed settings.
            if (! $store || $store->price_recalculation_run !== $this->runId) {
                return;
            }

            $next = $this->runBatch($store);
        } finally {
            $lock->release();
        }

        // Queued once the lock is free: a sync queue runs it straight away.
        if ($next !== null) {
            static::dispatch($this->storeId, $this->runId, $this->upToId, $this->since, ...$next);
        }
    }

    /**
     * Run this batch. Returns the next batch's step and cursor, or null when the run is done.
     *
     * @return array{0: string, 1: int}|null
     */
    protected function runBatch(Store $store): ?array
    {
        if ($this->step === self::STEP_PRICES) {
            $lastId = $store->recalculatePriceBatch(
                $store->price_adjustments,
                $store->hasShippingStrategy(),
                $this->afterId,
                $this->upToId,
                $this->since === null ? null : Carbon::parse($this->since),
                self::$batchSize,
            );

            return $lastId === null ? [self::STEP_CACHES, 0] : [self::STEP_PRICES, $lastId];
        }

        $lastId = $store->refreshProductCacheBatch($this->afterId, self::$productBatchSize);

        return $lastId === null ? null : [self::STEP_CACHES, $lastId];
    }

    /**
     * A run that stopped can be started again from the store page, or by saving changed
     * settings.
     */
    public function failed(?Throwable $exception): void
    {
        logger()->error('Recalculating store prices stopped', [
            'store_id' => $this->storeId,
            'step' => $this->step,
            'after_id' => $this->afterId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\Price;
use App\Models\Store;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recalculate one batch of a store's saved prices, then queue the next batch. After the
 * prices, the same chain rebuilds the affected products' caches, also in batches, and
 * ends the run. Start it with Store::queuePriceRecalculation().
 *
 * The store's price adjustments cannot change while its run is under way, so a batch uses
 * them as they are. A batch only runs while its run is the store's current one: a run
 * taken as abandoned, and ended by a save or replaced, stops at its next batch.
 *
 * A price batch checks its run and writes its prices while holding the store's row, so a
 * save that ends an abandoned run can never land inside a batch. Each batch also holds
 * the store's price lock, the same one a listing recalculation takes. The first batch
 * sets the cutoff: prices saved after the run started already use its settings.
 *
 * If a run fails or a crash cuts it off, a product's caches are rebuilt at its next
 * scheduled price check, which rebuilds them anyway.
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
     * A batch takes well under a second. Keeping this under the queue's default
     * retry_after (90 s) means a slow batch is never handed to a second worker while it
     * still runs.
     */
    public $timeout = 60;

    /**
     * Retried while the store's lock is busy, up to retryUntil(); failing three times
     * gives up.
     */
    public $maxExceptions = 3;

    public $backoff = 10;

    /**
     * @param  string  $runId  the run's id, the store's current run until it ends or is abandoned
     * @param  ?string  $since  only prices saved from this time
     * @param  ?int  $upToId  the newest price when the first batch ran; set by it
     * @param  string  $step  recalculating prices, or then rebuilding product caches
     * @param  int  $afterId  the last price, or product, done by the previous batch
     */
    public function __construct(
        public int $storeId,
        public string $runId,
        public ?string $since = null,
        public ?int $upToId = null,
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
            $next = $this->step === self::STEP_PRICES
                ? DB::transaction(fn () => $this->recalculatePrices())
                : $this->refreshCaches();
        } finally {
            $lock->release();
        }

        if ($next === false) {
            return;
        }

        // Queued once the lock is free: a sync queue runs it straight away.
        if ($next !== null) {
            static::dispatch($this->storeId, $this->runId, $this->since, ...$next);

            return;
        }

        Store::endPriceRecalculation($this->storeId, $this->runId);
    }

    /**
     * Recalculate the next batch of prices. Returns the next batch's cutoff, step and
     * cursor, or false when the run is no longer current.
     *
     * @return array{0: int, 1: string, 2: int}|false
     */
    protected function recalculatePrices(): array|false
    {
        /** @var ?Store $store */
        $store = Store::query()->whereKey($this->storeId)->lockForUpdate()->first();

        if ($store?->price_recalculation_run !== $this->runId) {
            return false;
        }

        Store::touchPriceRecalculation($this->storeId, $this->runId);

        $upToId = $this->upToId ?? (int) Price::query()->where('store_id', $this->storeId)->max('id');
        $lastId = $store->recalculatePriceBatch(
            $this->afterId,
            $upToId,
            $this->since === null ? null : Carbon::parse($this->since),
            self::$batchSize,
        );

        return $lastId === null ? [$upToId, self::STEP_CACHES, 0] : [$upToId, self::STEP_PRICES, $lastId];
    }

    /**
     * Rebuild the next batch of product caches. Returns the next batch's cutoff, step and
     * cursor, null when the run is done, or false when it is no longer current.
     *
     * @return array{0: int, 1: string, 2: int}|false|null
     */
    protected function refreshCaches(): array|false|null
    {
        $store = Store::find($this->storeId);

        if ($store?->price_recalculation_run !== $this->runId) {
            return false;
        }

        Store::touchPriceRecalculation($this->storeId, $this->runId);

        $lastId = $store->refreshProductCacheBatch($this->afterId, self::$productBatchSize);

        return $lastId === null ? null : [(int) $this->upToId, self::STEP_CACHES, $lastId];
    }

    /**
     * End the run, so the store's settings can be changed again. It can be started again
     * from the store page; products left with old caches are rebuilt at their next
     * scheduled price check.
     */
    public function failed(?Throwable $exception): void
    {
        Store::endPriceRecalculation($this->storeId, $this->runId);

        logger()->error('Recalculating store prices stopped', [
            'store_id' => $this->storeId,
            'step' => $this->step,
            'after_id' => $this->afterId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

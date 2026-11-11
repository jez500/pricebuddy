<?php

namespace App\Jobs;

use App\Dto\PriceAdjustmentsDto;
use App\Models\Price;
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
 * prices, the same chain rebuilds the affected products' caches, also in batches, and
 * ends the run. Start it with Store::queuePriceRecalculation().
 *
 * A batch only runs while its run is the store's current one, a record kept in the
 * database: starting another run replaces it, so a run held up in the queue can never
 * overwrite a newer one. The run carries the settings it started with. If the store's
 * settings change while it runs (saved with "New prices only"), it stops recalculating
 * and goes on to rebuild the caches of the products it already changed.
 *
 * Each batch holds the store's price lock, the same one a listing recalculation takes,
 * so the two never interleave. The first batch sets the cutoff: prices saved after the
 * run started recalculating are left alone, as they already use its settings.
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
     * @param  array<string, mixed>  $settings  the store's price adjustments when it started
     * @param  bool  $readsPageShipping  whether the store had a shipping strategy then
     * @param  ?string  $since  only prices saved from this time
     * @param  ?int  $upToId  the newest price when the first batch ran; set by it
     * @param  string  $step  recalculating prices, or then rebuilding product caches
     * @param  int  $afterId  the last price, or product, done by the previous batch
     */
    public function __construct(
        public int $storeId,
        public string $runId,
        public array $settings,
        public bool $readsPageShipping,
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
            $store = Store::find($this->storeId);

            // Deleted, or replaced by a newer run, which rebuilds the caches when it ends.
            if (! $store || $store->price_recalculation_run !== $this->runId) {
                return;
            }

            $next = $this->runBatch($store);
        } finally {
            $lock->release();
        }

        // Queued once the lock is free: a sync queue runs it straight away.
        if ($next !== null) {
            static::dispatch($this->storeId, $this->runId, $this->settings, $this->readsPageShipping, $this->since, ...$next);

            return;
        }

        Store::endPriceRecalculation($this->storeId, $this->runId);
    }

    /**
     * Run this batch. Returns the next batch's cutoff, step and cursor, or null when the
     * run is done.
     *
     * @return array{0: int, 1: string, 2: int}|null
     */
    protected function runBatch(Store $store): ?array
    {
        // Settings saved with "New prices only" while it ran: stop recalculating with the
        // old ones, and rebuild the caches of the products already changed.
        if ($this->step === self::STEP_PRICES && ! $store->usesPriceAdjustments($this->settings, $this->readsPageShipping)) {
            return $this->afterId > 0 ? [(int) $this->upToId, self::STEP_CACHES, 0] : null;
        }

        $upToId = $this->upToId ?? (int) Price::query()->where('store_id', $this->storeId)->max('id');

        if ($this->step === self::STEP_PRICES) {
            $lastId = $store->recalculatePriceBatch(
                PriceAdjustmentsDto::fromArray($this->settings),
                $this->readsPageShipping,
                $this->afterId,
                $upToId,
                $this->since === null ? null : Carbon::parse($this->since),
                self::$batchSize,
            );

            return $lastId === null ? [$upToId, self::STEP_CACHES, 0] : [$upToId, self::STEP_PRICES, $lastId];
        }

        $lastId = $store->refreshProductCacheBatch($this->afterId, self::$productBatchSize);

        return $lastId === null ? null : [$upToId, self::STEP_CACHES, $lastId];
    }

    /**
     * End the run, so the store page no longer shows one under way. It can be started
     * again from the store page; products left with old caches are rebuilt at their next
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

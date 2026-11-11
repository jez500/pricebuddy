<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\PriceFetcherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Recalculate a store's saved prices with its current price adjustments, from a date
 * (midnight, app time zone) or from the start.
 */
class RecalculateStorePrices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A store with many listings has many prices. Each price is saved with its own
     * settings, so a run that stops half way leaves nothing inconsistent; saving the
     * store again finishes it.
     */
    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    public $tries = 1;

    public function __construct(public int $storeId, public ?string $since = null) {}

    public function handle(): void
    {
        Store::find($this->storeId)?->recalculatePrices(
            $this->since === null ? null : Carbon::parse($this->since)->startOfDay(),
        );
    }
}

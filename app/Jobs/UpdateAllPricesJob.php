<?php

namespace App\Jobs;

use App\Services\PriceFetcherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class UpdateAllPricesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    public function __construct(public array $productIds = []) {}

    public function handle(): void
    {
        PriceFetcherService::new()
            ->setLogging(true)
            ->updatePrices($this->productIds);
    }
}

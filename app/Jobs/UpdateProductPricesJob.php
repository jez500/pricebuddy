<?php

namespace App\Jobs;

use App\Models\Product;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Settings\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;

class UpdateProductPricesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    public function __construct(public Product $product, public bool $logging) {}

    public function handle(): void
    {
        if ($this->logging) {
            logger()->info("Starting price fetch for: '{$this->product->title}'", [
                'product_id' => $this->product->id,
            ]);
        }

        $successful = $this->product->updatePrices();

        if ($this->logging) {
            $prefix = $successful ? 'Successful' : 'Failed (or partially failed)'; // @phpstan-ignore-line
            $method = $successful ? 'info' : 'warning'; // @phpstan-ignore-line
            logger()->{$method}("$prefix price fetch for product: '{$this->product->title}'", [
                'product_id' => $this->product->id,
            ]);
        }

        if (! $successful) { // @phpstan-ignore-line
            $this->product->user?->notify(new ScrapeFailNotification($this->product));
        }

        Sleep::for(AppSettings::new()->sleep_seconds_between_scrape)->seconds();
    }
}

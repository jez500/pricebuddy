<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Url;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Settings\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
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

        $failedUrls = $this->product->updatePrices();
        $successful = $failedUrls->isEmpty();

        if ($this->logging) {
            $prefix = $successful ? 'Successful' : 'Failed (or partially failed)';
            $method = $successful ? 'info' : 'warning';
            logger()->{$method}("$prefix price fetch for product: '{$this->product->title}'", [
                'product_id' => $this->product->id,
            ]);
        }

        if (! $successful) {
            $this->handleFailures($failedUrls);
        }

        Sleep::for(AppSettings::new()->sleep_seconds_between_scrape)->seconds();
    }

    /**
     * Schedule a delayed retry for each failed URL, or notify immediately if
     * delayed retries are disabled.
     *
     * @param  Collection<int, Url>  $failedUrls
     */
    protected function handleFailures(Collection $failedUrls): void
    {
        $settings = AppSettings::new();

        if ($settings->scrape_retry_max_attempts < 2) {
            $this->product->user?->notify(new ScrapeFailNotification($this->product));

            return;
        }

        $delay = now()->addMinutes($settings->scrape_retry_delay_minutes);

        $failedUrls->each(
            fn (Url $url) => RetryUrlPriceJob::dispatch($url, 2)->delay($delay)
        );
    }
}

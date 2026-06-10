<?php

namespace App\Jobs;

use App\Models\Url;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Settings\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class RetryUrlPriceJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    /**
     * @param  Url  $url  the URL to re-scrape
     * @param  int  $attempt  the attempt number this job represents (the original scheduled scrape is attempt 1)
     */
    public function __construct(public Url $url, public int $attempt) {}

    public function handle(): void
    {
        $product = $this->url->product;

        if (! $product || $product->paused) {
            return;
        }

        $result = $this->url->updatePrice();
        $product->updatePriceCache();

        // A non-null result means the scrape recovered (a price was recorded,
        // or the URL is legitimately out of stock). Nothing more to do.
        if (! is_null($result)) {
            return;
        }

        $settings = AppSettings::new();

        if ($this->attempt < $settings->scrape_retry_max_attempts) {
            self::dispatch($this->url, $this->attempt + 1)
                ->delay(now()->addMinutes($settings->scrape_retry_delay_minutes));

            return;
        }

        $product->user?->notify(new ScrapeFailNotification($product));
    }
}

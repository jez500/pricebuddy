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
use Illuminate\Support\Facades\Cache;

class RetryUrlPriceJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    /**
     * The retry chain is driven explicitly (this job re-dispatches itself), so a
     * single attempt is enough — queue-level retries would duplicate it.
     */
    public $tries = 1;

    /**
     * If the URL (or its product) is deleted before this delayed job runs, just
     * discard the job instead of failing it.
     */
    public bool $deleteWhenMissingModels = true;

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
        $product->updateInsightsCache();

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

        $this->notifyExhausted($product, $settings);
    }

    /**
     * Notify the product owner that the scrape failed after all retries. The
     * notification is product-level, so it is throttled to one per product per
     * retry window to avoid flooding when several of a product's URLs exhaust
     * their retries at around the same time.
     */
    protected function notifyExhausted(Product $product, AppSettings $settings): void
    {
        $cacheKey = "scrape-fail-notified:{$product->getKey()}";
        $window = now()->addMinutes(max(1, $settings->scrape_retry_delay_minutes));

        if (Cache::add($cacheKey, true, $window)) {
            $product->user?->notify(new ScrapeFailNotification($product));
        }
    }
}

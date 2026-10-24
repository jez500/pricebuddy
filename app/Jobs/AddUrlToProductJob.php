<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\AddUrlFailedNotification;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AddUrlToProductJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A failed scrape is reported to the user. A retry would only repeat it.
     */
    public $tries = 1;

    public $timeout = 300;

    public function __construct(
        public int $productId,
        public string $url,
        public int $userId,
        public float $priceFactor = 1,
    ) {}

    public function handle(): void
    {
        $product = Product::find($this->productId);
        $user = User::find($this->userId);

        // The product or the user was deleted after the job was queued.
        if (! $product || ! $user) {
            return;
        }

        try {
            $urlModel = Url::createFromUrl(
                url: $this->url,
                productId: $product->getKey(),
                userId: $user->getKey(),
                priceFactor: $this->priceFactor,
            );
        } catch (Exception $e) {
            logger()->warning('Failed to add URL to product: '.$e->getMessage(), [
                'url' => $this->url,
                'product_id' => $product->getKey(),
            ]);
            $urlModel = false;
        }

        if ($urlModel === false) {
            $user->notify(new AddUrlFailedNotification($product, $this->url));
        }
    }

    /**
     * Laravel calls this when handle() throws, for example on a timeout or a PHP error.
     */
    public function failed(?Throwable $exception): void
    {
        $product = Product::find($this->productId);
        $user = User::find($this->userId);

        if ($product && $user) {
            $user->notify(new AddUrlFailedNotification($product, $this->url));
        }
    }
}

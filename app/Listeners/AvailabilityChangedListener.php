<?php

namespace App\Listeners;

use App\Events\AvailabilityChangedEvent;
use App\Notifications\StockAlertNotification;
use Exception;

class AvailabilityChangedListener
{
    public function __construct() {}

    public function handle(AvailabilityChangedEvent $event): void
    {
        // Only notify when an item becomes purchasable again.
        if (! $event->isBackInStock()) {
            return;
        }

        $url = $event->url;

        // Need a product to proceed.
        if (! $product = $url->product) {
            return;
        }

        // The user must have opted in to back-in-stock alerts for this product.
        if (! $product->notify_in_stock) {
            return;
        }

        try {
            $product->user?->notify(new StockAlertNotification($url));
        } catch (Exception $e) {
            logger()->error('Error sending back-in-stock notification: '.$e->getMessage(), [
                'product' => $product->title,
                'product_id' => $product->getKey(),
                'url' => $url->url,
                'url_id' => $url->getKey(),
            ]);
        }
    }
}

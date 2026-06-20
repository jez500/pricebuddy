<?php

namespace App\Listeners;

use App\Events\NotifyPriceChangeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPriceChangeListener implements ShouldQueue
{
    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the listener may run before timing out.
     */
    public int $timeout = 120;

    public function handle(NotifyPriceChangeEvent $event): void
    {
        $event->product->updateInsightsCache();
    }
}

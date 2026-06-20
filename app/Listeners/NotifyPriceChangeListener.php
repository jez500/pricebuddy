<?php

namespace App\Listeners;

use App\Events\NotifyPriceChangeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPriceChangeListener implements ShouldQueue
{
    public function handle(NotifyPriceChangeEvent $event): void
    {
        $event->product->updateInsightsCache();
    }
}

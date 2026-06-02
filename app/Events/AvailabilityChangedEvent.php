<?php

namespace App\Events;

use App\Enums\StockStatus;
use App\Models\Url;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a URL's availability changes between scrapes.
 *
 * `$previous` and `$current` are null when the item is in stock (PriceBuddy
 * stores in-stock as a null availability) or a StockStatus when unavailable.
 */
class AvailabilityChangedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Url $url,
        public ?StockStatus $previous,
        public ?StockStatus $current,
    ) {}

    /**
     * Whether the item just became purchasable (was unavailable, now in stock).
     */
    public function isBackInStock(): bool
    {
        return $this->previous !== null && $this->current === null;
    }
}

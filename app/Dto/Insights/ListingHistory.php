<?php

namespace App\Dto\Insights;

use App\Enums\StockStatus;
use Illuminate\Support\Collection;

final class ListingHistory
{
    /**
     * @param  Collection<string, float>  $history  keyed Y-m-d => price, chronological
     */
    public function __construct(
        public readonly string $storeName,
        public readonly Collection $history,
        public readonly StockStatus $availability = StockStatus::InStock,
        public readonly ?int $urlId = null,
    ) {}
}

<?php

namespace App\Dto\Insights;

use App\Enums\StockStatus;

final class AvailabilityData
{
    /**
     * @param  array<int, array{available: bool, days: int}>  $segments
     */
    public function __construct(
        public readonly string $storeName,
        public readonly float $inStockPercent,
        public readonly StockStatus $currentStatus,
        public readonly array $segments,
    ) {}
}

<?php

namespace App\Dto\Insights;

final class StoreShowdownData
{
    public function __construct(
        public readonly string $storeName,
        public readonly float $currentPrice,
        public readonly bool $isAvailable,
        public readonly float $winRate,
        public readonly bool $isCheapestToday,
    ) {}
}

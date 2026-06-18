<?php

namespace App\Dto\Insights;

final class PriceStatsData
{
    public function __construct(
        public readonly float $lowest,
        public readonly ?string $lowestDate,
        public readonly ?string $lowestStore,
        public readonly float $highest,
        public readonly ?string $highestDate,
        public readonly float $average,
        public readonly float $current,
        public readonly float $percentVsAverage,
    ) {}
}

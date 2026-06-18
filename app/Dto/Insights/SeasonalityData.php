<?php

namespace App\Dto\Insights;

final class SeasonalityData
{
    /**
     * @param  array<int, ?float>  $monthlyAverages  keyed 1..12
     * @param  array<int, int>  $cheapestMonths  ordered list of month numbers (1..12), cheapest first
     */
    public function __construct(
        public readonly array $monthlyAverages,
        public readonly array $cheapestMonths,
        public readonly bool $hasEnoughData,
    ) {}
}

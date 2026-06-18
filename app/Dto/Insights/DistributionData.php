<?php

namespace App\Dto\Insights;

final class DistributionData
{
    /**
     * @param  array<int, array{min: float, max: float, count: int, isCurrent: bool}>  $bins
     */
    public function __construct(
        public readonly array $bins,
        public readonly int $currentBinPercent,
    ) {}
}

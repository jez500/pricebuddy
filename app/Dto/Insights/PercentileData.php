<?php

namespace App\Dto\Insights;

final class PercentileData
{
    public function __construct(
        public readonly float $beatFraction,
        public readonly int $percentCheaperThan,
    ) {}
}

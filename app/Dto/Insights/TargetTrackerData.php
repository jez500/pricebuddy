<?php

namespace App\Dto\Insights;

final class TargetTrackerData
{
    public function __construct(
        public readonly float $target,
        public readonly float $current,
        public readonly float $gap,
        public readonly int $progressPercent,
        public readonly int $hitCount,
        public readonly int $hitPercent,
        public readonly ?string $lastHitDate,
    ) {}
}

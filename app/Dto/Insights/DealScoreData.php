<?php

namespace App\Dto\Insights;

final class DealScoreData
{
    public function __construct(
        public readonly float $score,
        public readonly string $verdictKey,
        public readonly string $verdict,
        public readonly bool $isAllTimeLow,
        public readonly bool $lowConfidence,
    ) {}
}

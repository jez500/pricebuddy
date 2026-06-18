<?php

namespace App\Services\Insights;

use App\Dto\Insights\TargetTrackerData;
use Illuminate\Support\Collection;

class TargetTrackerCalculator
{
    /**
     * @param  Collection<string, float>  $series
     */
    public function calculate(Collection $series, float $current, ?float $target): ?TargetTrackerData
    {
        if ($target === null || $target <= 0 || $series->isEmpty()) {
            return null;
        }

        $high = (float) $series->max();
        $range = $high - $target;

        $progress = $range > 0
            ? (int) round(max(0, min(1, ($high - $current) / $range)) * 100)
            : ($current <= $target ? 100 : 0);

        $hits = $series->filter(fn ($v): bool => (float) $v <= $target);

        return new TargetTrackerData(
            target: $target,
            current: $current,
            gap: round($current - $target, 2),
            progressPercent: $progress,
            hitCount: $hits->count(),
            hitPercent: (int) round($hits->count() / $series->count() * 100),
            lastHitDate: $hits->keys()->last(),
        );
    }
}

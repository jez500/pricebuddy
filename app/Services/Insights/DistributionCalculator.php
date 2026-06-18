<?php

namespace App\Services\Insights;

use App\Dto\Insights\DistributionData;
use Illuminate\Support\Collection;

class DistributionCalculator
{
    /**
     * @param  Collection<string, float>  $series
     */
    public function calculate(Collection $series, float $current, int $binCount = 8): DistributionData
    {
        if ($series->isEmpty()) {
            return new DistributionData([], 0);
        }

        $low = (float) $series->min();
        $high = (float) $series->max();
        $total = $series->count();

        if ($high <= $low) {
            return new DistributionData(
                [['min' => $low, 'max' => $high, 'count' => $total, 'isCurrent' => true]],
                100,
            );
        }

        $width = ($high - $low) / $binCount;
        $bins = [];
        for ($i = 0; $i < $binCount; $i++) {
            $bins[$i] = [
                'min' => round($low + $i * $width, 2),
                'max' => round($low + ($i + 1) * $width, 2),
                'count' => 0,
                'isCurrent' => false,
            ];
        }

        $index = fn (float $value): int => max(0, min($binCount - 1, (int) floor(($value - $low) / $width)));

        foreach ($series as $value) {
            $bins[$index((float) $value)]['count']++;
        }

        $currentIndex = $index($current);
        $bins[$currentIndex]['isCurrent'] = true;
        $percent = (int) round($bins[$currentIndex]['count'] / $total * 100);

        return new DistributionData(array_values($bins), $percent);
    }
}

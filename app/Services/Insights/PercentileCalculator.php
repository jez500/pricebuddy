<?php

namespace App\Services\Insights;

use App\Dto\Insights\PercentileData;
use Illuminate\Support\Collection;

class PercentileCalculator
{
    /**
     * @param  Collection<string, float>  $series
     */
    public function calculate(Collection $series, float $current): PercentileData
    {
        if ($series->isEmpty()) {
            return new PercentileData(0, 0);
        }

        $beat = $series->filter(fn ($v): bool => (float) $v > $current)->count();
        $fraction = $beat / $series->count();

        return new PercentileData(round($fraction, 4), (int) round($fraction * 100));
    }
}

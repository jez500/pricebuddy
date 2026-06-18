<?php

namespace App\Services\Insights;

use App\Dto\Insights\DealScoreData;

class DealScoreCalculator
{
    /**
     * Minimum daily data points before a deal score is considered reliable. Below this the
     * percentile/lowest baselines are too thin (under ~2 weeks of history) to trust.
     */
    private const MIN_CONFIDENCE_DATA_POINTS = 14;

    /**
     * Tolerance for treating two prices as equal, to absorb float rounding when comparing
     * the current price against the all-time low.
     */
    private const PRICE_EPSILON = 0.005;

    private const VERDICTS = [
        'great' => 'Great time to buy',
        'good' => 'Good price',
        'average' => 'About average',
        'pricey' => 'A bit pricey',
        'wait' => "Wait — it's expensive right now",
    ];

    public function calculate(float $beatFraction, float $current, float $lowest, int $dataPoints): DealScoreData
    {
        $score = round($beatFraction * 10, 1);
        $isAllTimeLow = $current > 0 && $current <= $lowest + self::PRICE_EPSILON;

        if ($isAllTimeLow) {
            $score = max($score, 9.5);
        }

        $key = match (true) {
            $score >= 8.0 => 'great',
            $score >= 6.0 => 'good',
            $score >= 4.0 => 'average',
            $score >= 2.0 => 'pricey',
            default => 'wait',
        };

        return new DealScoreData(
            score: $score,
            verdictKey: $key,
            verdict: self::VERDICTS[$key],
            isAllTimeLow: $isAllTimeLow,
            lowConfidence: $dataPoints < self::MIN_CONFIDENCE_DATA_POINTS,
        );
    }
}

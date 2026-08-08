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

    /**
     * Score for the one case where a price with no history still warrants a verdict:
     * it is cheaper than the product's other listings. High enough to reach the
     * dashboard's "Buy now" cut-off, below the score a genuine all-time low earns.
     */
    private const NO_HISTORY_GREAT_SCORE = 8.0;

    private const VERDICTS = [
        'great' => 'Great time to buy',
        'good' => 'Good price',
        'average' => 'About average',
        'pricey' => 'A bit pricey',
        'wait' => "Wait — it's expensive right now",
        'unknown' => 'Not enough data yet',
    ];

    /**
     * $hasPriceVariation is whether the price has ever actually moved, and
     * $beatsOtherListings whether this is currently cheaper than the product's other URLs.
     *
     * Both baselines this score rests on — the percentile and the all-time low — are
     * meaningless without price movement: on the day a product is added, its only
     * observed price is trivially the lowest ever seen, which used to floor the score at
     * 9.5 and announce "Great time to buy" about a price nothing has been compared to.
     * With no movement the only real evidence left is cross-sectional: being cheaper than
     * the other places the same product is tracked.
     */
    public function calculate(float $beatFraction, float $current, float $lowest, int $dataPoints, bool $hasPriceVariation, bool $beatsOtherListings): DealScoreData
    {
        if (! $hasPriceVariation) {
            return $this->withoutHistory($beatsOtherListings);
        }

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

    /**
     * The verdict for a price that has never moved. Never an all-time low — a low only
     * means something next to a high — and always low confidence, since there is by
     * definition nothing to be confident about yet.
     *
     * The score doubles as the dashboard's "Buy now" ranking, so a no-verdict product
     * scores 0 and stays out of it rather than topping it on the day it was added.
     */
    private function withoutHistory(bool $beatsOtherListings): DealScoreData
    {
        $key = $beatsOtherListings ? 'great' : 'unknown';

        return new DealScoreData(
            score: $beatsOtherListings ? self::NO_HISTORY_GREAT_SCORE : 0.0,
            verdictKey: $key,
            verdict: self::VERDICTS[$key],
            isAllTimeLow: false,
            lowConfidence: true,
        );
    }
}

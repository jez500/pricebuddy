<?php

namespace App\Dto\Insights;

use Illuminate\Support\Collection;

final class ProductInsightsData
{
    /**
     * @param  Collection<string, float>  $dailyBest
     * @param  Collection<int, DropEventData>  $dropEvents
     * @param  Collection<int, StoreShowdownData>  $storeShowdown
     * @param  Collection<int, AvailabilityData>  $availability
     */
    public function __construct(
        public readonly Collection $dailyBest,
        public readonly float $bestPrice,
        public readonly ?string $bestStore,
        public readonly PriceStatsData $stats,
        public readonly PercentileData $percentile,
        public readonly DealScoreData $dealScore,
        public readonly DistributionData $distribution,
        public readonly Collection $dropEvents,
        public readonly Collection $storeShowdown,
        public readonly SeasonalityData $seasonality,
        public readonly Collection $availability,
        public readonly ?TargetTrackerData $targetTracker,
        public readonly bool $hasEnoughData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dailyBest' => $this->dailyBest->toArray(),
            'bestPrice' => $this->bestPrice,
            'bestStore' => $this->bestStore,
            'stats' => $this->stats->toArray(),
            'percentile' => $this->percentile->toArray(),
            'dealScore' => $this->dealScore->toArray(),
            'distribution' => $this->distribution->toArray(),
            'dropEvents' => $this->dropEvents->map(fn (DropEventData $e): array => $e->toArray())->all(),
            'storeShowdown' => $this->storeShowdown->map(fn (StoreShowdownData $s): array => $s->toArray())->all(),
            'seasonality' => $this->seasonality->toArray(),
            'availability' => $this->availability->map(fn (AvailabilityData $a): array => $a->toArray())->all(),
            'targetTracker' => $this->targetTracker?->toArray(),
            'hasEnoughData' => $this->hasEnoughData,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dailyBest: (new Collection($data['dailyBest']))->map(fn ($v): float => (float) $v),
            bestPrice: (float) $data['bestPrice'],
            bestStore: $data['bestStore'] ?? null,
            stats: PriceStatsData::fromArray($data['stats']),
            percentile: PercentileData::fromArray($data['percentile']),
            dealScore: DealScoreData::fromArray($data['dealScore']),
            distribution: DistributionData::fromArray($data['distribution']),
            dropEvents: (new Collection($data['dropEvents']))->map(fn (array $e): DropEventData => DropEventData::fromArray($e)),
            storeShowdown: (new Collection($data['storeShowdown']))->map(fn (array $s): StoreShowdownData => StoreShowdownData::fromArray($s)),
            seasonality: SeasonalityData::fromArray($data['seasonality']),
            availability: (new Collection($data['availability']))->map(fn (array $a): AvailabilityData => AvailabilityData::fromArray($a)),
            targetTracker: isset($data['targetTracker']) ? TargetTrackerData::fromArray($data['targetTracker']) : null,
            hasEnoughData: (bool) $data['hasEnoughData'],
        );
    }
}

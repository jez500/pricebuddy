<?php

namespace App\Dto\Insights;

final class PriceStatsData
{
    public function __construct(
        public readonly float $lowest,
        public readonly ?string $lowestDate,
        public readonly ?string $lowestStore,
        public readonly float $highest,
        public readonly ?string $highestDate,
        public readonly float $average,
        public readonly float $current,
        public readonly float $percentVsAverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lowest' => $this->lowest,
            'lowestDate' => $this->lowestDate,
            'lowestStore' => $this->lowestStore,
            'highest' => $this->highest,
            'highestDate' => $this->highestDate,
            'average' => $this->average,
            'current' => $this->current,
            'percentVsAverage' => $this->percentVsAverage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            lowest: (float) $data['lowest'],
            lowestDate: $data['lowestDate'] ?? null,
            lowestStore: $data['lowestStore'] ?? null,
            highest: (float) $data['highest'],
            highestDate: $data['highestDate'] ?? null,
            average: (float) $data['average'],
            current: (float) $data['current'],
            percentVsAverage: (float) $data['percentVsAverage'],
        );
    }
}

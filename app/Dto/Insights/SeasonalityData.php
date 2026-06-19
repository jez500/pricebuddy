<?php

namespace App\Dto\Insights;

final class SeasonalityData
{
    /**
     * @param  array<int, ?float>  $monthlyAverages  keyed 1..12
     * @param  array<int, int>  $cheapestMonths  ordered list of month numbers (1..12), cheapest first
     */
    public function __construct(
        public readonly array $monthlyAverages,
        public readonly array $cheapestMonths,
        public readonly bool $hasEnoughData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monthlyAverages' => $this->monthlyAverages,
            'cheapestMonths' => $this->cheapestMonths,
            'hasEnoughData' => $this->hasEnoughData,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $monthlyAverages = [];
        foreach ($data['monthlyAverages'] as $month => $value) {
            $monthlyAverages[(int) $month] = $value === null ? null : (float) $value;
        }

        return new self(
            monthlyAverages: $monthlyAverages,
            cheapestMonths: array_map('intval', $data['cheapestMonths']),
            hasEnoughData: (bool) $data['hasEnoughData'],
        );
    }
}

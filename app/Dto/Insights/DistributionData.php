<?php

namespace App\Dto\Insights;

final class DistributionData
{
    /**
     * @param  array<int, array{min: float, max: float, count: int, isCurrent: bool}>  $bins
     */
    public function __construct(
        public readonly array $bins,
        public readonly int $currentBinPercent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bins' => $this->bins,
            'currentBinPercent' => $this->currentBinPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $bins = array_map(static fn (array $bin): array => [
            'min' => (float) $bin['min'],
            'max' => (float) $bin['max'],
            'count' => (int) $bin['count'],
            'isCurrent' => (bool) $bin['isCurrent'],
        ], $data['bins']);

        return new self(
            bins: $bins,
            currentBinPercent: (int) $data['currentBinPercent'],
        );
    }
}

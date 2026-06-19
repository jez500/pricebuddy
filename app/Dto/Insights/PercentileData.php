<?php

namespace App\Dto\Insights;

final class PercentileData
{
    public function __construct(
        public readonly float $beatFraction,
        public readonly int $percentCheaperThan,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'beatFraction' => $this->beatFraction,
            'percentCheaperThan' => $this->percentCheaperThan,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            beatFraction: (float) $data['beatFraction'],
            percentCheaperThan: (int) $data['percentCheaperThan'],
        );
    }
}

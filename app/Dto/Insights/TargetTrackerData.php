<?php

namespace App\Dto\Insights;

final class TargetTrackerData
{
    public function __construct(
        public readonly float $target,
        public readonly float $current,
        public readonly float $gap,
        public readonly int $progressPercent,
        public readonly int $hitCount,
        public readonly int $hitPercent,
        public readonly ?string $lastHitDate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'current' => $this->current,
            'gap' => $this->gap,
            'progressPercent' => $this->progressPercent,
            'hitCount' => $this->hitCount,
            'hitPercent' => $this->hitPercent,
            'lastHitDate' => $this->lastHitDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            target: (float) $data['target'],
            current: (float) $data['current'],
            gap: (float) $data['gap'],
            progressPercent: (int) $data['progressPercent'],
            hitCount: (int) $data['hitCount'],
            hitPercent: (int) $data['hitPercent'],
            lastHitDate: $data['lastHitDate'] ?? null,
        );
    }
}

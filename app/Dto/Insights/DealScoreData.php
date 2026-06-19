<?php

namespace App\Dto\Insights;

final class DealScoreData
{
    public function __construct(
        public readonly float $score,
        public readonly string $verdictKey,
        public readonly string $verdict,
        public readonly bool $isAllTimeLow,
        public readonly bool $lowConfidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'verdictKey' => $this->verdictKey,
            'verdict' => $this->verdict,
            'isAllTimeLow' => $this->isAllTimeLow,
            'lowConfidence' => $this->lowConfidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            score: (float) $data['score'],
            verdictKey: (string) $data['verdictKey'],
            verdict: (string) $data['verdict'],
            isAllTimeLow: (bool) $data['isAllTimeLow'],
            lowConfidence: (bool) $data['lowConfidence'],
        );
    }
}

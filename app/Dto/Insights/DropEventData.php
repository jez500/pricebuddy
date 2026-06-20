<?php

namespace App\Dto\Insights;

final class DropEventData
{
    public function __construct(
        public readonly string $storeName,
        public readonly string $date,
        public readonly float $change,
        public readonly float $changePercent,
        public readonly bool $isDrop,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'storeName' => $this->storeName,
            'date' => $this->date,
            'change' => $this->change,
            'changePercent' => $this->changePercent,
            'isDrop' => $this->isDrop,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            storeName: (string) $data['storeName'],
            date: (string) $data['date'],
            change: (float) $data['change'],
            changePercent: (float) $data['changePercent'],
            isDrop: (bool) $data['isDrop'],
        );
    }
}

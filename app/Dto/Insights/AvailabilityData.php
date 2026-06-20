<?php

namespace App\Dto\Insights;

use App\Enums\StockStatus;

final class AvailabilityData
{
    /**
     * @param  array<int, array{available: bool, days: int}>  $segments
     */
    public function __construct(
        public readonly string $storeName,
        public readonly float $inStockPercent,
        public readonly StockStatus $currentStatus,
        public readonly array $segments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'storeName' => $this->storeName,
            'inStockPercent' => $this->inStockPercent,
            'currentStatus' => $this->currentStatus->value,
            'segments' => $this->segments,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $segments = array_map(static fn (array $segment): array => [
            'available' => (bool) $segment['available'],
            'days' => (int) $segment['days'],
        ], $data['segments']);

        return new self(
            storeName: (string) $data['storeName'],
            inStockPercent: (float) $data['inStockPercent'],
            currentStatus: StockStatus::from($data['currentStatus']),
            segments: $segments,
        );
    }
}

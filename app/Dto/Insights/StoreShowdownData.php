<?php

namespace App\Dto\Insights;

final class StoreShowdownData
{
    public function __construct(
        public readonly string $storeName,
        public readonly float $currentPrice,
        public readonly bool $isAvailable,
        public readonly float $winRate,
        public readonly bool $isCheapestToday,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'storeName' => $this->storeName,
            'currentPrice' => $this->currentPrice,
            'isAvailable' => $this->isAvailable,
            'winRate' => $this->winRate,
            'isCheapestToday' => $this->isCheapestToday,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            storeName: (string) $data['storeName'],
            currentPrice: (float) $data['currentPrice'],
            isAvailable: (bool) $data['isAvailable'],
            winRate: (float) $data['winRate'],
            isCheapestToday: (bool) $data['isCheapestToday'],
        );
    }
}

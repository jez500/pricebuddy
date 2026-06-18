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
}

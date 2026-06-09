<?php

namespace App\Dto;

use App\Enums\StockStatus;

class AiExtractionResultDto
{
    public function __construct(
        public ?string $title = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?string $image = null,
        public ?StockStatus $stockStatus = null,
        public float $confidence = 0.0,
    ) {}
}

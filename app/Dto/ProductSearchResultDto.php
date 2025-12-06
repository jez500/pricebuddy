<?php

namespace App\Dto;

class ProductSearchResultDto
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $price = null,
        public ?string $image = null,
        public ?int $source_id = null,
    ) {}
}

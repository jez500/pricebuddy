<?php

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;

it('holds extraction fields with sensible defaults', function () {
    $dto = new AiExtractionResultDto(
        title: 'Widget',
        price: 12.99,
        currency: 'USD',
        image: 'https://example.com/w.jpg',
        stockStatus: StockStatus::InStock,
        confidence: 0.8,
    );

    expect($dto->title)->toBe('Widget')
        ->and($dto->price)->toBe(12.99)
        ->and($dto->currency)->toBe('USD')
        ->and($dto->image)->toBe('https://example.com/w.jpg')
        ->and($dto->stockStatus)->toBe(StockStatus::InStock)
        ->and($dto->confidence)->toBe(0.8);
});

it('defaults every field', function () {
    $dto = new AiExtractionResultDto;

    expect($dto->title)->toBeNull()
        ->and($dto->price)->toBeNull()
        ->and($dto->image)->toBeNull()
        ->and($dto->stockStatus)->toBeNull()
        ->and($dto->confidence)->toBe(0.0);
});

<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

trait ScraperTrait
{
    protected function mockScrape(mixed $price, mixed $title = null, mixed $image = null, mixed $availability = null): void
    {
        Http::fake([
            '*' => Http::response(View::make('tests.product-page', [
                'price' => $price,
                'title' => $title,
                'image' => $image,
                'availability' => $availability,
            ])->render()),
        ]);
    }
}

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

    protected function mockScrapeSchema(mixed $price, mixed $title = null, mixed $image = null): void
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $title,
            'image' => $image,
            'description' => 'Schema description',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => $price,
            ],
        ]);

        $html = <<<HTML
<html>
<head>
    <script type="application/ld+json">
    {$json}
    </script>
</head>
<body>
    <p>This page is used for test responses with schema.org</p>
</body>
</html>
HTML;

        Http::fake([
            '*' => Http::response($html),
        ]);
    }
}

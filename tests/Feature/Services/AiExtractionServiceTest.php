<?php

namespace Tests\Feature\Services;

use App\Dto\AiExtractionResultDto;
use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
use App\Enums\StockStatus;
use App\Services\AiExtractionService;
use App\Services\AiService;
use Tests\TestCase;

class AiExtractionServiceTest extends TestCase
{
    private function provider(): AiProviderConfigDto
    {
        return new AiProviderConfigDto(id: 'p1', name: 'Test', type: AiProvider::Ollama, model: 'm');
    }

    public function test_returns_null_when_ai_is_disabled(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->never();
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget $12</body></html>');

        $this->assertNull($result);
    }

    public function test_maps_a_structured_ai_result_to_a_dto(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturn([
                'name' => 'Widget',
                'description' => 'A small widget',
                'price' => '12.99',
                'currency' => 'USD',
                'imageUrl' => 'https://example.com/w.jpg',
                'stockStatus' => 'https://schema.org/InStock',
                'confidence' => 0.82,
            ]);
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget $12.99</body></html>', provider: $this->provider());

        $this->assertInstanceOf(AiExtractionResultDto::class, $result);
        $this->assertSame('Widget', $result->title);
        $this->assertSame('A small widget', $result->description);
        $this->assertSame(12.99, $result->price);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('https://example.com/w.jpg', $result->image);
        $this->assertSame(StockStatus::InStock, $result->stockStatus);
        $this->assertSame(0.82, $result->confidence);
    }

    public function test_returns_null_when_the_ai_result_is_empty(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturnNull();
        });

        $result = AiExtractionService::new()->extract('<html></html>', provider: $this->provider());

        $this->assertNull($result);
    }

    public function test_preprocesses_html_strips_scripts_styles_and_truncates_to_25k_chars(): void
    {
        $service = AiExtractionService::new();
        $html = '<html><head><style>.a{color:red}</style><script>alert(1)</script></head>'
            .'<body>Price: $12.99'.str_repeat('x', 40000).'</body></html>';

        $prepared = $service->prepareHtml($html);

        $this->assertStringNotContainsString('alert(1)', $prepared);
        $this->assertStringNotContainsString('color:red', $prepared);
        $this->assertLessThanOrEqual(25000, strlen($prepared));
    }

    public function test_maps_schema_org_out_of_stock_url_to_out_of_stock_status(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturn([
                'name' => 'Widget',
                'price' => '9.99',
                'currency' => 'USD',
                'imageUrl' => null,
                'stockStatus' => 'https://schema.org/OutOfStock',
                'confidence' => 0.9,
            ]);
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget</body></html>', provider: $this->provider());

        $this->assertInstanceOf(AiExtractionResultDto::class, $result);
        $this->assertSame(StockStatus::OutOfStock, $result->stockStatus);
    }

    public function test_maps_schema_org_pre_order_url_to_pre_order_status(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('structured')->once()->andReturn([
                'name' => 'Widget',
                'price' => '9.99',
                'currency' => 'USD',
                'imageUrl' => null,
                'stockStatus' => 'https://schema.org/PreOrder',
                'confidence' => 0.9,
            ]);
        });

        $result = AiExtractionService::new()->extract('<html><body>Widget</body></html>', provider: $this->provider());

        $this->assertInstanceOf(AiExtractionResultDto::class, $result);
        $this->assertSame(StockStatus::PreOrder, $result->stockStatus);
    }

    public function test_prepare_html_prepends_schema_org_json_within_25k_limit(): void
    {
        $service = AiExtractionService::new();
        $schemaOrg = collect([['@type' => 'Product', 'name' => 'Widget', 'offers' => ['price' => '12.99']]]);

        $prepared = $service->prepareHtml('<body>hi</body>', $schemaOrg);

        $this->assertTrue(str_contains($prepared, $schemaOrg->toJson()));
        $this->assertLessThanOrEqual(25000, strlen($prepared));
    }
}

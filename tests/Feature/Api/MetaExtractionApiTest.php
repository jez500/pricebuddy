<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class MetaExtractionApiTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected string $url = 'https://example.com/product/123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_can_extract_meta_using_domain_store_configuration(): void
    {
        Store::factory()->create([
            'user_id' => $this->user->id,
            'domains' => [
                ['domain' => parse_url($this->url, PHP_URL_HOST)],
            ],
            'settings' => [
                'scraper_service' => 'http',
                'scraper_service_settings' => '',
            ],
            'scrape_strategy' => [
                'title' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:title"]|content',
                ],
                'price' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:price:amount"]|content',
                ],
                'image' => [
                    'type' => 'selector',
                    'value' => 'meta[property="og:image"]|content',
                ],
            ],
        ]);

        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Example product')
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.image', 'https://example.com/image.jpg');
    }

    public function test_can_extract_meta_using_store_override(): void
    {
        $this->mockScrape('$19.99', 'Override product', 'https://example.com/override.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
            'store' => [
                'settings' => [
                    'scraper_service' => 'http',
                    'scraper_service_settings' => '',
                ],
                'scrape_strategy' => [
                    'title' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:title"]|content',
                    ],
                    'price' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:price:amount"]|content',
                    ],
                    'image' => [
                        'type' => 'selector',
                        'value' => 'meta[property="og:image"]|content',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Override product')
            ->assertJsonPath('data.price', 19.99)
            ->assertJsonPath('data.image', 'https://example.com/override.jpg');
    }

    public function test_can_extract_meta_using_schema_org_strategies_with_null_values(): void
    {
        $this->mockScrapeSchema('$42.50', 'Schema product', 'https://example.com/schema.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
            'store' => [
                'settings' => [
                    'scraper_service' => 'http',
                    'scraper_service_settings' => '',
                ],
                'scrape_strategy' => [
                    'title' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                    'price' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                    'image' => [
                        'type' => 'schema_org',
                        'value' => null,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Schema product')
            ->assertJsonPath('data.price', 42.5)
            ->assertJsonPath('data.image', 'https://example.com/schema.jpg');
    }

    public function test_auto_create_path_normalizes_the_price_output(): void
    {
        $this->mockScrape('$35.00', 'Example product', 'https://example.com/image.jpg');

        $response = $this->postJson('/api/meta-extraction', [
            'url' => $this->url,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Example product')
            ->assertJsonPath('data.price', 35)
            ->assertJsonPath('data.image', 'https://example.com/image.jpg');
    }

    public function test_validation_fails_without_a_url(): void
    {
        $response = $this->postJson('/api/meta-extraction', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }
}

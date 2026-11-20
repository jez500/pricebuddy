<?php

namespace Tests\Feature\Services;

use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\AiService;
use App\Services\Helpers\SettingsHelper;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;

class DeterministicHealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function schemaOrgHtml(string $availabilityLabel = 'InStock'): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => 'Widget',
            'image' => 'https://x.test/w.png',
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => 48.95,
                'availability' => 'https://schema.org/'.$availabilityLabel,
            ],
        ]);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    public function test_heals_deterministically_from_schema_org_without_invoking_the_agent(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, $this->schemaOrgHtml());

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('schema_org', $store->scrape_strategy->price?->type->value);
        $this->assertSame('schema_org', $store->scrape_strategy->availability?->type->value);
        $this->assertSame('schema_org', $store->scrape_strategy->title?->type->value);
    }

    public function test_preview_returns_schema_org_fields_without_invoking_the_agent(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $preview = AiConfigHealer::new()->previewForUrl('https://shop.test/widget', null, $this->schemaOrgHtml());

        $this->assertSame('schema_org', data_get($preview, 'fields.price.type'));
        $this->assertSame('schema_org', data_get($preview, 'fields.availability.type'));
        $this->assertFalse(data_get($preview, 'usedBrowser'));
    }

    public function test_escalates_to_browser_then_heals_deterministically_when_static_is_blocked(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        $blocked = '<html><head><title>Access Denied</title></head><body>blocked</body></html>';
        $schema = $this->schemaOrgHtml();

        // Static (http) returns the blocked page; browser (api) returns the schema.org page.
        WebScraper::shouldReceive('make')->andReturnUsing(
            fn (string $service) => (new WebScraperFake)->setBody($service === 'api' ? $schema : $blocked),
        );

        // html=null forces a static fetch (blocked) → heuristics fail → browser fetch → heuristics succeed.
        $store = AiConfigHealer::new()->healStoreForUrl('https://shop.test/widget', null, null);

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('api', data_get($store->settings, 'scraper_service'));
        $this->assertSame('schema_org', $store->scrape_strategy->price?->type->value);
    }

    /**
     * A failed fetch (eg a 403 challenge page) has no body. Healing fetches a fresh page
     * itself and switches the store to the browser scraper when that is what works.
     */
    public function test_heals_a_failed_fetch_through_the_browser(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->never());

        // The static fetch is still blocked; the browser returns the product page.
        WebScraper::shouldReceive('make')->andReturnUsing(
            fn (string $service) => (new WebScraperFake)->setBody($service === 'api' ? $this->schemaOrgHtml() : ''),
        );

        $url = $this->urlForNewStore();

        $result = AiConfigHealer::new()->heal($url, ['store' => $url->store, ScrapeUrl::SCRAPE_FAILED_KEY => true]);

        $this->assertSame('api', data_get($url->store->fresh()->settings, 'scraper_service'));
        $this->assertNotEmpty($result['price'] ?? null);
        $this->assertArrayNotHasKey(ScrapeUrl::SCRAPE_FAILED_KEY, $result);
    }

    public function test_failed_fetch_stays_failed_when_healing_cannot_recover_it(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('runAgent')->andReturn(null));

        WebScraper::shouldReceive('make')->andReturnUsing(fn () => (new WebScraperFake)->setBody(''));

        $url = $this->urlForNewStore();

        $result = AiConfigHealer::new()->heal($url, ['store' => $url->store, ScrapeUrl::SCRAPE_FAILED_KEY => true]);

        $this->assertTrue($result[ScrapeUrl::SCRAPE_FAILED_KEY]);
        $this->assertSame('http', data_get($url->store->fresh()->settings, 'scraper_service'));
        $this->assertNotNull($url->store->fresh()->getAiHealFailedAt());
    }

    private function urlForNewStore(): Url
    {
        $store = Store::factory()->create(['scrape_strategy' => [], 'settings' => ['scraper_service' => 'http']]);

        return Url::factory()->for($store)->create(['url' => 'https://shop.test/widget']);
    }
}

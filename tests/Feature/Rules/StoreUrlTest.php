<?php

namespace Tests\Feature\Rules;

use App\Models\Store;
use App\Rules\StoreUrl;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class StoreUrlTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function enableHealing(): void
    {
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

    /**
     * @return array<int, string>
     */
    private function runRule(string $url, bool $createStore): array
    {
        $failures = [];
        $fail = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        (new StoreUrl)->setData(['data' => ['create_store' => $createStore]])->validate('url', $url, $fail);

        return $failures;
    }

    public function test_accepts_missing_price_when_store_treats_it_as_out_of_stock(): void
    {
        Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http', 'missing_price_out_of_stock' => true],
        ]);
        $this->mockScrape('', 'Out of Stock Product');

        $this->assertSame([], $this->runRule('https://shop.test/p', createStore: false));
    }

    public function test_rejects_missing_price_when_store_setting_is_off(): void
    {
        Store::factory()->create([
            'domains' => [['domain' => 'shop.test']],
            'settings' => ['scraper_service' => 'http'],
        ]);
        $this->mockScrape('', 'Out of Stock Product');

        $this->assertContains('The url does not contain a valid title or price', $this->runRule('https://shop.test/p', createStore: false));
    }

    public function test_rejects_unknown_domain_when_healing_disabled(): void
    {
        $failures = $this->runRule('https://unknown.test/p', createStore: false);

        $this->assertContains('The domain does not belong to any stores', $failures);
    }

    public function test_defers_unknown_domain_when_healing_enabled(): void
    {
        $this->enableHealing();

        $failures = $this->runRule('https://unknown.test/p', createStore: false);

        $this->assertSame([], $failures);
    }

    public function test_still_rejects_malformed_url_when_healing_enabled(): void
    {
        $this->enableHealing();

        $failures = $this->runRule('not-a-url', createStore: true);

        $this->assertNotEmpty($failures);
    }
}

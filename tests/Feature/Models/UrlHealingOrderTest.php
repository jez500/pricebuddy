<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\AiScrapeEnhancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UrlHealingOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_heal_runs_before_enhancer_and_recovered_price_is_saved(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);
        $url = Url::factory()->for($store)->create(['url' => 'https://shop.test/x']);

        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('heal')
            ->once()
            ->andReturnUsing(fn ($u, $r) => array_merge($r, ['price' => '9.99'])));

        // Enhancer must receive the price the healer recovered (proves ordering).
        $this->mock(AiScrapeEnhancer::class, fn ($m) => $m->shouldReceive('enhance')
            ->once()
            ->with(Mockery::any(), Mockery::on(fn ($r) => data_get($r, 'price') === '9.99'))
            ->andReturnUsing(fn ($u, $r) => $r));

        $price = $url->updatePrice(null, ['price' => null, 'body' => '<html></html>', 'availability' => null]);

        $this->assertNotNull($price);
        $this->assertSame(9.99, (float) $price->price);
    }
}

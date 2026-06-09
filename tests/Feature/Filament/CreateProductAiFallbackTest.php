<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Store;
use App\Models\User;
use App\Services\AiConfigHealer;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class CreateProductAiFallbackTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    const URL = 'https://newshop.test/p/1';

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
        $this->actingAs(User::factory()->create());

        // Enable AI healing so StoreUrl validation defers to createFromUrl.
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

    public function test_product_is_created_when_ai_heals_the_store(): void
    {
        // No store exists for newshop.test; validation defers (healing on); createFromUrl
        // calls the (mocked) healer which creates a working store; the re-scrape succeeds.
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->once()
            ->andReturnUsing(fn ($url, $store, $html) => Store::factory()->create([
                'domains' => [['domain' => 'newshop.test']],
            ])));

        $this->mockScrape('19.99', 'AI Widget');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => self::URL,
                'create_store' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['title' => 'AI Widget']);
        $this->assertDatabaseHas('urls', ['url' => self::URL]);
    }

    public function test_validation_error_when_ai_cannot_heal(): void
    {
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('healStoreForUrl')
            ->andReturnNull());

        $this->mockScrape('', '');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'url' => self::URL,
                'create_store' => false,
            ])
            ->call('create')
            ->assertHasErrors(['url']);

        $this->assertDatabaseCount('products', 0);
    }
}

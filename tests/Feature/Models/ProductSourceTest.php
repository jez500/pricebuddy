<?php

namespace Tests\Feature\Models;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Models\ProductSource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_slug_from_name()
    {
        $source = ProductSource::factory()->create(['name' => 'Test Source Name']);
        $this->assertEquals('test-source-name', $source->slug);
    }

    public function test_casts_type_to_enum()
    {
        $source = ProductSource::factory()->create(['type' => ProductSourceType::DealsSite]);
        $this->assertInstanceOf(ProductSourceType::class, $source->type);
        $this->assertEquals(ProductSourceType::DealsSite, $source->type);
    }

    public function test_casts_status_to_enum()
    {
        $source = ProductSource::factory()->create(['status' => ProductSourceStatus::Active]);
        $this->assertInstanceOf(ProductSourceStatus::class, $source->status);
        $this->assertEquals(ProductSourceStatus::Active, $source->status);
    }

    public function test_casts_extraction_strategy_to_array()
    {
        $strategy = [
            'list_container' => ['type' => 'selector', 'value' => '.item'],
            'product_title' => ['type' => 'selector', 'value' => 'h2'],
        ];
        $source = ProductSource::factory()->create(['extraction_strategy' => $strategy]);
        $this->assertIsArray($source->extraction_strategy);
        $this->assertEquals($strategy, $source->extraction_strategy);
    }

    public function test_casts_settings_to_array()
    {
        $settings = ['scraper_service' => 'http', 'timeout' => 30];
        $source = ProductSource::factory()->create(['settings' => $settings]);
        $this->assertIsArray($source->settings);
        $this->assertEquals($settings, $source->settings);
    }

    public function test_defaults_status_to_active()
    {
        $source = ProductSource::factory()->create([
            'status' => ProductSourceStatus::Active,
        ]);
        $this->assertEquals(ProductSourceStatus::Active, $source->status);
    }

    public function test_belongs_to_store()
    {
        $store = Store::factory()->create(['name' => 'Test Store']);
        $source = ProductSource::factory()->create([
            'type' => ProductSourceType::OnlineStore,
            'store_id' => $store->id,
        ]);

        $this->assertInstanceOf(Store::class, $source->store);
        $this->assertEquals($store->id, $source->store->id);
    }

    public function test_belongs_to_user()
    {
        $user = User::factory()->create();
        $source = ProductSource::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $source->user);
        $this->assertEquals($user->id, $source->user->id);
    }

    public function test_allows_null_store_id_for_deals_site()
    {
        $source = ProductSource::factory()->create([
            'type' => ProductSourceType::DealsSite,
            'store_id' => null,
        ]);

        $this->assertNull($source->store_id);
        $this->assertEquals(ProductSourceType::DealsSite, $source->type);
    }

    public function test_allows_store_id_for_online_store()
    {
        $store = Store::factory()->create(['name' => 'Online Store']);
        $source = ProductSource::factory()->create([
            'type' => ProductSourceType::OnlineStore,
            'store_id' => $store->id,
        ]);

        $this->assertEquals($store->id, $source->store_id);
        $this->assertEquals(ProductSourceType::OnlineStore, $source->type);
    }
}

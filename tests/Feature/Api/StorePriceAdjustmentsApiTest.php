<?php

namespace Tests\Feature\Api;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorePriceAdjustmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $token = User::factory()->create()->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    /**
     * @param  array<string, mixed>  $adjustments
     * @param  array<string, string>|null  $shipping
     * @return array<string, mixed>
     */
    private function payload(array $adjustments, ?array $shipping = null): array
    {
        $strategy = [
            'title' => ['type' => 'selector', 'value' => 'h1'],
            'price' => ['type' => 'selector', 'value' => '.price'],
            'image' => ['type' => 'selector', 'value' => 'img|src'],
        ];

        if ($shipping !== null) {
            $strategy['shipping'] = $shipping;
        }

        return [
            'name' => 'Amazon.de',
            'domains' => [['domain' => 'amazon.de']],
            'scrape_strategy' => $strategy,
            'settings' => [
                'scraper_service' => ScraperService::Http->value,
                'price_adjustments' => $adjustments,
            ],
        ];
    }

    public function test_create_saves_price_adjustments_and_a_shipping_strategy(): void
    {
        $this->postJson('/api/stores', $this->payload(
            ['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65, 'free_shipping_from' => 39, 'tax_on_shipping' => false],
            ['type' => 'selector', 'value' => '#mir-layout-DELIVERY_BLOCK [data-csa-c-delivery-price]|data-csa-c-delivery-price'],
        ))->assertCreated();

        $store = Store::query()->firstOrFail();
        $this->assertSame(23.0, $store->price_adjustments->taxPercent);
        $this->assertSame(7.65, $store->price_adjustments->shippingCost);
        $this->assertSame(39.0, $store->price_adjustments->freeShippingFrom);
        $this->assertTrue($store->hasShippingStrategy());
    }

    public function test_create_rejects_invalid_price_adjustments(): void
    {
        $this->postJson('/api/stores', $this->payload(['tax_percent' => 150, 'tax_included_percent' => -1, 'shipping_cost' => 'free', 'free_shipping_from' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'settings.price_adjustments.tax_percent',
                'settings.price_adjustments.tax_included_percent',
                'settings.price_adjustments.shipping_cost',
                'settings.price_adjustments.free_shipping_from',
            ]);
    }

    public function test_a_shipping_strategy_cannot_use_schema_org(): void
    {
        $this->postJson('/api/stores', $this->payload([], ['type' => 'schema_org']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scrape_strategy.shipping.type']);
    }

    public function test_update_applies_from_the_next_check(): void
    {
        $store = Store::factory()->create();

        $this->putJson("/api/stores/{$store->id}", [
            'settings' => array_merge($store->settings, ['price_adjustments' => ['tax_percent' => 23]]),
        ])->assertSuccessful();

        $this->assertSame(23.0, $store->fresh()->price_adjustments->taxPercent);
        Queue::assertNothingPushed();
    }
}

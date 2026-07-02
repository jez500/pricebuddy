<?php

namespace Tests\Feature\View;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function productWithVerdict(string $verdictKey, string $verdict, bool $lowConfidence): Product
    {
        $product = Product::factory()->create([
            'price_cache' => [['price' => 100.0, 'date' => now()->toDateString(), 'history' => []]],
            // Clear notify thresholds so the verdict badge isn't suppressed by
            // an incidental notify-match.
            'notify_price' => null,
            'notify_percent' => null,
        ]);

        $product->forceFill([
            'insights_cache' => [
                'dealScore' => [
                    'verdict' => $verdict,
                    'verdictKey' => $verdictKey,
                    'lowConfidence' => $lowConfidence,
                ],
            ],
        ])->saveQuietly();

        return $product->fresh();
    }

    private function renderBadges(Product $product)
    {
        return $this->blade(
            '<x-product-badges :product="$product" />',
            ['product' => $product],
        );
    }

    public function test_confident_verdict_uses_verdict_colour_and_verdict_tooltip(): void
    {
        $product = $this->productWithVerdict('great', 'Great time to buy', false);

        $this->renderBadges($product)
            ->assertSee('Great time to buy')
            ->assertSee('data-verdict-color="success"', false);
    }

    public function test_low_confidence_verdict_is_greyed_with_confidence_tooltip(): void
    {
        $product = $this->productWithVerdict('great', 'Great time to buy', true);

        $this->renderBadges($product)
            ->assertSee('Great time to buy')
            ->assertSee('Not enough price history for a confident verdict')
            ->assertSee('data-verdict-color="gray"', false);
    }

    public function test_separate_low_confidence_badge_is_gone(): void
    {
        $product = $this->productWithVerdict('great', 'Great time to buy', true);

        $this->renderBadges($product)
            ->assertDontSee('Low confidence');
    }

    public function test_verdict_badge_hidden_when_notify_match(): void
    {
        $product = $this->productWithVerdict('great', 'Great time to buy', false);
        // notify_price at/above current price makes is_notified_price true.
        $product->forceFill(['notify_price' => 9999])->saveQuietly();

        $this->assertTrue($product->fresh()->is_notified_price);

        $this->renderBadges($product->fresh())
            ->assertDontSee('Great time to buy')
            ->assertSee('Notify match');
    }
}

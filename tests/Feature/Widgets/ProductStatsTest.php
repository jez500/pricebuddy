<?php

namespace Tests\Feature\Widgets;

use App\Filament\Widgets\ProductStats;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_products_are_grouped_and_sorted_by_tag_weight(): void
    {
        // Create tags with different weights
        $highWeightTag = Tag::factory()->create([
            'name' => 'High Priority',
            'weight' => 100,
            'user_id' => $this->user->id,
        ]);

        $mediumWeightTag = Tag::factory()->create([
            'name' => 'Medium Priority',
            'weight' => 50,
            'user_id' => $this->user->id,
        ]);

        $lowWeightTag = Tag::factory()->create([
            'name' => 'Low Priority',
            'weight' => 10,
            'user_id' => $this->user->id,
        ]);

        // Create products and attach them to tags
        $highPriorityProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $highPriorityProduct->tags()->attach($highWeightTag);

        $mediumPriorityProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 200.00, 'date' => now()->toDateString()]],
        ]);
        $mediumPriorityProduct->tags()->attach($mediumWeightTag);

        $lowPriorityProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 50.00, 'date' => now()->toDateString()]],
        ]);
        $lowPriorityProduct->tags()->attach($lowWeightTag);

        // Get grouped products from the widget
        $groups = ProductStats::getProductsGrouped();

        // Assert that we have 3 groups
        $this->assertCount(3, $groups);

        // Assert that groups are sorted by weight (lowest first)
        $groupKeys = array_keys($groups);
        $this->assertEquals('Low Priority', $groups[$groupKeys[0]]['heading']);
        $this->assertEquals('Medium Priority', $groups[$groupKeys[1]]['heading']);
        $this->assertEquals('High Priority', $groups[$groupKeys[2]]['heading']);

        // Assert weight values are correctly assigned
        $this->assertEquals(10, $groups[$groupKeys[0]]['weight']);
        $this->assertEquals(50, $groups[$groupKeys[1]]['weight']);
        $this->assertEquals(100, $groups[$groupKeys[2]]['weight']);
    }

    public function test_uncategorized_products_have_zero_weight(): void
    {
        // Create a tag with weight
        $weightedTag = Tag::factory()->create([
            'name' => 'Weighted Tag',
            'weight' => 75,
            'user_id' => $this->user->id,
        ]);

        // Create a product with the weighted tag
        $taggedProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $taggedProduct->tags()->attach($weightedTag);

        // Create an uncategorized product (no tags)
        $uncategorizedProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 50.00, 'date' => now()->toDateString()]],
        ]);

        $groups = ProductStats::getProductsGrouped();

        $this->assertCount(2, $groups);

        // Find the uncategorized group
        $uncategorizedGroup = null;
        $weightedGroup = null;

        foreach ($groups as $group) {
            if ($group['heading'] === 'Uncategorized') {
                $uncategorizedGroup = $group;
            } else {
                $weightedGroup = $group;
            }
        }

        $this->assertNotNull($uncategorizedGroup);
        $this->assertNotNull($weightedGroup);
        $this->assertEquals(0, $uncategorizedGroup['weight']);
        $this->assertEquals(75, $weightedGroup['weight']);

        // Uncategorized should appear first (weight 0 is lowest)
        $groupKeys = array_keys($groups);
        $this->assertEquals('Uncategorized', $groups[$groupKeys[0]]['heading']);
        $this->assertEquals('Weighted Tag', $groups[$groupKeys[1]]['heading']);
    }

    public function test_products_with_multiple_tags_use_max_weight(): void
    {
        // Create tags with different weights
        $highWeightTag = Tag::factory()->create([
            'name' => 'High Weight',
            'weight' => 100,
            'user_id' => $this->user->id,
        ]);

        $lowWeightTag = Tag::factory()->create([
            'name' => 'Low Weight',
            'weight' => 10,
            'user_id' => $this->user->id,
        ]);

        // Create a product with both tags
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $product->tags()->attach([$highWeightTag->id, $lowWeightTag->id]);

        $groups = ProductStats::getProductsGrouped();

        $this->assertCount(1, $groups);

        $group = array_values($groups)[0];

        // The group heading should include both tag names
        $this->assertStringContainsString('High Weight', $group['heading']);
        $this->assertStringContainsString('Low Weight', $group['heading']);

        // The weight should be the maximum of the attached tags
        $this->assertEquals(100, $group['weight']);
    }

    public function test_only_favourite_and_published_products_are_included(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Test Tag',
            'weight' => 50,
            'user_id' => $this->user->id,
        ]);

        // Create a favourite, published product (should be included)
        $includedProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $includedProduct->tags()->attach($tag);

        // Create a non-favourite product (should be excluded)
        $excludedProduct1 = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => false,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $excludedProduct1->tags()->attach($tag);

        // Create an archived product (should be excluded)
        $excludedProduct2 = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'a',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $excludedProduct2->tags()->attach($tag);

        $groups = ProductStats::getProductsGrouped();

        $this->assertCount(1, $groups);
        $group = array_values($groups)[0];
        $this->assertCount(1, $group['products']);
        $this->assertEquals($includedProduct->id, $group['products']->first()->id);
    }

    public function test_products_without_price_cache_are_excluded(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Test Tag',
            'weight' => 50,
            'user_id' => $this->user->id,
        ]);

        // Create a product without price cache (should be excluded)
        $productWithoutPrice = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => null,
        ]);
        $productWithoutPrice->tags()->attach($tag);

        // Create a product with empty price cache (should be excluded)
        $productWithEmptyCache = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [],
        ]);
        $productWithEmptyCache->tags()->attach($tag);

        // Create a product with valid price cache (should be included)
        $productWithPrice = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
        ]);
        $productWithPrice->tags()->attach($tag);

        $groups = ProductStats::getProductsGrouped();

        $this->assertCount(1, $groups);
        $group = array_values($groups)[0];
        $this->assertCount(1, $group['products']);
        $this->assertEquals($productWithPrice->id, $group['products']->first()->id);
    }

    public function test_tags_with_same_weight_are_ordered_consistently(): void
    {
        // Create tags with the same weight
        $tagA = Tag::factory()->create([
            'name' => 'Tag A',
            'weight' => 50,
            'user_id' => $this->user->id,
        ]);

        $tagB = Tag::factory()->create([
            'name' => 'Tag B',
            'weight' => 50,
            'user_id' => $this->user->id,
        ]);

        // Create products for each tag
        $productA = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 100.00, 'date' => now()->toDateString()]],
            'created_at' => now()->subHour(), // Earlier created
        ]);
        $productA->tags()->attach($tagA);

        $productB = Product::factory()->create([
            'user_id' => $this->user->id,
            'favourite' => true,
            'status' => 'p',
            'price_cache' => [['price' => 200.00, 'date' => now()->toDateString()]],
            'created_at' => now(), // Later created
        ]);
        $productB->tags()->attach($tagB);

        $groups = ProductStats::getProductsGrouped();

        $this->assertCount(2, $groups);

        // Both should have the same weight
        $groupValues = array_values($groups);
        $this->assertEquals(50, $groupValues[0]['weight']);
        $this->assertEquals(50, $groupValues[1]['weight']);
    }
}

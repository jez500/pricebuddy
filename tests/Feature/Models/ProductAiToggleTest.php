<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAiToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_extraction_disabled_defaults_to_false_and_casts_to_bool(): void
    {
        $product = Product::factory()->create();

        $this->assertIsBool($product->ai_extraction_disabled);
        $this->assertFalse($product->ai_extraction_disabled);
    }

    public function test_ai_extraction_disabled_persists_when_set(): void
    {
        $product = Product::factory()->create(['ai_extraction_disabled' => true]);

        $this->assertTrue($product->fresh()->ai_extraction_disabled);
    }
}

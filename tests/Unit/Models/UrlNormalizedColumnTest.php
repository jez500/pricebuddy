<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UrlNormalizedColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_populates_url_normalized(): void
    {
        $url = Url::factory()->create(['url' => 'https://WWW.Target.com.au/p/Xbox/?ref=nav']);

        $this->assertSame('target.com.au/p/xbox', $url->fresh()->url_normalized);
    }

    public function test_saving_a_malformed_url_stores_null_not_empty_string(): void
    {
        $url = Url::factory()->create(['url' => 'not a url']);

        $this->assertNull($url->fresh()->url_normalized);
    }

    public function test_changing_the_url_updates_the_normalized_value(): void
    {
        $url = Url::factory()->create(['url' => 'https://shop.com/a']);

        $url->forceFill(['url' => 'https://shop.com/b'])->save();

        $this->assertSame('shop.com/b', $url->fresh()->url_normalized);
    }

    public function test_the_raw_url_is_never_mutated(): void
    {
        $raw = 'https://WWW.Target.com.au/p/Xbox/?ref=nav';
        $url = Url::factory()->create(['url' => $raw]);

        $this->assertSame($raw, $url->fresh()->url);
    }

    public function test_renormalize_all_backfills_pre_existing_rows(): void
    {
        $product = Product::factory()->create();
        $store = Store::factory()->create();

        // Insert through the query builder so the saving hook never fires, which is
        // exactly the state the backfill migration finds.
        DB::table('urls')->insert([
            ['url' => 'https://www.shop.com/a/', 'url_normalized' => null, 'product_id' => $product->id, 'store_id' => $store->id, 'price_factor' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['url' => 'not a url', 'url_normalized' => null, 'product_id' => $product->id, 'store_id' => $store->id, 'price_factor' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $changed = Url::renormalizeAll();

        $this->assertSame(1, $changed);
        $this->assertSame('shop.com/a', DB::table('urls')->where('url', 'https://www.shop.com/a/')->value('url_normalized'));
        $this->assertNull(DB::table('urls')->where('url', 'not a url')->value('url_normalized'));
    }

    public function test_renormalize_all_is_a_no_op_on_a_second_pass(): void
    {
        Url::factory()->create(['url' => 'https://shop.com/a']);

        Url::renormalizeAll();

        $this->assertSame(0, Url::renormalizeAll());
    }

    public function test_renormalize_all_does_not_touch_updated_at(): void
    {
        $url = Url::factory()->create(['url' => 'https://shop.com/a']);
        DB::table('urls')->where('id', $url->id)->update([
            'url_normalized' => null,
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        Url::renormalizeAll();

        $this->assertSame('2020-01-01 00:00:00', DB::table('urls')->where('id', $url->id)->value('updated_at'));
    }
}

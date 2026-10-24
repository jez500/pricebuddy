<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Notifications\Messages\DiscordProductMessage;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscordProductMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_alert_includes_image_history_targets_and_rand_prices(): void
    {
        $url = $this->createListing([15000, 12000]);
        $message = DiscordProductMessage::forPriceAlert($url);
        $embed = $message->toEmbedDetails();
        $fields = array_column($embed['fields'], 'value', 'name');

        $this->assertSame($url->product->title, $message->content);
        $this->assertSame(['url' => $url->product->image], $embed['thumbnail']);
        $this->assertSame('Example Store', $fields['Store']);
        $this->assertSame(CurrencyHelper::toString(12000, locale: 'en_ZA', iso: 'ZAR'), $fields['Current price']);
        $this->assertSame(CurrencyHelper::toString(15000, locale: 'en_ZA', iso: 'ZAR'), $fields['Previous price']);
        $this->assertSame(CurrencyHelper::toString(12500, locale: 'en_ZA', iso: 'ZAR'), $fields['Your target price']);
        $this->assertStringContainsString('R', $fields['Current price']);
        $this->assertStringNotContainsString('$', implode(' ', $fields));
        $this->assertStringContainsString('Down R', $fields['Price change']);
        $this->assertStringContainsString('(20%)', $fields['Price change']);
        $this->assertArrayHasKey('Previous price', $fields);
        $this->assertArrayHasKey('Your target price', $fields);
        $this->assertSame('15% below the first recorded price', $fields['Your drop target']);
        $this->assertSame($url->buy_url, $message->url);
        $this->assertSame('[View price history](<'.url($url->product_url).'>)', $fields['Price history']);
    }

    public function test_long_store_url_does_not_displace_price_history_link(): void
    {
        $url = $this->createListing([12000]);
        $url->update(['url' => 'https://example.com/dishwasher?ref='.str_repeat('a', 2000)]);
        $message = DiscordProductMessage::forPriceAlert($url);
        $fields = array_column($message->toEmbedDetails()['fields'], 'value', 'name');

        $this->assertSame($url->buy_url, $message->url);
        $this->assertSame('[View price history](<'.url($url->product_url).'>)', $fields['Price history']);
        $this->assertStringNotContainsString($url->buy_url, implode(' ', $fields));
    }

    public function test_first_price_does_not_claim_a_previous_price_or_change(): void
    {
        $url = $this->createListing([12000]);
        $url->product->update(['notify_price' => 0, 'notify_percent' => 0]);
        $fields = array_column(DiscordProductMessage::forPriceAlert($url)->toEmbedDetails()['fields'], 'value', 'name');

        $this->assertArrayHasKey('Current price', $fields);
        foreach (['Previous price', 'Price change', 'Your target price', 'Your drop target'] as $field) {
            $this->assertArrayNotHasKey($field, $fields);
        }
    }

    public function test_zero_previous_price_does_not_produce_a_percentage(): void
    {
        $message = DiscordProductMessage::forPriceAlert($this->createListing([0, 12000]));
        $fields = array_column($message->toEmbedDetails()['fields'], 'value', 'name');

        $this->assertStringStartsWith('Up R', $fields['Price change']);
        $this->assertStringNotContainsString('%', $fields['Price change']);
    }

    public function test_unchanged_price_is_not_reported_as_a_drop(): void
    {
        $message = DiscordProductMessage::forPriceAlert($this->createListing([12000, 12000]));
        $fields = array_column($message->toEmbedDetails()['fields'], 'value', 'name');

        $this->assertSame('Unchanged', $fields['Price change']);
    }

    public function test_stock_alert_labels_historical_price_and_its_timestamp(): void
    {
        $url = $this->createListing([12000]);
        $message = DiscordProductMessage::forStockAlert($url);
        $fields = array_column($message->toEmbedDetails()['fields'], 'value', 'name');

        $this->assertSame('Back in stock', $message->title);
        $this->assertStringContainsString('R', $fields['Last recorded price']);
        $this->assertSame('<t:'.$url->prices()->first()->created_at->getTimestamp().':f>', $fields['Price recorded']);
        $this->assertArrayNotHasKey('Current price', $fields);
    }

    public function test_no_history_or_public_image_does_not_invent_a_price_or_thumbnail(): void
    {
        $url = $this->createListing([]);

        foreach ([null, '', '/images/product.jpg', 'data:image/png;base64,test', 'ftp://example.com/product.jpg'] as $image) {
            $url->product->image = $image;
            $embed = DiscordProductMessage::forStockAlert($url)->toEmbedDetails();
            $fields = array_column($embed['fields'], 'value', 'name');

            $this->assertArrayNotHasKey('thumbnail', $embed);
            $this->assertArrayNotHasKey('Last recorded price', $fields);
            $this->assertArrayNotHasKey('Price recorded', $fields);
        }
    }

    public function test_large_product_details_fit_discord_embed_limits(): void
    {
        $message = new DiscordProductMessage(str_repeat('P', 4096));
        $message->title(str_repeat('T', 256));

        for ($i = 0; $i < 30; $i++) {
            $message->field(str_repeat('N', 300), str_repeat('V', 1200));
        }

        $embed = $message->toEmbedDetails();
        $total = mb_strlen($message->title.$message->content.$embed['footer']['text']);

        $this->assertLessThanOrEqual(25, count($embed['fields']));
        foreach ($embed['fields'] as $field) {
            $this->assertLessThanOrEqual(256, mb_strlen($field['name']));
            $this->assertLessThanOrEqual(1024, mb_strlen($field['value']));
            $total += mb_strlen($field['name'].$field['value']);
        }
        $this->assertLessThanOrEqual(6000, $total);
    }

    protected function createListing(array $prices): Url
    {
        $store = Store::factory()->create([
            'name' => 'Example Store',
            'settings' => ['locale_settings' => ['locale' => 'en_ZA', 'currency' => 'ZAR']],
        ]);
        $product = Product::factory()->create([
            'title' => 'Bosch Series 8 freestanding dishwasher',
            'image' => 'https://example.com/dishwasher.jpg',
            'notify_price' => 12500,
            'notify_percent' => 15,
        ]);

        return Url::factory()->for($product)->for($store)->withPrices($prices)->create([
            'url' => 'https://example.com/dishwasher',
        ]);
    }
}

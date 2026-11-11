<?php

namespace Tests\Feature\Notifications;

use App\Filament\Resources\NotificationHistoryResource;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An alert for a price the store adjusted says what the price is made of, on a line of
 * its own: the alert channels get a newline, the notifications panel and the history
 * page a <br>, the email a line under the price.
 */
class PriceAlertBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const string BREAKDOWN = '(page €324.80 + €10.92 tax + €7.65 shipping)';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $adjustments
     * @return array{Url, Price}
     */
    protected function makePrice(array $adjustments): array
    {
        $store = Store::factory()->create([
            'name' => 'Amazon.de',
            'settings' => [
                'locale_settings' => ['locale' => 'en_US', 'currency' => 'EUR'],
                'price_adjustments' => $adjustments,
            ],
        ]);
        $product = Product::factory()->create(['title' => 'Ryzen & Co 5800X3D', 'user_id' => $this->user->id]);
        $url = Url::factory()->for($store)->for($product)->create(['url' => 'https://example.com/product']);

        return [$url, $url->updatePrice(324.80)];
    }

    public function test_an_adjusted_price_alert_has_the_breakdown_on_its_own_line(): void
    {
        [$url, $price] = $this->makePrice(['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65]);
        $notification = new PriceAlertNotification($url, null, $price);

        $this->assertSame('Price drop: Ryzen & Co 5800X3D (€343.37)', $notification->toDiscord($this->user)->title);
        $this->assertSame(
            "Amazon.de has had a price drop for Ryzen & Co 5800X3D - €343.37\n".self::BREAKDOWN,
            $notification->toDiscord($this->user)->content,
        );
        $this->assertSame(
            'Amazon.de has had a price drop for Ryzen &amp; Co 5800X3D - €343.37<br>'."\n".self::BREAKDOWN,
            $notification->toDatabase($this->user)['body'],
        );
        $this->assertStringContainsString(self::BREAKDOWN, (string) $notification->toMail($this->user)->render());
    }

    public function test_a_threshold_alert_uses_the_latest_price(): void
    {
        [$url] = $this->makePrice(['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65]);

        $this->assertStringEndsWith("\n".self::BREAKDOWN, (new PriceAlertNotification($url))->toNtfy($this->user)->content);
    }

    public function test_an_unadjusted_price_alert_is_unchanged(): void
    {
        [$url, $price] = $this->makePrice([]);
        $notification = new PriceAlertNotification($url, null, $price);
        $summary = 'Amazon.de has had a price drop for Ryzen & Co 5800X3D - €324.80';

        $this->assertSame($summary, $notification->toGotify($this->user)->content);
        $this->assertSame($summary, $notification->toDatabase($this->user)['body']);
        $this->assertNull($notification->toArray($this->user)['priceBreakdown']);
        $this->assertStringNotContainsString('(page', (string) $notification->toMail($this->user)->render());
    }

    public function test_the_history_page_shows_the_breakdown_like_the_panel(): void
    {
        [$url, $price] = $this->makePrice(['tax_included_percent' => 19, 'tax_percent' => 23, 'shipping_cost' => 7.65]);
        $this->user->notify(new PriceAlertNotification($url, null, $price));
        // An alert saved before price adjustments existed: plain text.
        $this->user->notifications()->create([
            'id' => 'b6f3f2a0-0000-4000-8000-000000000001',
            'type' => PriceAlertNotification::class,
            'data' => ['title' => 'Price drop: Old', 'body' => 'Old Store has had a price drop for Old & Gold - $5.00'],
        ]);

        // Filament's HTML sanitizer writes <br /> and encodes "+".
        $this->actingAs($this->user)
            ->get(NotificationHistoryResource::getUrl('index'))
            ->assertOk()
            ->assertSee("€343.37<br />\n(page €324.80 &#43; €10.92 tax &#43; €7.65 shipping)", escape: false)
            ->assertSee('Old Store has had a price drop for Old &amp; Gold - $5.00', escape: false);
    }
}

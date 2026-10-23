<?php

namespace Tests\Feature\Api;

use App\Actions\AddUrlsToProductAction;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Jobs\AddUrlToProductJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\AddUrlFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class ProductUrlsApiTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        Store::query()->delete();
        Store::factory()->create(['domains' => [['domain' => 'example.com']]]);

        $this->product = Product::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_queues_valid_urls_and_reports_skipped_urls(): void
    {
        Queue::fake();

        $existing = Url::factory()->create([
            'product_id' => $this->product->id,
            'url' => 'https://example.com/existing',
        ]);

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => [
                'https://example.com/a',
                'https://example.com/b',
                'https://www.example.com/a',
                $existing->url,
                'not a url',
                'ftp://example.com/c',
            ],
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', ['https://example.com/a', 'https://example.com/b'])
            ->assertJsonPath('data.skipped', [
                ['url' => 'https://www.example.com/a', 'reason' => AddUrlsToProductAction::SKIP_DUPLICATE],
                ['url' => $existing->url, 'reason' => AddUrlsToProductAction::SKIP_EXISTING],
                ['url' => 'not a url', 'reason' => AddUrlsToProductAction::SKIP_INVALID],
                ['url' => 'ftp://example.com/c', 'reason' => AddUrlsToProductAction::SKIP_INVALID],
            ]);

        Queue::assertPushed(AddUrlToProductJob::class, 2);
        Queue::assertPushed(
            AddUrlToProductJob::class,
            fn (AddUrlToProductJob $job) => $job->productId === $this->product->id
                && $job->userId === $this->user->id
                && $job->url === 'https://example.com/b'
        );
    }

    public function test_job_adds_the_urls_to_the_product(): void
    {
        $this->mockScrape(25, 'Example product');

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/a', 'https://example.com/b'],
            'price_factor' => 2,
        ])->assertStatus(202);

        $urls = $this->product->urls()->get();

        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            $urls->pluck('url')->sort()->values()->all()
        );
        $this->assertEquals([2, 2], $urls->pluck('price_factor')->all());
        $this->assertEquals(25, $urls->first()->prices()->first()->price);
    }

    public function test_job_notifies_the_user_when_a_url_cannot_be_added(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response('<html><body>No price here</body></html>')]);

        $this->postJson("/api/products/{$this->product->id}/urls", [
            'urls' => ['https://example.com/no-price'],
        ])->assertStatus(202);

        $this->assertSame(0, $this->product->urls()->count());
        Notification::assertSentTo(
            $this->user,
            AddUrlFailedNotification::class,
            fn (AddUrlFailedNotification $notification) => $notification->url === 'https://example.com/no-price'
        );
    }

    /**
     * A timeout or a PHP error skips the catch in handle(). Laravel then calls failed().
     */
    public function test_failed_job_notifies_the_user(): void
    {
        Notification::fake();

        (new AddUrlToProductJob($this->product->id, 'https://example.com/slow', $this->user->id))
            ->failed(new \RuntimeException('Job timed out'));

        Notification::assertSentTo(
            $this->user,
            AddUrlFailedNotification::class,
            fn (AddUrlFailedNotification $notification) => $notification->url === 'https://example.com/slow'
        );
    }

    public function test_failed_job_sends_nothing_when_the_product_was_deleted(): void
    {
        Notification::fake();
        $productId = $this->product->id;
        $this->product->delete();

        (new AddUrlToProductJob($productId, 'https://example.com/slow', $this->user->id))
            ->failed(new \RuntimeException('Job timed out'));

        Notification::assertNothingSent();
    }

    public function test_rejects_more_than_the_maximum_number_of_urls(): void
    {
        Queue::fake();

        $urls = collect(range(1, AddUrlsToProductAction::MAX_URLS + 1))
            ->map(fn (int $i) => "https://example.com/{$i}")
            ->all();

        $this->postJson("/api/products/{$this->product->id}/urls", ['urls' => $urls])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urls']);

        Queue::assertNothingPushed();
    }

    public function test_cannot_add_urls_to_another_users_product(): void
    {
        Queue::fake();
        $other = Product::factory()->create();

        $this->postJson("/api/products/{$other->id}/urls", ['urls' => ['https://example.com/a']])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_requires_the_product_update_ability(): void
    {
        Queue::fake();

        Auth::forgetGuards();
        $detailOnly = $this->user->createToken('detail-only', ['product:detail'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$detailOnly]);

        $this->postJson("/api/products/{$this->product->id}/urls", ['urls' => ['https://example.com/a']])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_view_page_action_adds_a_single_url_straight_away(): void
    {
        Queue::fake();
        $this->mockScrape(25, 'Example product');
        $this->actingAs($this->user);

        Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction('add_url', data: [
                'url' => "  https://example.com/a\n",
                'price_factor' => 1,
            ])
            ->assertHasNoActionErrors();

        Queue::assertNothingPushed();
        $this->assertSame(['https://example.com/a'], $this->product->urls()->pluck('url')->all());
    }

    public function test_view_page_action_queues_one_url_per_line(): void
    {
        Queue::fake();
        $this->actingAs($this->user);

        Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction('add_url', data: [
                'url' => "https://example.com/a\n\n  https://example.com/b  \r\nhttps://example.com/a",
                'price_factor' => 1,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushed(AddUrlToProductJob::class, 2);
    }

    public function test_view_page_action_rejects_too_many_urls(): void
    {
        Queue::fake();
        $this->actingAs($this->user);

        $urls = collect(range(1, AddUrlsToProductAction::MAX_URLS + 1))
            ->map(fn (int $i) => "https://example.com/{$i}")
            ->implode("\n");

        Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()])
            ->callAction('add_url', data: ['url' => $urls, 'price_factor' => 1])
            ->assertHasActionErrors(['url']);

        Queue::assertNothingPushed();
    }
}

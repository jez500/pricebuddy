<?php

namespace Tests\Feature\Services;

use App\Enums\Statuses;
use App\Enums\StockStatus;
use App\Events\AvailabilityChangedEvent;
use App\Events\PriceCreatedEvent;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\PriceHistoryCleanup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PriceHistoryCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected PriceHistoryCleanup $cleanup;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = User::factory()->create();
        $this->cleanup = app(PriceHistoryCleanup::class);
    }

    protected function product(array $prices = [60, 70, 80]): Product
    {
        return Product::factory()->for($this->user)
            ->addUrlWithPrices('https://cleanup.example/item', $prices)
            ->create(['notify_price' => null, 'notify_percent' => null]);
    }

    public function test_removing_a_bad_price_repairs_both_materialized_caches(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->for($user)
            ->addUrlWithPrices('https://cleanup.example/item', [0.01, 80, 90])
            ->create(['notify_price' => null, 'notify_percent' => null]);
        $bad = $product->prices()->where('price', '<', 1)->firstOrFail();

        $this->assertEquals(0.01, $product->fresh()->getPriceCacheAggregate('min'));
        $this->assertEquals(0.01, $product->fresh()->insights_cache['stats']['lowest']);

        $cleanup = app(PriceHistoryCleanup::class);
        $snapshot = $cleanup->snapshot($user, $product, [$bad->getKey()]);
        $this->assertSame(1, $cleanup->deleteSnapshot($user, $product, $snapshot));

        $product->refresh();
        $this->assertEquals(80, $product->getPriceCacheAggregate('min'));
        $this->assertEquals(80, $product->insights_cache['stats']['lowest']);
    }

    public function test_deleting_all_prices_preserves_tracking_stock_targets_and_schedule(): void
    {
        $product = $this->product();
        $product->forceFill(['status' => Statuses::Archived, 'paused' => true, 'refresh_interval' => 3600, 'next_check_at' => now()->addHours(2), 'notify_price' => 55])->saveQuietly();
        $url = $product->urls()->firstOrFail();
        $url->update(['availability' => StockStatus::OutOfStock]);
        $before = $product->fresh()->only(['status', 'paused', 'refresh_interval', 'next_check_at', 'notify_price']);
        $snapshot = $this->cleanup->snapshot($this->user, $product, $product->prices()->pluck('prices.id')->all());
        Event::fake([PriceCreatedEvent::class, AvailabilityChangedEvent::class]);

        $this->assertSame(3, $this->cleanup->deleteSnapshot($this->user, $product, $snapshot));

        $product->refresh();
        $this->assertEquals($before, $product->only(array_keys($before)));
        $this->assertEquals(0, $product->current_price);
        $this->assertSame(0, $product->prices()->count());
        $this->assertDatabaseHas('urls', ['id' => $url->id, 'product_id' => $product->id]);
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
        $this->assertFalse($product->insights_cache['hasEnoughData']);
        Event::assertNotDispatched(PriceCreatedEvent::class);
        Event::assertNotDispatched(AvailabilityChangedEvent::class);
        Notification::assertNothingSent();
    }

    public function test_deleting_latest_uses_remaining_prices_and_keeps_other_urls(): void
    {
        $product = $this->product([60, 70, 80]);
        $url = $product->urls()->firstOrFail();
        $other = Url::factory()->for($product)->withPrices([90, 100])->create();
        $latest = $url->prices()->reorder('id', 'desc')->firstOrFail();
        $snapshot = $this->cleanup->snapshot($this->user, $product, [$latest->id]);

        $this->cleanup->deleteSnapshot($this->user, $product, $snapshot);

        $cache = $product->fresh()->getPriceCache()->first(fn ($cache) => $cache->getUrlId() === $url->id);
        $this->assertEquals(70, $cache->getPrice());
        $this->assertEquals(70, $cache->getUnitPrice());
        $this->assertSame(2, $other->prices()->count());
        $this->assertDatabaseHas('urls', ['id' => $url->id]);
    }

    public function test_confirmation_is_a_fixed_id_snapshot_and_can_be_repeated(): void
    {
        $product = $this->product();
        $old = $product->prices()->firstOrFail();
        $snapshot = $this->cleanup->snapshot($this->user, $product, [$old->id]);
        $new = $product->urls()->firstOrFail()->updatePrice(50);

        $this->assertSame(1, $this->cleanup->deleteSnapshot($this->user, $product, $snapshot));
        $this->assertSame(0, $this->cleanup->deleteSnapshot($this->user, $product, $snapshot));
        $this->assertDatabaseHas('prices', ['id' => $new->getKey(), 'price' => 50]);
        $this->assertEquals(50, $product->fresh()->current_price);
    }

    public function test_one_raw_reading_can_be_removed_without_deleting_its_day(): void
    {
        $product = $this->product([40]);
        $url = $product->urls()->firstOrFail();
        $first = $url->prices()->firstOrFail();
        $second = Price::factory()->for($url)->create(['store_id' => $url->store_id, 'price' => 45, 'unit_price' => 45, 'created_at' => $first->created_at->copy()->addHour()]);
        $snapshot = $this->cleanup->snapshot($this->user, $product, [$first->id]);

        $this->cleanup->deleteSnapshot($this->user, $product, $snapshot);

        $this->assertSame(1, $url->prices()->count());
        $this->assertDatabaseHas('prices', ['id' => $second->id]);
        $this->assertEquals(45, $product->fresh()->getPriceCacheAggregate('min'));
    }

    public function test_cache_refresh_does_not_persist_unsaved_fields_from_a_stale_model(): void
    {
        $product = $this->product();
        $title = $product->title;
        $product->load('urls.prices');
        $product->title = 'Unsaved caller title';
        $product->notify_price = 1;

        $product->updatePriceCache();
        $product->updateInsightsCache();

        $this->assertSame($title, $product->fresh()->title);
        $this->assertNull($product->fresh()->notify_price);
        $this->assertSame('Unsaved caller title', $product->title);
        $this->assertTrue($product->isDirty('title'));
        $this->assertFalse($product->relationLoaded('urls'));
    }

    public function test_a_direct_url_check_rebuilds_insights_for_readers_without_a_live_fallback(): void
    {
        $product = $this->product([80, 70, 60]);
        $product->urls()->firstOrFail()->updatePrice(40);

        $fresh = $product->fresh();
        $this->assertIsArray($fresh->insights_cache);
        $this->assertEquals(40, $fresh->insights_cache['stats']['current']);
        $this->assertEquals($fresh->buildInsightsCache(), $fresh->insights_cache);
        $this->assertNotEmpty($fresh->insights_cache['dealScore']['verdict']);
    }

    public function test_fractional_amounts_at_both_filter_boundaries_are_included(): void
    {
        $product = $this->product([0.01, 29.99, 30.01]);
        $url = $product->urls()->firstOrFail();

        $matches = $this->cleanup->query($this->user, $product, ['listing_id' => $url->id, 'min_price' => 0.01, 'max_price' => 29.99])->get();

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing([0.01, 29.99], $matches->pluck('price')->all());
    }

    public function test_a_batch_rebuilds_each_cache_once_and_rolls_back_if_rebuild_fails(): void
    {
        $product = $this->product([0.01, 0.02, 90]);
        $snapshot = $this->cleanup->snapshot($this->user, $product, $product->prices()->where('price', '<', 1)->pluck('prices.id')->all());
        $before = $product->fresh()->only(['price_cache', 'insights_cache', 'current_price']);
        $inject = true;
        DB::listen(function (QueryExecuted $query) use (&$inject): void {
            if ($inject && str_starts_with($query->sql, 'update `products`') && str_contains($query->sql, '`insights_cache`')) {
                $inject = false;
                throw new RuntimeException('Simulated cache write failure');
            }
        });
        try {
            $this->cleanup->deleteSnapshot($this->user, $product, $snapshot);
            $this->fail('Cache failure must abort deletion');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated cache write failure', $exception->getMessage());
        } finally {
            $inject = false;
        }
        $this->assertSame(3, $product->prices()->count());
        $this->assertEquals($before, $product->fresh()->only(array_keys($before)));

        $priceWrites = $insightsWrites = 0;
        DB::listen(function (QueryExecuted $query) use (&$priceWrites, &$insightsWrites): void {
            if (str_starts_with($query->sql, 'update `products`')) {
                $priceWrites += (int) str_contains($query->sql, '`price_cache`');
                $insightsWrites += (int) str_contains($query->sql, '`insights_cache`');
            }
        });
        $this->assertSame(2, $this->cleanup->deleteSnapshot($this->user, $product, $snapshot));
        $this->assertSame(1, $priceWrites);
        $this->assertSame(1, $insightsWrites);
    }

    public function test_foreign_product_ids_are_rejected_without_partial_deletion(): void
    {
        $product = $this->product();
        $other = $this->product();
        $ids = [$product->prices()->firstOrFail()->id, $other->prices()->firstOrFail()->id];

        try {
            $this->cleanup->snapshot($this->user, $product, $ids);
            $this->fail('Cross-product selection must be rejected');
        } catch (ValidationException) {
            $this->assertSame(3, $product->prices()->count());
            $this->assertSame(3, $other->prices()->count());
        }
    }

    public function test_a_forged_model_cannot_authorize_someone_elses_product(): void
    {
        $product = $this->product();
        $other = User::factory()->create();
        $product->user_id = $other->id;

        $this->expectException(AuthorizationException::class);
        $this->cleanup->snapshot($other, $product, $product->prices()->pluck('prices.id')->all());
    }

    public function test_changed_ownership_is_rechecked_when_confirming(): void
    {
        $product = $this->product();
        $snapshot = $this->cleanup->snapshot($this->user, $product, $product->prices()->pluck('prices.id')->all());
        Product::whereKey($product->id)->update(['user_id' => User::factory()->create()->id]);

        $this->expectException(AuthorizationException::class);
        $this->cleanup->deleteSnapshot($this->user, $product, $snapshot);
    }

    public function test_tampered_and_expired_confirmations_are_rejected(): void
    {
        $product = $this->product();
        $snapshot = $this->cleanup->snapshot($this->user, $product, $product->prices()->pluck('prices.id')->all());
        foreach ([$snapshot.'tampered', 'not-a-confirmation'] as $token) {
            try {
                $this->cleanup->deleteSnapshot($this->user, $product, $token);
                $this->fail('Tampered selection must be rejected');
            } catch (ValidationException) {
                $this->assertSame(3, $product->prices()->count());
            }
        }
        $this->travel(16)->minutes();
        try {
            $this->cleanup->deleteSnapshot($this->user, $product, $snapshot);
            $this->fail('Expired selection must be rejected');
        } catch (ValidationException) {
            $this->assertSame(3, $product->prices()->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_price_filters_require_a_listing_and_use_its_store_currency(): void
    {
        $product = $this->product([40, 60]);
        $usd = $product->urls()->firstOrFail();
        $usd->store->update(['settings' => ['locale_settings' => ['locale' => 'en_US', 'currency' => 'USD']]]);
        $eurStore = Store::factory()->create(['settings' => ['locale_settings' => ['locale' => 'de_DE', 'currency' => 'EUR']]]);
        $eur = Url::factory()->for($product)->for($eurStore)->withPrices([40, 60])->create();
        $matches = $this->cleanup->query($this->user, $product, ['listing_id' => $usd->id, 'min_price' => 30, 'max_price' => 50])->get();
        $this->assertCount(1, $matches);
        $this->assertSame($usd->id, $matches->first()->url_id);
        $this->assertNotSame($eur->id, $matches->first()->url_id);

        $this->expectException(ValidationException::class);
        $this->cleanup->validateFilters(['min_price' => 30]);
    }

    public function test_a_deleted_listing_filter_returns_no_records(): void
    {
        $product = $this->product();
        $listing = $product->urls()->firstOrFail();
        $remaining = Url::factory()->for($product)->withPrices([90])->create();
        $foreign = Product::factory()->for(User::factory())->addUrlWithPrices('https://foreign.example/item', [10])->create();
        $listing->delete();

        $this->assertEmpty($this->cleanup->query($this->user, $product, ['listing_id' => $listing->id, 'max_price' => 100])->get());
        $this->assertSame($remaining->prices()->pluck('id')->all(), $this->cleanup->query($this->user, $product)->pluck('id')->all());
        $this->assertSame(1, $foreign->prices()->count());
    }

    public function test_a_listing_moved_to_another_product_returns_no_records(): void
    {
        $product = $this->product();
        $listing = $product->urls()->firstOrFail();
        $remaining = Url::factory()->for($product)->withPrices([90])->create();
        $foreign = Product::factory()->for(User::factory())->addUrlWithPrices('https://foreign.example/item', [10])->create();
        $listing->update(['product_id' => $foreign->id]);

        $this->assertEmpty($this->cleanup->query($this->user, $product, ['listing_id' => $listing->id, 'max_price' => 100])->get());
        $this->assertSame($remaining->prices()->pluck('id')->all(), $this->cleanup->query($this->user, $product)->pluck('id')->all());
        $this->assertSame(4, $foreign->prices()->count());
    }

    public function test_a_valid_listing_filter_keeps_the_store_constraint(): void
    {
        $product = $this->product([40]);
        $listing = $product->urls()->firstOrFail();
        $expected = $listing->prices()->firstOrFail();
        $otherStore = Store::factory()->create();
        $wrongStore = Price::withoutEvents(fn () => Price::factory()->for($listing)->create([
            'store_id' => $otherStore->id, 'price' => 40, 'unit_price' => 40,
        ]));

        $matches = $this->cleanup->query($this->user, $product, ['listing_id' => $listing->id, 'min_price' => 40, 'max_price' => 40])->get();

        $this->assertSame([$expected->id], $matches->modelKeys());
        $this->assertDatabaseHas('prices', ['id' => $wrongStore->id]);
    }

    public function test_date_range_includes_both_days_but_not_the_next_midnight(): void
    {
        $product = $this->product([40, 50, 60]);
        $rows = $product->prices()->reorder('id')->get();
        $rows[0]->updateQuietly(['created_at' => '2026-10-01 00:00:00']);
        $rows[1]->updateQuietly(['created_at' => '2026-10-02 23:59:59']);
        $rows[2]->updateQuietly(['created_at' => '2026-10-03 00:00:00']);

        $ids = $this->cleanup->query($this->user, $product, ['from' => '2026-10-01', 'until' => '2026-10-02'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$rows[0]->id, $rows[1]->id], $ids);
    }

    public function test_date_filters_follow_the_configured_timezone_across_a_dst_day(): void
    {
        $previous = date_default_timezone_get();
        config()->set('app.timezone', 'Australia/Melbourne');
        date_default_timezone_set('Australia/Melbourne');
        try {
            $product = $this->product([40, 50]);
            $rows = $product->prices()->reorder('id')->get();
            $rows[0]->updateQuietly(['created_at' => '2026-10-04 00:00:00']);
            $rows[1]->updateQuietly(['created_at' => '2026-10-05 00:00:00']);

            $ids = $this->cleanup->query($this->user, $product, ['from' => '2026-10-04', 'until' => '2026-10-04'])->pluck('id')->all();
            $this->assertSame([$rows[0]->id], $ids);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_invalid_ranges_and_oversized_selections_are_rejected(): void
    {
        foreach ([['from' => '2026-10-03', 'until' => '2026-10-01'], ['listing_id' => 1, 'min_price' => 50, 'max_price' => 40], ['from' => 'not-a-date'], ['listing_id' => 1, 'min_price' => -1]] as $filters) {
            try {
                $this->cleanup->validateFilters($filters);
                $this->fail('Invalid filter must be rejected');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $product = $this->product();
        $this->expectException(ValidationException::class);
        $this->cleanup->snapshot($this->user, $product, range(1, PriceHistoryCleanup::MAX_BATCH_SIZE + 1));
    }
}

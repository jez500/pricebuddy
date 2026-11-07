<?php

namespace Tests\Feature\Services;

use App\Enums\StockStatus;
use App\Events\AvailabilityChangedEvent;
use App\Events\PriceCreatedEvent;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Notifications\StockAlertNotification;
use App\Services\PriceHistoryCleanup;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/** Real commits and a second process: RefreshDatabase's outer transaction would hide the race. */
class PriceHistoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('This test requires two real MySQL/InnoDB connections.');
        }

        $this->assertDisposableTestDatabase();
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            // Historical settings migrations are not all reversible. Fresh migrations
            // and wiping the disposable test schema still isolate real commits without
            // executing unrelated legacy down() methods or wrapping an outer transaction.
            $this->assertDisposableTestDatabase();
            $this->artisan('db:wipe', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(0, DB::transactionLevel());
        $probeConfig = DB::connection()->getConfig();
        unset($probeConfig['name']);
        config()->set('database.connections.history_probe', $probeConfig);
        DB::connection('history_probe')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::disconnect('history_probe');
        parent::tearDown();
    }

    public function test_scrape_waits_for_cleanup_and_cannot_overwrite_its_caches_with_deleted_history(): void
    {
        [$user, $product, $url] = $this->productWithHistory();
        $bad = $url->prices()->where('price', '<', 1)->firstOrFail();
        $cleanup = app(PriceHistoryCleanup::class);
        $snapshot = $cleanup->snapshot($user, $product, [$bad->getKey()]);
        $directory = sys_get_temp_dir().'/price-history-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/HistoryConcurrencyWorker.php'), $directory], base_path());
        $process->setTimeout(10);
        // Configuration stays on the child's stdin, never in argv or diagnostic output.
        $process->setInput(json_encode([
            'connection' => DB::connection()->getConfig(),
            'product_id' => $product->getKey(),
            'url_id' => $url->getKey(),
            'price' => 40,
        ], JSON_THROW_ON_ERROR));

        try {
            $process->start();
            $this->awaitMarker($directory.'/ready', $process);
            $identity = json_decode(file_get_contents($directory.'/ready'), true, flags: JSON_THROW_ON_ERROR);
            $connectionId = $identity['connection_id'];
            $this->assertSame(DB::selectOne('SELECT DATABASE() AS current_database')->current_database, $identity['database'], 'The child must use the exact parallel-test database.');
            $this->assertSame($connectionId, $identity['model_connection_id'], 'The observed PDO must also be the Eloquent writer connection.');

            $product->withPriceHistoryLock(function (Product $locked) use ($cleanup, $user, $snapshot, $directory, $process, $connectionId, $bad): void {
                // A prior suite query can leave InnoDB's diagnostic cache populated
                // before this writer begins its transaction.
                DB::selectOne('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?', [$connectionId]);
                touch($directory.'/write');
                $this->awaitMarker($directory.'/attempting', $process);
                $deadline = microtime(true) + 2;
                do {
                    $process->checkTimeout();
                    $state = DB::connection('history_probe')->selectOne('SELECT trx_state, trx_rows_modified FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?', [$connectionId]);
                    if (($state->trx_state ?? null) === 'LOCK WAIT') {
                        break;
                    }
                    // InnoDB refreshes this diagnostic snapshot only after an idle
                    // interval exceeding 100ms. A 10ms poll can pin an old empty view
                    // indefinitely, even though the writer is genuinely waiting.
                    usleep(150000);
                } while (microtime(true) < $deadline && $process->isRunning());

                $this->assertSame('LOCK WAIT', $state->trx_state ?? null, 'The real scrape connection must wait for the product lock. '.$this->workerDiagnostic($directory));
                $this->assertSame(0, (int) ($state->trx_rows_modified ?? -1), 'The scrape must wait before inserting a price, not only during a later cache rebuild.');
                // An older autocommit insert could already have committed before its
                // created-listener blocks rebuilding the cache. Use an independent
                // current read, not the cleanup transaction's repeatable-read snapshot.
                $this->assertFalse(DB::connection('history_probe')->table('prices')
                    ->where('url_id', $bad->url_id)->where('price', 40)->exists(), 'The scrape must not commit a price before acquiring the shared product lock.');
                $this->assertFileDoesNotExist($directory.'/done');
                $this->assertSame(1, $cleanup->deleteSnapshot($user, $locked, $snapshot));
                $this->assertFalse(Price::query()->whereKey($bad->getKey())->exists());
            });

            $this->awaitMarker($directory.'/done', $process);
            $this->assertSame(0, $process->wait(), 'The history writer must complete after cleanup commits. '.$this->workerDiagnostic($directory));
            $result = json_decode(file_get_contents($directory.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok']);
            $this->assertDatabaseHas('prices', ['id' => $result['price_id'], 'url_id' => $url->getKey(), 'price' => 40]);
            $this->assertDatabaseMissing('prices', ['id' => $bad->getKey()]);

            $fresh = $product->fresh();
            $this->assertEquals(40, $fresh->current_price);
            $this->assertEquals($fresh->buildPriceCache()->toArray(), $fresh->price_cache);
            $this->assertEquals($fresh->buildInsightsCache(), $fresh->insights_cache);
            $this->assertEquals(40, $fresh->insights_cache['stats']['lowest']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_real_events_and_database_notifications_run_only_after_commit_and_never_after_rollback(): void
    {
        [$user, $product, $url] = $this->productWithHistory();
        $product->forceFill(['notify_price' => 100, 'notify_percent' => null, 'notify_in_stock' => true])->saveQuietly();
        $url->forceFill(['availability' => StockStatus::OutOfStock])->saveQuietly();
        $events = [];
        $notifications = [];
        foreach ([PriceCreatedEvent::class, AvailabilityChangedEvent::class] as $eventClass) {
            Event::listen($eventClass, function (object $event) use (&$events, $product): void {
                $events[] = [get_class($event), DB::transactionLevel(), $this->productLockIsAvailable($product)];
            });
        }
        // Use the real Laravel dispatcher and database notification channel, not Event::fake().
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$notifications, $product): void {
            $notifications[] = [get_class($event->notification), DB::transactionLevel(), $this->productLockIsAvailable($product)];
        });

        DB::beginTransaction();
        try {
            $url->updatePrice(40, ['price' => 40, 'availability' => null]);
            $this->assertSame([], $events);
            $this->assertSame([], $notifications);
            $this->assertSame(0, $user->notifications()->count());
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertEqualsCanonicalizing([PriceCreatedEvent::class, AvailabilityChangedEvent::class], array_column($events, 0));
        $this->assertEqualsCanonicalizing([PriceAlertNotification::class, StockAlertNotification::class], array_column($notifications, 0));
        foreach ([...$events, ...$notifications] as $observation) {
            $this->assertSame(0, $observation[1], 'Notifications must not run inside the writer transaction.');
            $this->assertTrue($observation[2], 'Another connection must be able to lock the product during notification delivery.');
        }

        $url->fresh()->forceFill(['availability' => StockStatus::OutOfStock])->saveQuietly();
        $events = [];
        $notifications = [];
        $priceCount = $url->prices()->count();
        DB::beginTransaction();
        try {
            $url->fresh()->updatePrice(30, ['price' => 30, 'availability' => null]);
            $this->assertSame([], $events);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame([], $events);
        $this->assertSame([], $notifications);
        $this->assertSame($priceCount, $url->prices()->count());
        $this->assertSame(2, $user->notifications()->count());
        $this->assertSame(StockStatus::OutOfStock, $url->fresh()->availability);
    }

    public function test_scraping_occurs_before_the_product_lock_is_acquired(): void
    {
        [, $product, $url] = $this->productWithHistory();
        $probe = new ScrapeBoundaryUrl;
        $probe->setRawAttributes($url->getAttributes(), true);
        $probe->exists = true;
        $called = false;
        $probe->scraper = function () use (&$called, $product): array {
            $called = true;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($this->productLockIsAvailable($product));

            return ['price' => 55, 'availability' => null];
        };

        $price = $probe->updatePrice();
        $this->assertTrue($called);
        $this->assertInstanceOf(Price::class, $price);
        $this->assertEquals(55, $price->price);
    }

    /** @return array{User, Product, Url} */
    private function productWithHistory(): array
    {
        $user = User::factory()->create(['settings' => []]);
        $product = Product::factory()->for($user)->create(['notify_price' => null, 'notify_percent' => null, 'notify_in_stock' => false]);
        $store = Store::factory()->create();
        $url = Url::factory()->for($product)->for($store)->create(['url' => 'https://history.example/item', 'availability' => null, 'price_factor' => 1]);
        Price::withoutEvents(function () use ($url, $store): void {
            foreach ([0.01, 80] as $day => $price) {
                $url->prices()->create(['price' => $price, 'unit_price' => $price, 'price_factor' => 1, 'store_id' => $store->getKey(), 'created_at' => now()->subDays(2 - $day)]);
            }
        });
        $product->updatePriceCache();
        $product->updateInsightsCache();

        return [$user, $product, $url];
    }

    private function productLockIsAvailable(Product $product): bool
    {
        $connection = DB::connection('history_probe');
        try {
            $connection->beginTransaction();
            $connection->table('products')->where('id', $product->getKey())->lockForUpdate()->first();

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    private function awaitMarker(string $path, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($path) && microtime(true) < $deadline && $process->isRunning()) {
            $process->checkTimeout();
            usleep(10000);
        }
        $this->assertFileExists($path, 'The isolated history worker did not reach the expected synchronization point. '.$this->workerDiagnostic(dirname($path)));
    }

    private function workerDiagnostic(string $directory): string
    {
        $path = $directory.'/result.json';
        if (! is_file($path)) {
            return 'Worker has not returned a result.';
        }
        $result = json_decode(file_get_contents($path), true);

        // Only explicitly safe fields; never print raw database exceptions or configuration.
        return json_encode(array_intersect_key($result ?? [], array_flip(['ok', 'stage', 'error_type', 'sql_state', 'driver_code'])));
    }

    private function assertDisposableTestDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'), 'Destructive isolation is only allowed in the testing environment.');
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $baseDatabase = preg_replace('/_test_\d+\z/', '', $database);
        $dedicatedSchema = $baseDatabase === 'tests_db' || preg_match('/\A[A-Za-z0-9_]+_test\z/', $baseDatabase) === 1;
        // Lando uses the same schema name on separate application and test services.
        $landoTestService = $baseDatabase === 'laravel'
            && $connection->getConfig('host') === 'tests_db'
            && ! $connection->getConfig('unix_socket');
        $this->assertTrue($dedicatedSchema || $landoTestService, 'Use a dedicated test schema or the configured Lando test service.');
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS current_database')->current_database, 'The actual connection must match the declared disposable test database.');
    }
}

class ScrapeBoundaryUrl extends Url
{
    protected $table = 'urls';

    public Closure $scraper;

    public function scrape(): array
    {
        return ($this->scraper)();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'url_id')->latest('created_at');
    }
}

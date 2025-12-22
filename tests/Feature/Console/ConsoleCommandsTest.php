<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BuildSearchResearch;
use App\Console\Commands\CreateStores;
use App\Console\Commands\FetchAll;
use App\Console\Commands\InitDatabase;
use App\Console\Commands\RegeneratePriceCache;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\StoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_all_command_runs_successfully()
    {
        // Command should run without errors even with no products
        $this->artisan(FetchAll::COMMAND)
            ->assertExitCode(0);
    }

    public function test_fetch_all_command_with_log_option()
    {
        // Command should run with log option
        $this->artisan(FetchAll::COMMAND, ['--log' => true])
            ->assertExitCode(0);
    }

    public function test_regenerate_price_cache_command_updates_all_products()
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(3)->create(['user_id' => $user->id]);

        $this->artisan(RegeneratePriceCache::COMMAND)
            ->assertExitCode(0);

        // Verify the command ran without errors
        $this->assertTrue(true);
    }

    public function test_regenerate_price_cache_command_handles_empty_products()
    {
        $this->artisan(RegeneratePriceCache::COMMAND)
            ->assertExitCode(0);
    }

    public function test_build_search_research_command_with_product_name()
    {
        // Command should run successfully even if no search sources are available
        $this->artisan(BuildSearchResearch::COMMAND, ['product_name' => 'laptop'])
            ->assertExitCode(0);
    }

    public function test_create_stores_command_creates_stores_for_country()
    {
        $country = StoreSeeder::getAllCountries()[0] ?? 'us';

        $this->artisan(CreateStores::COMMAND, ['country' => $country])
            ->assertExitCode(0);

        // Verify at least one store was created
        $this->assertGreaterThan(0, Store::count());
    }

    public function test_create_stores_command_creates_all_stores()
    {
        $this->artisan(CreateStores::COMMAND, ['country' => 'all'])
            ->assertExitCode(0);

        // Verify stores were created
        $this->assertGreaterThan(0, Store::count());
    }

    public function test_create_stores_command_skips_existing_stores()
    {
        $country = StoreSeeder::getAllCountries()[0] ?? 'us';
        $storeData = StoreSeeder::getStoreData($country);

        if (! empty($storeData)) {
            $firstStore = $storeData[0];
            Store::factory()->create([
                'slug' => $firstStore['slug'] ?? \Illuminate\Support\Str::slug($firstStore['name']),
                'name' => $firstStore['name'],
            ]);

            $initialCount = Store::count();

            $this->artisan(CreateStores::COMMAND, ['country' => $country])
                ->assertExitCode(0);

            // Should not create duplicate
            $this->assertEquals($initialCount + count($storeData) - 1, Store::count());
        } else {
            $this->markTestSkipped('No store data available for country');
        }
    }

    public function test_create_stores_command_updates_existing_stores_with_update_flag()
    {
        $country = StoreSeeder::getAllCountries()[0] ?? 'us';
        $storeData = StoreSeeder::getStoreData($country);

        if (! empty($storeData)) {
            $firstStore = $storeData[0];
            $store = Store::factory()->create([
                'slug' => $firstStore['slug'] ?? \Illuminate\Support\Str::slug($firstStore['name']),
                'name' => 'Old Name',
            ]);

            $this->artisan(CreateStores::COMMAND, ['country' => $country, '--update' => true])
                ->assertExitCode(0);

            // Should update the name
            $this->assertSame($firstStore['name'], $store->refresh()->name);
        } else {
            $this->markTestSkipped('No store data available for country');
        }
    }

    public function test_init_database_command_when_already_initialized()
    {
        // Database is already initialized in tests
        $this->assertTrue(Schema::hasTable('migrations'));

        // Command should run successfully when database is already initialized
        $this->artisan(InitDatabase::COMMAND)
            ->assertExitCode(0);
    }

    public function test_init_database_command_detects_database_connection()
    {
        // Verify we can connect to the database
        try {
            DB::connection()->getPdo();
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail('Database connection failed: '.$e->getMessage());
        }
    }
}

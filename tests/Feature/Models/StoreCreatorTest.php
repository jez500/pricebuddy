<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stores are shared. user_id records who created the store, and is never NULL.
 */
class StoreCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_created_by_a_logged_in_user_records_that_user(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertSame($user->id, Store::factory()->create(['user_id' => null])->user_id);
    }

    public function test_store_created_without_a_user_records_the_oldest_admin(): void
    {
        User::factory()->create();
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->assertSame($admin->id, Store::factory()->create(['user_id' => null])->user_id);
    }

    public function test_store_created_without_a_user_or_admin_records_the_oldest_user(): void
    {
        $first = User::factory()->create();
        User::factory()->create();

        $this->assertSame($first->id, Store::factory()->create(['user_id' => null])->user_id);
    }

    public function test_an_explicit_creator_is_kept(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->assertSame($user->id, Store::factory()->create(['user_id' => $user->id])->user_id);
    }

    /**
     * A queued job has no logged-in user. A store it auto-creates records the user who
     * added the URL, not the fallback admin.
     */
    public function test_store_auto_created_while_adding_a_url_records_that_user(): void
    {
        Store::query()->delete();
        User::factory()->admin()->create();
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        Http::fake(['*' => Http::response(
            '<html><head>
                <meta property="og:title" content="My product">
                <meta property="product:price:amount" content="35.00">
                <meta property="og:image" content="http://localhost/my-image.jpg">
            </head><body></body></html>'
        )]);

        $this->assertGuest();

        $url = Url::createFromUrl('https://newshop.test/product/1', $product->id, $user->id);

        $this->assertInstanceOf(Url::class, $url);
        $this->assertSame($user->id, $url->store->user_id);
    }
}

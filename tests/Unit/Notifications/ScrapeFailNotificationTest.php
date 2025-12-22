<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrapeFailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_uses_database_channel()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        $notification = new ScrapeFailNotification($product);

        $channels = $notification->via($user);

        $this->assertSame(['database'], $channels);
    }

    public function test_to_array_returns_product_array()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        $notification = new ScrapeFailNotification($product);

        $array = $notification->toArray($user);

        $this->assertIsArray($array);
        $this->assertArrayHasKey('id', $array);
        $this->assertEquals($product->id, $array['id']);
    }

    public function test_to_database_returns_formatted_message()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test Product Title',
        ]);
        $notification = new ScrapeFailNotification($product);

        $databaseMessage = $notification->toDatabase($user);

        $this->assertIsArray($databaseMessage);
        $this->assertArrayHasKey('title', $databaseMessage);
        $this->assertArrayHasKey('body', $databaseMessage);
        $this->assertArrayHasKey('actions', $databaseMessage);
        $this->assertSame('Error scraping product urls', $databaseMessage['title']);
    }

    public function test_notification_has_view_product_action()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        $notification = new ScrapeFailNotification($product);

        $databaseMessage = $notification->toDatabase($user);

        $this->assertArrayHasKey('actions', $databaseMessage);
        $this->assertIsArray($databaseMessage['actions']);
        $this->assertGreaterThan(0, count($databaseMessage['actions']));
    }

    public function test_notification_truncates_long_product_title()
    {
        $user = User::factory()->create();
        $longTitle = str_repeat('A', 200);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'title' => $longTitle,
        ]);
        $notification = new ScrapeFailNotification($product);

        $databaseMessage = $notification->toDatabase($user);

        // The title method truncates to 100 characters + ellipsis
        $this->assertLessThanOrEqual(103, strlen($databaseMessage['body']));
    }
}

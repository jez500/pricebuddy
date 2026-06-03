<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\NotificationHistoryResource;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Notifications\ScrapeFailNotification;
use App\Notifications\StockAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationHistoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeNotification(User $user, string $type, array $data = [], $readAt = null): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data ?: ['title' => 'Test title', 'body' => 'Test body'],
            'read_at' => $readAt,
        ]);
    }

    public function test_query_returns_only_current_users_pricebuddy_notifications()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeNotification($user, PriceAlertNotification::class);
        $this->makeNotification($user, StockAlertNotification::class);
        $this->makeNotification($user, ScrapeFailNotification::class);
        // Excluded: not a PriceBuddy notification type.
        $this->makeNotification($user, 'Some\\Other\\Notification');
        // Excluded: belongs to another user.
        $this->makeNotification($other, PriceAlertNotification::class);

        $this->actingAs($user);

        $types = NotificationHistoryResource::getEloquentQuery()->pluck('type');

        $this->assertCount(3, $types);
        $this->assertContains(PriceAlertNotification::class, $types->all());
        $this->assertContains(StockAlertNotification::class, $types->all());
        $this->assertContains(ScrapeFailNotification::class, $types->all());
        $this->assertNotContains('Some\\Other\\Notification', $types->all());
    }

    public function test_users_cannot_see_each_others_notifications()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeNotification($other, PriceAlertNotification::class);

        $this->actingAs($user);

        $this->assertSame(0, NotificationHistoryResource::getEloquentQuery()->count());
    }

    public function test_type_filter_metadata_covers_every_pricebuddy_notification()
    {
        $meta = NotificationHistoryResource::typeMeta();

        $this->assertArrayHasKey(PriceAlertNotification::class, $meta);
        $this->assertArrayHasKey(StockAlertNotification::class, $meta);
        $this->assertArrayHasKey(ScrapeFailNotification::class, $meta);

        foreach ($meta as $entry) {
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('color', $entry);
            $this->assertArrayHasKey('icon', $entry);
        }
    }
}

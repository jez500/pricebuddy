<?php

namespace Tests\Feature\Filament;

use App\Enums\NotificationMethods;
use App\Enums\Role;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Notifications\StockAlertNotification;
use App\Services\Helpers\NotificationsHelper;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TestNotificationActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);

        NotificationsHelper::setSetting(NotificationMethods::Mail, value: ['enabled' => true]);
        NotificationsHelper::setSetting(NotificationMethods::Gotify, value: [
            'enabled' => true,
            'url' => 'https://gotify.test',
            'token' => 'test-token',
        ]);

        $this->user = User::factory()->withNotificationSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => false],
        ])->createOne();
        $this->actingAs($this->user);

        $this->product = Product::factory()->addUrlsAndPrices()->create(['user_id' => $this->user->getKey()]);
    }

    public function test_sends_test_price_alert_to_the_users_enabled_methods_only(): void
    {
        Notification::fake();

        Livewire::test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->callAction('test_notification', ['type' => 'price'])
            ->assertHasNoActionErrors()
            ->assertNotified('Test notification sent');

        Notification::assertSentTo(
            $this->user,
            PriceAlertNotification::class,
            fn (PriceAlertNotification $notification, array $channels) => $channels === ['mail']
                && str_starts_with($notification->toArray($this->user)['title'], 'Test: Price ')
        );
    }

    public function test_sends_test_stock_alert(): void
    {
        Notification::fake();

        Livewire::test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->callAction('test_notification', ['type' => 'stock'])
            ->assertNotified('Test notification sent');

        Notification::assertSentTo(
            $this->user,
            StockAlertNotification::class,
            fn (StockAlertNotification $notification) => str_starts_with($notification->toArray($this->user)['title'], 'Test: Back in stock')
        );
    }

    public function test_reports_a_failing_method_and_still_sends_the_others(): void
    {
        Http::fake(['gotify.test/*' => Http::response('nope', 500)]);
        $this->user->update(['settings' => ['notifications' => [
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => true],
        ]]]);

        $results = NotificationsHelper::sendTest(
            $this->user->fresh(),
            (new PriceAlertNotification($this->product->urls()->first()))->asTest()
        );

        $this->assertSame(['mail', 'gotify'], array_keys($results));
        $this->assertNull($results['mail']);
        $this->assertNotNull($results['gotify']);
    }

    public function test_failure_details_are_only_shown_to_admins(): void
    {
        Http::fake(['gotify.test/*' => Http::response('secret-detail', 500)]);
        $settings = ['notifications' => [NotificationMethods::Gotify->value => ['enabled' => true]]];

        $this->user->update(['role' => Role::User, 'settings' => $settings]);
        Livewire::test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->callAction('test_notification', ['type' => 'price'])
            ->assertNotified(
                FilamentNotification::make()
                    ->title('Test notification failed')
                    ->body('Gotify: Ask an administrator to check the settings and logs')
                    ->danger()
                    ->persistent()
            );

        $this->user->update(['role' => Role::Admin]);
        Livewire::test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->callAction('test_notification', ['type' => 'price'])
            ->assertNotified(
                FilamentNotification::make()
                    ->title('Test notification failed')
                    ->body("Gotify: HTTP request returned status code 500:\nsecret-detail\n")
                    ->danger()
                    ->persistent()
            );
    }

    public function test_warns_when_the_user_has_no_notification_methods_enabled(): void
    {
        Notification::fake();
        $this->user->update(['settings' => ['notifications' => []]]);

        Livewire::test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->callAction('test_notification', ['type' => 'price'])
            ->assertNotified('No notification methods to test');

        Notification::assertNothingSent();
    }

    public function test_warns_when_the_product_has_no_urls(): void
    {
        Notification::fake();
        $product = Product::factory()->create(['user_id' => $this->user->getKey()]);

        Livewire::test(ViewProduct::class, ['record' => $product->getKey()])
            ->callAction('test_notification', ['type' => 'price'])
            ->assertNotified('Add a URL to this product to send a test notification');

        Notification::assertNothingSent();
    }
}

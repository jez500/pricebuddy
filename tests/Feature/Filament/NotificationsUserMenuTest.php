<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\NotificationHistoryResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsUserMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_notifications_is_not_registered_in_the_sidebar_navigation()
    {
        $this->assertFalse(NotificationHistoryResource::shouldRegisterNavigation());
    }

    public function test_notifications_is_available_in_the_user_menu()
    {
        $items = collect(Filament::getCurrentPanel()->getUserMenuItems());

        $notifications = $items->first(fn ($item) => $item->getLabel() === 'Notifications');

        $this->assertNotNull($notifications, 'Notifications should be in the user menu.');
        $this->assertSame(NotificationHistoryResource::getUrl('index'), $notifications->getUrl());
    }
}

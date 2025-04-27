<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Channels\GotifyChannel;
use App\Services\Helpers\NotificationsHelper;
use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\Pushover\PushoverChannel;
use Tests\TestCase;

class NotificationsHelperTest extends TestCase
{
    use RefreshDatabase;

    protected array $testSettings = [
        NotificationMethods::Mail->value => [
            'enabled' => true,
            'smtp_host' => 'my.smtp.com',
            'smtp_port' => '25',
            'smtp_user' => 'mailuser',
            'smtp_password' => 'mailpass',
        ],
        NotificationMethods::Pushover->value => [
            'enabled' => false,
            'token' => 'test_po_token',
        ],
        NotificationMethods::Gotify->value => [
            'enabled' => false,
            'token' => 'test_po_token',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGlobalSettings($this->testSettings);
    }

    public function test_get_services_returns_correct_collection()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Pushover->value => ['enabled' => false],
            NotificationMethods::Gotify->value => ['enabled' => false],
        ]);

        $services = NotificationsHelper::getServices();

        // Note: The original diff showed new files in app/Notifications/Channels/ and app/Notifications/Messages/
        // Assuming GotifyChannel is correctly implemented and NotificationMethods::Gotify exists.
        // The count should reflect all methods defined in NotificationMethods enum. Let's assume 3 for now.
        $this->assertCount(3, $services);
        $this->assertArrayHasKey(NotificationMethods::Mail->value, $services);
        $this->assertArrayHasKey(NotificationMethods::Pushover->value, $services);
        $this->assertArrayHasKey(NotificationMethods::Gotify->value, $services);
        $this->assertEquals('mail', $services['mail']['channel']);
        $this->assertEquals(PushoverChannel::class, $services['pushover']['channel']);
        // Correcting the assertion below based on the Gotify enum value
        $this->assertEquals(GotifyChannel::class, $services['gotify']['channel']);
    }

    public function test_get_all_notification_services()
    {
        $services = NotificationsHelper::getServices();
        $this->assertIsArray($services->toArray());
        $this->assertSame('my.smtp.com', $services->get('mail')['smtp_host']);
        $this->assertSame('test_po_token', $services->get('pushover')['token']);
        $this->assertSame('test_po_token', $services->get('gotify')['token']);

        foreach ($services->keys() as $service) {
            $this->assertArrayHasKey('enabled', $services[$service]);
        }
    }

    public function test_get_all_enabled_notification_services()
    {
        $this->assertSame(['mail'], NotificationsHelper::getEnabled()->keys()->toArray());
    }

    public function test_get_custom_enabled_notification_services()
    {
        $newSettings = $this->testSettings;
        $newSettings[NotificationMethods::Pushover->value]['enabled'] = true;
        $newSettings[NotificationMethods::Gotify->value]['enabled'] = true; // Enable Gotify globally for this test case
        $this->setGlobalSettings($newSettings);

        $user = User::factory()->create([
            'settings' => ['notifications' => [
                NotificationMethods::Mail->value => ['enabled' => true],
                NotificationMethods::Pushover->value => ['enabled' => true],
                NotificationMethods::Gotify->value => ['enabled' => true], // Enable Gotify for the user
            ]],
        ]);

        // Expect mail, Pushover, and Gotify channels
        $this->assertSame(
            ['mail', PushoverChannel::class, GotifyChannel::class],
            NotificationsHelper::getEnabledChannels($user)->toArray(),
        );

        $this->setGlobalSettings($this->testSettings); // Reset global settings
    }

    public function test_get_notification_service_setting()
    {
        $this->assertSame('my.smtp.com', NotificationsHelper::getSetting('mail', 'smtp_host'));
        $this->assertSame('test_po_token', NotificationsHelper::getSetting('pushover', 'token'));
        $this->assertSame('test_po_token', NotificationsHelper::getSetting('gotify', 'token'));
    }

    public function test_get_user_services_returns_correct_collection()
    {
        $user = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => true]]],
        ]);

        $userServices = NotificationsHelper::getUserServices($user);

        $this->assertCount(1, $userServices);
        $this->assertArrayHasKey('mail', $userServices);
        $this->assertTrue($userServices['mail']['enabled']);
    }

    public function test_get_enabled_returns_correct_collection()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Pushover->value => ['enabled' => false],
            NotificationMethods::Gotify->value => ['enabled' => false],
        ]);

        $enabledServices = NotificationsHelper::getEnabled();

        $this->assertCount(1, $enabledServices);
        $this->assertArrayHasKey('mail', $enabledServices);
        $this->assertEquals('mail', $enabledServices['mail']['channel']);
    }

    public function test_get_user_enabled_returns_true_for_enabled_service()
    {
        $user = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => true]]],
        ]);

        $this->assertTrue(NotificationsHelper::getUserEnabled($user, NotificationMethods::Mail->value));
        $this->assertFalse(NotificationsHelper::getUserEnabled($user, NotificationMethods::Pushover->value));
        $this->assertFalse(NotificationsHelper::getUserEnabled($user, NotificationMethods::Gotify->value));
    }

    public function test_is_enabled_returns_true_for_enabled_service()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Pushover->value => ['enabled' => false],
            NotificationMethods::Gotify->value => ['enabled' => false],
        ]);

        $this->assertTrue(NotificationsHelper::isEnabled(NotificationMethods::Mail->value));
        $this->assertFalse(NotificationsHelper::isEnabled(NotificationMethods::Pushover->value));
        $this->assertFalse(NotificationsHelper::isEnabled(NotificationMethods::Gotify->value));
    }

    public function test_get_enabled_channels_returns_correct_channels()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Pushover->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => true],
        ]);

        $user = User::factory()->create([
            'settings' => ['notifications' => [
                NotificationMethods::Mail->value => ['enabled' => true],
                NotificationMethods::Pushover->value => ['enabled' => false], // Pushover disabled for user
                NotificationMethods::Gotify->value => ['enabled' => true], // Gotify enabled for user
            ]],
        ]);

        $channels = NotificationsHelper::getEnabledChannels($user);

        // Expect mail and Gotify channels
        $this->assertCount(2, $channels);
        $this->assertContains('mail', $channels);
        $this->assertContains(GotifyChannel::class, $channels);
        $this->assertNotContains(PushoverChannel::class, $channels);
    }

    public function test_get_settings_returns_correct_settings()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true, 'host' => 'mail_host'],
            NotificationMethods::Pushover->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => true],
        ]);

        $settings = NotificationsHelper::getSettings(NotificationMethods::Mail->value);

        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('host', $settings);
        $this->assertArrayHasKey('channel', $settings);
        $this->assertTrue($settings['enabled']);
        $this->assertEquals('mail_host', $settings['host']);
    }

    public function test_get_setting_returns_correct_value()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true, 'host' => 'mail_host'],
            NotificationMethods::Pushover->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => true],
        ]);

        $value = NotificationsHelper::getSetting(NotificationMethods::Mail->value, 'host');

        $this->assertSame('mail_host', $value);
    }

    public function test_get_setting_returns_default_value_if_not_set()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true],
            NotificationMethods::Pushover->value => ['enabled' => true],
            NotificationMethods::Gotify->value => ['enabled' => true],
        ]);

        $value = NotificationsHelper::getSetting(NotificationMethods::Mail->value, 'nonexistent', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    protected function setGlobalSettings(array $settings): void
    {
        AppSettings::new()->fill(['notification_services' => $settings])->save();
        SettingsHelper::$settings = null; // Force reload on next access
    }
}

<?php

namespace App\Filament\Actions\Notifications;

use App\Notifications\Channels\DiscordChannel;
use Closure;
use Exception;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;

class TestDiscordAction extends Action
{
    public Closure $settingsCallback;

    public static function getDefaultName(): ?string
    {
        return 'discord_test';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Test'));

        $this->successNotificationTitle(__('Test notification sent successfully'));

        $this->failureNotificationTitle(__('Error'));

        $this->icon('heroicon-m-bell');

        $this->color('gray');

        $this->action(fn () => $this->testSendingNotification());
    }

    public function setSettings(Closure $settingsCallback): self
    {
        $this->settingsCallback = $settingsCallback;

        return $this;
    }

    protected function testSendingNotification(): void
    {
        $settings = call_user_func($this->settingsCallback);
        $webhookUrl = data_get($settings, 'webhook_url');

        if (empty($webhookUrl)) {
            Notification::make()
                ->title('Error')
                ->body('Please save a Discord webhook URL first')
                ->danger()
                ->send();

            return;
        }

        try {
            $response = DiscordChannel::sendRequest(
                $webhookUrl,
                'Test PriceBuddy notification',
                'This is a test notification from PriceBuddy',
                url('/')
            );

            $response->throw();
            $this->success();
        } catch (RequestException|Exception $e) {
            Notification::make()
                ->title('Failed to send test notification')
                ->body('Error: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}

<?php

namespace App\Filament\Actions\Notifications;

use App\Notifications\Channels\AppriseChannel;
use Closure;
use Exception;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;

class TestAppriseAction extends Action
{
    public Closure $settingsCallback;

    public static function getDefaultName(): ?string
    {
        return 'apprise_test';
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
        $baseUrl = data_get($settings, 'url');
        $token = data_get($settings, 'token');

        if (empty($baseUrl) || empty($token)) {
            Notification::make()
                ->title('Error')
                ->body('Please save your Apprise settings first')
                ->danger()
                ->send();

            return;
        }

        try {
            $response = AppriseChannel::sendRequest(
                AppriseChannel::makeUrl($baseUrl, $token),
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

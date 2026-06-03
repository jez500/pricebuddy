<?php

namespace App\Filament\Actions\Notifications;

use Closure;
use Exception;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Validates a Telegram bot token via the getMe endpoint.
 *
 * A full test message can't be sent from the app settings because the chat id
 * is configured per user, so this confirms the shared bot token is valid.
 */
class TestTelegramAction extends Action
{
    public Closure $settingsCallback;

    public static function getDefaultName(): ?string
    {
        return 'telegram_test';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Test bot token'));

        $this->successNotificationTitle(__('Telegram bot token is valid'));

        $this->failureNotificationTitle(__('Error'));

        $this->icon('heroicon-m-bell');

        $this->color('gray');

        $this->action(fn () => $this->testToken());
    }

    public function setSettings(Closure $settingsCallback): self
    {
        $this->settingsCallback = $settingsCallback;

        return $this;
    }

    protected function testToken(): void
    {
        $settings = call_user_func($this->settingsCallback);
        $botToken = data_get($settings, 'bot_token');

        if (empty($botToken)) {
            Notification::make()
                ->title('Error')
                ->body('Please save your Telegram bot token first')
                ->danger()
                ->send();

            return;
        }

        try {
            $response = Http::get('https://api.telegram.org/bot'.$botToken.'/getMe');
            $response->throw();

            if (! $response->json('ok')) {
                throw new Exception($response->json('description', 'Invalid bot token'));
            }

            $this->success();
        } catch (RequestException|Exception $e) {
            Notification::make()
                ->title('Telegram bot token is invalid')
                ->body('Error: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
